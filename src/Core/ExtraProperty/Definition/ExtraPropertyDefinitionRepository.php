<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Throwable;

/**
 * Reads and writes extra property definitions in the extra_property_definition registry table.
 *
 * This implementation does not add any caching; wrap with Definition\CachedExtraPropertyDefinitionRepository
 * for production use.
 *
 * All public read methods return typed ExtraPropertyDefinition value objects or collections.
 */
class ExtraPropertyDefinitionRepository implements ExtraPropertyDefinitionRepositoryInterface, ExtraPropertyDefinitionWriterInterface
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getAllDefinitions(): ExtraPropertyDefinitionCollection
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('eef.*')
            ->from($table, 'eef')
            ->orderBy('eef.id_extra_property_definition', 'ASC');

        $rows = $this->enrichRowsWithColumnMetadata($qb->executeQuery()->fetchAllAssociative() ?: []);

        return new ExtraPropertyDefinitionCollection(array_values(array_map(
            static fn (array $row): ExtraPropertyDefinition => ExtraPropertyDefinition::fromRow($row),
            $rows
        )));
    }

    /**
     * {@inheritdoc}
     */
    public function findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinition
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('eef.*')
            ->from($table, 'eef')
            ->where('eef.entity_name = :entityName')
            ->andWhere('eef.property_name = :fieldName')
            ->andWhere('eef.scope = :fieldScope')
            ->setParameter('entityName', $entityName)
            ->setParameter('fieldName', $fieldName)
            ->setParameter('fieldScope', $fieldScope);

        $this->applyModuleNameFilter($qb, $moduleName, 'eef');

        $row = $qb->executeQuery()->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        return ExtraPropertyDefinition::fromRow($this->enrichRowsWithColumnMetadata([$row])[0]);
    }

    /**
     * {@inheritdoc}
     */
    public function save(
        ExtraPropertyDefinition $options,
        string $entityName,
        string $propertyName,
        ?string $normalizedModuleName,
        string $normalizedScope,
    ): int|false {
        $table = $this->prefix . 'extra_property_definition';

        $data = [
            'module_name' => $normalizedModuleName,
            'scope' => $normalizedScope,
            'type' => $options->getType()->value,
            'size' => $options->getSize(),
            'form_required' => (int) $options->isFormRequired(),
            'default_value' => null !== $options->getDefaultValue() ? (string) $options->getDefaultValue() : null,
            'form_field_type' => $options->getFormFieldType(),
            'form_options' => null !== $options->getFormOptions() ? json_encode($options->getFormOptions()) : null,
            'sql_index' => $options->getSqlIndex()->value,
            'validator' => $options->getValidator(),
            'display_api' => (int) $options->isDisplayApi(),
            'associated_forms' => !empty($options->getAssociatedForms()) ? json_encode(array_values($options->getAssociatedForms())) : null,
            'associated_grids' => !empty($options->getAssociatedGrids()) ? json_encode(array_values($options->getAssociatedGrids())) : null,
            'display_front' => (int) $options->isDisplayFront(),
            'label_wording' => $options->getLabelWording(),
            'label_domain' => $options->getLabelDomain(),
            'description_wording' => $options->getDescriptionWording(),
            'description_domain' => $options->getDescriptionDomain(),
        ];

        // Resolve existing row ID from the unique key to decide INSERT vs UPDATE.
        $existingId = $this->findIdByUniqueKey($entityName, $normalizedModuleName, $propertyName, $normalizedScope);

        if (null !== $existingId) {
            $saved = (bool) $this->connection->update($table, $data, ['id_extra_property_definition' => $existingId]);

            return $saved ? $existingId : false;
        }

        $data['entity_name'] = $entityName;
        $data['property_name'] = $propertyName;

        $saved = (bool) $this->connection->insert($table, $data);
        if (!$saved) {
            return false;
        }

        return (int) $this->connection->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): bool
    {
        $table = $this->prefix . 'extra_property_definition';

        return (bool) $this->connection->delete($table, ['id_extra_property_definition' => $id]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByDefinition(ExtraPropertyDefinition $definition): bool
    {
        $entityName = $definition->getEntityName();
        $propertyName = $definition->getPropertyName();
        $moduleName = $definition->getModuleName();
        $scope = $definition->getScope()->value;

        $id = $this->findIdByUniqueKey($entityName, $moduleName, $propertyName, $scope);
        if (null === $id) {
            return true;
        }

        return $this->delete($id);
    }

    /**
     * Looks up the primary key for a definition identified by its unique key.
     *
     * Returns null when no matching row exists.
     */
    protected function findIdByUniqueKey(string $entityName, ?string $moduleName, string $propertyName, string $scope): ?int
    {
        $table = $this->prefix . 'extra_property_definition';
        $qb = $this->connection->createQueryBuilder();
        $qb->select('id_extra_property_definition')
            ->from($table)
            ->where('entity_name = :entityName')
            ->andWhere('property_name = :propertyName')
            ->andWhere('scope = :scope')
            ->setParameter('entityName', $entityName)
            ->setParameter('propertyName', $propertyName)
            ->setParameter('scope', $scope);

        $this->applyModuleNameFilter($qb, $moduleName);

        $id = $qb->executeQuery()->fetchOne();

        return false !== $id && null !== $id ? (int) $id : null;
    }

    /**
     * Applies a WHERE clause for module_name on a query builder.
     *
     * Uses `module_name IS NULL` for core fields (null/empty) since SQL `= NULL` never matches.
     *
     * @param QueryBuilder $qb Query builder to modify in place
     * @param string|null $moduleName Module name, or null/'' for core fields
     * @param string $alias Optional table alias prefix (e.g. 'eef' → 'eef.module_name')
     */
    protected function applyModuleNameFilter(QueryBuilder $qb, ?string $moduleName, string $alias = ''): void
    {
        $column = ('' !== $alias) ? $alias . '.module_name' : 'module_name';

        if (null !== $moduleName && '' !== $moduleName) {
            $qb->andWhere($column . ' = :moduleName')->setParameter('moduleName', $moduleName);
        } else {
            $qb->andWhere($column . ' IS NULL');
        }
    }

    /**
     * Enriches registry rows with the synthetic 'nullable' and 'enum_values' keys, deduced
     * from the live DB structure of each definition's storage column. These two attributes
     * are not persisted in the registry table: the extra table schema is their source of
     * truth (NULL/NOT NULL clause, ENUM literals for CHOICE columns).
     *
     * One SHOW COLUMNS query per distinct extra table; getAllDefinitions() results are cached
     * by CachedExtraPropertyDefinitionRepository, so the introspection cost is amortized.
     * Rows whose storage column does not exist (yet) are left untouched — fromRow() then
     * applies its safe defaults (nullable, no enum).
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    protected function enrichRowsWithColumnMetadata(array $rows): array
    {
        $columnsByTable = [];

        foreach ($rows as &$row) {
            $scope = ExtraPropertyScope::tryFrom((string) ($row['scope'] ?? '')) ?? ExtraPropertyScope::COMMON;
            $tableName = $this->prefix . ExtraPropertyDefinition::buildExtraTableName((string) ($row['entity_name'] ?? ''), $scope);
            $columnName = ExtraPropertyDefinition::buildStorageColumnName(
                isset($row['module_name']) && '' !== $row['module_name'] ? (string) $row['module_name'] : null,
                (string) ($row['property_name'] ?? '')
            );

            if (!array_key_exists($tableName, $columnsByTable)) {
                $columnsByTable[$tableName] = $this->fetchColumnMetadata($tableName);
            }

            $columnMetadata = $columnsByTable[$tableName][$columnName] ?? null;
            if (null === $columnMetadata) {
                continue;
            }

            $row['nullable'] = $columnMetadata['nullable'];
            $row['enum_values'] = $columnMetadata['enum_values'];
        }

        return $rows;
    }

    /**
     * Introspects an extra table and returns nullability + ENUM literals per column.
     *
     * Returns an empty array when the table does not exist (no extra property value was
     * ever registered for that entity/scope combination yet).
     *
     * @param string $tableName Full table name (with prefix)
     *
     * @return array<string, array{nullable: bool, enum_values: list<string>|null}> keyed by column name
     */
    protected function fetchColumnMetadata(string $tableName): array
    {
        try {
            $columns = $this->connection->fetchAllAssociative(
                'SHOW COLUMNS FROM ' . $this->connection->quoteIdentifier($tableName)
            );
        } catch (Throwable) {
            return [];
        }

        $metadata = [];
        foreach ($columns as $column) {
            $metadata[(string) $column['Field']] = [
                'nullable' => 'YES' === strtoupper((string) ($column['Null'] ?? 'YES')),
                'enum_values' => self::parseEnumValues((string) ($column['Type'] ?? '')),
            ];
        }

        return $metadata;
    }

    /**
     * Extracts the literals of a SQL ENUM column type, e.g. "enum('a','b')" → ['a', 'b'].
     *
     * Returns null for any non-ENUM column type.
     *
     * @return list<string>|null
     */
    protected static function parseEnumValues(string $sqlColumnType): ?array
    {
        if (!str_starts_with(strtolower($sqlColumnType), 'enum(')) {
            return null;
        }

        // Literals are single-quoted; embedded quotes are doubled ('').
        preg_match_all("/'((?:[^']|'')*)'/", $sqlColumnType, $matches);
        $values = array_map(
            static fn (string $value): string => str_replace("''", "'", $value),
            $matches[1]
        );

        return [] !== $values ? array_values($values) : null;
    }
}
