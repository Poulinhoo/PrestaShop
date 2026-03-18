<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product;

use PrestaShop\PrestaShop\Core\Pricing\ValueObject\PriceBreakdown;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\PriceModification;
use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;

final class TrackedProductPrice implements ProductPriceInterface
{
    private TaxablePrice $unitPrice;
    private TaxablePrice $totalPrice;
    private TaxablePrice $originalPrice;
    private PriceBreakdown $breakdown;

    private function __construct(
        private readonly int $productId,
        private readonly int $combinationId,
    ) {
        $this->unitPrice = TaxablePrice::zero();
        $this->totalPrice = TaxablePrice::zero();
        $this->originalPrice = TaxablePrice::zero();
        $this->breakdown = new PriceBreakdown();
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
        $this->recordModification('unitPrice', $this->unitPrice, $unitPrice);
        $this->unitPrice = $unitPrice;
    }

    public function getTotalPrice(): TaxablePrice
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(TaxablePrice $totalPrice): void
    {
        $this->recordModification('totalPrice', $this->totalPrice, $totalPrice);
        $this->totalPrice = $totalPrice;
    }

    public function getOriginalPrice(): TaxablePrice
    {
        return $this->originalPrice;
    }

    public function setOriginalPrice(TaxablePrice $originalPrice): void
    {
        $this->recordModification('originalPrice', $this->originalPrice, $originalPrice);
        $this->originalPrice = $originalPrice;
    }

    public function getBreakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }

    private function recordModification(string $property, TaxablePrice $previous, TaxablePrice $new): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2] ?? [];

        $this->breakdown->addStep(new PriceModification(
            callerClass: $caller['class'] ?? 'unknown',
            callerLine: $caller['line'] ?? 0,
            property: $property,
            previousValue: (string) $previous->getTaxExcluded(),
            newValue: (string) $new->getTaxExcluded(),
        ));
    }
}
