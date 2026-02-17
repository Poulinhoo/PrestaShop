<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule\CommandHandler;

use PrestaShop\PrestaShop\Adapter\CartRule\AbstractCartRuleHandler;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\DeleteCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler\DeleteCartRuleHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CannotDeleteCartRuleException;

/**
 * Handles deletion of cart rule using legacy object model
 */
#[AsCommandHandler]
final class DeleteCartRuleHandler extends AbstractCartRuleHandler implements DeleteCartRuleHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle(DeleteCartRuleCommand $command): void
    {
        $cartRuleId = $command->getCartRuleId();
        $cartRule = $this->getCartRule($cartRuleId);

        if (!$this->deleteCartRule($cartRule)) {
            throw new CannotDeleteCartRuleException(
                sprintf(
                    'Cannot delete SpecificPriceRule object with id "%s".',
                    $cartRuleId->getValue()
                ),
                CannotDeleteCartRuleException::FAILED_DELETE
            );
        }
    }
}
