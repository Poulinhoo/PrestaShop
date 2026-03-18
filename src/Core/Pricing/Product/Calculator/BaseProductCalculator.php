<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;
use PrestaShop\PrestaShop\Core\Pricing\Product\Provider\ProductProviderInterface;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxRate;

final class BaseProductCalculator implements ProductCalculatorInterface
{
    public function __construct(
        private readonly ProductProviderInterface $productProvider,
    ) {
    }

    public function compute(ProductPriceInterface $productPrice): void
    {
        $basePrice = $this->productProvider->getBasePrice($productPrice->getProductId());
        $unitPrice = new TaxablePrice($basePrice, TaxRate::zero());

        $productPrice->setUnitPrice($unitPrice);
        $productPrice->setOriginalPrice(new TaxablePrice($basePrice, TaxRate::zero()));

        if ($productPrice->getCombinationId() > 0) {
            $impact = $this->productProvider->getCombinationPriceImpact(
                $productPrice->getProductId(),
                $productPrice->getCombinationId()
            );
            $productPrice->setUnitPrice(new TaxablePrice($basePrice->plus($impact), TaxRate::zero()));
        }

        // Total = unit price (no quantity multiplication in Phase 1, PriceContext deferred)
        $productPrice->setTotalPrice(new TaxablePrice(
            $productPrice->getUnitPrice()->getTaxExcluded(),
            TaxRate::zero()
        ));
    }
}
