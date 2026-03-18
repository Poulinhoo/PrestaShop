<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\ValueObject;

use PrestaShop\Decimal\DecimalNumber;

final class TaxRate
{
    public function __construct(
        private readonly DecimalNumber $rate,
    ) {
        if ($rate->isLowerThan(new DecimalNumber('0'))) {
            throw new \InvalidArgumentException('Tax rate must be greater than or equal to 0');
        }
    }

    public static function zero(): self
    {
        return new self(new DecimalNumber('0'));
    }

    public function getRate(): DecimalNumber
    {
        return $this->rate;
    }

    /**
     * Returns 1 + rate/100 (e.g. 1.2 for a 20% tax rate).
     */
    public function getMultiplier(): DecimalNumber
    {
        return (new DecimalNumber('1'))->plus(
            $this->rate->dividedBy(new DecimalNumber('100'), 20)
        );
    }

    /**
     * Computes the tax amount: taxExcluded * rate / 100.
     */
    public function computeTaxAmount(DecimalNumber $taxExcluded): DecimalNumber
    {
        return $taxExcluded->times($this->rate)->dividedBy(new DecimalNumber('100'), 20);
    }
}
