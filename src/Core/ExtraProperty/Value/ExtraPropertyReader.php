<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Value;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use Throwable;

/**
 * Reads extra property values from the *_extra / *_extra_lang / *_extra_shop tables.
 *
 * Values are grouped by module technical name then by field name.
 * Used by ObjectModel (via ServiceLocator) and front-office LazyArray / presenter contexts.
 */
class ExtraPropertyReader implements ExtraPropertyReaderInterface
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getExtraProperties(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        ?int $langId,
        ShopConstraint $shopConstraint,
        bool $isLangMultishop = false,
    ): array {
        if ($entityId <= 0) {
            return [];
        }

        $allDefinitions = $this->repository->getDefinitionCollection($entityName);
        if ($allDefinitions->isEmpty()) {
            return [];
        }

        $shopId = $shopConstraint->isSingleShopContext() ? $shopConstraint->getShopId()->getValue() : null;

        $propertiesByModule = [];

        foreach (ExtraPropertyScope::cases() as $scope) {
            $scoped = $allDefinitions->filterByScope($scope);
            if ($scoped->isEmpty()) {
                continue;
            }
            $propertiesByModule = array_replace_recursive(
                $propertiesByModule,
                $this->hydrateExtraPropertiesScope(
                    $entityName,
                    $primaryKeyName,
                    $entityId,
                    $scope->value,
                    iterator_to_array($scoped),
                    $langId,
                    $shopId,
                    $isLangMultishop
                )
            );
        }

        return $propertiesByModule;
    }

    /**
     * Fetches extra property values for one scope and returns them grouped by module name.
     *
     * When $langId is null (lang scope) or $shopId is null (shop scope), all rows are fetched
     * and grouped by id_lang / id_shop respectively — used by BO forms and Admin API.
     * When specific IDs are given, a WHERE clause filters to a single row.
     *
     * Returned structure: ['module_key' => ['property_name' => value_or_keyed_array]]
     *
     * @param list<ExtraPropertyDefinition> $definitions All definitions for this scope
     *
     * @return array<string, array<string, mixed>>
     */
    protected function hydrateExtraPropertiesScope(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        string $fieldScope,
        array $definitions,
        ?int $langId,
        ?int $shopId,
        bool $isLangMultishop,
    ): array {
        $groupByLang = ExtraPropertyScope::LANG->value === $fieldScope && null === $langId;
        $groupByShop = ExtraPropertyScope::SHOP->value === $fieldScope && null === $shopId;
        $isGrouped = $groupByLang || $groupByShop;

        $extraTableName = ExtraPropertyDefinition::buildExtraTableName($entityName, ExtraPropertyScope::from($fieldScope));

        // Build a map from DB column name to [module_key, property_name] and seed $result.
        $columnToPropertyMap = [];
        $result = [];
        foreach ($definitions as $definition) {
            $propertyName = $definition->getPropertyName();
            if ('' === $propertyName) {
                continue;
            }

            $moduleName = $definition->getDisplayModuleKey();
            $result[$moduleName] ??= [];
            $result[$moduleName][$propertyName] ??= ($isGrouped ? [] : null);

            $columnName = $definition->getStorageColumnName();
            if ('' === $columnName) {
                continue;
            }

            $columnToPropertyMap[$columnName] = ['module_name' => $moduleName, 'property_name' => $propertyName];
        }

        if (empty($columnToPropertyMap)) {
            return $result;
        }

        // Skip if IDs that must be positive were given but are invalid.
        if (ExtraPropertyScope::LANG->value === $fieldScope && null !== $langId && $langId <= 0) {
            return $result;
        }
        if (ExtraPropertyScope::SHOP->value === $fieldScope && null !== $shopId && $shopId <= 0) {
            return $result;
        }
        if (ExtraPropertyScope::LANG->value === $fieldScope && $isLangMultishop && null !== $shopId && $shopId <= 0) {
            return $result;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->from($this->prefix . $extraTableName, 'extra')
            ->where('extra.' . $this->connection->quoteIdentifier($primaryKeyName) . ' = :entityId')
            ->setParameter('entityId', $entityId);

        $selectCols = array_map(
            fn (string $col): string => 'extra.' . $this->connection->quoteIdentifier($col),
            array_keys($columnToPropertyMap)
        );

        if (ExtraPropertyScope::LANG->value === $fieldScope) {
            if ($groupByLang) {
                // Fetch all languages; caller will receive an array keyed by id_lang.
                array_unshift($selectCols, 'extra.' . $this->connection->quoteIdentifier('id_lang'));
            } else {
                $qb->andWhere('extra.id_lang = :langId')->setParameter('langId', $langId);
            }
            if ($isLangMultishop && null !== $shopId) {
                $qb->andWhere('extra.id_shop = :shopId')->setParameter('shopId', $shopId);
            }
        } elseif (ExtraPropertyScope::SHOP->value === $fieldScope) {
            if ($groupByShop) {
                // Fetch all shops; caller will receive an array keyed by id_shop.
                array_unshift($selectCols, 'extra.' . $this->connection->quoteIdentifier('id_shop'));
            } else {
                $qb->andWhere('extra.id_shop = :shopId')->setParameter('shopId', $shopId);
            }
        }

        $qb->select(...$selectCols);

        try {
            if ($isGrouped) {
                $rows = $qb->executeQuery()->fetchAllAssociative();
            } else {
                $singleRow = $qb->executeQuery()->fetchAssociative();
                $rows = is_array($singleRow) ? [$singleRow] : [];
            }
        } catch (Throwable) {
            return $result;
        }

        foreach ($rows as $row) {
            if ($groupByLang) {
                $groupKey = (int) ($row['id_lang'] ?? 0);
                foreach ($columnToPropertyMap as $columnName => $propertyPath) {
                    if (array_key_exists($columnName, $row)) {
                        $result[$propertyPath['module_name']][$propertyPath['property_name']][$groupKey] = $row[$columnName];
                    }
                }
            } elseif ($groupByShop) {
                $groupKey = (int) ($row['id_shop'] ?? 0);
                foreach ($columnToPropertyMap as $columnName => $propertyPath) {
                    if (array_key_exists($columnName, $row)) {
                        $result[$propertyPath['module_name']][$propertyPath['property_name']][$groupKey] = $row[$columnName];
                    }
                }
            } else {
                foreach ($columnToPropertyMap as $columnName => $propertyPath) {
                    if (array_key_exists($columnName, $row)) {
                        $result[$propertyPath['module_name']][$propertyPath['property_name']] = $row[$columnName];
                    }
                }
            }
        }

        return $result;
    }
}
