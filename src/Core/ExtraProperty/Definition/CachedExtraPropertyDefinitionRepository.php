<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use Symfony\Component\Cache\Exception\LogicException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Cache-decorating read-only repository.
 *
 * Wraps ExtraPropertyDefinitionRepository and caches getAllDefinitions() results under a single
 * global key. Cache invalidation after writes is performed by CachedExtraPropertyRegistry
 * (the single point of invalidation).
 *
 * Cache key scheme: "extra_property_definition_all" (one entry for all definitions).
 * Cache tags: ["extra_property_definition"] (when tag-aware).
 */
class CachedExtraPropertyDefinitionRepository implements ExtraPropertyDefinitionRepositoryInterface
{
    public const CACHE_KEY = 'extra_property_definition_all';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepository $repository,
        protected readonly CacheInterface $definitionCache,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Cached under a single global key. All callers use collection filter helpers
     * (filterByEntity, filterByForm, filterByGrid, etc.) to narrow the result.
     */
    public function getAllDefinitions(): ExtraPropertyDefinitionCollection
    {
        return $this->definitionCache->get(self::CACHE_KEY, function (ItemInterface $item): ExtraPropertyDefinitionCollection {
            try {
                $item->tag(['extra_property_definition']);
            } catch (LogicException) {
                // Pool may not be tag-aware; key-based invalidation still works.
            }

            return $this->repository->getAllDefinitions();
        });
    }

    /**
     * {@inheritdoc}
     *
     * Not cached: targeted write-path lookup that must always reflect current DB state.
     */
    public function findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinition
    {
        return $this->repository->findDefinitionByModuleAndField($entityName, $moduleName, $fieldName, $fieldScope);
    }
}
