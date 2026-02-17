<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Command;

use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;

/**
 * Toggles cart rule status
 */
class ToggleCartRuleStatusCommand
{
    /**
     * @var CartRuleId
     */
    private $cartRuleId;

    /**
     * @var bool
     */
    private $expectedStatus;

    /**
     * @param int $cartRuleId
     * @param bool $expectedStatus
     */
    public function __construct(int $cartRuleId, bool $expectedStatus)
    {
        $this->expectedStatus = $expectedStatus;
        $this->cartRuleId = new CartRuleId($cartRuleId);
    }

    /**
     * @return CartRuleId
     */
    public function getCartRuleId(): CartRuleId
    {
        return $this->cartRuleId;
    }

    /**
     * @return bool
     */
    public function getExpectedStatus(): bool
    {
        return $this->expectedStatus;
    }
}
