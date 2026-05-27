<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Value;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use Throwable;

/**
 * Writes extra property values into the *_extra / *_extra_lang / *_extra_shop tables.
 *
 * All writes use UPSERT (INSERT … ON DUPLICATE KEY UPDATE) to handle the case where
 * a row may or may not already exist.
 */
class ExtraPropertyWriter implements ExtraPropertyWriterInterface
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $prefix
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function writeAll(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        array $entityValues,
        array $langValuesByIdLang,
        array $shopValues,
        ShopConstraint $shopConstraint
    ): void {
        $shopId = $shopConstraint->isSingleShopContext() ? $shopConstraint->getShopId()->getValue() : null;

        if (!empty($entityValues)) {
            $this->writeCommon($entityName, $primaryKeyName, $entityId, $entityValues);
        }

        if (!empty($langValuesByIdLang) && null !== $shopId) {
            $this->writeLang($entityName, $primaryKeyName, $entityId, $shopId, $langValuesByIdLang);
        }

        if (!empty($shopValues) && null !== $shopId) {
            $this->writeShop($entityName, $primaryKeyName, $entityId, $shopId, $shopValues);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAll(string $entityName, string $primaryKeyName, int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }

        $quotedPk = $this->connection->quoteIdentifier($primaryKeyName);

        foreach (ExtraPropertyScope::values() as $scope) {
            $fullTable = $this->connection->quoteIdentifier(
                $this->prefix . ExtraPropertyNaming::extraTableName($entityName, $scope)
            );

            try {
                $this->connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE %s = ?', $fullTable, $quotedPk),
                    [$entityId]
                );
            } catch (Throwable) {
                // Table may not exist if no extra properties have been registered — safe to ignore
            }
        }
    }

    /**
     * Writes common-scope (entity-level) values for one entity instance.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function writeCommon(string $entityName, string $primaryKeyName, int $entityId, array $columnValues): void
    {
        $fullTableName = $this->prefix . ExtraPropertyNaming::extraTableName($entityName, ExtraPropertyScope::Common->value);
        $sql = $this->buildUpsertSql($fullTableName, $primaryKeyName, [], $columnValues);
        $this->connection->executeStatement($sql, [$entityId, ...array_values($columnValues)]);
    }

    /**
     * Writes lang-scope values for one entity instance, one row per language.
     *
     * @param array<int, array<string, mixed>> $langValuesByIdLang [idLang => ['column' => value]]
     */
    protected function writeLang(string $entityName, string $primaryKeyName, int $entityId, int $shopId, array $langValuesByIdLang): void
    {
        $fullTableName = $this->prefix . ExtraPropertyNaming::extraTableName($entityName, ExtraPropertyScope::Lang->value);

        foreach ($langValuesByIdLang as $idLang => $columnValues) {
            if (empty($columnValues)) {
                continue;
            }
            $sql = $this->buildUpsertSql($fullTableName, $primaryKeyName, ['id_shop', 'id_lang'], $columnValues);
            $this->connection->executeStatement($sql, [$entityId, $shopId, (int) $idLang, ...array_values($columnValues)]);
        }
    }

    /**
     * Writes shop-scope values for one entity instance.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function writeShop(string $entityName, string $primaryKeyName, int $entityId, int $shopId, array $columnValues): void
    {
        $fullTableName = $this->prefix . ExtraPropertyNaming::extraTableName($entityName, ExtraPropertyScope::Shop->value);
        $sql = $this->buildUpsertSql($fullTableName, $primaryKeyName, ['id_shop'], $columnValues);
        $this->connection->executeStatement($sql, [$entityId, $shopId, ...array_values($columnValues)]);
    }

    /**
     * Builds an INSERT … ON DUPLICATE KEY UPDATE statement.
     *
     * $systemColumns are fixed keys inserted before the data columns (e.g. id_shop, id_lang).
     * Callers must pass parameters in order: entityId, systemColumn values, then data values.
     *
     * @param string[] $systemColumns Fixed system key column names (order matters for bindings)
     * @param array<string, mixed> $columnValues Data column name → value map
     */
    protected function buildUpsertSql(string $fullTableName, string $primaryKeyName, array $systemColumns, array $columnValues): string
    {
        $quotedPk = $this->connection->quoteIdentifier($primaryKeyName);
        $quotedSystemCols = array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            $systemColumns
        );
        $quotedDataCols = array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            array_keys($columnValues)
        );

        $allColsList = implode(', ', [$quotedPk, ...($quotedSystemCols ?: []), ...$quotedDataCols]);
        $allPlaceholders = implode(', ', array_fill(0, 1 + count($systemColumns) + count($columnValues), '?'));
        $updateParts = implode(', ', array_map(
            fn (string $quotedCol): string => $quotedCol . ' = VALUES(' . $quotedCol . ')',
            $quotedDataCols
        ));

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->connection->quoteIdentifier($fullTableName),
            $allColsList,
            $allPlaceholders,
            $updateParts
        );
    }
}
