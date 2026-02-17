<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShopBundle\Form\Admin\Sell\CartRule;

use PrestaShopBundle\Form\Admin\Type\CurrencyChoiceType;
use PrestaShopBundle\Form\Admin\Type\ShippingInclusionChoiceType;
use PrestaShopBundle\Form\Admin\Type\TaxInclusionChoiceType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MinimumAmountType extends TranslatorAwareType
{
    /**
     * {@inheritDoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('amount', NumberType::class, [
                'label' => false,
                'required' => false,
                'help' => $this->trans(
                    'You can choose a minimum amount for the cart either with or without the taxes and shipping.',
                    'Admin.Catalog.Help'
                ),
            ])
            ->add('currency', CurrencyChoiceType::class)
            ->add('tax_included', TaxInclusionChoiceType::class)
            ->add('shipping_included', ShippingInclusionChoiceType::class)
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'form_theme' => '@PrestaShop/Admin/Sell/Catalog/CartRule/FormTheme/minimum_amount.html.twig',
            'label' => $this->trans('Minimum amount', 'Admin.Catalog.Feature'),
            'required' => false,
            'disabling_switch' => true,
            'disabled_value' => static function (?array $data): bool {
                return empty($data['amount']);
            },
        ]);
    }
}
