<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Debug;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;

final class PricingRegistry
{
    /** @var ProductPriceInterface[] */
    private array $productPrices = [];

    public function registerProductPrice(ProductPriceInterface $productPrice): void
    {
        $this->productPrices[] = $productPrice;
    }

    /**
     * @return ProductPriceInterface[]
     */
    public function getProductPrices(): array
    {
        return $this->productPrices;
    }

    public function count(): int
    {
        return count($this->productPrices);
    }

    public function clear(): void
    {
        $this->productPrices = [];
    }
}
