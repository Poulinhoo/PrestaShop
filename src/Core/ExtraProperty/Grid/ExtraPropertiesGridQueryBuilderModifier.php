<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Grid;

use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScopeGrouper;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

/**
 * Adds JOIN/SELECT/FILTER clauses for extra properties in BO Symfony grids.
 *
 * This modifier assumes:
 * - grid id matches the entity table name (e.g. "product")
 * - registry structure and *_extra tables are coherent (no runtime checks for performance)
 */
class ExtraPropertiesGridQueryBuilderModifier
{
    private const EXTRA_ENTITY_ALIAS = 'extra_entity';
    private const EXTRA_LANG_ALIAS = 'extra_lang';
    private const EXTRA_SHOP_ALIAS = 'extra_shop';

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly string $dbPrefix,
        protected readonly int $contextLangId,
    ) {
    }

    public function apply(
        QueryBuilder $searchQueryBuilder,
        QueryBuilder $countQueryBuilder,
        SearchCriteriaInterface $searchCriteria,
        string $gridId
    ): void {
        $definitions = $this->repository->getDefinitionCollectionByGridId($gridId);
        if ($definitions->isEmpty()) {
            return;
        }

        $firstDefinition = $definitions->first();
        $entityName = null !== $firstDefinition ? $firstDefinition->getEntityName() : $gridId;
        $primaryKey = 'id_' . $entityName;

        $mainAlias = $this->resolveMainAlias($searchQueryBuilder, $gridId, $entityName);
        if (null === $mainAlias) {
            return;
        }

        $scopedDefinitions = ExtraPropertyScopeGrouper::groupDefinitionsByScope($definitions->toArray());

        $this->applyEntityScope($searchQueryBuilder, $countQueryBuilder, $searchCriteria, $entityName, $primaryKey, $mainAlias, $scopedDefinitions['common']);
        $this->applyLangScope($searchQueryBuilder, $countQueryBuilder, $searchCriteria, $entityName, $primaryKey, $mainAlias, $scopedDefinitions['lang']);
        $this->applyShopScope($searchQueryBuilder, $countQueryBuilder, $searchCriteria, $entityName, $primaryKey, $mainAlias, $scopedDefinitions['shop']);
    }

    /**
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     */
    protected function applyEntityScope(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        SearchCriteriaInterface $criteria,
        string $entityName,
        string $primaryKey,
        string $mainAlias,
        array $definitions
    ): void {
        if (empty($definitions)) {
            return;
        }

        $extraTable = $this->dbPrefix . ExtraPropertyNaming::extraTableName($entityName, 'common');
        $this->ensureLeftJoin($searchQb, $countQb, $mainAlias, $extraTable, self::EXTRA_ENTITY_ALIAS, sprintf(
            '%s.`%s` = %s.`%s`',
            self::EXTRA_ENTITY_ALIAS,
            $primaryKey,
            $mainAlias,
            $primaryKey
        ));

        $this->applySelectsAndFilters($searchQb, $countQb, $criteria, self::EXTRA_ENTITY_ALIAS, $definitions);
    }

    /**
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     */
    protected function applyLangScope(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        SearchCriteriaInterface $criteria,
        string $entityName,
        string $primaryKey,
        string $mainAlias,
        array $definitions
    ): void {
        if (empty($definitions)) {
            return;
        }

        $extraTable = $this->dbPrefix . ExtraPropertyNaming::extraTableName($entityName, 'lang');
        $baseLangTable = $this->dbPrefix . $entityName . '_lang';
        [$langAlias, $langJoinCondition] = $this->findJoinedTableAliasAndCondition($searchQb, $baseLangTable);

        if (null !== $langAlias) {
            $joinParts = [
                sprintf('%s.`%s` = %s.`%s`', self::EXTRA_LANG_ALIAS, $primaryKey, $langAlias, $primaryKey),
                sprintf('%s.`id_lang` = %s.`id_lang`', self::EXTRA_LANG_ALIAS, $langAlias),
            ];
            if (null !== $langJoinCondition && $this->joinConditionMentionsShopId($langAlias, $langJoinCondition)) {
                $joinParts[] = sprintf('%s.`id_shop` = %s.`id_shop`', self::EXTRA_LANG_ALIAS, $langAlias);
            }

            $this->ensureLeftJoin($searchQb, $countQb, $langAlias, $extraTable, self::EXTRA_LANG_ALIAS, implode(' AND ', $joinParts));
        } else {
            // Fallback: join on context lang only when no base lang join exists in the query.
            $this->ensureLeftJoin($searchQb, $countQb, $mainAlias, $extraTable, self::EXTRA_LANG_ALIAS, sprintf(
                '%s.`%s` = %s.`%s` AND %s.`id_lang` = :extraLangId',
                self::EXTRA_LANG_ALIAS,
                $primaryKey,
                $mainAlias,
                $primaryKey,
                self::EXTRA_LANG_ALIAS
            ));
            $searchQb->setParameter('extraLangId', $this->contextLangId);
            $countQb->setParameter('extraLangId', $this->contextLangId);
        }

        $this->applySelectsAndFilters($searchQb, $countQb, $criteria, self::EXTRA_LANG_ALIAS, $definitions);
    }

    /**
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     */
    protected function applyShopScope(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        SearchCriteriaInterface $criteria,
        string $entityName,
        string $primaryKey,
        string $mainAlias,
        array $definitions
    ): void {
        if (empty($definitions)) {
            return;
        }

        $extraTable = $this->dbPrefix . ExtraPropertyNaming::extraTableName($entityName, 'shop');
        $baseShopTable = $this->dbPrefix . $entityName . '_shop';
        [$shopAlias] = $this->findJoinedTableAliasAndCondition($searchQb, $baseShopTable);

        if (null !== $shopAlias) {
            $this->ensureLeftJoin($searchQb, $countQb, $shopAlias, $extraTable, self::EXTRA_SHOP_ALIAS, sprintf(
                '%s.`%s` = %s.`%s` AND %s.`id_shop` = %s.`id_shop`',
                self::EXTRA_SHOP_ALIAS,
                $primaryKey,
                $shopAlias,
                $primaryKey,
                self::EXTRA_SHOP_ALIAS,
                $shopAlias
            ));
        } elseif (array_key_exists('shopId', $searchQb->getParameters())) {
            // Fallback for single-shop constrained queries.
            $this->ensureLeftJoin($searchQb, $countQb, $mainAlias, $extraTable, self::EXTRA_SHOP_ALIAS, sprintf(
                '%s.`%s` = %s.`%s` AND %s.`id_shop` = :shopId',
                self::EXTRA_SHOP_ALIAS,
                $primaryKey,
                $mainAlias,
                $primaryKey,
                self::EXTRA_SHOP_ALIAS
            ));
        } else {
            // No safe way to join shop extra data without knowing the shop constraint of the query.
            return;
        }

        $this->applySelectsAndFilters($searchQb, $countQb, $criteria, self::EXTRA_SHOP_ALIAS, $definitions);
    }

    /**
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     */
    protected function applySelectsAndFilters(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        SearchCriteriaInterface $criteria,
        string $joinAlias,
        array $definitions
    ): void {
        $filters = $criteria->getFilters();

        foreach ($definitions as $definition) {
            $moduleName = ExtraPropertyNaming::displayModuleKey($definition->getModuleName());
            $fieldName = $definition->getPropertyName();
            $scope = $definition->getFieldScope();
            if ('' === $fieldName) {
                continue;
            }

            $storageColumn = ExtraPropertyNaming::storageColumnName($definition->getModuleName(), $fieldName);

            $selectAlias = ExtraPropertyNaming::formFieldName($moduleName, $fieldName, $scope);
            $searchQb->addSelect(sprintf('%s.`%s` AS `%s`', $joinAlias, $storageColumn, $selectAlias));

            if (!array_key_exists($selectAlias, $filters)) {
                continue;
            }

            $filterValue = $filters[$selectAlias];
            if (null === $filterValue || '' === $filterValue) {
                continue;
            }

            $paramName = $this->buildFilterParamName($selectAlias);
            $isBoolean = CheckboxType::class === $definition->getFormFieldType();

            if ($isBoolean) {
                $this->applyWhereEquals($searchQb, $countQb, $joinAlias, $storageColumn, $paramName, $filterValue);
            } else {
                $this->applyWhereLike($searchQb, $countQb, $joinAlias, $storageColumn, $paramName, $filterValue);
            }
        }
    }

    protected function applyWhereEquals(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        string $alias,
        string $column,
        string $paramName,
        mixed $value
    ): void {
        $expr = sprintf('%s.`%s` = :%s', $alias, $column, $paramName);
        $searchQb->andWhere($expr)->setParameter($paramName, $value);
        $countQb->andWhere($expr)->setParameter($paramName, $value);
    }

    protected function applyWhereLike(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        string $alias,
        string $column,
        string $paramName,
        mixed $value
    ): void {
        $expr = sprintf('%s.`%s` LIKE :%s', $alias, $column, $paramName);
        $likeValue = '%' . (string) $value . '%';
        $searchQb->andWhere($expr)->setParameter($paramName, $likeValue);
        $countQb->andWhere($expr)->setParameter($paramName, $likeValue);
    }

    protected function buildFilterParamName(string $filterName): string
    {
        return 'extra_filter_' . substr(sha1($filterName), 0, 10);
    }

    protected function ensureLeftJoin(
        QueryBuilder $searchQb,
        QueryBuilder $countQb,
        string $fromAlias,
        string $joinTable,
        string $joinAlias,
        string $condition
    ): void {
        if (null === $this->findJoinedTableAliasAndCondition($searchQb, $joinTable)[0]) {
            $searchQb->leftJoin($fromAlias, $joinTable, $joinAlias, $condition);
        }
        if (null === $this->findJoinedTableAliasAndCondition($countQb, $joinTable)[0]) {
            $countQb->leftJoin($fromAlias, $joinTable, $joinAlias, $condition);
        }
    }

    protected function resolveMainAlias(QueryBuilder $qb, string $gridId, string $entityName): ?string
    {
        $fromParts = $qb->getQueryPart('from');
        if (!is_array($fromParts)) {
            return null;
        }

        $mainTables = array_values(array_unique([
            $this->dbPrefix . strtolower($gridId),
            $this->dbPrefix . strtolower($entityName),
        ]));
        foreach ($fromParts as $from) {
            if (!is_array($from)) {
                continue;
            }
            $table = $from['table'] ?? null;
            $alias = $from['alias'] ?? null;
            if (is_string($table) && in_array(strtolower($table), $mainTables, true) && is_string($alias) && '' !== $alias) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected function findJoinedTableAliasAndCondition(QueryBuilder $qb, string $tableName): array
    {
        $joinParts = $qb->getQueryPart('join');
        if (!is_array($joinParts)) {
            return [null, null];
        }

        foreach ($joinParts as $joinsByFromAlias) {
            if (!is_array($joinsByFromAlias)) {
                continue;
            }

            foreach ($joinsByFromAlias as $join) {
                if (!is_array($join)) {
                    continue;
                }
                $joinTable = $join['joinTable'] ?? $join['table'] ?? null;
                if ($tableName !== $joinTable) {
                    continue;
                }

                $alias = $join['joinAlias'] ?? $join['alias'] ?? null;
                $condition = $join['joinCondition'] ?? $join['condition'] ?? null;

                return [is_string($alias) ? $alias : null, is_string($condition) ? $condition : null];
            }
        }

        return [null, null];
    }

    protected function joinConditionMentionsShopId(string $langAlias, string $joinCondition): bool
    {
        // Detect patterns like "pl.`id_shop`" or "pl.id_shop"
        return (bool) preg_match('/\b' . preg_quote($langAlias, '/') . '\.(?:`)?id_shop(?:`)?\b/', $joinCondition);
    }
}
