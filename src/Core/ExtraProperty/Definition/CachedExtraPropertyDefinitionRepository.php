<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepository;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use Symfony\Component\Cache\Exception\LogicException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Cache-decorating read-only repository.
 *
 * Wraps ExtraPropertyDefinitionRepository and caches getDefinitionCollection() results.
 * Only read operations are handled here; cache invalidation after writes is performed by
 * CachedExtraPropertyRegistry (the single point of invalidation).
 *
 * Cache key scheme: "extra_property_definition_{entityName}" (one entry per entity).
 * Cache tags: ["extra_property_definition", "extra_property_definition_{entityName}"] (when tag-aware).
 */
class CachedExtraPropertyDefinitionRepository implements ExtraPropertyDefinitionRepositoryInterface
{
    public const CACHE_KEY_PREFIX = 'extra_property_definition_';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepository $repository,
        protected readonly CacheInterface $definitionCache,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * The underlying definition list is cached per entity name. The collection wrapper is
     * lightweight and not cached itself.
     */
    public function getDefinitionCollection(string $entityName): ExtraPropertyDefinitionCollection
    {
        $cacheKey = self::buildCacheKey($entityName);

        /** @var list<ExtraPropertyDefinitionInfo> $definitions */
        $definitions = $this->definitionCache->get($cacheKey, function (ItemInterface $item) use ($entityName): array {
            try {
                $item->tag(['extra_property_definition', 'extra_property_definition_' . $entityName]);
            } catch (LogicException) {
                // Pool may not be tag-aware; key-based invalidation still works.
            }

            return $this->repository->getDefinitionCollection($entityName)->toArray();
        });

        return new ExtraPropertyDefinitionCollection($definitions);
    }

    /**
     * {@inheritdoc}
     *
     * Not cached: cross-entity queries span all entity cache buckets. Invalidating them reliably
     * would require tracking every entity touched by a write, which would negate the benefit.
     * Grid rendering happens once per request, so the cost of an uncached query is acceptable.
     */
    public function getDefinitionCollectionByGridId(string $gridId): ExtraPropertyDefinitionCollection
    {
        return $this->repository->getDefinitionCollectionByGridId($gridId);
    }

    /**
     * {@inheritdoc}
     *
     * Not cached: cross-entity form queries span all entity cache buckets.
     */
    public function getDefinitionCollectionByFormId(string $formId): ExtraPropertyDefinitionCollection
    {
        return $this->repository->getDefinitionCollectionByFormId($formId);
    }

    /**
     * {@inheritdoc}
     *
     * Not cached: by-module+field lookups are targeted reads used by the write path (registry),
     * and must always reflect current DB state.
     */
    public function findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinitionInfo
    {
        return $this->repository->findDefinitionByModuleAndField($entityName, $moduleName, $fieldName, $fieldScope);
    }

    /**
     * Builds the cache key for an entity's definition list.
     *
     * Declared public static so CachedExtraPropertyRegistry can compute the same key.
     */
    public static function buildCacheKey(string $entityName): string
    {
        return self::CACHE_KEY_PREFIX . preg_replace('/[^a-zA-Z0-9_]/', '_', $entityName);
    }
}
