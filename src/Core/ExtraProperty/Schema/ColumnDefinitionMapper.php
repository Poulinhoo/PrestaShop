<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Schema;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyType;

/**
 * Maps an ExtraPropertyOptions VO to a complete SQL column definition fragment.
 *
 * The returned string is ready to be appended after the column name in an ALTER TABLE … ADD COLUMN statement.
 * NULL/NOT NULL and DEFAULT clauses are always explicit so the caller does not need to add them.
 */
class ColumnDefinitionMapper
{
    /**
     * Returns the full SQL column definition fragment for the given options.
     *
     * @param ExtraPropertyOptions $options Property options containing type, enumValues, nullable, defaultValue
     *
     * @return string e.g. "VARCHAR(255) NULL" or "ENUM('a','b') NOT NULL DEFAULT 'a'"
     */
    public static function getSqlDefinition(ExtraPropertyOptions $options): string
    {
        $enumValues = null !== $options->enumValues
            ? array_values(array_filter($options->enumValues, 'is_string'))
            : [];

        $baseDefinition = self::buildBaseDefinition($options->type, $enumValues, $options->size);
        $nullClause = $options->nullable ? 'NULL' : 'NOT NULL';

        $parts = [$baseDefinition, $nullClause];

        if (null !== $options->defaultValue) {
            $parts[] = 'DEFAULT ' . self::quoteDefaultValue($options->type, $options->defaultValue);
        }

        return implode(' ', $parts);
    }

    /**
     * Returns the base SQL type string (without NULL/NOT NULL or DEFAULT).
     *
     * @param ExtraPropertyType $type
     * @param list<string> $enumValues Only used for Choice type
     * @param int|null $size For STRING type: varchar length (1–16383). Defaults to 255 when null.
     *                       Ignored for all other types.
     *
     * @return string
     */
    private static function buildBaseDefinition(ExtraPropertyType $type, array $enumValues, ?int $size = null): string
    {
        return match ($type) {
            ExtraPropertyType::INT => 'INT(11)',
            ExtraPropertyType::BOOL => 'TINYINT(1) UNSIGNED',
            ExtraPropertyType::STRING => 'VARCHAR(' . (null !== $size && $size > 0 ? $size : 255) . ')',
            ExtraPropertyType::FLOAT => 'DECIMAL(20,6)',
            ExtraPropertyType::DATE => 'DATETIME',
            ExtraPropertyType::HTML => 'TEXT',
            ExtraPropertyType::JSON => 'LONGTEXT',
            ExtraPropertyType::CHOICE => !empty($enumValues) ? self::buildEnumDefinition($enumValues) : 'VARCHAR(64)',
        };
    }

    /**
     * Builds an ENUM SQL definition from a list of allowed values, with proper single-quote escaping.
     *
     * @param list<string> $enumValues
     *
     * @return string e.g. "ENUM('pending','active','closed')"
     */
    private static function buildEnumDefinition(array $enumValues): string
    {
        $quotedValues = array_map(
            static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $enumValues
        );

        return 'ENUM(' . implode(',', $quotedValues) . ')';
    }

    /**
     * Formats a default value as a SQL literal appropriate for the given type.
     *
     * Numeric types are unquoted; string-like types are single-quoted with escaping.
     *
     * @param ExtraPropertyType $type
     * @param scalar $defaultValue
     *
     * @return string
     */
    private static function quoteDefaultValue(ExtraPropertyType $type, mixed $defaultValue): string
    {
        return match ($type) {
            ExtraPropertyType::INT,
            ExtraPropertyType::BOOL,
            ExtraPropertyType::FLOAT => (string) $defaultValue,
            ExtraPropertyType::STRING,
            ExtraPropertyType::DATE,
            ExtraPropertyType::HTML,
            ExtraPropertyType::JSON,
            ExtraPropertyType::CHOICE => "'" . str_replace("'", "''", (string) $defaultValue) . "'",
        };
    }
}
