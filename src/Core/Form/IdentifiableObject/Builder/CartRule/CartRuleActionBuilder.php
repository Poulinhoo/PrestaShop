<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Form\IdentifiableObject\Builder\CartRule;

use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleAction;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\Discount;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\DiscountApplicationType;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\GiftProduct;
use PrestaShop\PrestaShop\Core\Domain\Currency\ValueObject\CurrencyId;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Money;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Reduction;
use Symfony\Component\PropertyAccess\PropertyAccess;

class CartRuleActionBuilder
{
    /**
     * @param array<string, mixed> $actionsData
     *
     * @return CartRuleAction
     */
    public function build(array $actionsData): CartRuleAction
    {
        return new CartRuleAction(
            $this->buildFreeShipping($actionsData),
            $this->buildGiftProduct($actionsData),
            $this->buildDiscount($actionsData)
        );
    }

    /**
     * Returns true when there are required keys to build action.
     * It can be used for example in partial update action, when action data is not being updated.
     *
     * @param array<string, mixed> $data
     *
     * @return bool
     */
    public function supports(array $data): bool
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor()
        ;

        if (array_key_exists('free_shipping', $data)) {
            return true;
        }

        if ($propertyAccessor->isReadable($data, '[gift_product][0]')) {
            return true;
        }

        if (
            $propertyAccessor->isReadable($data, '[discount][reduction][value]')
            && $propertyAccessor->isReadable($data, '[discount][reduction][type]')
        ) {
            return true;
        }

        return false;
    }

    private function buildFreeShipping(array $data): bool
    {
        return !empty($data['free_shipping']);
    }

    private function buildGiftProduct(array $data): ?GiftProduct
    {
        if (empty($data['gift_product'][0])) {
            return null;
        }

        $giftProductData = $data['gift_product'][0];

        return new GiftProduct(
            $giftProductData['product_id'],
            isset($giftProductData['combination_id']) ? (int) $giftProductData['combination_id'] : null
        );
    }

    private function buildDiscount(array $data): ?Discount
    {
        if (empty($data['discount']['reduction']['value'])) {
            return null;
        }

        $specificProductId = null;
        if (!empty($data['discount']['specific_product'][0]['id'])) {
            $specificProductId = (int) $data['discount']['specific_product'][0]['id'];
        }

        $discountApplicationType = new DiscountApplicationType(
            $data['discount']['discount_application'] ?? DiscountApplicationType::ORDER_WITHOUT_SHIPPING,
            $specificProductId
        );
        $reductionData = $data['discount']['reduction'];
        // creating this VO mostly just to fire the validation inside its constructor,
        // and we don't need to create DecimalNumbers manually when using in Discount objects
        $reduction = new Reduction($reductionData['type'], (string) $reductionData['value']);

        if ($reduction->getType() === Reduction::TYPE_AMOUNT) {
            return Discount::buildAmountDiscount(
                new Money(
                    $reduction->getValue(),
                    new CurrencyId($reductionData['currency']),
                    (bool) $reductionData['include_tax']
                ),
                $discountApplicationType
            );
        }

        return Discount::buildPercentageDiscount(
            $reduction->getValue(),
            !empty($data['discount']['apply_to_discounted_products']),
            $discountApplicationType
        );
    }
}
