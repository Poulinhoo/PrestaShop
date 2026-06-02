<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertySqlIndex;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Manages the DDL of *_extra / *_extra_lang / *_extra_shop tables.
 *
 * Table naming convention (without DB prefix):
 *   entity scope → {entity}_extra          (e.g. product_extra)
 *   lang scope   → {entity}_extra_lang     (e.g. product_extra_lang)
 *   shop scope   → {entity}_extra_shop     (e.g. product_extra_shop)
 */
class ExtraPropertySchemaManager implements ExtraPropertySchemaManagerInterface
{
    /** @var array<string, bool> */
    protected array $tableExistenceCache = [];

    /** @var array<string, Table> */
    protected array $tableDetailsCache = [];

    protected readonly LoggerInterface $logger;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function ensureExtraTableAndColumn(string $entityName, string $fieldScope, string $columnName, string $sqlColumnDefinition, ExtraPropertySqlIndex $sqlIndex): void
    {
        $baseTableName = $this->prefix . $this->buildBaseEntityTableName($entityName, $fieldScope);
        $extraTableName = $this->prefix . $this->buildExtraEntityTableName($entityName, $fieldScope);

        if (!$this->tableExists($baseTableName)) {
            throw new RuntimeException(sprintf('The base table "%s" does not exist.', $baseTableName));
        }

        if (!$this->tableExists($extraTableName)) {
            $this->createExtraTableFromBaseTable($baseTableName, $extraTableName);
            $this->logger->info('Extra table created: {table}', ['table' => $extraTableName]);
        }

        if (!$this->columnExists($extraTableName, $columnName)) {
            if (!$this->isValidSqlIdentifier($columnName)) {
                throw new RuntimeException(sprintf('Invalid extra column name "%s".', $columnName));
            }

            $sql = sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s',
                $this->connection->quoteIdentifier($extraTableName),
                $this->connection->quoteIdentifier($columnName),
                $sqlColumnDefinition
            );
            $this->connection->executeStatement($sql);
            $this->invalidateTableCache($extraTableName);
            $this->logger->info('Extra column created: {table}.{column}', ['table' => $extraTableName, 'column' => $columnName]);
        }

