<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShopBundle\Form\Admin\Sell\Discount;

use PrestaShopBundle\Form\Admin\Type\CurrencyMoneyType;
use PrestaShopBundle\Form\Admin\Type\TaxInclusionChoiceType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MinimumAmountType extends TranslatorAwareType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('value', CurrencyMoneyType::class)
            ->add('tax_included', TaxInclusionChoiceType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setDefaults([
                'form_theme' => '@PrestaShop/Admin/Sell/Catalog/Discount/FormTheme/minimum_amount.html.twig',
            ])
        ;
    }
}
