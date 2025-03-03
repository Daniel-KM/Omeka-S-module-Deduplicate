<?php declare(strict_types=1);

namespace Deduplicate\Controller;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'manual';
        return $this->forward()->dispatch(__CLASS__, $params);
    }


    public function autoAction()
    {
        /** @var \Deduplicate\Form\DeduplicateAutoForm $form */
        $form = $this->getForm(\Deduplicate\Form\DeduplicateAutoForm::class);

        $request = $this->getRequest();

        $view = new ViewModel([
            'form' => $form,
            'resourceType' => null,
            'property' => null,
            'query' => null,
            'method' => null,
            'duplicates' => [],
            'process' => '0',
        ]);

        $hiddenProcess = [
            'name' => 'process',
            'type' => \Laminas\Form\Element\Hidden::class,
            'attributes' => [
                'id' => 'deduplicate-process',
                'value' => '0',
            ],
        ];

        if (!$request->isPost()) {
            $form->remove('process')->add($hiddenProcess);
            return $view;
        }

        $params = $request->getPost();
        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        $property = $params['deduplicate_property'] ?? null;
        if (!$property) {
            $this->messenger()->addError(
                'A property to deduplicate on is required.' // @translate
            );
            return $view;
        }

        unset($params['csrf']);

        $resourceType = 'items';
        $method = $params['method'] ?? 'equal';
        $query = [];

        $duplicates = $this->getDuplicates($resourceType, $property, $method, $query);

        $view = new ViewModel([
            'form' => $form,
            'resourceType' => $resourceType,
            'property' => $property,
            'query' => $query,
            'method' => $method,
            'duplicates' => $duplicates,
            'process' => (int) !empty($params['process']),
        ]);

        if (!$duplicates) {
            $this->messenger()->addSuccess(new PsrMessage(
                'There are no duplicates for the property {property}.', // @translate
                ['property' => $property]
             ));
            $form->remove('process')->add($hiddenProcess);
            return $view;
        }

        $this->messenger()->addSuccess(new PsrMessage(
            'There are {count} duplicates for the property {property}.', // @translate
            ['count' => count($duplicates), 'property' => $property]
        ));

        if (empty($params['process'])) {
            $this->messenger()->addWarning(
                'Confirm removing duplicates, except the first, by checking the checkbox.' // @translate
            );
            return $view;
        }

        // Remove first resource and keep only the list of resources.
        // The results are already ordered by resource id.
        $result = [];
        foreach ($duplicates as $rs) {
            array_shift($rs);
            $result[] = $rs;
        }

        $result = array_unique(array_merge(...$result));
        sort($result);

        if (count($result)) {
            try {
                $this->api()->batchDelete('items', $result);
                $this->messenger()->addWarning(new PsrMessage(
                    '{count} duplicates were removed.', // @translate
                    ['count' => count($result)]
                ));
            } catch (\Exception $e) {
                $this->messenger()->addWarning(new PsrMessage(
                    'An error occurred when deleting duplicates: {msg}.', // @translate
                    ['msg' => $e->getMessage()]
                ));
            }
        }

        return $view;
    }

    public function manualAction()
    {
        /** @var \Deduplicate\Form\DeduplicateForm $form */
        $form = $this->getForm(\Deduplicate\Form\DeduplicateForm::class);

        $request = $this->getRequest();
        $params = $request->getPost();

        $args = [
            'resources' => [],
            'form' => $form,
            'resourceType' => 'items',
            'query' => [],
            'property' => null,
            'value' => '',
            'method' => null,
            'totalResourcesQuery' => null,
            'totalResources' => null,
        ];

        // TODO The check may be done in the form.

        $api = $this->api();
        $hasError = false;
        $isPost = $request->isPost();

        if ($isPost) {
            $property = empty($params['deduplicate_property']) ? null : $params['deduplicate_property'];
            $propertyTerm = $this->easyMeta()->propertyTerm($property);
            if ($property && !$propertyTerm) {
                $this->messenger()->addError(new PsrMessage(
                    'The property {property} does not exist.', // @translate
                    ['property' => $params['deduplicate_property']]
                ));
                $hasError = true;
            } else {
                $property = $propertyTerm;
            }

            $value = $params['deduplicate_value'] ?? '';
            if ($property && !strlen($value)) {
                $this->messenger()->addError(
                    'A value to deduplicate on is required.' // @translate
                );
                $hasError = true;
            } elseif (!$property && strlen($value)) {
                $this->messenger()->addError(
                    'A property is required to search on.' // @translate
                );
                $hasError = true;
            }

            if (mb_strlen($value) > 255) {
                $this->messenger()->addError(
                    'The string is too long (more than {length} characters).', // @translate
                    ['length' => 255]
                );
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
                    $this->messenger()->addError(
                        'The query does not find selected resource ids.' // @translate
                    );
                    $hasError = true;
                }
                $query = ['id' => $resourceIds];
            } else {
                $query = $params['query'] ?? '[]';
                $query = array_diff_key(json_decode($query, true) ?: [], array_flip(['csrf', 'sort_by', 'sort_order', 'page', 'per_page', 'offset', 'limit']));
            }

            if ($query) {
                $args['query'] = $query;
                $args['totalResourcesQuery'] = $api->search($resourceType, $query + ['limit' => 0])->getTotalResults();
                if (!$args['totalResourcesQuery']) {
                    $this->messenger()->addError(
                        'The query returned no resource.' // @translate
                    );
                    $hasError = true;
                }
            }

            $method = $params['method'] ?? 'equal';
            $args['method'] = $method;

            if (!$hasError) {
                // Do the search via module AdvancedSearch.
                $queryResources = $query;
                if ($property && strlen($value)) {
                    $nearValues = $this->getValuesNear($method, $value, $property, $resourceType, $query);
                    if (is_null($nearValues)) {
                        $this->messenger()->addWarning(new PsrMessage(
                            'There are too many similar values near "{value}". You may filter resources first.', // @translate
                            ['value' => $value]
                        ));
                        $hasError = true;
                    } elseif (!$nearValues) {
                        $this->messenger()->addWarning(new PsrMessage(
                            'There is no existing value for property {property} near "{value}".', // @translate
                            ['property' => $property, 'value' => $value]
                        ));
                        $hasError = true;
                    } else {
                        /* TODO Add a near query in Advanced Search via mysql.
                        $queryResources['property'][] = [
                            'property' => $property,
                            'type' => 'near',
                            'value' => $value,
                        ];
                        */
                        $queryResources['property'][] = [
                            'property' => $property,
                            'type' => 'list',
                            'text' => $nearValues,
                        ];
                    }
                }
                if (!$hasError) {
                    $queryResources['limit'] = $this->settings()->get('pagination_per_page') ?: \Omeka\Stdlib\Paginator::PER_PAGE;

                    $response = $api->search($resourceType, $queryResources);
                    $args['resources'] = $response->getContent();
                    $args['totalResources'] = $response->getTotalResults();
                }
            }

            $resourceId = isset($params['resource_id']) ? (int) $params['resource_id'] : 0;
            $resourcesMerged = [];
            if ($resourceId) {
                $resource = $api->search($resourceType, ['id' => $resourceId])->getContent();
                if (!$resource) {
                    $this->messenger()->addError(new PsrMessage(
                        'The resource #{resource_id} does not exist.', // @translate
                        ['resource_id' => $params['resource_id']]
                    ));
                    $hasError = true;
                }

                // Sometime, a 0 is included in the list of selected resource
                // ids and that may break advanced search.

                if (!empty($params['resources_merged'])) {
                    $params['resources_merged'] = array_unique(array_filter($params['resources_merged'])) ?: [];
                    $resourcesMerged = $api->search($resourceType, ['id' => $params['resources_merged']], ['returnScalar' => 'id'])->getContent();
                    if (!$resourcesMerged || count($resourcesMerged) !== count($params['resources_merged'])) {
                        $this->messenger()->addError(new PsrMessage(
                            'Some merged resources do not exist.' // @translate
                        ));
                        $hasError = true;
                    }
                }
            }
        }

        $form->init();
        $form->setData([
            'deduplicate_property' => $property,
            'deduplicate_value' => $value ?? null,
            'method' => $method ?? 'equal',
            'csrf' => $params['csrf'] ?? null,
        ]);

        $view = new ViewModel($args);

        if ($hasError || !$isPost || isset($params['batch_action'])) {
            return $view;
        }

        // Useless: the form is checked above.
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        // The process may be heavy with many linked resources, so use a job.
        if ($resourceId && $resourcesMerged) {
            $jobParams = [
                'resourceId' => $resourceId,
                'resourcesMerged' => array_values($resourcesMerged),
            ];
            $job = $this->jobDispatcher()->dispatch(\Deduplicate\Job\DeduplicateResources::class, $jobParams);
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                'Processing deduplication in background (job {link}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'link' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                    ),
                    'job_id' => $job->getId(),
                    'link_end' => '</a>',
                    'link_log' => sprintf(
                        '<a href="%1$s">',
                        class_exists('Log\Module', false)
                            ? $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])
                            : $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])
                    ),
                ]
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
            return $view;
        }

        return $view;
    }

    /**
     * Search all resources grouped by duplicate values on the specified property.
     */
    protected function getDuplicates(string $resourceType, string $property, string $method, array $query): array
    {
        $propertyId = $this->easyMeta()->propertyId($property);
        if (!$propertyId) {
            return [];
        }

        $methods = [
            'equal',
            'equal_insensitive',
        ];
        $method = in_array($method, $methods) ? $method : 'equal';

        $filteredIds = null;
        if ($query) {
            $query['property'][] = [
                'property' => $propertyId,
                'type' => 'ex',
            ];
            $response = $this->api()->search($resourceType, $query, ['returnScalar' => 'id']);
            if (!$response->getTotalResults()) {
                return [];
            }
            $filteredIds = $response->getContent();
        }

        // Get all values for the specified resources.

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->distinct()
            ->from('value', 'value')
            // TODO Only items are managed currently.
            ->innerJoin('value', 'item', 'resource', 'resource.id = value.resource_id')
            ->where($expr->eq('value.property_id', ':property_id'))
            ->andWhere($expr->neq('value.value', ':empty_string'))
            ->andWhere($expr->isNotNull('value.value'))
            // TODO Deduplicate on linked resource id.
            ->andWhere($expr->isNull('value.value_resource_id'))
            // TODO Deduplicate early directly in mysql (count(value) > 2).
            ->groupBy('value.id');

        if ($method === 'equal_insensitive') {
            $qb
                ->select('LOWER(value.value) AS v', 'value.resource_id AS r')
                ->addOrderBy('LOWER(value.value)', 'asc');
        } else {
            $qb
                // TODO Use group_concat, but limited to 1024 by default.
                ->select('value.value AS v', 'value.resource_id AS r')
                ->addOrderBy('value.value', 'asc');
        }
        $qb
            ->addOrderBy('value.resource_id', 'asc');

        $bind = [
            'property_id' => $propertyId,
            'empty_string' => '',
        ];
        $types = [
            'property_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'empty_string' => \Doctrine\DBAL\ParameterType::STRING,
        ];

        if ($filteredIds) {
            $qb
                ->andWhere($expr->in('value.resource_id', ':resource_ids'));
            $bind['resource_ids'] = $filteredIds;
            $types['resource_ids'] = $connection::PARAM_INT_ARRAY;
        }
        $allValues = $connection->executeQuery($qb, $bind, $types)->fetchAllAssociative();

        $result = [];
        foreach ($allValues as $data) {
            $result[$data['v']][] = $data['r'];
        }

        // Clean and order duplicates by resources.
        foreach ($result as $v => $rs) {
            $rs = array_unique($rs);
            if (count($rs) < 2) {
                unset($result[$v]);
            } else {
                sort($rs);
                $result[$v] = $rs;
            }
        }

        return $result;
    }

    /**
     * There is no simple way to do a search "similar" in mysql and doctrine
     * doesn't implement "soundex" easily, so process via php.
     */
    protected function getValuesNear(string $method, ?string $value, ?string $property, string $resourceType, array $query): array
    {
        if (is_null($value) || !strlen($value)) {
            return [];
        }

        $propertyId = $this->easyMeta()->propertyId($property);
        if (!$propertyId) {
            return [];
        }

        $methods = [
            'equal',
            'similar_text',
            'levenshtein',
            'metaphone',
            'soundex',
        ];
        $method = in_array($method, $methods) ? $method : 'equal';
        if ($method === 'equal') {
            return [$value];
        }

        $query['property'][] = [
            'property' => $propertyId,
            'type' => 'ex',
        ];
        $response = $this->api()->search($resourceType, $query, ['returnScalar' => 'id']);
        if (!$response->getTotalResults()) {
            return [];
        }
        $filteredIds = $response->getContent();

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->distinct()
            ->select('value.value')
            ->from('value', 'value')
            ->where($expr->eq('value.property_id', ':property_id'))
            ->andWhere($expr->in('value.resource_id', ':resource_ids'))
            ->andWhere($expr->neq('value.value', ''))
            ->andWhere($expr->isNotNull('value.value'))
            // TODO What is the purpuse of this limit (for big values)? Once the value is specified, there is no issue.
            // ->andWhere($expr->lte('LENGTH(value.value)', 255))
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

        switch ($method) {
            default:
                return [];
            case 'similar_text':
                foreach ($allValues as $oneValue) {
                    similar_text($lowerValue, mb_strtolower($oneValue), $percent);
                    if ($percent > 66) {
                        $result[] = $oneValue;
                    }
                }
                break;
            case 'levenshtein':
                foreach ($allValues as $oneValue) {
                    if (levenshtein($lowerValue, mb_strtolower($oneValue)) < 10) {
                        $result[] = $oneValue;
                    }
                }
                break;
            case 'metaphone':
                $code = metaphone($lowerValue);
                foreach ($allValues as $oneValue) {
                    if ($code === metaphone(mb_strtolower($oneValue))) {
                        $result[] = $oneValue;
                    }
                }
                break;
            case 'soundex':
                $code = soundex($lowerValue);
                foreach ($allValues as $oneValue) {
                    if ($code === soundex(mb_strtolower($oneValue))) {
                        $result[] = $oneValue;
                    }
                }
                break;
        }

        return $result;
    }
}
