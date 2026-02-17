<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;

class CartRuleAction
{
    /**
     * @var bool
     */
    private $freeShipping;

    /**
     * @var GiftProduct|null
     */
    private $giftProduct;

    /**
     * @var Discount|null
     */
    private $discount;

    public function __construct(
        bool $freeShipping,
        ?GiftProduct $giftProduct = null,
        ?Discount $discount = null
    ) {
        if ($freeShipping || $giftProduct || $discount) {
            $this->freeShipping = $freeShipping;
            $this->giftProduct = $giftProduct;
            $this->discount = $discount;

            return;
        }

        throw new CartRuleConstraintException(
            'Cart rule must have at least one action',
            CartRuleConstraintException::MISSING_ACTION
        );
    }

    public function isFreeShipping(): bool
    {
        return $this->freeShipping;
    }

    public function getGiftProduct(): ?GiftProduct
    {
        return $this->giftProduct;
    }

    public function getDiscount(): ?Discount
    {
        return $this->discount;
    }
}
