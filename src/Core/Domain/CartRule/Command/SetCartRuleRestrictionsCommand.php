<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Command;

use PrestaShop\PrestaShop\Core\Domain\Carrier\ValueObject\CarrierId;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\Restriction\RestrictionRuleGroup;
use PrestaShop\PrestaShop\Core\Domain\Country\ValueObject\CountryId;
use PrestaShop\PrestaShop\Core\Domain\Customer\Group\ValueObject\GroupId;

/**
 * Sets cart rule restrictions.
 * Leaving property as NULL will cause no changes, however having an empty array will clear existing restrictions.
 */
class SetCartRuleRestrictionsCommand
{
    private readonly CartRuleId $cartRuleId;

    /**
     * @var CartRuleId[]|null
     */
    private ?array $restrictedCartRuleIds = null;

    /**
     * @var RestrictionRuleGroup[]|null
     */
    private ?array $productRestrictionRuleGroups = null;

    /**
     * @var CarrierId[]|null
     */
    private ?array $restrictedCarrierIds = null;

    /**
     * @var CountryId[]|null
     */
    private ?array $restrictedCountryIds = null;

    /**
     * @var GroupId[]|null
     */
    private ?array $restrictedGroupIds = null;

    /**
     * @param int $cartRuleId
     */
    public function __construct(
        int $cartRuleId,
    ) {
        $this->cartRuleId = new CartRuleId($cartRuleId);
    }

    public function setRestrictedCarrierIds(array $restrictedCarrierIds): self
    {
        $this->restrictedCarrierIds = array_map(static function ($carrierId): CarrierId {
            return new CarrierId($carrierId);
        }, $restrictedCarrierIds);

        return $this;
    }

    public function setRestrictedCountryIds(array $restrictedCountryIds): self
    {
        $this->restrictedCountryIds = array_map(static function ($countryId): CountryId {
            return new CountryId($countryId);
        }, $restrictedCountryIds);

        return $this;
    }

    public function setRestrictedGroupIds(array $restrictedGroupIds): self
    {
        $this->restrictedGroupIds = array_map(static function ($groupId): GroupId {
            return new GroupId($groupId);
        }, $restrictedGroupIds);

        return $this;
    }

    /**
     * @return CarrierId[]|null
     */
    public function getRestrictedCarrierIds(): ?array
    {
        return $this->restrictedCarrierIds;
    }

    /**
     * @return CountryId[]|null
     */
    public function getRestrictedCountryIds(): ?array
    {
        return $this->restrictedCountryIds;
    }

    /**
     * @return GroupId[]|null
     */
    public function getRestrictedGroupIds(): ?array
    {
        return $this->restrictedGroupIds;
    }

    /**
     * @return CartRuleId
     */
    public function getCartRuleId(): CartRuleId
    {
        return $this->cartRuleId;
    }

    /**
     * @param int[] $restrictedCartRuleIds
     *
     * @return self
     *
     * @throws CartRuleConstraintException
     */
    public function setRestrictedCartRuleIds(array $restrictedCartRuleIds): self
    {
        $this->restrictedCartRuleIds = [];
        foreach ($restrictedCartRuleIds as $restrictedCartRuleId) {
            if ($restrictedCartRuleId === $this->getCartRuleId()->getValue()) {
                throw new CartRuleConstraintException(
                    'Restricted CartRule ids cannot contain id of current cart rule',
                    CartRuleConstraintException::INVALID_CART_RULE_RESTRICTION
                );
            }
            $this->restrictedCartRuleIds[] = new CartRuleId($restrictedCartRuleId);
        }

        return $this;
    }

    /**
     * @return CartRuleId[]|null
     */
    public function getRestrictedCartRuleIds(): ?array
    {
        return $this->restrictedCartRuleIds;
    }

    /**
     * @param RestrictionRuleGroup[] $productRestrictionRuleGroups
     *
     * @return self
     */
    public function setProductRestrictionRuleGroups(array $productRestrictionRuleGroups): self
    {
        $this->productRestrictionRuleGroups = $productRestrictionRuleGroups;

        return $this;
    }

    /**
     * @return RestrictionRuleGroup[]|null
     */
    public function getProductRestrictionRuleGroups(): ?array
    {
        return $this->productRestrictionRuleGroups;
    }
}
