<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\Pricing\Product;

use PHPUnit\Framework\TestCase;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Pricing\Product\TrackedProductPrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxRate;

class TrackedProductPriceTest extends TestCase
{
    public function testSetUnitPriceRecordsModification(): void
    {
        $price = TrackedProductPrice::create(1, 0);
        $price->setUnitPrice(new TaxablePrice(new DecimalNumber('29.99'), TaxRate::zero()));

        $breakdown = $price->getBreakdown();
        $this->assertSame(1, $breakdown->count());

        $step = $breakdown->getSteps()[0];
        $this->assertSame('unitPrice', $step->getProperty());
        $this->assertSame('0', $step->getPreviousValue());
        $this->assertSame('29.99', $step->getNewValue());
    }

    public function testSetTotalPriceRecordsModification(): void
    {
        $price = TrackedProductPrice::create(1, 0);
        $price->setTotalPrice(new TaxablePrice(new DecimalNumber('59.98'), TaxRate::zero()));

        $breakdown = $price->getBreakdown();
        $this->assertSame(1, $breakdown->count());

        $step = $breakdown->getSteps()[0];
        $this->assertSame('totalPrice', $step->getProperty());
    }

    public function testSetOriginalPriceRecordsModification(): void
    {
        $price = TrackedProductPrice::create(1, 0);
        $price->setOriginalPrice(new TaxablePrice(new DecimalNumber('39.99'), TaxRate::zero()));

        $breakdown = $price->getBreakdown();
        $this->assertSame(1, $breakdown->count());

        $step = $breakdown->getSteps()[0];
        $this->assertSame('originalPrice', $step->getProperty());
    }

    public function testMultipleModificationsAreTracked(): void
    {
        $price = TrackedProductPrice::create(1, 0);
        $price->setUnitPrice(new TaxablePrice(new DecimalNumber('29.99'), TaxRate::zero()));
        $price->setOriginalPrice(new TaxablePrice(new DecimalNumber('29.99'), TaxRate::zero()));
        $price->setTotalPrice(new TaxablePrice(new DecimalNumber('29.99'), TaxRate::zero()));

        $this->assertSame(3, $price->getBreakdown()->count());
    }

    public function testCallerClassIsCaptured(): void
    {
        $price = TrackedProductPrice::create(1, 0);
        $price->setUnitPrice(new TaxablePrice(new DecimalNumber('10'), TaxRate::zero()));

        $step = $price->getBreakdown()->getSteps()[0];
        $this->assertSame(self::class, $step->getCallerClass());
        $this->assertGreaterThan(0, $step->getCallerLine());
    }
}
