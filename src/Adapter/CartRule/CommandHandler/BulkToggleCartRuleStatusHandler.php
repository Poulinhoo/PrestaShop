<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Adapter\CartRule\AbstractCartRuleHandler;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\BulkToggleCartRuleStatusCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler\BulkToggleCartRuleStatusHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\BulkToggleCartRuleException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleException;

/**
 * Handles command which toggles cart rule status in bulk action
 */
#[AsCommandHandler]
final class BulkToggleCartRuleStatusHandler extends AbstractCartRuleHandler implements BulkToggleCartRuleStatusHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle(BulkToggleCartRuleStatusCommand $command): void
    {
        $errors = [];

        foreach ($command->getCartRuleIds() as $cartRuleId) {
            try {
                $cartRule = $this->getCartRule($cartRuleId);

                if (!$this->toggleCartRuleStatus($cartRule, $command->getExpectedStatus())) {
                    $errors[] = $cartRuleId->getValue();
                }
            } catch (CartRuleException) {
                $errors[] = $cartRuleId->getValue();
            }
        }

        if (!empty($errors)) {
            throw new BulkToggleCartRuleException($errors, 'Failed to toggle all of selected cart rules');
        }
    }
}
