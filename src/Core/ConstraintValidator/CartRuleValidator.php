<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ConstraintValidator;

use PrestaShop\PrestaShop\Core\ConstraintValidator\Constraints\CartRule;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\DiscountApplicationType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class CartRuleValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof CartRule) {
            throw new UnexpectedTypeException($constraint, CartRule::class);
        }

        if (!is_array($value)) {
            throw new UnexpectedValueException($value, 'array');
        }

        $this->validateActions($value, $constraint);
        $this->validateDiscounts($value, $constraint);
    }

    private function validateDiscounts(array $formData, CartRule $constraint): void
    {
        // actions supposed to be validated already with validateActions method,
        // so in case discount is missing it means only free shipping or gift product action is applied
        if (!isset($formData['actions']['discount'])) {
            return;
        }

        $discountData = $formData['actions']['discount'];
        $discountApplicationType = $discountData['discount_application'];

        if (DiscountApplicationType::SPECIFIC_PRODUCT === $discountApplicationType && empty($discountData['specific_product'][0]['id'])) {
            $this->buildViolation($constraint->missingSpecificProductMessage, '[actions][discount][specific_product]');
        }

        if (
            DiscountApplicationType::SELECTED_PRODUCTS === $discountApplicationType
            // @todo: restrictions are not implemented, so this will still adapt,
            //       but the point is to check if any products restrictions are applied
            //       also need to check more in depth if its legit with products only or also with categories/attributes etc.)
            && empty($formData['conditions']['product_restrictions'])
        ) {
            $this->buildViolation($constraint->missingProductRestrictionsMessage, '[actions][discount][discount_application]');
        }
    }

    private function validateActions(array $formData, CartRule $constraint): void
    {
        if (!empty($formData['actions']['free_shipping'])) {
            return;
        }

        if (!empty($formData['actions']['gift_product'][0]['product_id'])) {
            return;
        }

        // in theory there are more required properties, but we already assume they are present when reduction value is present
        // (the disabling switch and reduction value can be violated by user, therefor other values missing would mean developer error instead of constraint violation)
        if (!empty($formData['actions']['disabling_switch_discount']) && !empty($formData['actions']['discount']['reduction']['value'])) {
            return;
        }

        $this->buildViolation($constraint->missingActionsMessage, '[actions]');
    }

    private function buildViolation(string $message, string $errorPath): void
    {
        $this->context
            ->buildViolation($message)
            ->setTranslationDomain('Admin.Notifications.Error')
            ->atPath($errorPath)
            ->addViolation()
        ;
    }
}
