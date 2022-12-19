<?php declare(strict_types=1);

namespace Deduplicate\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

class IndexController extends AbstractActionController
{
    const MAX_TO_MERGE = \Omeka\Stdlib\Paginator::PER_PAGE;

    public function indexAction()
    {
        /** @var \Deduplicate\Form\DeduplicateForm $form */
        $form = $this->getForm(\Deduplicate\Form\DeduplicateForm::class);
        // There is no csrf in batch edit. So checks are done directly with api.
        $form->remove('deduplicateform_csrf');

        $request = $this->getRequest();
        $params = $request->getPost();

        $args = [
            'resources' => [],
            'form' => $form,
            'resourceType' => 'items',
            'query' => [],
            'property' => null,
            'value' => '',
            'totalResourcesQuery' => null,
            'totalResources' => null,
        ];

        // TODO The check may be done in the form.

        $api = $this->api();
        $hasError = false;
        $isPost = $request->isPost();

        if ($isPost) {
            $property = $params['deduplicate_property'] ?? null;
            if (!$property || (!is_numeric($property) && !strpos($property, ':'))) {
                $this->messenger()->addError('A property is required to search on.'); // @translate
                $hasError = $isPost ? true : false;
            } else {
                /** @var \Omeka\Api\Representation\PropertyRepresentation $property */
                $property = $api->searchOne('properties', is_numeric($property) ? ['id' => (int) $property] : ['vocabulary_prefix' => strtok($property, ':'), 'local_name' => strtok(':')])->getContent();
                if (!$property) {
                    $this->messenger()->addError(new Message('The property "%s" does not exist.', $params['property'])); // @translate
                    $hasError = true;
                }
            }

            $args['property'] = $property;

            $value = $params['deduplicate_value'] ?? '';
            if (!strlen($value)) {
                $this->messenger()->addError('A value to deduplicate on is required.'); // @translate
                $hasError = true;
            }

            if (mb_strlen($value) > 255) {
                $this->messenger()->addError('The string is too long (more than %d characters).', 255); // @translate
                $hasError = true;
            }

            $args['value'] = $value;

            $resourceTypes = [
                'item' => 'items',
                'item-set' => 'item_sets',
                'media' => 'media',
                'items' => 'items',
                'item_sets' => 'item_sets',
            ];

            $resourceType = empty($params['resource_type']) || !isset($resourceTypes[$params['resource_type']])
                ? 'items'
                : $resourceTypes[$params['resource_type']];
            $args['resourceType'] = $resourceType;

            $query = [];

            $batchAction = isset($params['deduplicate_selected']) || (isset($params['batch_action']) && $params['batch_action'] === 'deduplicate_selected')
                ? 'deduplicate_selected'
                : 'deduplicate_all';

            if ($batchAction === 'deduplicate_selected') {
                $resourceIds = $params['resource_ids'] ?? [];
                $resourceIds = array_unique(array_filter($resourceIds));
                if (!$resourceIds) {
                    $this->messenger()->addError('The query does not find selected resource ids.'); // @translate
                    $hasError = true;
                }
                $query = ['id' => $resourceIds];
            } else {
                $query = $params['query'] ?? '[]';
                $query = array_diff_key(json_decode($query, true) ?: [], array_flip(['csrf', 'deduplicateform_csrf', 'sort_by', 'sort_order', 'page', 'per_page', 'offset', 'limit']));
            }

            if ($query) {
                $args['query'] = $query;
                $args['totalResourcesQuery'] = $api->search($resourceType, $query + ['limit' => 0])->getTotalResults();
                if (!$args['totalResourcesQuery']) {
                    $this->messenger()->addError('The query returned no resource.'); // @translate
                    $hasError = true;
                }
            }

            if (!$hasError) {
                $nearValues = $this->near($value, $property->id(), $resourceType, $query);
                if (is_null($nearValues)) {
                    $this->messenger()->addWarning(new Message('There are too many similar values near "%s". You may filter resources first.', $value)); // @translate
                    $hasError = true;
                } elseif (!$nearValues) {
                    $this->messenger()->addWarning(new Message('There is no existing value near "%s".', $value)); // @translate
                    $hasError = true;
                } else {
                    // Do the search via module AdvancedSearch.
                    $queryProperty = $query;
                    /* TODO Add a near query in Advanced Search via mysql.
                    $queryProperty['property'][] = [
                        'property' => $property->term(),
                        'type' => 'near',
                        'value' => $value,
                    ];
                    */
                    $queryProperty['property'][] = [
                        'property' => $property->term(),
                        'type' => 'list',
                        'text' => $nearValues,
                    ];
                    // $queryProperty['limit'] = $this->settings()->get('pagination_per_page') ?: \Omeka\Stdlib\Paginator::PER_PAGE;
                    $queryProperty['limit'] = self::MAX_TO_MERGE;

                    $response = $api->search($resourceType, $queryProperty);
                    $args['resources'] = $response->getContent();
                    $args['totalResources'] = $response->getTotalResults();

                    $resourceId = isset($params['resource_id']) ? (int) $params['resource_id'] : 0;
                    if ($resourceId) {
                        $resource = $api->search($resourceType, ['id' => $resourceId])->getContent();
                        if (!$resource) {
                            $this->messenger()->addError(new Message('The resource %s does not exist.', $params['resource_id'])); // @translate
                            $hasError = true;
                        }
                    }

                    // Sometime, a 0 is included in the list of selected resource
                    // ids and that may break advanced search.

                    $resourcesMerged = [];
                    if (!empty($params['resources_merged'])) {
                        $params['resources_merged'] = array_unique(array_filter($params['resources_merged'])) ?: [];
                        $resourcesMerged = $api->search($resourceType, ['id' => $params['resources_merged']], ['returnScalar' => 'id'])->getContent();
                        if (!$resourcesMerged || count($resourcesMerged) !== count($params['resources_merged'])) {
                            $this->messenger()->addError(new Message('Some merged resources do not exist.')); // @translate
                            $hasError = true;
                        }
                    }
                }
            }
        }

        $view = new ViewModel($args);

        if ($hasError || !$isPost) {
            return $view;
        }

        if ($resourceId && $resourcesMerged) {
            return $view;
        }

        // Useless: the form is checked above.
        $form->init();
        $form->setData([
            'deduplicate_property' => $property ? $property->term() : null,
            'deduplicate_value' => $value,
        ]);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        return $view;
    }

