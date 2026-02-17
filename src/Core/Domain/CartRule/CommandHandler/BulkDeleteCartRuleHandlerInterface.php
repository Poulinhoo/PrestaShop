<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\BulkDeleteCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleException;

/**
 * Defines contract for BulkDeleteCartRuleHandler
 */
interface BulkDeleteCartRuleHandlerInterface
{
    /**
     * @param BulkDeleteCartRuleCommand $command
     *
     * @throws CartRuleException
     */
    public function handle(BulkDeleteCartRuleCommand $command): void;
}
