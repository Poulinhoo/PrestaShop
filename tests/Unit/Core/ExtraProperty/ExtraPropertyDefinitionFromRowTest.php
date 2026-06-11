<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\ExtraProperty;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepository;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyType;

/**
 * Covers the schema-deduced attributes flowing through ExtraPropertyDefinition::fromRow():
 * nullable and enum_values are synthetic row keys injected by the repository from the live
 * column structure (they are not persisted in the registry table).
 */
class ExtraPropertyDefinitionFromRowTest extends TestCase
{
    private const BASE_ROW = [
        'entity_name' => 'product',
        'property_name' => 'packaging_type',
        'type' => 'choice',
        'scope' => 'common',
        'module_name' => 'demoextrafield',
    ];

    public function testDefaultsWhenMetadataKeysAreAbsent(): void
    {
        $definition = ExtraPropertyDefinition::fromRow(self::BASE_ROW);

        $this->assertTrue($definition->isNullable());
        $this->assertNull($definition->getEnumValues());
    }

    public function testNullableComesFromRow(): void
    {
        $notNull = ExtraPropertyDefinition::fromRow(self::BASE_ROW + ['nullable' => false]);
        $nullable = ExtraPropertyDefinition::fromRow(self::BASE_ROW + ['nullable' => true]);

        $this->assertFalse($notNull->isNullable());
        $this->assertTrue($nullable->isNullable());
    }

    public function testEnumValuesComeFromRow(): void
    {
        $definition = ExtraPropertyDefinition::fromRow(self::BASE_ROW + ['enum_values' => ['box', 'bag', 'pallet']]);

        $this->assertSame(ExtraPropertyType::CHOICE, $definition->getType());
        $this->assertSame(['box', 'bag', 'pallet'], $definition->getEnumValues());
    }

    public function testEmptyOrInvalidEnumValuesFallBackToNull(): void
    {
        $empty = ExtraPropertyDefinition::fromRow(self::BASE_ROW + ['enum_values' => []]);
        $invalid = ExtraPropertyDefinition::fromRow(self::BASE_ROW + ['enum_values' => 'not-an-array']);

        $this->assertNull($empty->getEnumValues());
        $this->assertNull($invalid->getEnumValues());
    }

    /**
     * @dataProvider enumColumnTypeProvider
     */
    public function testParseEnumValuesFromSqlColumnType(string $sqlColumnType, ?array $expected): void
    {
        $repository = new class($this->createMock(Connection::class), 'ps_') extends ExtraPropertyDefinitionRepository {
            public static function parseEnums(string $sqlColumnType): ?array
            {
                return self::parseEnumValues($sqlColumnType);
            }
        };

        $this->assertSame($expected, $repository::parseEnums($sqlColumnType));
    }

    public static function enumColumnTypeProvider(): iterable
    {
        yield 'plain enum' => ["enum('box','bag','pallet')", ['box', 'bag', 'pallet']];
        yield 'uppercase enum' => ["ENUM('a','b')", ['a', 'b']];
        yield 'escaped quote in literal' => ["enum('it''s','plain')", ["it's", 'plain']];
        yield 'varchar is not enum' => ['varchar(255)', null];
        yield 'int is not enum' => ['int(11)', null];
        yield 'set is not enum' => ["set('a','b')", null];
    }
}
