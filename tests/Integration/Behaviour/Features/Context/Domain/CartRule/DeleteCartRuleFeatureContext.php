<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Behaviour\Features\Context\Domain\CartRule;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\BulkDeleteCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\DeleteCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;

class DeleteCartRuleFeatureContext extends AbstractCartRuleFeatureContext
{
    /**
     * @When I delete Cart rule with reference :cartRuleReference
     *
     * @param string $cartRuleReference
     *
     * @throws CartRuleConstraintException
     */
    public function deleteCartRule(string $cartRuleReference): void
    {
        $this->getCommandBus()->handle(
            new DeleteCartRuleCommand($this->getSharedStorage()->get($cartRuleReference))
        );
    }

    /**
     * @When I bulk delete cart rules :cartRuleReferences
     *
     * @param string $cartRuleReferences
     *
     * @throws CartRuleConstraintException
     */
    public function bulkDeleteCartRules(string $cartRuleReferences): void
    {
        $this->getCommandBus()->handle(new BulkDeleteCartRuleCommand($this->referencesToIds($cartRuleReferences)));
    }
}
