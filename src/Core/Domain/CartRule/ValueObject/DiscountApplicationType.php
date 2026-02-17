<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;

/**
 * Discount application type indicates what the discount should be applied to.
 * E.g. to whole order, to a specific product, to cheapest product.
 */
class DiscountApplicationType
{
    /**
     * Discount will be applied to order without shipping
     */
    public const ORDER_WITHOUT_SHIPPING = 'order_without_shipping';

    /**
     * Discount will be applied to specifically selected product
     */
    public const SPECIFIC_PRODUCT = 'specific_product';

    /**
     * Discount will be applied to cheapest product of the cart
     */
    public const CHEAPEST_PRODUCT = 'cheapest_product';

    /**
     * Discount will be applied to products selection from cart rule's conditions.
     */
    public const SELECTED_PRODUCTS = 'selected_products';

    private const AVAILABLE_TYPES = [
        self::ORDER_WITHOUT_SHIPPING,
        self::SPECIFIC_PRODUCT,
        self::CHEAPEST_PRODUCT,
        self::SELECTED_PRODUCTS,
    ];

    /**
     * @var string
     */
    private $type;

    /**
     * @var ProductId|null
     */
    private $productId;

    /**
     * @param string $type
     * @param int|null $productId product id is required when application type is "specific_product"
     *
     * @throws CartRuleConstraintException
     * @throws ProductConstraintException
     */
    public function __construct(string $type, ?int $productId = null)
    {
        if (!in_array($type, self::AVAILABLE_TYPES)) {
            throw new CartRuleConstraintException(sprintf('Invalid cart rule discount application type %s. Available types are: %s', var_export($type, true), implode(', ', self::AVAILABLE_TYPES)), CartRuleConstraintException::INVALID_DISCOUNT_APPLICATION_TYPE);
        }

        if ($type === self::SPECIFIC_PRODUCT) {
            if (!$productId) {
                throw new CartRuleConstraintException(
                    'Provided Cart rule discount application type requires specific product',
                    CartRuleConstraintException::MISSING_DISCOUNT_APPLICATION_PRODUCT
                );
            }

            $this->productId = new ProductId($productId);
        }

        $this->type = $type;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return ProductId|null
     */
    public function getProductId(): ?ProductId
    {
        return $this->productId;
    }
}
