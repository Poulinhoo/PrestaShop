<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Repository;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;

/**
 * Read-only repository for extra property definitions.
 *
 * Provides definition look-ups used by the registry, the BO form/grid modifiers,
 * and the ObjectModel. Implementations may be decorated with a cache layer.
 *
 * All read methods return typed ExtraPropertyDefinitionInfo value objects or collections.
 */
interface ExtraPropertyDefinitionRepositoryInterface
{
    /**
     * Returns all extra property definitions for an entity as a typed collection.
     *
     * Use the collection's filter helpers (filterByScope, filterByForm, filterByGrid, …)
     * instead of relying on scope-specific overloads.
     *
     * @param string $entityName Entity table name (e.g. 'product')
     *
     * @return ExtraPropertyDefinitionCollection
     */
    public function getDefinitionCollection(string $entityName): ExtraPropertyDefinitionCollection;

    /**
     * Returns all extra property definitions that target the given grid ID, regardless of entity.
     *
     * Unlike getDefinitionCollection() (which queries by entity_name), this method searches
     * the associated_grids JSON column across all entities and returns every definition whose
     * entry list contains the given $gridId — whether as a bare "gridId", "gridId.column",
     * "gridId.column:before", or "gridId.column:after".
     *
     * Use this in grid modifiers instead of getDefinitionCollection($entityName) to avoid the
     * assumption that grid ID == entity name. The grid ID comes from GridDefinition::getId()
     * (e.g. 'product' for ProductGridDefinitionFactory, 'customer' for CustomerGridDefinitionFactory).
     *
     * @param string $gridId BO grid identifier, as returned by GridDefinition::getId() (e.g. 'product', 'customer')
     */
    public function getDefinitionCollectionByGridId(string $gridId): ExtraPropertyDefinitionCollection;

    /**
     * Returns all extra property definitions that target the given form ID, regardless of entity.
     *
     * Searches the associated_forms JSON column across all entities and returns every definition
     * whose entry list contains the given $formId — whether as a bare "formId", "formId.path",
     * "formId.path:before", or "formId.path:after".
     *
     * @param string $formId BO form identifier (usually equals form block_prefix, e.g. 'category')
     */
    public function getDefinitionCollectionByFormId(string $formId): ExtraPropertyDefinitionCollection;

    /**
     * Finds one registry definition matching entity, module, property name, and scope.
     * Returns null when not found.
     *
     * @param string $entityName Normalized entity name
     * @param string|null $moduleName Module technical name, or null for core fields
     * @param string $fieldName Property name
     * @param string $fieldScope Normalized scope ('common', 'lang', 'shop')
     *
     * @return ExtraPropertyDefinitionInfo|null
     */
    public function findDefinitionByModuleAndField(
        string $entityName,
        ?string $moduleName,
        string $fieldName,
        string $fieldScope,
    ): ?ExtraPropertyDefinitionInfo;
}
