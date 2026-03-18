<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product;

use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;

final class ProductPrice implements ProductPriceInterface
{
    private TaxablePrice $unitPrice;
    private TaxablePrice $totalPrice;
    private TaxablePrice $originalPrice;

    private function __construct(
        private readonly int $productId,
        private readonly int $combinationId,
    ) {
        $this->unitPrice = TaxablePrice::zero();
        $this->totalPrice = TaxablePrice::zero();
        $this->originalPrice = TaxablePrice::zero();
    }

    public static function create(int $productId, int $combinationId): self
    {
        return new self($productId, $combinationId);
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getCombinationId(): int
    {
        return $this->combinationId;
    }

    public function getUnitPrice(): TaxablePrice
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(TaxablePrice $unitPrice): void
    {
        $this->unitPrice = $unitPrice;
    }

    public function getTotalPrice(): TaxablePrice
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(TaxablePrice $totalPrice): void
    {
        $this->totalPrice = $totalPrice;
    }

    public function getOriginalPrice(): TaxablePrice
    {
        return $this->originalPrice;
    }

    public function setOriginalPrice(TaxablePrice $originalPrice): void
    {
        $this->originalPrice = $originalPrice;
    }
}
