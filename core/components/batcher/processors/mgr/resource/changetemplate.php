<?php

/**
 * Batcher
 *
 * Copyright 2010 by Shaun McCormick <shaun@modxcms.com>
 *
 * This file is part of Batcher, a batch resource editing Extra.
 *
 * @package batcher
 */

/**
 * Change parent for multiple resources.
 *
 * MODX 3 compatible implementation with:
 *
 * - Changes context_key when moving between contexts
 * - Recursively updates context_key on descendants
 * - Checks save permissions
 * - Prevents moving a resource into itself
 * - Prevents moving a resource into one of its descendants
 * - Circular-parent protection
 * - Avoids moving a selected child twice when its ancestor is also selected
 * - Reports individual errors
 *
 * @package batcher
 * @subpackage processors
 */

namespace Batcher\Processors\Resource;

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modResource;

class ChangeParent extends Processor
{
    /**
     * Process request.
     *
     * @return \MODX\Revolution\Processors\ProcessorResponse
     */
    public function process()
    {
        if (!$this->modx->hasPermission('save_document')) {
            return $this->failure( $this->modx->lexicon('access_denied') );
        }

        if (empty($this->properties['resources'])) {
            return $this->failure( $this->modx->lexicon('batcher.resources_err_ns') );
        }

        if (
            !isset($this->properties['parent']) ||
            $this->properties['parent'] === ''
        ) {
            return $this->failure( $this->modx->lexicon('batcher.parent_err_ns') );
        }

        $parentId = (int) $this->properties['parent'];

        $parentResource = $this->modx->getObject( modResource::class, $parentId );

        if (!$parentResource) {
            return $this->failure( $this->modx->lexicon('batcher.parent_err_nf') );
        }

        /*
         * The destination parent's context is authoritative.
         *
         * When a resource is moved to a parent in another context,
         * the resource must follow the parent's context.
         */
        $destinationContext = $parentResource->get('context_key');

        /*
         * Parse and normalize the resource IDs.
         */
        $resourceIds = array_filter(
            array_unique(
                array_map(
                    'intval',
                    explode(',', $this->properties['resources'])
                )
            )
        );

        if (empty($resourceIds)) {
            return $this->failure( $this->modx->lexicon('batcher.resources_err_ns') );
        }

        /*
         * Load the selected resources and perform the initial permission
         * checks before changing anything.
         */
        $resources = [];

        foreach ($resourceIds as $resourceId) {
            $resource = $this->modx->getObject(
                modResource::class,
                $resourceId
            );

            if (!$resource) {
                return $this->failure(
                    sprintf(
                        'Resource %d could not be found.',
                        $resourceId
                    )
                );
            }

            if (!$resource->checkPolicy('save')) {
                return $this->failure(
                    sprintf(
                        'You do not have permission to move resource %d.',
                        $resourceId
                    )
                );
            }

            $resources[$resourceId] = $resource;
        }

        /*
         * Validate that the destination is not the resource itself or
         * somewhere inside the resource's own subtree.
         */
        foreach ($resources as $resourceId => $resource) {
            if ($this->isDescendantOf($parentId, $resourceId)) {
                return $this->failure(
                    sprintf(
                        'Cannot move resource %d: the destination parent (%d) is the resource itself or one of its descendants.',
                        $resourceId,
                        $parentId
                    )
                );
            }
        }

        /*
         * If both an ancestor and one of its descendants were selected,
         * only move the ancestor. The descendant will be moved with it.
         */
        $resourcesToMove = [];

        foreach ($resources as $resourceId => $resource) {
            if (!$this->hasSelectedAncestor($resource, $resources)) {
                $resourcesToMove[$resourceId] = $resource;
            }
        }

        /*
         * Perform the moves.
         */
        foreach ($resourcesToMove as $resourceId => $resource) {
            $resource->set('parent', $parentId);
            $resource->set('context_key', $destinationContext);

            if ($resource->save() === false) {
                $error = $resource->getOne('_errors');

                return $this->failure(
                    sprintf(
                        'Failed to move resource %d.',
                        $resourceId
                    )
                );
            }

            /*
             * The root resource has now been moved. Propagate the
             * destination context through the entire subtree.
             *
             * Children are queried directly by parent ID rather than
             * through getChildIds(), because the context_key values may
             * currently belong to the old context.
             */
            $visited = [];

            if (
                !$this->updateContextTree(
                    $resource,
                    $destinationContext,
                    $visited
                )
            ) {
                return $this->failure(
                    sprintf(
                        'Resource %d was moved, but updating the context of its descendants failed.',
                        $resourceId
                    )
                );
            }
        }

        return $this->success();
    }

