<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Query;

use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;

/**
 * Gets cart rule for editing in Back Office
 */
class GetCartRuleForEditing
{
    public readonly CartRuleId $cartRuleId;

    public function __construct(int $cartRuleId)
    {
        $this->cartRuleId = new CartRuleId($cartRuleId);
    }
}
