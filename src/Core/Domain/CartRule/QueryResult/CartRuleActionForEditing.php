<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

class CartRuleActionForEditing
{
    /**
     * @var bool
     */
    private $freeShipping;

    /**
     * @var CartRuleReductionForEditing
     */
    private $reduction;

    /**
     * @var int|null
     */
    private $giftProductId;

    /**
     * @var int|null
     */
    private $giftCombinationId;

    /**
     * @var string
     */
    private $discountApplicationType;

    public function __construct(
        bool $freeShipping,
        CartRuleReductionForEditing $reduction,
        string $discountApplicationType,
        ?int $giftProductId,
        ?int $giftCombinationId
    ) {
        $this->freeShipping = $freeShipping;
        $this->reduction = $reduction;
        $this->discountApplicationType = $discountApplicationType;
        $this->giftProductId = $giftProductId;
        $this->giftCombinationId = $giftCombinationId;
    }

    /**
     * @return bool
     */
    public function isFreeShipping(): bool
    {
        return $this->freeShipping;
    }

    /**
     * @return CartRuleReductionForEditing
     */
    public function getReduction(): CartRuleReductionForEditing
    {
        return $this->reduction;
    }

    /**
     * @return int|null
     */
    public function getGiftProductId(): ?int
    {
        return $this->giftProductId;
    }

    /**
     * @return int|null
     */
    public function getGiftCombinationId(): ?int
    {
        return $this->giftCombinationId;
    }

    /**
     * @return string
     */
    public function getDiscountApplicationType(): string
    {
        return $this->discountApplicationType;
    }
}
