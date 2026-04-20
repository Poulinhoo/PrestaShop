<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use Throwable;

/**
 * Loads extra properties values for Back Office forms using Doctrine DBAL.
 *
 * Returned structure:
 * - entity scope: ['module' => ['field' => scalar]]
 * - lang scope:   ['module' => ['field' => [id_lang => value]]]
 * - shop scope:   ['module' => ['field' => scalar]] (shop context)
 */
class ExtraPropertiesFormDataLoader
{
    /**
     * @param string $prefix Database prefix (e.g. 'ps_')
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * @param string $entityName
     * @param int $entityId
     * @param int $shopId Current shop context ID (used for lang/shop scopes)
     * @param ExtraPropertyDefinitionCollection $definitions Already filtered definitions (display_form=1)
     *
     * @return array<string, array<string, mixed>>
     */
    public function load(string $entityName, int $entityId, int $shopId, ExtraPropertyDefinitionCollection $definitions): array
    {
        if ($entityId <= 0 || $definitions->isEmpty()) {
            return [];
        }

        $storageEntityName = $this->resolveStorageEntityName($entityName, $definitions);

        $result = [];
        $entityResult = $this->loadEntityScope($storageEntityName, $entityId, $definitions);
        $langResult = $this->loadLangScope($storageEntityName, $entityId, $shopId, $definitions);
        $shopResult = $this->loadShopScope($storageEntityName, $entityId, $shopId, $definitions);

        foreach ([$entityResult, $langResult, $shopResult] as $partial) {
            foreach ($partial as $moduleName => $fields) {
                foreach ($fields as $fieldName => $value) {
                    $result[$moduleName][$fieldName] = $value;
                }
            }
        }

        return $result;
    }

    protected function resolveStorageEntityName(string $fallbackEntityName, ExtraPropertyDefinitionCollection $definitions): string
    {
        $first = $definitions->first();
        if (null !== $first && '' !== trim($first->getEntityName())) {
            return trim($first->getEntityName());
        }

        return $fallbackEntityName;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function loadEntityScope(string $entityName, int $entityId, ExtraPropertyDefinitionCollection $definitions): array
    {
        $columnToPropertyMap = $this->buildColumnPropertyMap($definitions, 'common');
        if (empty($columnToPropertyMap)) {
            return [];
        }

        $tableName = $this->prefix . ExtraPropertyNaming::extraTableName($entityName, 'common');
        $primaryKeyName = 'id_' . $entityName;

        $selectedColumns = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            array_keys($columnToPropertyMap)
        ));

        try {
            $row = $this->connection->fetchAssociative(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = :entityId',
                    $selectedColumns,
                    $this->connection->quoteIdentifier($tableName),
                    $this->connection->quoteIdentifier($primaryKeyName)
                ),
                ['entityId' => $entityId]
            );
        } catch (Throwable) {
            return [];
        }

        if (false === $row) {
            return [];
        }

        $result = [];
        foreach ($columnToPropertyMap as $columnName => $propertyPath) {
            if (!array_key_exists($columnName, $row)) {
                continue;
            }
            $result[$propertyPath['module_name']][$propertyPath['property_name']] = $row[$columnName];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function loadLangScope(string $entityName, int $entityId, int $shopId, ExtraPropertyDefinitionCollection $definitions): array
    {
        $columnToPropertyMap = $this->buildColumnPropertyMap($definitions, 'lang');
        if (empty($columnToPropertyMap) || $shopId <= 0) {
            return [];
        }

        $tableName = $this->prefix . ExtraPropertyNaming::extraTableName($entityName, 'lang');
        $primaryKeyName = 'id_' . $entityName;

        // Always include id_lang to group values
        $selectedColumns = array_merge(['id_lang'], array_keys($columnToPropertyMap));
        $quotedColumns = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            $selectedColumns
        ));

        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = :entityId AND id_shop = :shopId',
                    $quotedColumns,
                    $this->connection->quoteIdentifier($tableName),
                    $this->connection->quoteIdentifier($primaryKeyName)
                ),
                ['entityId' => $entityId, 'shopId' => $shopId]
            );
        } catch (Throwable) {
            return [];
        }

        if (empty($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $idLang = (int) ($row['id_lang'] ?? 0);
            if ($idLang <= 0) {
                continue;
            }

            foreach ($columnToPropertyMap as $columnName => $propertyPath) {
                if (!array_key_exists($columnName, $row)) {
                    continue;
                }
                $result[$propertyPath['module_name']][$propertyPath['property_name']][$idLang] = $row[$columnName];
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function loadShopScope(string $entityName, int $entityId, int $shopId, ExtraPropertyDefinitionCollection $definitions): array
    {
        $columnToPropertyMap = $this->buildColumnPropertyMap($definitions, 'shop');
        if (empty($columnToPropertyMap) || $shopId <= 0) {
            return [];
        }

        $tableName = $this->prefix . ExtraPropertyNaming::extraTableName($entityName, 'shop');
        $primaryKeyName = 'id_' . $entityName;

        $selectedColumns = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            array_keys($columnToPropertyMap)
        ));

        try {
            $row = $this->connection->fetchAssociative(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = :entityId AND id_shop = :shopId',
                    $selectedColumns,
                    $this->connection->quoteIdentifier($tableName),
                    $this->connection->quoteIdentifier($primaryKeyName)
                ),
                ['entityId' => $entityId, 'shopId' => $shopId]
            );
        } catch (Throwable) {
            return [];
        }

        if (false === $row) {
            return [];
        }

        $result = [];
        foreach ($columnToPropertyMap as $columnName => $propertyPath) {
            if (!array_key_exists($columnName, $row)) {
                continue;
            }
            $result[$propertyPath['module_name']][$propertyPath['property_name']] = $row[$columnName];
        }

        return $result;
    }

    /**
     * @return array<string, array{module_name: string, property_name: string}>
     */
    protected function buildColumnPropertyMap(ExtraPropertyDefinitionCollection $definitions, string $scope): array
    {
        $result = [];
        foreach ($definitions as $definition) {
            if ($definition->getFieldScope() !== $scope) {
                continue;
            }
            $propertyName = $definition->getPropertyName();
            $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());

            if ('' === $propertyName) {
                continue;
            }
            $columnName = ExtraPropertyNaming::storageColumnName($definition->getModuleName() ?? '', $propertyName);
            $result[$columnName] = [
                'module_name' => $moduleName,
                'property_name' => $propertyName,
            ];
        }

        return $result;
    }
}
