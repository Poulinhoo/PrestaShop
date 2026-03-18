<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\Pricing\Product\Calculator;

use PHPUnit\Framework\TestCase;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\BaseProductCalculator;
use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPrice;
use PrestaShop\PrestaShop\Core\Pricing\Product\Provider\MockProductProvider;

class BaseProductCalculatorTest extends TestCase
{
    public function testSetsBasePriceFromProvider(): void
    {
        $provider = new MockProductProvider([1 => '29.99']);
        $calculator = new BaseProductCalculator($provider);
        $productPrice = ProductPrice::create(1, 0);

        $calculator->compute($productPrice);

        $this->assertTrue(
            $productPrice->getUnitPrice()->getTaxExcluded()->equals(new DecimalNumber('29.99'))
        );
        $this->assertTrue(
            $productPrice->getOriginalPrice()->getTaxExcluded()->equals(new DecimalNumber('29.99'))
        );
        $this->assertTrue(
            $productPrice->getTotalPrice()->getTaxExcluded()->equals(new DecimalNumber('29.99'))
        );
    }

    public function testHandlesCombinations(): void
    {
        $provider = new MockProductProvider(
            basePrices: [1 => '100'],
            combinationImpacts: ['1-5' => '15.50']
        );
        $calculator = new BaseProductCalculator($provider);
        $productPrice = ProductPrice::create(1, 5);

        $calculator->compute($productPrice);

        // Unit price = base (100) + combination impact (15.50) = 115.50
        $this->assertTrue(
            $productPrice->getUnitPrice()->getTaxExcluded()->equals(new DecimalNumber('115.50'))
        );
        // Original price = base price only
        $this->assertTrue(
            $productPrice->getOriginalPrice()->getTaxExcluded()->equals(new DecimalNumber('100'))
        );
    }

    public function testUnknownProductReturnsZero(): void
    {
        $provider = new MockProductProvider();
        $calculator = new BaseProductCalculator($provider);
        $productPrice = ProductPrice::create(999, 0);

        $calculator->compute($productPrice);

        $this->assertTrue($productPrice->getUnitPrice()->getTaxExcluded()->equalsZero());
    }

    public function testCombinationIdZeroSkipsCombinationImpact(): void
    {
        $provider = new MockProductProvider(
            basePrices: [1 => '50'],
            combinationImpacts: ['1-0' => '10']
        );
        $calculator = new BaseProductCalculator($provider);
        $productPrice = ProductPrice::create(1, 0);

        $calculator->compute($productPrice);

        // combinationId = 0 should not apply impact
        $this->assertTrue(
            $productPrice->getUnitPrice()->getTaxExcluded()->equals(new DecimalNumber('50'))
        );
    }
}
