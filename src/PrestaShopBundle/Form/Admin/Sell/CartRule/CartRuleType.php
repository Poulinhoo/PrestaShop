<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShopBundle\Form\Admin\Sell\CartRule;

use PrestaShop\PrestaShop\Core\ConstraintValidator\Constraints\CartRule;
use PrestaShopBundle\Form\Admin\Type\NavigationTabType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CartRuleType extends TranslatorAwareType
{
    /**
     * {@inheritDoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('information', InformationType::class, [
                'label' => $this->trans('Information', 'Admin.Catalog.Feature'),
            ])
            ->add('conditions', ConditionsType::class, [
                'label' => $this->trans('Conditions', 'Admin.Catalog.Feature'),
            ])
            ->add('actions', ActionsType::class, [
                'label' => $this->trans('Actions', 'Admin.Catalog.Feature'),
                'error_bubbling' => false,
            ])
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function getParent(): string
    {
        return NavigationTabType::class;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'form_theme' => '@PrestaShop/Admin/TwigTemplateForm/prestashop_ui_kit_base.html.twig',
            'constraints' => [
                new CartRule(),
            ],
        ]);
    }
}
