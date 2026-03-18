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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CatalogProductProviderTest extends KernelTestCase
{
    private CatalogProductProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $dbPrefix = self::getContainer()->getParameter('database_prefix');

        $this->provider = new CatalogProductProvider($connection, $dbPrefix);
    }

    public function testGetBasePriceReturnsDecimalNumber(): void
    {
        // Product ID 1 should exist in the test database (demo data)
        $basePrice = $this->provider->getBasePrice(1);

        $this->assertInstanceOf(DecimalNumber::class, $basePrice);
        // The demo product should have a non-zero price
        $this->assertFalse($basePrice->isNegative());
    }

    public function testGetBasePriceForNonExistentProduct(): void
    {
        $basePrice = $this->provider->getBasePrice(999999);

        $this->assertTrue($basePrice->equalsZero());
    }

    public function testGetCombinationPriceImpactForNonExistentCombination(): void
    {
        $impact = $this->provider->getCombinationPriceImpact(999999, 999999);

        $this->assertTrue($impact->equalsZero());
    }
}
