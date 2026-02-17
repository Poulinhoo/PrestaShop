<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject;

use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Money;

/**
 * Represents a reduction value for a cart rule. It can reduce certain percentage of price or a specific amount of Money.
 * Both percentage and amount discounts has a type, which indicates what the discount should be applied to.
 *
 * @see Money
 * @see PercentageDiscount
 * @see DiscountApplicationType
 */
class Discount
{
    /**
     * @var DiscountApplicationType
     */
    private $discountApplicationType;

    /**
     * @var Money|null
     */
    private $amountDiscount;

    /**
     * @var PercentageDiscount|null
     */
    private $percentageDiscount;

    /**
     * Static factory method to build amount reduction type discount
     *
     * @param Money $amountDiscount
     * @param DiscountApplicationType $discountApplicationType
     *
     * @return self
     *
     * @throws CartRuleConstraintException
     */
    public static function buildAmountDiscount(
        Money $amountDiscount,
        DiscountApplicationType $discountApplicationType
    ): self {
        $unsupportedTypeMessages = [
            DiscountApplicationType::CHEAPEST_PRODUCT => 'Cart rule, which is applied to cheapest product, cannot be applied to amount discount type.',
            DiscountApplicationType::SELECTED_PRODUCTS => 'Cart rule, which is applied to selected products, cannot be applied to amount discount type.',
        ];

        if (isset($unsupportedTypeMessages[$discountApplicationType->getType()])) {
            throw new CartRuleConstraintException(
                $unsupportedTypeMessages[$discountApplicationType->getType()],
                CartRuleConstraintException::INVALID_DISCOUNT_APPLICATION_TYPE
            );
        }

        return new self(
            $discountApplicationType,
            $amountDiscount,
            null
        );
    }

    /**
     * Static factory method to build percentage reduction type discount
     *
     * @param DecimalNumber $reductionValue
     * @param bool $applyToDiscountedProducts
     * @param DiscountApplicationType $discountApplicationType
     *
     * @return self
     *
     * @throws CartRuleConstraintException
     */
    public static function buildPercentageDiscount(
        DecimalNumber $reductionValue,
        bool $applyToDiscountedProducts,
        DiscountApplicationType $discountApplicationType
    ): self {
        return new self(
            $discountApplicationType,
            null,
            new PercentageDiscount(
                $reductionValue,
                $applyToDiscountedProducts
            )
        );
    }

    public function getDiscountApplicationType(): DiscountApplicationType
    {
        return $this->discountApplicationType;
    }

    public function getAmountDiscount(): ?Money
    {
        return $this->amountDiscount;
    }

    public function getPercentageDiscount(): ?PercentageDiscount
    {
        return $this->percentageDiscount;
    }

    /**
     * Constructor is private intentionally. Use corresponding static method to build this class.
     *
     * @see buildAmountDiscount
     * @see buildPercentageDiscount
     *
     * @param DiscountApplicationType $discountApplicationType
     * @param Money|null $amountDiscount
     * @param PercentageDiscount|null $percentageDiscount
     *
     * @throws CartRuleConstraintException
     */
    private function __construct(
        DiscountApplicationType $discountApplicationType,
        ?Money $amountDiscount,
        ?PercentageDiscount $percentageDiscount
    ) {
        if (($amountDiscount && $percentageDiscount) || (!$amountDiscount && !$percentageDiscount)) {
            throw new CartRuleConstraintException(
                sprintf('Only one of the following must be set for %s: $amountDiscount or $percentageDiscount', self::class),
                CartRuleConstraintException::INVALID_PRICE_DISCOUNT
            );
        }

        $this->discountApplicationType = $discountApplicationType;
        $this->amountDiscount = $amountDiscount;
        $this->percentageDiscount = $percentageDiscount;
    }
}
