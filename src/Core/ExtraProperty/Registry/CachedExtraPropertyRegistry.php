<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Registry;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\CachedExtraPropertyDefinitionRepository;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Cache-invalidating decorator for ExtraPropertyRegistryInterface.
 *
 * Delegates all operations to the inner registry and removes the cached definition
 * entry for the affected entity after every successful write (register / unregister).
 *
 * This is the single point of cache invalidation for extra property definitions:
 * ExtraPropertySchemaManager and ExtraPropertyRegistry themselves do not touch the cache.
 */
class CachedExtraPropertyRegistry implements ExtraPropertyRegistryInterface
{
    public function __construct(
        protected readonly ExtraPropertyRegistry $inner,
        protected readonly CacheInterface $definitionCache,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $entityName, string $propertyName, ExtraPropertyDefinition $options): bool
    {
        $result = $this->inner->register($entityName, $propertyName, $options);
        if ($result) {
            $this->invalidateEntityCache($entityName);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function unregister(string $entityName, string $propertyName, ?string $moduleName, ExtraPropertyScope $fieldScope = ExtraPropertyScope::COMMON, bool $dropColumn = false): bool
    {
        $result = $this->inner->unregister($entityName, $propertyName, $moduleName, $fieldScope, $dropColumn);
        if ($result) {
            $this->invalidateEntityCache($entityName);
        }

        return $result;
    }

    /**
     * Removes the entity definition entry from the cache so the next read reloads from DB.
     */
    protected function invalidateEntityCache(string $entityName): void
    {
        if ('' === $entityName) {
            return;
        }

        $this->definitionCache->delete(CachedExtraPropertyDefinitionRepository::buildCacheKey($entityName));
    }
}
