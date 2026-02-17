<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

use PrestaShop\Decimal\DecimalNumber;

class CartRuleMinimumForEditing
{
    /**
     * @var DecimalNumber
     */
    private $amount;

    /**
     * @var bool
     */
    private $amountTax;

    /**
     * @var int
     */
    private $currencyId;

    /**
     * @var bool
     */
    private $shipping;

    public function __construct(
        DecimalNumber $amount,
        bool $amountTax,
        int $currencyId,
        bool $shipping
    ) {
        $this->amount = $amount;
        $this->amountTax = $amountTax;
        $this->currencyId = $currencyId;
        $this->shipping = $shipping;
    }

    /**
     * @return DecimalNumber
     */
    public function getAmount(): DecimalNumber
    {
        return $this->amount;
    }

    /**
     * @return bool
     */
    public function isAmountTax(): bool
    {
        return $this->amountTax;
    }

    /**
     * @return int
     */
    public function getCurrencyId(): int
    {
        return $this->currencyId;
    }

    /**
     * @return bool
     */
    public function isShipping(): bool
    {
        return $this->shipping;
    }
}
