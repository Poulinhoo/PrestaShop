<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Command;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;

/**
 * Deletes cart rule
 */
class DeleteCartRuleCommand
{
    /**
     * @var CartRuleId
     */
    private $cartRuleId;

    /**
     * @param int $cartRuleId
     *
     * @throws CartRuleConstraintException
     */
    public function __construct(int $cartRuleId)
    {
        $this->cartRuleId = new CartRuleId($cartRuleId);
    }

    /**
     * @return CartRuleId
     */
    public function getCartRuleId(): CartRuleId
    {
        return $this->cartRuleId;
    }
}
