<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\AddCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;

/**
 * Interface for service that handles adding new cart rule.
 */
interface AddCartRuleHandlerInterface
{
    /**
     * @param AddCartRuleCommand $command
     *
     * @return CartRuleId
     */
    public function handle(AddCartRuleCommand $command): CartRuleId;
}
