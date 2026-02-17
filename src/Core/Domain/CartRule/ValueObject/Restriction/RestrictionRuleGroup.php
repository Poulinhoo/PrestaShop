<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\Restriction;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;

class RestrictionRuleGroup
{
    private int $requiredQuantityInCart;

    /**
     * @var RestrictionRule[]
     */
    private array $restrictionRules;

    /**
     * @param int $requiredQuantityInCart
     * @param RestrictionRule[] $restrictionRules
     */
    public function __construct(
        int $requiredQuantityInCart,
        array $restrictionRules
    ) {
        $this->assertQuantityIsNotNegative($requiredQuantityInCart);
        $this->assertRestrictionRules($restrictionRules);
        $this->requiredQuantityInCart = $requiredQuantityInCart;
        $this->restrictionRules = $restrictionRules;
    }

    /**
     * @return int
     */
    public function getRequiredQuantityInCart(): int
    {
        return $this->requiredQuantityInCart;
    }

    /**
     * @return RestrictionRule[]
     */
    public function getRestrictionRules(): array
    {
        return $this->restrictionRules;
    }

    private function assertQuantityIsNotNegative(int $quantity): void
    {
        if ($quantity < 0) {
            throw new CartRuleConstraintException(
                'Restrictions required quantity in cart cannot be negative',
                CartRuleConstraintException::INVALID_QUANTITY
            );
        }
    }

    /**
     * @param RestrictionRule[] $rules
     *
     * @return void
     *
     * @throws CartRuleConstraintException
     */
    private function assertRestrictionRules(array $rules): void
    {
        if (empty($rules)) {
            throw new CartRuleConstraintException(
                'Restriction rules list cannot be empty',
                CartRuleConstraintException::EMPTY_RESTRICTION_RULES
            );
        }
    }
}
