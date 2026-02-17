<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\Restriction\RestrictionRuleGroup;

class CartRuleRestrictionsForEditing
{
    /**
     * @param int[] $restrictedCartRuleIds
     * @param RestrictionRuleGroup[] $productRestrictionRuleGroups
     * @param int[] $restrictedCarrierIds
     * @param int[] $restrictedCountryIds
     * @param int[] $restrictedGroupIds
     */
    public function __construct(
        private readonly array $restrictedCartRuleIds,
        private readonly array $productRestrictionRuleGroups,
        private readonly array $restrictedCarrierIds,
        private readonly array $restrictedCountryIds,
        private readonly array $restrictedGroupIds
    ) {
    }

    /**
     * @return int[]
     */
    public function getRestrictedCartRuleIds(): array
    {
        return $this->restrictedCartRuleIds;
    }

    /**
     * @return RestrictionRuleGroup[]
     */
    public function getProductRestrictionRuleGroups(): array
    {
        return $this->productRestrictionRuleGroups;
    }

    /**
     * @return int[]
     */
    public function getRestrictedCarrierIds(): array
    {
        return $this->restrictedCarrierIds;
    }

    /**
     * @return int[]
     */
    public function getRestrictedCountryIds(): array
    {
        return $this->restrictedCountryIds;
    }

    /**
     * @return int[]
     */
    public function getRestrictedGroupIds(): array
    {
        return $this->restrictedGroupIds;
    }
}
