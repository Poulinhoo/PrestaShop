<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Repository;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use Symfony\Component\Cache\Exception\LogicException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Cache-decorating read-only repository.
 *
 * Wraps ExtraPropertyDefinitionRepository and caches the result of getByEntityNameAllScopes()
 * in the Symfony cache.app pool when available; otherwise falls back to a FilesystemAdapter
 * under the legacy cache directory.
 *
 * Only read operations are handled here. Cache invalidation after writes is performed
 * by ExtraPropertyRegistry, which holds direct references to the same cache pools.
 *
 * Cache key scheme: "extra_property_definition_{entityName}" (one entry per entity).
 * Cache tags: ["extra_property_definition", "extra_property_definition_{entityName}"] (when tag-aware).
 */
class CachedExtraPropertyDefinitionRepository implements ExtraPropertyDefinitionRepositoryInterface
{
    public const CACHE_KEY_PREFIX = 'extra_property_definition_';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepository $repository,
        protected readonly ?CacheInterface $cacheApp,
        protected readonly CacheInterface $filesystemDefinitionCache,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinitionCollection(string $entityName): ExtraPropertyDefinitionCollection
    {
        return new ExtraPropertyDefinitionCollection($this->getByEntityNameAllScopes($entityName));
    }

    /**
     * {@inheritdoc}
     */
    public function getByEntityNameAllScopes(string $entityName): array
    {
        $cacheKey = self::buildCacheKey($entityName);

        return $this->getEffectiveCache()->get($cacheKey, function (ItemInterface $item) use ($entityName): array {
            try {
                $item->tag(['extra_property_definition', 'extra_property_definition_' . $entityName]);
            } catch (LogicException) {
                // Pool may not be tag-aware; key-based invalidation still works.
            }

            return $this->repository->getByEntityNameAllScopes($entityName);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getByEntityName(string $entityName, string $fieldScope = 'common'): array
    {
        return array_values(array_filter(
            $this->getByEntityNameAllScopes($entityName),
            static fn (ExtraPropertyDefinitionInfo $d): bool => $d->getFieldScope() === $fieldScope
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getByEntityAndPropertyName(string $entityName, string $propertyName, string $fieldScope = 'common'): ?ExtraPropertyDefinitionInfo
    {
        foreach ($this->getByEntityNameAllScopes($entityName) as $definition) {
            if (
                $definition->getPropertyName() === $propertyName
                && $definition->getFieldScope() === $fieldScope
            ) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasExtraProperties(string $entityName): bool
    {
        return !empty($this->getByEntityNameAllScopes($entityName));
    }

    /**
     * {@inheritdoc}
     *
     * Not cached: by-ID lookups are one-off (admin UI, toggle) and must reflect current DB state.
     */
    public function getDefinitionById(int $id): ?ExtraPropertyDefinitionInfo
    {
        return $this->repository->getDefinitionById($id);
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
     * Declared public static so that CacheInvalidatingSchemaManager and ExtraPropertyRegistry
     * can compute the same key without duplicating the logic.
     *
     * @param string $entityName
     *
     * @return string
     */
    public static function buildCacheKey(string $entityName): string
    {
        return self::CACHE_KEY_PREFIX . preg_replace('/[^a-zA-Z0-9_]/', '_', $entityName);
    }

    /**
     * Pool used for reads: Symfony app pool when available, otherwise filesystem cache (FO legacy container).
     */
    protected function getEffectiveCache(): CacheInterface
    {
        return $this->cacheApp ?? $this->filesystemDefinitionCache;
    }
}
