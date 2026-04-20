<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Storage;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use Throwable;

/**
 * Writes extra property values into the *_extra / *_extra_lang / *_extra_shop tables.
 *
 * Used by ObjectModel (via ServiceLocator) when persisting extra property values,
 * and by the BackOffice form persister for bulk writes.
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
    public function writeValue(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        string $storageColumnName,
        mixed $value,
        string $fieldScope = 'common',
        ?int $langId = null,
        ?int $shopId = null
    ): bool {
        if ($entityId <= 0) {
            return false;
        }

        $tableName = ExtraPropertyNaming::extraTableName($entityName, $fieldScope);

        try {
            if ('lang' === $fieldScope) {
                if ((int) $langId <= 0 || (int) $shopId <= 0) {
                    return false;
                }
                $this->upsertLangRow($tableName, $primaryKeyName, $entityId, (int) $shopId, (int) $langId, [$storageColumnName => $value]);
            } elseif ('shop' === $fieldScope) {
                if ((int) $shopId <= 0) {
                    return false;
                }
                $this->upsertShopRow($tableName, $primaryKeyName, $entityId, (int) $shopId, [$storageColumnName => $value]);
            } else {
                $this->upsertEntityRow($tableName, $primaryKeyName, $entityId, [$storageColumnName => $value]);
            }
        } catch (Throwable) {
            return false;
        }

        return true;
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
        ?int $shopId = null
    ): void {
        if (!empty($entityValues)) {
            $this->upsertEntityRow(
                ExtraPropertyNaming::extraTableName($entityName, 'common'),
                $primaryKeyName,
                $entityId,
                $entityValues
            );
        }

        if (!empty($langValuesByIdLang) && (int) $shopId > 0) {
            foreach ($langValuesByIdLang as $idLang => $columnValues) {
                $this->upsertLangRow(
                    ExtraPropertyNaming::extraTableName($entityName, 'lang'),
                    $primaryKeyName,
                    $entityId,
                    (int) $shopId,
                    (int) $idLang,
                    $columnValues
                );
            }
        }

        if (!empty($shopValues) && (int) $shopId > 0) {
            $this->upsertShopRow(
                ExtraPropertyNaming::extraTableName($entityName, 'shop'),
                $primaryKeyName,
                $entityId,
                (int) $shopId,
                $shopValues
            );
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

        foreach (['common', 'lang', 'shop'] as $scope) {
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
     * @param array<string, mixed> $columnValues
     */
    protected function upsertEntityRow(string $tableName, string $primaryKeyName, int $entityId, array $columnValues): void
    {
        $fullTableName = $this->prefix . $tableName;
        $quotedPk = $this->connection->quoteIdentifier($primaryKeyName);
        $columnParts = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            array_keys($columnValues)
        ));
        $placeholders = implode(', ', array_fill(0, count($columnValues), '?'));
        $updateParts = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col) . ' = VALUES(' . $this->connection->quoteIdentifier($col) . ')',
            array_keys($columnValues)
        ));

        $sql = sprintf(
            'INSERT INTO %s (%s, %s) VALUES (?, %s) ON DUPLICATE KEY UPDATE %s',
            $this->connection->quoteIdentifier($fullTableName),
            $quotedPk,
            $columnParts,
            $placeholders,
            $updateParts
        );

        $this->connection->executeStatement($sql, [$entityId, ...array_values($columnValues)]);
    }

    /**
     * @param array<string, mixed> $columnValues
     */
    protected function upsertLangRow(string $tableName, string $primaryKeyName, int $entityId, int $shopId, int $langId, array $columnValues): void
    {
        $fullTableName = $this->prefix . $tableName;
        $quotedPk = $this->connection->quoteIdentifier($primaryKeyName);
        $columnParts = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            array_keys($columnValues)
        ));
        $placeholders = implode(', ', array_fill(0, count($columnValues), '?'));
        $updateParts = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col) . ' = VALUES(' . $this->connection->quoteIdentifier($col) . ')',
            array_keys($columnValues)
        ));

        $sql = sprintf(
            'INSERT INTO %s (%s, id_shop, id_lang, %s) VALUES (?, ?, ?, %s) ON DUPLICATE KEY UPDATE %s',
            $this->connection->quoteIdentifier($fullTableName),
            $quotedPk,
            $columnParts,
            $placeholders,
            $updateParts
        );

        $this->connection->executeStatement($sql, [$entityId, $shopId, $langId, ...array_values($columnValues)]);
    }

    /**
     * @param array<string, mixed> $columnValues
     */
    protected function upsertShopRow(string $tableName, string $primaryKeyName, int $entityId, int $shopId, array $columnValues): void
    {
        $fullTableName = $this->prefix . $tableName;
        $quotedPk = $this->connection->quoteIdentifier($primaryKeyName);
        $columnParts = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col),
            array_keys($columnValues)
        ));
        $placeholders = implode(', ', array_fill(0, count($columnValues), '?'));
        $updateParts = implode(', ', array_map(
            fn (string $col): string => $this->connection->quoteIdentifier($col) . ' = VALUES(' . $this->connection->quoteIdentifier($col) . ')',
            array_keys($columnValues)
        ));

        $sql = sprintf(
            'INSERT INTO %s (%s, id_shop, %s) VALUES (?, ?, %s) ON DUPLICATE KEY UPDATE %s',
            $this->connection->quoteIdentifier($fullTableName),
            $quotedPk,
            $columnParts,
            $placeholders,
            $updateParts
        );

        $this->connection->executeStatement($sql, [$entityId, $shopId, ...array_values($columnValues)]);
    }
}
