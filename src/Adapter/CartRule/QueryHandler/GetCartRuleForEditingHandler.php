<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\CartRule\QueryHandler;

use CartRule;
use DateTime;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Adapter\CartRule\LegacyDiscountApplicationType;
use PrestaShop\PrestaShop\Adapter\CartRule\Repository\CartRuleRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsQueryHandler;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Query\GetCartRuleForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryHandler\GetCartRuleForEditingHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleActionForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleConditionsForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleInformationForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleMinimumForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleReductionForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleRestrictionsForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\DiscountApplicationType;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\Restriction\RestrictionRuleGroup;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\CustomerId;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\NoCustomerId;
use PrestaShop\PrestaShop\Core\Util\DateTime\DateTime as DateTimeUtils;

/**
 * Handles command which gets cart rule for editing using legacy object model
 */
#[AsQueryHandler]
class GetCartRuleForEditingHandler implements GetCartRuleForEditingHandlerInterface
{
    public function __construct(
        protected readonly CartRuleRepository $cartRuleRepository
    ) {
    }

    /**
     * @param GetCartRuleForEditing $query
     *
     * @return CartRuleForEditing
     */
    public function handle(GetCartRuleForEditing $query): CartRuleForEditing
    {
        $cartRuleId = $query->cartRuleId;
        $cartRule = $this->cartRuleRepository->get($cartRuleId);

        $cartRuleInformation = $this->getCartRuleInformation($cartRule);
        $cartRuleConditions = $this->getCartRuleConditions($cartRule);
        $cartRuleActions = $this->getCartRuleActions($cartRule);
        $dateAdd = $cartRule->date_add;
        $dateUpd = $cartRule->date_upd;

        return new CartRuleForEditing(
            $cartRuleId,
            $cartRuleInformation,
            $cartRuleConditions,
            $cartRuleActions,
            !DateTimeUtils::isNull($dateAdd) ? new DateTime($dateAdd) : null,
            !DateTimeUtils::isNull($dateUpd) ? new DateTime($dateUpd) : null
        );
    }

    private function getCartRuleInformation(CartRule $cartRule): CartRuleInformationForEditing
    {
        return new CartRuleInformationForEditing(
            $cartRule->name,
            $cartRule->description,
            $cartRule->code,
            (bool) $cartRule->highlight,
            (bool) $cartRule->partial_use,
            (int) $cartRule->priority,
            (bool) $cartRule->active
        );
    }

    private function getCartRuleConditions(CartRule $cartRule): CartRuleConditionsForEditing
    {
        $customerId = (int) $cartRule->id_customer !== NoCustomerId::NO_CUSTOMER_ID_VALUE ? new CustomerId((int) $cartRule->id_customer) : new NoCustomerId();
        $dateFrom = $cartRule->date_from;
        $dateTo = $cartRule->date_to;

        $cartRuleMinimum = null;
        if (!empty($cartRule->minimum_amount)) {
            $minimumAmount = new DecimalNumber($cartRule->minimum_amount);
            if (!$minimumAmount->equalsZero()) {
                $cartRuleMinimum = new CartRuleMinimumForEditing(
                    $minimumAmount,
                    (bool) $cartRule->minimum_amount_tax,
                    (int) $cartRule->minimum_amount_currency,
                    (bool) $cartRule->minimum_amount_shipping
                );
            }
        }

        $cartRuleId = new CartRuleId((int) $cartRule->id);

        $restrictedCartRules = [];
        if ($cartRule->cart_rule_restriction) {
            $restrictedCartRules = $this->cartRuleRepository->getRestrictedCartRuleIds($cartRuleId);
        }

        $restrictedCarrierIds = [];
        if ($cartRule->carrier_restriction) {
            $restrictedCarrierIds = $this->cartRuleRepository->getRestrictedCarrierIds($cartRuleId);
        }
        $restrictedCountryIds = [];
        if ($cartRule->country_restriction) {
            $restrictedCountryIds = $this->cartRuleRepository->getRestrictedCountryIds($cartRuleId);
        }
        $restrictedGroupIds = [];
        if ($cartRule->group_restriction) {
            $restrictedGroupIds = $this->cartRuleRepository->getRestrictedGroupIds($cartRuleId);
        }

        $cartRuleRestrictions = new CartRuleRestrictionsForEditing(
            $restrictedCartRules,
            $this->getRestrictionRuleGroups($cartRule),
            $restrictedCarrierIds,
            $restrictedCountryIds,
            $restrictedGroupIds
        );

        return new CartRuleConditionsForEditing(
            $customerId,
            !DateTimeUtils::isNull($dateFrom) ? new DateTime($dateFrom) : null,
            !DateTimeUtils::isNull($dateTo) ? new DateTime($dateTo) : null,
            (int) $cartRule->quantity,
            (int) $cartRule->quantity_per_user,
            $cartRuleMinimum,
            $cartRuleRestrictions
        );
    }

    /**
     * @param CartRule $cartRule
     *
     * @return RestrictionRuleGroup[]
     */
    private function getRestrictionRuleGroups(CartRule $cartRule): array
    {
        if (!$cartRule->product_restriction) {
            return [];
        }

        return $this->cartRuleRepository->getProductRestrictions(new CartRuleId((int) $cartRule->id));
    }

    private function getCartRuleActions(CartRule $cartRule): CartRuleActionForEditing
    {
        $discountApplicationType = $this->getDiscountApplicationType($cartRule);

        $discountProductId = null;
        if ($discountApplicationType === DiscountApplicationType::SPECIFIC_PRODUCT) {
            $discountProductId = (int) $cartRule->reduction_product;
        }

        $reduction = new CartRuleReductionForEditing(
            new DecimalNumber($cartRule->reduction_percent),
            new DecimalNumber($cartRule->reduction_amount),
            (bool) $cartRule->reduction_tax,
            (int) $cartRule->reduction_currency ?: null,
            $discountProductId,
            !$cartRule->reduction_exclude_special
        );

        return new CartRuleActionForEditing(
            (bool) $cartRule->free_shipping,
            $reduction,
            $this->getDiscountApplicationType($cartRule),
            (int) $cartRule->gift_product ?: null,
            (int) $cartRule->gift_product_attribute ?: null
        );
    }

    private function getDiscountApplicationType(CartRule $cartRule): string
    {
        $discountApplicationMap = [
            LegacyDiscountApplicationType::CHEAPEST_PRODUCT => DiscountApplicationType::CHEAPEST_PRODUCT,
            LegacyDiscountApplicationType::ORDER_WITHOUT_SHIPPING => DiscountApplicationType::ORDER_WITHOUT_SHIPPING,
            LegacyDiscountApplicationType::SELECTED_PRODUCTS => DiscountApplicationType::SELECTED_PRODUCTS,
        ];

        if (array_key_exists((int) $cartRule->reduction_product, $discountApplicationMap)) {
            return $discountApplicationMap[$cartRule->reduction_product];
        } else {
            return DiscountApplicationType::SPECIFIC_PRODUCT;
        }
    }
}
