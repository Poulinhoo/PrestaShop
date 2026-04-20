<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Schema;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertySqlIndex;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\CachedExtraPropertyDefinitionRepository;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Schema manager decorator that invalidates the definition cache after DDL changes.
 *
 * Wraps ExtraPropertySchemaManager and removes the cache entry for the affected entity after
 * any table/column creation or drop. Invalidates both cache.app (when defined) and the
 * filesystem definition cache so front-office and back-office definitions stay aligned.
 *
 * Cache key computation is delegated to CachedExtraPropertyDefinitionRepository::buildCacheKey()
 * to avoid duplication.
 */
class CacheInvalidatingSchemaManager implements ExtraPropertySchemaManagerInterface
{
    public function __construct(
        protected readonly ExtraPropertySchemaManagerInterface $inner,
        protected readonly ?CacheInterface $cacheApp,
        protected readonly CacheInterface $filesystemDefinitionCache,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function ensureExtraTableAndColumn(string $entityName, string $fieldScope, string $columnName, string $sqlColumnDefinition, ExtraPropertySqlIndex $sqlIndex): void
    {
        $this->inner->ensureExtraTableAndColumn($entityName, $fieldScope, $columnName, $sqlColumnDefinition, $sqlIndex);
        $this->invalidateEntityCache($entityName);
    }

    /**
     * {@inheritdoc}
     */
    public function dropExtraColumnIfExists(string $entityName, string $fieldScope, string $columnName): void
    {
        $this->inner->dropExtraColumnIfExists($entityName, $fieldScope, $columnName);
        $this->invalidateEntityCache($entityName);
    }

    /**
     * Removes the entity definition entry from both cache pools.
     *
     * Delegates key computation to CachedExtraPropertyDefinitionRepository to avoid duplication.
     *
     * @param string $entityName
     */
    protected function invalidateEntityCache(string $entityName): void
    {
        if ('' === $entityName) {
            return;
        }

        $cacheKey = CachedExtraPropertyDefinitionRepository::buildCacheKey($entityName);
        $this->filesystemDefinitionCache->delete($cacheKey);

        if (null !== $this->cacheApp) {
            $this->cacheApp->delete($cacheKey);
        }
    }
}
