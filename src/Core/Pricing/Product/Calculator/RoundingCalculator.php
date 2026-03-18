<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;
use PrestaShop\PrestaShop\Core\Pricing\Rounding\RoundingServiceInterface;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxRate;

final class RoundingCalculator implements ProductCalculatorInterface
{
    public function __construct(
        private readonly RoundingServiceInterface $roundingService,
    ) {
    }

    public function compute(ProductPriceInterface $productPrice): void
    {
        $productPrice->setUnitPrice($this->roundTaxablePrice($productPrice->getUnitPrice()));
        $productPrice->setTotalPrice($this->roundTaxablePrice($productPrice->getTotalPrice()));
        $productPrice->setOriginalPrice($this->roundTaxablePrice($productPrice->getOriginalPrice()));
    }

    private function roundTaxablePrice(TaxablePrice $price): TaxablePrice
    {
        $roundedTaxExcluded = $this->roundingService->round($price->getTaxExcluded());
        $roundedTaxIncluded = $this->roundingService->round($price->getTaxIncluded());

        $rounded = new TaxablePrice($roundedTaxExcluded, $price->getTaxRate());
        // Override the computed tax-included with independently rounded value
        $rounded->setTaxIncluded($roundedTaxIncluded);

        return $rounded;
    }
}
