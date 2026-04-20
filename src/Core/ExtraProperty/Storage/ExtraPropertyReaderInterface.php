<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Storage;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;

/**
 * Reads extra property values and definitions for a given entity instance.
 *
 * Used by ObjectModel (via ServiceLocator) and front-office LazyArray / presenter contexts.
 * Values are grouped by module name and then by field name.
 *
 * Replaces the former ExtraPropertyValueProviderInterface (merged here to avoid duplication).
 */
interface ExtraPropertyReaderInterface
{
    /**
     * Returns extra property values for one entity instance, grouped by module name.
     *
     * Format:
     * [
     *     'module_technical_name' => [
     *         'property_name' => 'value_or_array',
     *     ],
     * ]
     *
     * Lang-scope fields return an array keyed by id_lang.
     * Shop-scope fields return the value for the requested shopId (or null).
     *
     * @param string $entityName Entity table name (e.g. "product")
     * @param string $primaryKeyName PK column name (e.g. "id_product")
     * @param int $entityId
     * @param int|null $langId
     * @param int|null $shopId
     * @param bool $isLangMultishop Whether lang scope is shop-aware
     * @param ExtraPropertyDefinitionCollection|null $preloadedDefinitions When set (e.g. from ObjectModel), skips a duplicate repository read
     *
     * @return array<string, array<string, mixed>>
     */
    public function getExtraProperties(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        ?int $langId = null,
        ?int $shopId = null,
        bool $isLangMultishop = false,
        ?ExtraPropertyDefinitionCollection $preloadedDefinitions = null
    ): array;

    /**
     * Returns extra property definitions for a given entity, module, and optional scope.
     * Useful to enumerate all fields of a module on an entity.
     *
     * @param string $entityName
     * @param string|null $moduleName Null returns definitions for all modules (including core)
     * @param string|null $fieldScope When provided, filters by scope
     *
     * @return list<ExtraPropertyDefinitionInfo>
     */
    public function getDefinitionsByModule(string $entityName, ?string $moduleName, ?string $fieldScope = null): array;

    /**
     * Finds one extra field definition for an entity and field name.
     *
     * When $fieldScope is null:
     * - if a single definition exists, it is returned;
     * - if multiple scoped definitions exist, returns null (ambiguous).
     *
     * @param string $entityName
     * @param string $fieldName
     * @param string|null $fieldScope Allowed values: common, lang, shop or null
     *
     * @return ExtraPropertyDefinitionInfo|null
     */
    public function findCustomFieldDefinition(string $entityName, string $fieldName, ?string $fieldScope = null): ?ExtraPropertyDefinitionInfo;
}