        $this->syncExtraColumnIndex($extraTableName, $columnName, $sqlIndex);
    }

    /**
     * {@inheritdoc}
     */
    public function dropExtraColumnIfExists(string $entityName, string $fieldScope, string $columnName): void
    {
        $extraTableName = $this->prefix . $this->buildExtraEntityTableName($entityName, $fieldScope);
        if (!$this->tableExists($extraTableName) || !$this->columnExists($extraTableName, $columnName)) {
            return;
        }

        if (!$this->isValidSqlIdentifier($columnName)) {
            throw new RuntimeException(sprintf('Invalid extra column name "%s".', $columnName));
        }

        $sql = sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->connection->quoteIdentifier($extraTableName),
            $this->connection->quoteIdentifier($columnName)
        );
        $this->connection->executeStatement($sql);
        $this->invalidateTableCache($extraTableName);
        $this->logger->info('Extra column dropped: {table}.{column}', ['table' => $extraTableName, 'column' => $columnName]);

        $this->dropExtraTableIfEmpty($extraTableName);
    }

    /**
     * Returns the base (non-extra) entity table name for a given scope.
     *
     * @param string $entityName
     * @param string $fieldScope
     *
     * @return string
     */
    protected function buildBaseEntityTableName(string $entityName, string $fieldScope): string
    {
        if ('lang' === $fieldScope) {
            return $entityName . '_lang';
        }
        if ('shop' === $fieldScope) {
            return $entityName . '_shop';
        }

        return $entityName;
    }

    /**
     * Returns the extra storage table name (without prefix) for a given entity and scope.
     *
     * @param string $entityName
     * @param string $fieldScope
     *
     * @return string
     */
    protected function buildExtraEntityTableName(string $entityName, string $fieldScope): string
    {
        return ExtraPropertyDefinition::buildExtraTableName($entityName, ExtraPropertyScope::from($fieldScope));
    }

    /**
     * Synchronises the SQL index on an extra column: drops stale indexes and creates the desired one.
     *
     * @param string $extraTableName Full table name (with prefix)
     * @param string $columnName
     * @param ExtraPropertySqlIndex $sqlIndex Desired index strategy
     */
    protected function syncExtraColumnIndex(string $extraTableName, string $columnName, ExtraPropertySqlIndex $sqlIndex): void
    {
        $keyIndexName = $this->buildExtraColumnIndexName($extraTableName, $columnName, ExtraPropertySqlIndex::KEY);
        $uniqueIndexName = $this->buildExtraColumnIndexName($extraTableName, $columnName, ExtraPropertySqlIndex::UNIQUE);

        // Drop any index that no longer matches the desired strategy.
        if (ExtraPropertySqlIndex::KEY !== $sqlIndex) {
            $this->dropIndexIfExists($extraTableName, $keyIndexName);
        }
        if (ExtraPropertySqlIndex::UNIQUE !== $sqlIndex) {
            $this->dropIndexIfExists($extraTableName, $uniqueIndexName);
        }

        if (ExtraPropertySqlIndex::NONE === $sqlIndex) {
            return;
        }

        // Create the desired index if it does not already exist.
        $indexName = (ExtraPropertySqlIndex::UNIQUE === $sqlIndex) ? $uniqueIndexName : $keyIndexName;
        if ($this->indexExists($extraTableName, $indexName)) {
            return;
        }

        $sql = sprintf(
            'ALTER TABLE %s ADD %s %s (%s)',
            $this->connection->quoteIdentifier($extraTableName),
            ExtraPropertySqlIndex::UNIQUE === $sqlIndex ? 'UNIQUE INDEX' : 'INDEX',
            $this->connection->quoteIdentifier($indexName),
            $this->connection->quoteIdentifier($columnName)
        );
        $this->connection->executeStatement($sql);
        $this->invalidateTableCache($extraTableName);
    }

    /**
     * Drops the extra table if all its columns are part of the primary key (i.e. no extra columns remain).
     *
     * @param string $extraTableName Full table name (with prefix)
     */
    protected function dropExtraTableIfEmpty(string $extraTableName): void
    {
        $tableDetails = $this->getTableDetails($extraTableName);
        if (null === $tableDetails) {
            return;
        }

        $primaryKey = $tableDetails->getPrimaryKey();
        if (null === $primaryKey) {
            return;
        }

        $primaryKeyColumns = array_flip($primaryKey->getColumns());
        foreach (array_keys($tableDetails->getColumns()) as $columnName) {
            if (!array_key_exists($columnName, $primaryKeyColumns)) {
                return;
            }
        }

        $sql = sprintf('DROP TABLE %s', $this->connection->quoteIdentifier($extraTableName));
        $this->connection->executeStatement($sql);
        $this->invalidateTableCache($extraTableName);
        $this->logger->info('Extra table dropped (empty): {table}', ['table' => $extraTableName]);
    }

    /**
     * Creates the extra table by mirroring the primary key columns of the base entity table.
     *
     * @param string $baseTableName Full base table name (with prefix)
     * @param string $extraTableName Full extra table name (with prefix)
     *
     * @throws RuntimeException if the base table schema cannot be loaded or has no PK
     */
    protected function createExtraTableFromBaseTable(string $baseTableName, string $extraTableName): void
    {
        $baseTableDetails = $this->getTableDetails($baseTableName);
        if (null === $baseTableDetails) {
            throw new RuntimeException(sprintf('The schema for base table "%s" cannot be loaded.', $baseTableName));
        }

        $primaryKey = $baseTableDetails->getPrimaryKey();
        if (null === $primaryKey) {
            throw new RuntimeException(sprintf('The base table "%s" has no primary key.', $baseTableName));
        }

        $platform = $this->connection->getDatabasePlatform();
        $columnDefinitions = [];

        foreach ($primaryKey->getColumns() as $primaryColumnName) {
            $primaryColumn = $baseTableDetails->getColumn($primaryColumnName);
            $columnOptions = $this->buildColumnDeclarationOptions($primaryColumn);
            $columnDefinitions[] = $platform->getColumnDeclarationSQL($primaryColumnName, $columnOptions);
        }

        $primaryKeyColumns = array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            $primaryKey->getColumns()
        );

        $sql = sprintf(
            'CREATE TABLE %s (%s, PRIMARY KEY (%s))',
            $this->connection->quoteIdentifier($extraTableName),
            implode(', ', $columnDefinitions),
            implode(', ', $primaryKeyColumns)
        );
        $this->connection->executeStatement($sql);
        $this->invalidateTableCache($extraTableName);
    }

    /**
     * Builds the options array needed by Doctrine's getColumnDeclarationSQL() for a given column.
     *
     * @param \Doctrine\DBAL\Schema\Column $column
     *
     * @return array<string, mixed>
     */
    protected function buildColumnDeclarationOptions(\Doctrine\DBAL\Schema\Column $column): array
    {
        $columnOptions = [
            'type' => $column->getType(),
            'precision' => $column->getPrecision(),
            'scale' => $column->getScale(),
            'unsigned' => $column->getUnsigned(),
            'fixed' => $column->getFixed(),
            'notnull' => true,
            'default' => $column->getDefault(),
            'autoincrement' => false,
        ];

        if (null !== $column->getLength()) {
            $columnOptions['length'] = $column->getLength();
        }
        if ('' !== $column->getComment()) {
            $columnOptions['comment'] = $column->getComment();
        }
        foreach (['charset', 'collation'] as $platformOptionName) {
            if ($column->hasPlatformOption($platformOptionName)) {
                $columnOptions[$platformOptionName] = $column->getPlatformOption($platformOptionName);
            }
        }

        return $columnOptions;
    }

    /**
     * @param string $tableName Full table name (with prefix)
     *
     * @return bool
     */
    protected function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistenceCache)) {
            return $this->tableExistenceCache[$tableName];
        }
        $exists = $this->connection->createSchemaManager()->tablesExist([$tableName]);
        $this->tableExistenceCache[$tableName] = $exists;

        return $exists;
    }

    /**
     * @param string $tableName Full table name (with prefix)
     * @param string $columnName
     *
     * @return bool
     */
    protected function columnExists(string $tableName, string $columnName): bool
    {
        $tableDetails = $this->getTableDetails($tableName);
        if (null === $tableDetails) {
            return false;
        }

        return $tableDetails->hasColumn($columnName);
    }

    /**
     * @param string $tableName Full table name (with prefix)
     * @param string $indexName
     *
     * @return bool
     */
    protected function indexExists(string $tableName, string $indexName): bool
    {
        $tableDetails = $this->getTableDetails($tableName);
        if (null === $tableDetails) {
            return false;
        }

        return $tableDetails->hasIndex($indexName);
    }

    /**
     * @param string $tableName Full table name (with prefix)
     * @param string $indexName
     */
    protected function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (!$this->indexExists($tableName, $indexName)) {
            return;
        }

        $sql = sprintf(
            'ALTER TABLE %s DROP INDEX %s',
            $this->connection->quoteIdentifier($tableName),
            $this->connection->quoteIdentifier($indexName)
        );
        $this->connection->executeStatement($sql);
        $this->invalidateTableCache($tableName);
    }

    /**
     * @param string $tableName Full table name (with prefix)
     *
     * @return Table|null
     */
    protected function getTableDetails(string $tableName): ?Table
    {
        if (array_key_exists($tableName, $this->tableDetailsCache)) {
            return $this->tableDetailsCache[$tableName];
        }
        if (!$this->tableExists($tableName)) {
            return null;
        }

        $tableDetails = $this->connection->createSchemaManager()->introspectTable($tableName);
        $this->tableDetailsCache[$tableName] = $tableDetails;

        return $tableDetails;
    }

    /**
     * Invalidates the in-memory schema cache for a specific table.
     *
     * @param string $tableName Full table name (with prefix)
     */
    protected function invalidateTableCache(string $tableName): void
    {
        unset($this->tableExistenceCache[$tableName], $this->tableDetailsCache[$tableName]);
    }

    /**
     * Builds a deterministic index name for an extra column based on table name, column name and index type.
     *
     * @param string $tableName Full table name (with prefix)
     * @param string $columnName
     * @param ExtraPropertySqlIndex $sqlIndex
     *
     * @return string
     */
    protected function buildExtraColumnIndexName(string $tableName, string $columnName, ExtraPropertySqlIndex $sqlIndex): string
    {
        $prefix = (ExtraPropertySqlIndex::UNIQUE === $sqlIndex) ? 'uniq_extra_' : 'idx_extra_';

        return $prefix . substr(sha1($tableName . '|' . $columnName), 0, 16);
    }

    /**
     * @param string $identifier
     *
     * @return bool
     */
    protected function isValidSqlIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $identifier);
    }
}
