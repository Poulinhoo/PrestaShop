<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Adapter\CartRule\AbstractCartRuleHandler;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\BulkDeleteCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler\BulkDeleteCartRuleHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\BulkDeleteCartRuleException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleException;

/**
 * Deletes cart rules in bulk action using legacy object model
 */
#[AsCommandHandler]
final class BulkDeleteCartRuleHandler extends AbstractCartRuleHandler implements BulkDeleteCartRuleHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle(BulkDeleteCartRuleCommand $command): void
    {
        $errors = [];

        foreach ($command->getCartRuleIds() as $cartRuleId) {
            try {
                $cartRule = $this->getCartRule($cartRuleId);

                if (!$this->deleteCartRule($cartRule)) {
                    $errors[] = $cartRuleId->getValue();
                }
            } catch (CartRuleException) {
                $errors[] = $cartRuleId->getValue();
            }
        }

        if (!empty($errors)) {
            throw new BulkDeleteCartRuleException($errors, 'Failed to delete all of selected cart rules');
        }
    }
}
