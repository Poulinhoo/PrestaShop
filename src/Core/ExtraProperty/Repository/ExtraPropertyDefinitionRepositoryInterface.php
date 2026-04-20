<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Repository;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;

/**
 * Read-only repository for extra property definitions.
 *
 * Provides definition look-ups used by the registry, the BO form/grid modifiers,
 * and the ObjectModel. Implementations may be decorated with a cache layer.
 *
 * All read methods return typed ExtraPropertyDefinitionInfo value objects.
 */
interface ExtraPropertyDefinitionRepositoryInterface
{
    /**
     * Returns all extra property definitions for an entity as a typed collection.
     *
     * @param string $entityName
     *
     * @return ExtraPropertyDefinitionCollection
     */
    public function getDefinitionCollection(string $entityName): ExtraPropertyDefinitionCollection;

    /**
     * Returns all extra property definitions for an entity, across all scopes.
     *
     * @param string $entityName
     *
     * @return list<ExtraPropertyDefinitionInfo>
     */
    public function getByEntityNameAllScopes(string $entityName): array;

    /**
     * Returns extra property definitions for one entity + one scope.
     *
     * @param string $entityName
     * @param string $fieldScope 'common' | 'lang' | 'shop'
     *
     * @return list<ExtraPropertyDefinitionInfo>
     */
    public function getByEntityName(string $entityName, string $fieldScope = 'common'): array;

    /**
     * Returns a single definition matching entity + property name + scope.
     * Returns null when not found or when parameters fail validation.
     *
     * @param string $entityName
     * @param string $propertyName
     * @param string $fieldScope 'common' | 'lang' | 'shop'
     *
     * @return ExtraPropertyDefinitionInfo|null
     */
    public function getByEntityAndPropertyName(string $entityName, string $propertyName, string $fieldScope = 'common'): ?ExtraPropertyDefinitionInfo;

    /**
     * Returns true when at least one extra property is defined for the given entity (any scope).
     *
     * @param string $entityName
     *
     * @return bool
     */
    public function hasExtraProperties(string $entityName): bool;

    /**
     * Loads one definition by primary key.
     * Returns null when not found.
     *
     * @param int $id
     *
     * @return ExtraPropertyDefinitionInfo|null
     */
    public function getDefinitionById(int $id): ?ExtraPropertyDefinitionInfo;

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
        string $fieldScope
    ): ?ExtraPropertyDefinitionInfo;
}
