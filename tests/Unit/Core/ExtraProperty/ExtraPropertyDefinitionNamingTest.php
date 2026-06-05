<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\ExtraProperty;

use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;

class ExtraPropertyDefinitionNamingTest extends TestCase
{
    /**
     * @dataProvider extraTableNameProvider
     */
    public function testBuildExtraTableName(string $entityName, ExtraPropertyScope $scope, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyDefinition::buildExtraTableName($entityName, $scope));
    }

    public static function extraTableNameProvider(): array
    {
        return [
            'common scope' => ['product', ExtraPropertyScope::COMMON, 'product_extra'],
            'lang scope' => ['product', ExtraPropertyScope::LANG, 'product_extra_lang'],
            'shop scope' => ['product', ExtraPropertyScope::SHOP, 'product_extra_shop'],
            'different entity common' => ['customer', ExtraPropertyScope::COMMON, 'customer_extra'],
            'different entity lang' => ['customer', ExtraPropertyScope::LANG, 'customer_extra_lang'],
            'different entity shop' => ['customer', ExtraPropertyScope::SHOP, 'customer_extra_shop'],
        ];
    }

    /**
     * @dataProvider storageColumnNameProvider
     */
    public function testBuildStorageColumnName(?string $moduleName, string $propertyName, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyDefinition::buildStorageColumnName($moduleName, $propertyName));
    }

    public static function storageColumnNameProvider(): array
    {
        return [
            'null module (core field)' => [null, 'video_link', 'video_link'],
            'empty string module (core field)' => ['', 'video_link', 'video_link'],
            'core sentinel module' => [ExtraPropertyDefinition::CORE_MODULE_KEY, 'video_link', 'video_link'],
            'module prefixes property' => ['ps_mymodule', 'video_link', 'ps_mymodule_video_link'],
            'another module' => ['demomodule', 'color', 'demomodule_color'],
        ];
    }

    /**
     * @dataProvider formFieldNameProvider
     */
    public function testGetFormFieldName(ExtraPropertyDefinition $definition, string $expected): void
    {
        $this->assertSame($expected, $definition->getFormFieldName());
    }

    public static function formFieldNameProvider(): array
    {
        return [
            'module field common scope' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'video_link', scope: ExtraPropertyScope::COMMON, moduleName: 'ps_mymodule'),
                'extra_common_ps_mymodule_video_link',
            ],
            'module field lang scope' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'video_link', scope: ExtraPropertyScope::LANG, moduleName: 'ps_mymodule'),
                'extra_lang_ps_mymodule_video_link',
            ],
            'module field shop scope' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'video_link', scope: ExtraPropertyScope::SHOP, moduleName: 'ps_mymodule'),
                'extra_shop_ps_mymodule_video_link',
            ],
            'core sentinel common scope' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'my_field', scope: ExtraPropertyScope::COMMON, moduleName: ExtraPropertyDefinition::CORE_MODULE_KEY),
                'extra_common__core_my_field',
            ],
            'null module treated as _core' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'my_field', scope: ExtraPropertyScope::COMMON, moduleName: null),
                'extra_common__core_my_field',
            ],
        ];
    }

    /**
     * @dataProvider displayModuleKeyProvider
     */
    public function testGetDisplayModuleKey(ExtraPropertyDefinition $definition, string $expected): void
    {
        $this->assertSame($expected, $definition->getDisplayModuleKey());
    }

    public static function displayModuleKeyProvider(): array
    {
        return [
            'null maps to _core' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'field', moduleName: null),
                ExtraPropertyDefinition::CORE_MODULE_KEY,
            ],
            '_core stays _core' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'field', moduleName: ExtraPropertyDefinition::CORE_MODULE_KEY),
                ExtraPropertyDefinition::CORE_MODULE_KEY,
            ],
            'actual module name is returned as-is' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'field', moduleName: 'ps_mymodule'),
                'ps_mymodule',
            ],
            'another module' => [
                new ExtraPropertyDefinition(entityName: 'entity', propertyName: 'field', moduleName: 'demomodule'),
                'demomodule',
            ],
        ];
    }

    public function testCoreModuleKeyConstant(): void
    {
        $this->assertSame('_core', ExtraPropertyDefinition::CORE_MODULE_KEY);
    }

    /**
     * Tests parseGridEntry indirectly via getGridEntry() on a definition instance.
     *
     * parseGridEntry() is protected; testing it via getGridEntry() covers the same behavior
     * through the public API.
     *
     * @dataProvider parseGridEntryProvider
     *
     * @param array{gridId: string, columnId: string|null, mode: 'before'|'after'|null} $expected
     */
    public function testGetGridEntry(string $entry, array $expected): void
    {
        $definition = new ExtraPropertyDefinition(
            entityName: $expected['gridId'],
            propertyName: 'test_field',
            associatedGrids: [$entry],
            labelWording: 'Test',
        );

        $this->assertSame($expected, $definition->getGridEntry($expected['gridId']));
    }

    public static function parseGridEntryProvider(): array
    {
        return [
            'bare grid id — no column, no mode' => [
                'product',
                ['gridId' => 'product', 'columnId' => null, 'mode' => null],
            ],
            'grid with column — default mode is after' => [
                'product.reference',
                ['gridId' => 'product', 'columnId' => 'reference', 'mode' => 'after'],
            ],
            'grid with column explicit after' => [
                'product.reference:after',
                ['gridId' => 'product', 'columnId' => 'reference', 'mode' => 'after'],
            ],
            'grid with column explicit before' => [
                'product.reference:before',
                ['gridId' => 'product', 'columnId' => 'reference', 'mode' => 'before'],
            ],
            'compound grid id with column' => [
                'manufacturer_address.city',
                ['gridId' => 'manufacturer_address', 'columnId' => 'city', 'mode' => 'after'],
            ],
            'compound grid id with column and before' => [
                'manufacturer_address.city:before',
                ['gridId' => 'manufacturer_address', 'columnId' => 'city', 'mode' => 'before'],
            ],
            'dot with empty rest treated as no column' => [
                'product.',
                ['gridId' => 'product', 'columnId' => null, 'mode' => null],
            ],
        ];
    }
}
