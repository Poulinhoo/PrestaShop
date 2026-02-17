<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Adapter\CartRule;

/**
 * Legacy discount application types, used in cart rules, are defined in this class.
 */
final class LegacyDiscountApplicationType
{
    /**
     * Discount is applied for selected products
     */
    public const SELECTED_PRODUCTS = -2;

    /**
     * Discount is applied to cheapest product
     */
    public const CHEAPEST_PRODUCT = -1;

    /**
     * Discount is applied to order without shipping
     */
    public const ORDER_WITHOUT_SHIPPING = 0;

    /**
     * Class used only for constants.
     */
    private function __construct()
    {
    }
}
