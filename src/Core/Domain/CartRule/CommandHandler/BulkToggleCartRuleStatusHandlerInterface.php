<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\BulkToggleCartRuleStatusCommand;

/**
 * Defines contract for BulkToggleCartRuleStatusHandler
 */
interface BulkToggleCartRuleStatusHandlerInterface
{
    /**
     * @param BulkToggleCartRuleStatusCommand $command
     */
    public function handle(BulkToggleCartRuleStatusCommand $command): void;
}