    /**
     * Determine whether a resource is the same as, or below, another
     * resource in the existing parent hierarchy.
     *
     * This deliberately follows the parent field directly instead of
     * using MODX's context-aware resource map.
     *
     * @param int $resourceId
     * @param int $possibleAncestorId
     *
     * @return bool
     */
    protected function isDescendantOf($resourceId, $possibleAncestorId)
    {
        $currentId = (int) $resourceId;
        $possibleAncestorId = (int) $possibleAncestorId;

        $visited = [];

        while ($currentId > 0) {
            /*
             * Resource is the possible ancestor itself.
             */
            if ($currentId === $possibleAncestorId) {
                return true;
            }

            /*
             * Existing circular hierarchy in the database.
             */
            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;

            $resource = $this->modx->getObject(
                modResource::class,
                $currentId
            );

            if (!$resource) {
                return false;
            }

            $currentId = (int) $resource->get('parent');
        }

        return false;
    }

    /**
     * Determine whether the resource has one of the selected resources
     * as an ancestor.
     *
     * @param modResource $resource
     * @param array       $selectedResources
     *
     * @return bool
     */
    protected function hasSelectedAncestor(
        modResource $resource,
        array $selectedResources
    ) {
        $currentId = (int) $resource->get('parent');
        $visited = [];

        while ($currentId > 0) {
            /*
             * Protect against an existing circular hierarchy.
             */
            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;

            /*
             * An ancestor is also selected, so this resource will be
             * moved automatically as part of that ancestor's subtree.
             */
            if (isset($selectedResources[$currentId])) {
                return true;
            }

            $ancestor = $this->modx->getObject(
                modResource::class,
                $currentId
            );

            if (!$ancestor) {
                break;
            }

            $currentId = (int) $ancestor->get('parent');
        }

        return false;
    }

    /**
     * Recursively update context_key on a resource and all descendants.
     *
     * Children are loaded directly by parent ID so this operation does not
     * depend on their existing context_key values.
     *
     * @param modResource $resource
     * @param string      $contextKey
     * @param array       $visited
     *
     * @return bool
     */
    protected function updateContextTree(
        modResource $resource,
        $contextKey,
        array &$visited
    ) {
        $resourceId = (int) $resource->get('id');

        /*
         * Prevent infinite recursion if the existing hierarchy is corrupt.
         */
        if (isset($visited[$resourceId])) {
            $this->modx->log(
                \xPDO\xPDO::LOG_LEVEL_ERROR,
                sprintf(
                    'Batcher ChangeParent: circular resource hierarchy detected at resource %d.',
                    $resourceId
                )
            );

            return false;
        }

        $visited[$resourceId] = true;

        /*
         * Check permission for every resource that we modify.
         */
        if (!$resource->checkPolicy('save')) {
            $this->modx->log(
                \xPDO\xPDO::LOG_LEVEL_ERROR,
                sprintf(
                    'Batcher ChangeParent: no save permission for resource %d.',
                    $resourceId
                )
            );

            return false;
        }

        /*
         * Update context if necessary.
         *
         * The root resource has already been saved, but this also makes
         * the method safe to call for it.
         */
        if ($resource->get('context_key') !== $contextKey) {
            $resource->set('context_key', $contextKey);

            if ($resource->save() === false) {
                $this->modx->log(
                    \xPDO\xPDO::LOG_LEVEL_ERROR,
                    sprintf(
                        'Batcher ChangeParent: failed saving context_key for resource %d.',
                        $resourceId
                    )
                );

                return false;
            }
        }

        /*
         * IMPORTANT:
         *
         * Do not use getChildIds() here. MODX's resource maps can be
         * context-specific, while we are specifically repairing a tree
         * whose context_key values may still point at the old context.
         */
        $children = $this->modx->getCollection(
            modResource::class,
            [
                'parent' => $resourceId,
            ]
        );

        foreach ($children as $child) {
            if (
                !$this->updateContextTree(
                    $child,
                    $contextKey,
                    $visited
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array
     */
    public function getLanguageTopics()
    {
        return ['batcher:default'];
    }
}
```
