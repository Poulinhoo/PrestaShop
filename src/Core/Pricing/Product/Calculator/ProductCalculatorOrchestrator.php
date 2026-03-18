<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;

final class ProductCalculatorOrchestrator
{
    /**
     * @param iterable<ProductCalculatorInterface> $calculators Tagged iterator, priority-sorted
     */
    public function __construct(
        private readonly iterable $calculators,
    ) {
    }

    public function compute(ProductPriceInterface $productPrice): ProductPriceInterface
    {
        foreach ($this->calculators as $calculator) {
            $calculator->compute($productPrice);
        }

        return $productPrice;
    }
}
