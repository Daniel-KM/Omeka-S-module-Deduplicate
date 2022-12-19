<?php declare(strict_types=1);

namespace Deduplicate\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Merge a list of resources into a resource and update linked resources.
 */
class DeduplicateResources extends AbstractJob
{
    public function perform(): void
    {
        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');

        $resourceId = (int) $this->getArg('resourceId');
        $resourcesMerged = $this->getArg('resourcesMerged', []);

        // Remove the resource to keep from resources to merge and take care of
        // non-unique values.
        $resourcesMerged = array_unique(array_filter($resourcesMerged));
        $keep = array_search($resourceId, $resourcesMerged);
        if ($keep !== false) {
            unset($resourcesMerged[$keep]);
        }

        if (!$resourceId || !$resourcesMerged) {
            $logger->warn(new Message('There is no resource to merge.')); // @translate
            return;
        }

        try {
            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
            $resource = $api->read('resources', $resourceId)->getContent();
        } catch (\Exception $e) {
            $logger->warn(new Message('There is no resource #%d to merge.', $resourceId)); // @translate
            return;
        }

        $resourceType = $resource->resourceName();

        // Don't load entities if the only information needed is total results.
        $query = ['id' => $resourcesMerged];
        $response = $api->search($resourceType, $query, ['returnScalar' => 'id']);
        $resourcesMerged = $response->getContent();
        if (!$resourcesMerged) {
            $logger->warn(new Message('There is no resource to merge.')); // @translate
            return;
        }

        // A simple sql can be used to update all linked resources, but all
        // resources should be reindexed. So use a loop, but it is far to be
        // optimal.
        // TODO Add an option to use direct sql + reindex or api?

        // First, update all resources linked to all resources to merge. It
        foreach (['items', 'item_sets', 'media'] as $linkedResourceType) {
            $linkedResources = $api->search($linkedResourceType, ['property' => [[
                'property' => null,
                'type' => 'res',
                'text' => $resourcesMerged,
            ]]], ['returnScalar' => 'id'])->getContent();
            foreach ($linkedResources as $linkedResourceId) {
                /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $linkedResource */
                $linkedResource = $api->read($linkedResourceType, $linkedResourceId)->getContent();
                $linkedResourceProperties = $linkedResource->values();
                $newValues = [];
                foreach ($linkedResourceProperties as $term => $propertyData) {
                    /** @var \Omeka\Api\Representation\ValueRepresentation $value */
                    foreach ($propertyData['values'] as $value) {
                        $newValue = $value->jsonSerialize();
                        $linkedLinkedResourceId = $newValue['value_resource_id'] ?? null;
                        if (isset($resourcesMerged[$linkedLinkedResourceId])) {
                           $newValue['value_resource_id'] = $resourceId;
                        }
                        $newValues[$term][] = $newValue;
                    }
                }
                $api->update($linkedResourceType, $linkedResourceId, $newValues, [
                    'isPartial' => true,
                ]);
            }
        }

        // Second, delete all merged resources.
        $api->batchDelete($resourceType, $resourcesMerged);

        $logger->notice(new Message('%d resources have been merged inside resource #%d.', count($resourcesMerged), $resourceId)); // @translate
    }
}
