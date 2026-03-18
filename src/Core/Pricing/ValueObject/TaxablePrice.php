<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\ValueObject;

use PrestaShop\Decimal\DecimalNumber;

final class TaxablePrice
{
    private DecimalNumber $taxExcluded;
    private DecimalNumber $taxIncluded;
    private DecimalNumber $taxAmount;
    private TaxRate $taxRate;

    /**
     * Primary constructor: derives taxIncluded from taxExcluded * taxRate multiplier.
     */
    public function __construct(DecimalNumber $taxExcluded, TaxRate $taxRate)
    {
        $this->taxExcluded = $taxExcluded;
        $this->taxRate = $taxRate;
        $this->taxAmount = $taxRate->computeTaxAmount($taxExcluded);
        $this->taxIncluded = $taxExcluded->times($taxRate->getMultiplier());
    }

    /**
     * Reverse constructor: derives taxExcluded from taxIncluded / taxRate multiplier.
     */
    public static function fromTaxIncluded(DecimalNumber $taxIncluded, TaxRate $taxRate): self
    {
        $taxExcluded = $taxIncluded->dividedBy($taxRate->getMultiplier(), 20);
        $instance = new self($taxExcluded, $taxRate);
        // Override taxIncluded with the exact value provided
        $instance->taxIncluded = $taxIncluded;
        $instance->taxAmount = $taxIncluded->minus($taxExcluded);

        return $instance;
    }

    public static function zero(): self
    {
        return new self(new DecimalNumber('0'), TaxRate::zero());
    }

    public function getTaxExcluded(): DecimalNumber
    {
        return $this->taxExcluded;
    }

    public function getTaxIncluded(): DecimalNumber
    {
        return $this->taxIncluded;
    }

    public function getTaxAmount(): DecimalNumber
    {
        return $this->taxAmount;
    }

    public function getTaxRate(): TaxRate
    {
        return $this->taxRate;
    }

    /**
     * Sets tax-excluded and recomputes tax-included and tax amount.
     */
    public function setTaxExcluded(DecimalNumber $taxExcluded): void
    {
        $this->taxExcluded = $taxExcluded;
        $this->taxAmount = $this->taxRate->computeTaxAmount($taxExcluded);
        $this->taxIncluded = $taxExcluded->times($this->taxRate->getMultiplier());
    }

    /**
     * Sets tax-included and recomputes tax-excluded and tax amount.
     */
    public function setTaxIncluded(DecimalNumber $taxIncluded): void
    {
        $this->taxIncluded = $taxIncluded;
        $this->taxExcluded = $taxIncluded->dividedBy($this->taxRate->getMultiplier(), 20);
        $this->taxAmount = $taxIncluded->minus($this->taxExcluded);
    }

    /**
     * Sets the tax rate and recomputes tax-included and tax amount from tax-excluded (source of truth).
     */
    public function setTaxRate(TaxRate $taxRate): void
    {
        $this->taxRate = $taxRate;
        $this->taxAmount = $taxRate->computeTaxAmount($this->taxExcluded);
        $this->taxIncluded = $this->taxExcluded->times($taxRate->getMultiplier());
    }
}
