<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule\CommandHandler;

use CartRule;
use PrestaShop\PrestaShop\Adapter\CartRule\Repository\CartRuleRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ValueObject\CarrierId;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\SetCartRuleRestrictionsCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler\SetCartRuleRestrictionsHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;
use PrestaShop\PrestaShop\Core\Domain\Country\ValueObject\CountryId;
use PrestaShop\PrestaShop\Core\Domain\Customer\Group\ValueObject\GroupId;

#[AsCommandHandler]
class SetCartRuleRestrictionsHandler implements SetCartRuleRestrictionsHandlerInterface
{
    public function __construct(
        protected readonly CartRuleRepository $cartRuleRepository
    ) {
    }

    public function handle(SetCartRuleRestrictionsCommand $command): void
    {
        if (null === $command->getRestrictedCartRuleIds()
            && null === $command->getProductRestrictionRuleGroups()
            && null === $command->getRestrictedCarrierIds()
            && null === $command->getRestrictedCountryIds()
            && null === $command->getRestrictedGroupIds()
        ) {
            // no restrictions were modified
            return;
        }

        $cartRule = $this->cartRuleRepository->get($command->getCartRuleId());

        $restrictedCartRuleIds = $command->getRestrictedCartRuleIds();
        if (null !== $restrictedCartRuleIds) {
            $this->setCartRuleRestrictions($cartRule, $restrictedCartRuleIds);
        }
        $productRestrictionGroups = $command->getProductRestrictionRuleGroups();
        if (null !== $productRestrictionGroups) {
            $this->setProductRestrictions($cartRule, $productRestrictionGroups);
        }
        $restrictedCarrierIds = $command->getRestrictedCarrierIds();
        if (null !== $restrictedCarrierIds) {
            $this->setCarrierRestrictions($cartRule, $restrictedCarrierIds);
        }
        $restrictedCountryIds = $command->getRestrictedCountryIds();
        if (null !== $restrictedCountryIds) {
            $this->setCountryRestrictions($cartRule, $restrictedCountryIds);
        }
        $restrictedGroupIds = $command->getRestrictedGroupIds();
        if (null !== $restrictedGroupIds) {
            $this->setGroupRestrictions($cartRule, $restrictedGroupIds);
        }
        // it would be more performant updating all restriction props at the end with one update call,
        // but that way we might introduce cart rule state failure in case one of steps fails somewhere in the middle
    }

    private function setCartRuleRestrictions(CartRule $cartRule, array $restrictedCartRuleIds): void
    {
        $cartRuleId = new CartRuleId((int) $cartRule->id);
        $this->cartRuleRepository->assertAllCartRulesExists($restrictedCartRuleIds);
        $this->cartRuleRepository->restrictCartRules($cartRuleId, $restrictedCartRuleIds);
        $hasRestrictions = !empty($restrictedCartRuleIds);

        $cartRule->cart_rule_restriction = $hasRestrictions;
        $this->cartRuleRepository->partialUpdate($cartRule, ['cart_rule_restriction']);

        // update cart_rule_restriction property for all the cart rules that have been affected
        foreach ($this->cartRuleRepository->getRestrictedCartRuleIds($cartRuleId) as $restrictedCartRuleId) {
            $affectedCartRule = $this->cartRuleRepository->get(new CartRuleId($restrictedCartRuleId));
            $affectedCartRule->cart_rule_restriction = $hasRestrictions;
            $this->cartRuleRepository->partialUpdate($affectedCartRule, ['cart_rule_restriction']);
        }
    }

    private function setProductRestrictions(CartRule $cartRule, array $restrictionRuleGroups): void
    {
        $this->cartRuleRepository->setProductRestrictions(new CartRuleId((int) $cartRule->id), $restrictionRuleGroups);

        $cartRule->product_restriction = !empty($restrictionRuleGroups);
        $this->cartRuleRepository->partialUpdate($cartRule, ['product_restriction']);
    }

    /**
     * @param CartRule $cartRule
     * @param CarrierId[] $restrictedCarrierIds
     *
     * @return void
     */
    private function setCarrierRestrictions(CartRule $cartRule, array $restrictedCarrierIds): void
    {
        $this->cartRuleRepository->setCarrierRestrictions(new CartRuleId((int) $cartRule->id), $restrictedCarrierIds);

        $cartRule->carrier_restriction = !empty($restrictedCarrierIds);
        $this->cartRuleRepository->partialUpdate($cartRule, ['carrier_restriction']);
    }

    /**
     * @param CartRule $cartRule
     * @param CountryId[] $restrictedCountryIds
     *
     * @return void
     */
    private function setCountryRestrictions(CartRule $cartRule, array $restrictedCountryIds): void
    {
        $this->cartRuleRepository->setCountryRestrictions(new CartRuleId((int) $cartRule->id), $restrictedCountryIds);

        $cartRule->country_restriction = !empty($restrictedCountryIds);
        $this->cartRuleRepository->partialUpdate($cartRule, ['country_restriction']);
    }

    /**
     * @param GroupId[] $restrictedGroupIds
     *
     * @return void
     */
    private function setGroupRestrictions(CartRule $cartRule, array $restrictedGroupIds): void
    {
        $this->cartRuleRepository->setGroupRestrictions(new CartRuleId((int) $cartRule->id), $restrictedGroupIds);

        $cartRule->group_restriction = !empty($restrictedGroupIds);
        $this->cartRuleRepository->partialUpdate($cartRule, ['group_restriction']);
    }
}
