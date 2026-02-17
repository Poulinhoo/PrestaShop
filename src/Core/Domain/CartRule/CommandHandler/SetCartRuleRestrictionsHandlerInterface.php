<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\SetCartRuleRestrictionsCommand;

interface SetCartRuleRestrictionsHandlerInterface
{
    public function handle(SetCartRuleRestrictionsCommand $command): void;
}
