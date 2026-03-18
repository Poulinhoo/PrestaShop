<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\Pricing\Product\Calculator;

use PHPUnit\Framework\TestCase;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\ProductCalculatorInterface;
use PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\ProductCalculatorOrchestrator;
use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPrice;
use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxRate;

class ProductCalculatorOrchestratorTest extends TestCase
{
    public function testIteratesCalculatorsInOrder(): void
    {
        $executionOrder = [];

        $calculator1 = $this->createCalculator(function () use (&$executionOrder) {
            $executionOrder[] = 'first';
        });
        $calculator2 = $this->createCalculator(function () use (&$executionOrder) {
            $executionOrder[] = 'second';
        });

        $orchestrator = new ProductCalculatorOrchestrator([$calculator1, $calculator2]);
        $productPrice = ProductPrice::create(1, 0);

        $orchestrator->compute($productPrice);

        $this->assertSame(['first', 'second'], $executionOrder);
    }

    public function testReturnsTheSameProductPrice(): void
    {
        $orchestrator = new ProductCalculatorOrchestrator([]);
        $productPrice = ProductPrice::create(1, 0);

        $result = $orchestrator->compute($productPrice);

        $this->assertSame($productPrice, $result);
    }

    public function testCalculatorsMutateProductPrice(): void
    {
        $calculator = $this->createCalculator(function (ProductPriceInterface $pp) {
            $pp->setUnitPrice(new TaxablePrice(new DecimalNumber('42'), TaxRate::zero()));
        });

        $orchestrator = new ProductCalculatorOrchestrator([$calculator]);
        $productPrice = ProductPrice::create(1, 0);

        $orchestrator->compute($productPrice);

        $this->assertTrue($productPrice->getUnitPrice()->getTaxExcluded()->equals(new DecimalNumber('42')));
    }

    private function createCalculator(callable $callback): ProductCalculatorInterface
    {
        return new class($callback) implements ProductCalculatorInterface {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function compute(ProductPriceInterface $productPrice): void
            {
                ($this->callback)($productPrice);
            }
        };
    }
}
