<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

use PrestaShop\Decimal\DecimalNumber;

class CartRuleReductionForEditing
{
    /**
     * @var DecimalNumber
     */
    private $percent;

    /**
     * @var DecimalNumber
     */
    private $amount;

    /**
     * @var bool
     */
    private $tax;

    /**
     * @var int|null
     */
    private $currencyId;

    /**
     * @var int|null
     */
    private $productId;

    /**
     * @var bool
     */
    private $applyToDiscountedProducts;

    public function __construct(
        DecimalNumber $percent,
        DecimalNumber $amount,
        bool $tax,
        ?int $currencyId,
        ?int $productId,
        bool $applyToDiscountedProducts
    ) {
        $this->percent = $percent;
        $this->amount = $amount;
        $this->tax = $tax;
        $this->currencyId = $currencyId;
        $this->productId = $productId;
        $this->applyToDiscountedProducts = $applyToDiscountedProducts;
    }

    /**
     * @return DecimalNumber
     */
    public function getPercent(): DecimalNumber
    {
        return $this->percent;
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
    public function isTax(): bool
    {
        return $this->tax;
    }

    /**
     * @return int|null
     */
    public function getCurrencyId(): ?int
    {
        return $this->currencyId;
    }

    /**
     * @return int|null
     */
    public function getProductId(): ?int
    {
        return $this->productId;
    }

    /**
     * @return bool
     */
    public function applyToDiscountedProducts(): bool
    {
        return $this->applyToDiscountedProducts;
    }
}
