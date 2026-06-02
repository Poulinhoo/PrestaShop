<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Registry;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;

/**
 * Write interface for extra property definitions: register and unregister operations.
 *
 * Deliberately does NOT extend ExtraPropertyDefinitionRepositoryInterface.
 * Read and write concerns are kept separate: inject ExtraPropertyDefinitionRepositoryInterface
 * for reads, and this interface for writes.
 *
 * Implementations are responsible for persisting the definition row AND ensuring the
 * corresponding SQL column exists in the entity's *_extra table, and for invalidating
 * any read cache after a successful write.
 */
interface ExtraPropertyRegistryInterface
{
    /**
     * Register or update an extra property definition.
     *
     * When the physical SQL column does not yet exist, it is created.
     * On conflict (same entity+module+field+scope), the definition row is updated.
     *
     * The module name is resolved from $options->moduleName. If it is null, the registry
     * treats the field as a core field (no owning module).
     *
     * @param string $entityName Entity table name (e.g. "product", "customer")
     * @param string $propertyName Property name within the module (e.g. "custom_size")
     * @param ExtraPropertyDefinition $options Typed configuration for the property
     *
     * @return bool
     */
    public function register(string $entityName, string $propertyName, ExtraPropertyDefinition $options): bool;

    /**
     * Unregister an extra property definition by entity, property name and scope.
     * When $dropColumn is true, the physical SQL column is also dropped.
     *
     * @param string $entityName
     * @param string $propertyName
     * @param string|null $moduleName Module that owns the property (null = core property)
     * @param ExtraPropertyScope $fieldScope
     * @param bool $dropColumn
     *
     * @return bool
     */
    public function unregister(string $entityName, string $propertyName, ?string $moduleName, ExtraPropertyScope $fieldScope = ExtraPropertyScope::COMMON, bool $dropColumn = false): bool;
}
