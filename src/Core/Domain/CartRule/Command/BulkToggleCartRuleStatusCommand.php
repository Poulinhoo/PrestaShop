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
 * Toggles cart rule status in bulk action
 */
class BulkToggleCartRuleStatusCommand
{
    /**
     * @var bool
     */
    private $expectedStatus;

    /**
     * @var CartRuleId[]
     */
    private $cartRuleIds;

    /**
     * @param int[] $cartRuleIds
     * @param bool $expectedStatus
     *
     * @throws CartRuleConstraintException
     */
    public function __construct(array $cartRuleIds, bool $expectedStatus)
    {
        $this->expectedStatus = $expectedStatus;
        $this->setCartRuleIds($cartRuleIds);
    }

    /**
     * @return bool
     */
    public function getExpectedStatus(): bool
    {
        return $this->expectedStatus;
    }

    /**
     * @return CartRuleId[]
     */
    public function getCartRuleIds(): array
    {
        return $this->cartRuleIds;
    }

    /**
     * @param int[] $cartRuleIds
     *
     * @throws CartRuleConstraintException
     */
    private function setCartRuleIds(array $cartRuleIds): void
    {
        foreach ($cartRuleIds as $cartRuleId) {
            $this->cartRuleIds[] = new CartRuleId($cartRuleId);
        }
    }
}
