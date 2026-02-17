<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ConstraintValidator\Constraints;

use Attribute;
use PrestaShop\PrestaShop\Core\ConstraintValidator\CartRuleValidator;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class CartRule extends Constraint
{
    /**
     * When discount type "specific_product" is selected, but the specific product is not provided
     *
     * @var string
     */
    public $missingSpecificProductMessage = 'Specific product must be selected for this discount application type';

    /**
     * When discount type "selected_products" is selected, but there are no selected product restrictions
     *
     * @var string
     */
    public $missingProductRestrictionsMessage = 'Product restrictions must be applied for this discount application type';

    /**
     * When cart rule has no actions
     *
     * @var string
     */
    public $missingActionsMessage = 'Cart rule must have at least one action';

    /**
     * {@inheritDoc}
     */
    public function validatedBy(): string
    {
        return CartRuleValidator::class;
    }
}
