<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\Pricing\Product;

use PHPUnit\Framework\TestCase;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxRate;

class ProductPriceTest extends TestCase
{
    public function testCreate(): void
    {
        $price = ProductPrice::create(1, 5);

        $this->assertSame(1, $price->getProductId());
        $this->assertSame(5, $price->getCombinationId());
    }

    public function testInitialPricesAreZero(): void
    {
        $price = ProductPrice::create(1, 0);

        $this->assertTrue($price->getUnitPrice()->getTaxExcluded()->equalsZero());
        $this->assertTrue($price->getTotalPrice()->getTaxExcluded()->equalsZero());
        $this->assertTrue($price->getOriginalPrice()->getTaxExcluded()->equalsZero());
    }

    public function testSetUnitPrice(): void
    {
        $price = ProductPrice::create(1, 0);
        $unitPrice = new TaxablePrice(new DecimalNumber('29.99'), TaxRate::zero());

        $price->setUnitPrice($unitPrice);

        $this->assertTrue($price->getUnitPrice()->getTaxExcluded()->equals(new DecimalNumber('29.99')));
    }

    public function testSetTotalPrice(): void
    {
        $price = ProductPrice::create(1, 0);
        $totalPrice = new TaxablePrice(new DecimalNumber('59.98'), TaxRate::zero());

        $price->setTotalPrice($totalPrice);

        $this->assertTrue($price->getTotalPrice()->getTaxExcluded()->equals(new DecimalNumber('59.98')));
    }

    public function testSetOriginalPrice(): void
    {
        $price = ProductPrice::create(1, 0);
        $originalPrice = new TaxablePrice(new DecimalNumber('39.99'), TaxRate::zero());

        $price->setOriginalPrice($originalPrice);

        $this->assertTrue($price->getOriginalPrice()->getTaxExcluded()->equals(new DecimalNumber('39.99')));
    }
}
