<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\ExtraProperty;

use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;

class ExtraPropertyNamingTest extends TestCase
{
    /**
     * @dataProvider extraTableNameProvider
     */
    public function testExtraTableName(string $entityName, string $scope, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyNaming::extraTableName($entityName, $scope));
    }

    public static function extraTableNameProvider(): array
    {
        return [
            'common scope' => ['product', 'common', 'product_extra'],
            'lang scope' => ['product', 'lang', 'product_extra_lang'],
            'shop scope' => ['product', 'shop', 'product_extra_shop'],
            'unknown scope falls back to common' => ['product', 'other', 'product_extra'],
            'different entity common' => ['customer', 'common', 'customer_extra'],
            'different entity lang' => ['customer', 'lang', 'customer_extra_lang'],
            'different entity shop' => ['customer', 'shop', 'customer_extra_shop'],
        ];
    }

    /**
     * @dataProvider storageColumnNameProvider
     */
    public function testStorageColumnName(?string $moduleName, string $propertyName, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyNaming::storageColumnName($moduleName, $propertyName));
    }

    public static function storageColumnNameProvider(): array
    {
        return [
            'null module (core field)' => [null, 'video_link', 'video_link'],
            'empty string module (core field)' => ['', 'video_link', 'video_link'],
            'core sentinel module' => [ExtraPropertyNaming::CORE_MODULE_KEY, 'video_link', 'video_link'],
            'module prefixes property' => ['ps_mymodule', 'video_link', 'ps_mymodule_video_link'],
            'another module' => ['demomodule', 'color', 'demomodule_color'],
        ];
    }

    /**
     * @dataProvider formFieldNameProvider
     */
    public function testFormFieldName(string $moduleName, string $propertyName, string $scope, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyNaming::formFieldName($moduleName, $propertyName, $scope));
    }

    public static function formFieldNameProvider(): array
    {
        return [
            'module field common scope' => ['ps_mymodule', 'video_link', 'common', 'extra_common_ps_mymodule_video_link'],
            'module field lang scope' => ['ps_mymodule', 'video_link', 'lang', 'extra_lang_ps_mymodule_video_link'],
            'module field shop scope' => ['ps_mymodule', 'video_link', 'shop', 'extra_shop_ps_mymodule_video_link'],
            'core sentinel common scope' => [ExtraPropertyNaming::CORE_MODULE_KEY, 'my_field', 'common', 'extra_common__core_my_field'],
            'empty module treated as _core' => ['', 'my_field', 'common', 'extra_common__core_my_field'],
        ];
    }

    /**
     * @dataProvider displayModuleKeyProvider
     */
    public function testDisplayModuleKey(?string $moduleName, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyNaming::displayModuleKey($moduleName));
    }

    public static function displayModuleKeyProvider(): array
    {
        return [
            'null maps to _core' => [null, ExtraPropertyNaming::CORE_MODULE_KEY],
            'empty string maps to _core' => ['', ExtraPropertyNaming::CORE_MODULE_KEY],
            '_core stays _core' => [ExtraPropertyNaming::CORE_MODULE_KEY, ExtraPropertyNaming::CORE_MODULE_KEY],
            'actual module name is returned as-is' => ['ps_mymodule', 'ps_mymodule'],
            'another module' => ['demomodule', 'demomodule'],
        ];
    }

    public function testCoreModuleKeyConstant(): void
    {
        $this->assertSame('_core', ExtraPropertyNaming::CORE_MODULE_KEY);
    }

    /**
     * @dataProvider parseGridEntryProvider
     *
     * @param array{gridId: string, columnId: string|null, mode: 'before'|'after'|null} $expected
     */
    public function testParseGridEntry(string $entry, array $expected): void
    {
        $this->assertSame($expected, ExtraPropertyNaming::parseGridEntry($entry));
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

    /**
     * @dataProvider legacyControllerFromEntityNameProvider
     */
    public function testLegacyControllerFromEntityName(string $entityName, string $expected): void
    {
        $this->assertSame($expected, ExtraPropertyNaming::legacyControllerFromEntityName($entityName));
    }

    public static function legacyControllerFromEntityNameProvider(): array
    {
        return [
            'product entity' => ['product', 'AdminProducts'],
            'customer entity' => ['customer', 'AdminCustomers'],
            'category entity' => ['category', 'AdminCategories'],
            'address entity' => ['address', 'AdminAddresses'],
            'manufacturer entity' => ['manufacturer', 'AdminManufacturers'],
            'order entity' => ['order', 'AdminOrders'],
        ];
    }
}