    /**
     * There is no simple way to do a search "similar" in mysql and doctrine
     * doesn't implement "soundex" easily, so process via php.
     */
    protected function near(?string $value, $propertyId, string $resourceType, array $query): array
    {
        if (is_null($value) || !$propertyId) {
            return [];
        }
        $query['property'][] = [
            'property' => $propertyId,
            'type' => 'ex',
        ];
        $filteredIds = $this->api()->search($resourceType, $query, ['returnScalar' => 'id'])->getContent();
        if (!count($filteredIds)) {
            return [];
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select('DISTINCT value.value')
            ->from('value', 'value')
            ->where($expr->eq('value.property_id', ':property_id'))
            ->andWhere($expr->in('value.resource_id', ':resource_ids'))
            ->addOrderBy('value.value', 'asc')
        ;
        $bind = [
            'property_id' => $propertyId,
            'resource_ids' => $filteredIds,
        ];
        $types = [
            'property_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'resource_ids' => $connection::PARAM_INT_ARRAY,
        ];
        $allValues = $connection->executeQuery($qb, $bind, $types)->fetchFirstColumn();

        $result = [];
        $percent = null;
        $lowerValue = mb_strtolower($value);
        foreach ($allValues as $oneValue) {
            similar_text($lowerValue, mb_strtolower($oneValue), $percent);
            if ($percent > 66) {
                $result[] = $oneValue;
            }
        }

        return $result;
    }
}
