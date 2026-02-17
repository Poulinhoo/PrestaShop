<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShopBundle\Form\Admin\Sell\CartRule;

use PrestaShopBundle\Form\Admin\Type\CustomerSearchType;
use PrestaShopBundle\Form\Admin\Type\DateRangeType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;

class ConditionsType extends TranslatorAwareType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('customer', CustomerSearchType::class, [
                'disabling_switch_event' => 'switchCartRuleCustomer',
            ])
            ->add('valid_date_range', DateRangeType::class, [
                'label' => false,
                'label_from' => $this->trans('Valid from', 'Admin.Catalog.Feature'),
                'label_to' => $this->trans('Valid to', 'Admin.Catalog.Feature'),
                'required' => false,
                'date_format' => DateRangeType::DEFAULT_DATE_TIME_FORMAT,
            ])
            ->add('minimum_amount', MinimumAmountType::class)
            ->add('total_available', NumberType::class, [
                'label' => $this->trans('Total available', 'Admin.Catalog.Feature'),
                'help' => $this->trans(
                    'The cart rule will be applied to the first "X" customers only.',
                    'Admin.Catalog.Help'
                ),
            ])
            ->add('available_per_user', NumberType::class, [
                'label' => $this->trans('Total available for each user', 'Admin.Catalog.Feature'),
                'help' => $this->trans(
                    'A customer will only be able to use the cart rule "X" time(s).',
                    'Admin.Catalog.Help'
                ),
            ])
            // @todo: Restrictions not handled. Will be in separate PR.
        ;
    }
}
