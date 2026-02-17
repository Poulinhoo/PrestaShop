<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShopBundle\Form\Admin\Sell\CartRule;

use PrestaShopBundle\Form\Admin\Type\ProductSearchType;
use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\FormBuilderInterface;

class ActionsType extends TranslatorAwareType
{
    /**
     * {@inheritDoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('free_shipping', SwitchType::class, [
                'required' => false,
            ])
            ->add('discount', DiscountType::class)
            ->add('gift_product', ProductSearchType::class, [
                'include_combinations' => true,
                'label' => $this->trans('Send a free gift', 'Admin.Catalog.Feature'),
            ])
        ;
    }
}
