<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Storage;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScopeGrouper;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use Throwable;

/**
 * Reads extra property values from the *_extra / *_extra_lang / *_extra_shop tables.
 *
 * Used by ObjectModel (via ServiceLocator) and front-office LazyArray / presenter contexts.
 * Values are grouped by module technical name then by field name.
 *
 * Also provides findCustomFieldDefinition() (formerly on ExtraPropertyValueProvider) to look
 * up a single definition by field name across all scopes.
 */
class ExtraPropertyReader implements ExtraPropertyReaderInterface
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly Connection $connection,
        protected readonly string $prefix
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getExtraProperties(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        ?int $langId = null,
        ?int $shopId = null,
        bool $isLangMultishop = false,
        ?ExtraPropertyDefinitionCollection $preloadedDefinitions = null
    ): array {
        $allDefinitions = null !== $preloadedDefinitions
            ? $preloadedDefinitions->toArray()
            : $this->repository->getByEntityNameAllScopes($entityName);

        if (empty($allDefinitions)) {
            return [];
        }

        $definitionsByScope = ExtraPropertyScopeGrouper::groupDefinitionsByScope($allDefinitions);
        $propertiesByModule = [];

        foreach (ExtraPropertyScope::values() as $fieldScope) {
            $definitions = $definitionsByScope[$fieldScope] ?? [];
            if (empty($definitions)) {
                continue;
            }
            $this->hydrateExtraPropertiesScope(
                $entityName,
                $primaryKeyName,
                $entityId,
                $fieldScope,
                $definitions,
                $propertiesByModule,
                $langId,
                $shopId,
                $isLangMultishop
            );
        }

        return $propertiesByModule;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinitionsByModule(string $entityName, ?string $moduleName, ?string $fieldScope = null): array
    {
        $allDefinitions = $this->repository->getByEntityNameAllScopes($entityName);
        $normalizedModule = empty($moduleName) ? null : $moduleName;

        return array_values(array_filter(
            $allDefinitions,
            static function (ExtraPropertyDefinitionInfo $definition) use ($normalizedModule, $fieldScope): bool {
                $defModule = $definition->getModuleName();
                if (null !== $normalizedModule && $defModule !== $normalizedModule) {
                    return false;
                }
                if (null !== $fieldScope && $definition->getFieldScope() !== $fieldScope) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * {@inheritdoc}
     *
     * When $fieldScope is null, returns the single matching definition or null when ambiguous.
     */
    public function findCustomFieldDefinition(string $entityName, string $fieldName, ?string $fieldScope = null): ?ExtraPropertyDefinitionInfo
    {
        if (null !== $fieldScope && !in_array($fieldScope, ExtraPropertyScope::values(), true)) {
            return null;
        }

        $matchingDefinitions = [];
        foreach ($this->repository->getByEntityNameAllScopes($entityName) as $definition) {
            if ($definition->getPropertyName() !== $fieldName) {
                continue;
            }

            $definitionScope = $definition->getFieldScope();
            if (!in_array($definitionScope, ExtraPropertyScope::values(), true)) {
                continue;
            }
            if (null !== $fieldScope && $definitionScope !== $fieldScope) {
                continue;
            }

            $matchingDefinitions[] = $definition;
        }

        if (null !== $fieldScope) {
            return $matchingDefinitions[0] ?? null;
        }
        if (count($matchingDefinitions) !== 1) {
            return null;
        }

        return $matchingDefinitions[0];
    }

    /**
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     * @param array<string, array<string, mixed>> $propertiesByModule
     */
    protected function hydrateExtraPropertiesScope(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        string $fieldScope,
        array $definitions,
        array &$propertiesByModule,
        ?int $langId,
        ?int $shopId,
        bool $isLangMultishop
    ): void {
        $extraTableName = ExtraPropertyNaming::extraTableName($entityName, $fieldScope);

        $columnToPropertyMap = [];
        foreach ($definitions as $definition) {
            $propertyName = $definition->getPropertyName();
            if ('' === $propertyName) {
                continue;
            }

            $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());
            $propertiesByModule[$moduleName] ??= [];
            $propertiesByModule[$moduleName][$propertyName] ??= null;

            $columnName = ExtraPropertyNaming::storageColumnName($definition->getModuleName() ?? '', $propertyName);
            if ('' === $columnName) {
                continue;
            }

            $columnToPropertyMap[$columnName] = ['module_name' => $moduleName, 'property_name' => $propertyName];
        }

        if (empty($columnToPropertyMap) || $entityId <= 0) {
            return;
        }

        if ('lang' === $fieldScope) {
            if ((int) $langId <= 0) {
                return;
            }
            if ($isLangMultishop && (int) $shopId <= 0) {
                return;
            }
        } elseif ('shop' === $fieldScope && (int) $shopId <= 0) {
            return;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->from($this->prefix . $extraTableName, 'extra')
            ->where('extra.' . $this->connection->quoteIdentifier($primaryKeyName) . ' = :entityId')
            ->setParameter('entityId', $entityId);

        $qb->select(...array_map(
            fn (string $col): string => 'extra.' . $this->connection->quoteIdentifier($col),
            array_keys($columnToPropertyMap)
        ));

        if ('lang' === $fieldScope) {
            $qb->andWhere('extra.id_lang = :langId')->setParameter('langId', (int) $langId);
            if ($isLangMultishop) {
                $qb->andWhere('extra.id_shop = :shopId')->setParameter('shopId', (int) $shopId);
            }
        } elseif ('shop' === $fieldScope) {
            $qb->andWhere('extra.id_shop = :shopId')->setParameter('shopId', (int) $shopId);
        }

        try {
            $row = $qb->executeQuery()->fetchAssociative();
        } catch (Throwable) {
            return;
        }

        if (!is_array($row)) {
            return;
        }

        foreach ($columnToPropertyMap as $columnName => $propertyPath) {
            if (!array_key_exists($columnName, $row)) {
                continue;
            }
            $propertiesByModule[$propertyPath['module_name']][$propertyPath['property_name']] = $row[$columnName];
        }
    }
}
