<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Value;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use Throwable;

/**
 * Reads extra property values from the *_extra / *_extra_lang / *_extra_shop tables.
 *
 * Values are grouped by module technical name then by field name, and returned TYPED:
 * every value is cast from its raw DB string to the declared PHP type via
 * ExtraPropertyValueCaster::castFromDb() (bool/int/float, nullable-aware NULLs).
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
        ?ExtraPropertyDefinitionCollection $definitions = null,
    ): array {
        if ($entityId <= 0) {
            return [];
        }

        $allDefinitions = $definitions ?? $this->repository->getAllDefinitions()->filterByEntity($entityName);
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
                    $primaryKeyName,
                    $entityId,
                    $scope,
                    $scoped,
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
     * For LANG scope with $langId = null: all languages are fetched and the value is an array
     * keyed by id_lang — used by BO forms and Admin API.
     * For LANG scope with $langId set: a single scalar value per field is returned.
     *
     * For SHOP scope: always returns a single scalar value for the given shop constraint.
     * COMMON scope: returns a single scalar value per field.
     *
     * Returned structure: ['module_key' => ['property_name' => scalar_or_lang_keyed_array]]
     *
     * @param ExtraPropertyDefinitionCollection $definitions All definitions for this scope (non-empty)
     *
     * @return array<string, array<string, mixed>>
     */
    protected function hydrateExtraPropertiesScope(
        string $primaryKeyName,
        int $entityId,
        ExtraPropertyScope $fieldScope,
        ExtraPropertyDefinitionCollection $definitions,
        ?int $langId,
        ?int $shopId,
        bool $isLangMultishop,
    ): array {
        $groupByLang = ExtraPropertyScope::LANG === $fieldScope && null === $langId;
        $extraTableName = $this->prefix . $definitions->first()->getExtraTableName();

        // Build a map from DB column name to [module_key, property_name, cast inputs] and seed $result.
        $columnToPropertyMap = [];
        $result = [];
        foreach ($definitions as $definition) {
            $propertyName = $definition->getPropertyName();
            $moduleName = $definition->getNormalizedModuleKey();
            $result[$moduleName] ??= [];
            $result[$moduleName][$propertyName] ??= ($groupByLang
                ? []
                : ExtraPropertyValueCaster::castFromDb($definition->getType(), null, $definition->isNullable()));

            $columnName = $definition->getStorageColumnName();
            $columnToPropertyMap[$columnName] = [
                'module_name' => $moduleName,
                'property_name' => $propertyName,
                'type' => $definition->getType(),
                'nullable' => $definition->isNullable(),
            ];
        }

        // Skip if IDs that must be positive were given but are invalid.
        if (ExtraPropertyScope::LANG === $fieldScope && null !== $langId && $langId <= 0) {
            return $result;
        }
        if (ExtraPropertyScope::SHOP === $fieldScope && null !== $shopId && $shopId <= 0) {
            return $result;
        }
        if (ExtraPropertyScope::LANG === $fieldScope && $isLangMultishop && null !== $shopId && $shopId <= 0) {
            return $result;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->from($extraTableName, 'extra')
            ->where('extra.' . $this->connection->quoteIdentifier($primaryKeyName) . ' = :entityId')
            ->setParameter('entityId', $entityId);

        $selectCols = array_map(
            fn (string $col): string => 'extra.' . $this->connection->quoteIdentifier($col),
            array_keys($columnToPropertyMap)
        );

        if (ExtraPropertyScope::LANG === $fieldScope) {
            if ($groupByLang) {
                // Fetch all languages; caller receives an array keyed by id_lang.
                array_unshift($selectCols, 'extra.' . $this->connection->quoteIdentifier('id_lang'));
            } else {
                $qb->andWhere('extra.id_lang = :langId')->setParameter('langId', $langId);
            }
            if ($isLangMultishop && null !== $shopId) {
                $qb->andWhere('extra.id_shop = :shopId')->setParameter('shopId', $shopId);
            }
        } elseif (ExtraPropertyScope::SHOP === $fieldScope && null !== $shopId) {
            // Shop scope is always a single scalar value for the given shop constraint.
            $qb->andWhere('extra.id_shop = :shopId')->setParameter('shopId', $shopId);
        }

        $qb->select(...$selectCols);

        try {
            if ($groupByLang) {
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
                        $result[$propertyPath['module_name']][$propertyPath['property_name']][$groupKey] = ExtraPropertyValueCaster::castFromDb($propertyPath['type'], $row[$columnName], $propertyPath['nullable']);
                    }
                }
            } else {
                foreach ($columnToPropertyMap as $columnName => $propertyPath) {
                    if (array_key_exists($columnName, $row)) {
                        $result[$propertyPath['module_name']][$propertyPath['property_name']] = ExtraPropertyValueCaster::castFromDb($propertyPath['type'], $row[$columnName], $propertyPath['nullable']);
                    }
                }
            }
        }

        return $result;
    }
}
