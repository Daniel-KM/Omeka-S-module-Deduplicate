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
            $batchAction = $params['batch_action'] ?? 'deduplicate_all';
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

            // Do the search via module AdvancedSearch.
            $queryProperty = $query;
            $queryProperty['property'][] = [
                'property' => $property->term(),
                'type' => 'near',
                'value' => $value,
            ];
            // $query['limit'] = $this->settings()->get('pagination_per_page') ?: \Omeka\Stdlib\Paginator::PER_PAGE;
            $query['limit'] = self::MAX_TO_MERGE;

            $response = $api->search($resourceType, $query);
            $args['resources'] = $response->getContent();
            $args['totalResources'] = $response->getTotalResults();
        }

        $view = new ViewModel($args);

        if ($hasError || !$isPost) {
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
}
