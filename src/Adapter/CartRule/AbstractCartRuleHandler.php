<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule;

use CartRule;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;
use PrestaShopException;

/**
 * Provides reusable methods for CartRule handlers
 */
abstract class AbstractCartRuleHandler
{
    /**
     * Gets legacy CartRule
     *
     * @param CartRuleId $cartRuleId
     *
     * @return CartRule
     *
     * @throws CartRuleException
     * @throws CartRuleNotFoundException
     */
    protected function getCartRule(CartRuleId $cartRuleId): CartRule
    {
        try {
            $cartRule = new CartRule($cartRuleId->getValue());
        } catch (PrestaShopException $e) {
            throw new CartRuleException('Failed to create new CartRule object', 0, $e);
        }

        if ($cartRule->id !== $cartRuleId->getValue()) {
            throw new CartRuleNotFoundException(sprintf('CartRule with id "%s" was not found.', $cartRuleId->getValue()));
        }

        return $cartRule;
    }

    /**
     * Deletes legacy CartRule
     *
     * @param CartRule $cartRule
     *
     * @return bool
     *
     * @throws CartRuleException
     */
    protected function deleteCartRule(CartRule $cartRule)
    {
        try {
            return $cartRule->delete();
        } catch (PrestaShopException) {
            throw new CartRuleException(sprintf('An error occurred when deleting CartRule object with id "%s".', $cartRule->id));
        }
    }

    /**
     * Toggles legacy cart rule status
     *
     * @param CartRule $cartRule
     * @param bool $newStatus
     *
     * @return bool
     *
     * @throws CartRuleException
     */
    protected function toggleCartRuleStatus(CartRule $cartRule, bool $newStatus): ?bool
    {
        $cartRule->active = $newStatus;

        try {
            return $cartRule->save();
        } catch (PrestaShopException) {
            throw new CartRuleException(sprintf('An error occurred when updating cart rule status with id "%s"', $cartRule->id));
        }
    }
}
