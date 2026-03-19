<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Core\Pricing\Product\Provider;

use Doctrine\DBAL\Connection;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Pricing\Product\Provider\CatalogProductProvider;
use PrestaShop\PrestaShop\Core\Pricing\Product\Provider\ProductPriceData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CatalogProductProviderTest extends KernelTestCase
{
    protected CatalogProductProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $dbPrefix = self::getContainer()->getParameter('database_prefix');

        $this->provider = new CatalogProductProvider($connection, $dbPrefix);
    }

    public function testGetProductPriceDataReturnsProductPriceData(): void
    {
        // Product ID 1 should exist in the test database (demo data)
        $priceData = $this->provider->getProductPriceData(1, 0);

        $this->assertInstanceOf(ProductPriceData::class, $priceData);
        $this->assertInstanceOf(DecimalNumber::class, $priceData->getPrice());
        $this->assertInstanceOf(DecimalNumber::class, $priceData->getUnitPrice());
        $this->assertFalse($priceData->getPrice()->isNegative());
    }

    public function testGetProductPriceDataForNonExistentProduct(): void
    {
        $priceData = $this->provider->getProductPriceData(999999, 0);

        $this->assertTrue($priceData->getPrice()->equalsZero());
        $this->assertTrue($priceData->getUnitPrice()->equalsZero());
    }

    public function testGetProductPriceDataForNonExistentCombination(): void
    {
        $priceData = $this->provider->getProductPriceData(999999, 999999);

        $this->assertTrue($priceData->getPrice()->equalsZero());
        $this->assertTrue($priceData->getUnitPrice()->equalsZero());
    }
}
