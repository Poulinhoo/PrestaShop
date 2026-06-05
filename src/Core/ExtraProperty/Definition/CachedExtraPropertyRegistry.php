<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use Symfony\Contracts\Cache\CacheInterface;

/**
 * Cache-invalidating decorator for ExtraPropertyRegistryInterface.
 *
 * Delegates all operations to the inner registry and invalidates the global definition
 * cache after every successful write (register / unregister).
 *
 * This is the single point of cache invalidation for extra property definitions.
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
    public function register(ExtraPropertyDefinition $definition): bool
    {
        $result = $this->inner->register($definition);
        if ($result) {
            $this->invalidateCache();
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
            $this->invalidateCache();
        }

        return $result;
    }

    /**
     * Removes the global definition cache entry so the next getAllDefinitions() reloads from DB.
     */
    protected function invalidateCache(): void
    {
        $this->definitionCache->delete(CachedExtraPropertyDefinitionRepository::CACHE_KEY);
    }
}
