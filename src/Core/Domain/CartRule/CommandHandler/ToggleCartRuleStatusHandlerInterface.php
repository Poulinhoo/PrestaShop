<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\ToggleCartRuleStatusCommand;

/**
 * Defines contract for ToggleCartRuleStatusHandler
 */
interface ToggleCartRuleStatusHandlerInterface
{
    /**
     * @param ToggleCartRuleStatusCommand $command
     */
    public function handle(ToggleCartRuleStatusCommand $command): void;
}
