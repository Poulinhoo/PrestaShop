<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Adapter\CartRule\AbstractCartRuleHandler;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\ToggleCartRuleStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler\ToggleCartRuleStatusHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\UpdateCartRuleException;

/**
 * Handles command which toggles cart rule status
 */
#[AsCommandHandler]
final class ToggleCartRuleStatusHandler extends AbstractCartRuleHandler implements ToggleCartRuleStatusHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle(ToggleCartRuleStatusCommand $command): void
    {
        $cartRule = $this->getCartRule($command->getCartRuleId());

        if (!$this->toggleCartRuleStatus($cartRule, $command->getExpectedStatus())) {
            throw new UpdateCartRuleException(
                sprintf(
                    'Unable to toggle cart rule status with id "%s"',
                    $cartRule->id
                ),
                UpdateCartRuleException::FAILED_UPDATE_STATUS
            );
        }
    }
}
