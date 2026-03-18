<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;

interface ProductCalculatorInterface
{
    /**
     * Applies a pricing computation step to the ProductPrice, mutating it in place.
     * Returns early when not relevant for the current context.
     */
    public function compute(ProductPriceInterface $productPrice): void;
}
