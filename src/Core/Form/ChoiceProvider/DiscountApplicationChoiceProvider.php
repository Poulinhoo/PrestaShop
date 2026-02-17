<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Core\Form\ChoiceProvider;

use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\DiscountApplicationType;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Reduction;
use PrestaShop\PrestaShop\Core\Form\ConfigurableFormChoiceProviderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

class DiscountApplicationChoiceProvider implements ConfigurableFormChoiceProviderInterface
{
    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(
        TranslatorInterface $translator
    ) {
        $this->translator = $translator;
    }

    /**
     * @return array<string, string>
     */
    public function getChoices(array $options): array
    {
        $options = $this->configureOptions($options);

        $choices = [
            $this->translator->trans('Order (without shipping)', [], 'Admin.Catalog.Feature') => DiscountApplicationType::ORDER_WITHOUT_SHIPPING,
            $this->translator->trans('Specific product', [], 'Admin.Catalog.Feature') => DiscountApplicationType::SPECIFIC_PRODUCT,
        ];

        if (Reduction::TYPE_PERCENTAGE === $options['reduction_type']) {
            $choices[$this->translator->trans('Cheapest product', [], 'Admin.Catalog.Feature')] = DiscountApplicationType::CHEAPEST_PRODUCT;
            $choices[$this->translator->trans('Selected product(s)', [], 'Admin.Catalog.Feature')] = DiscountApplicationType::SELECTED_PRODUCTS;
        }

        return $choices;
    }

    /**
     * @param array<string, string> $options
     *
     * @return array
     */
    protected function configureOptions(array $options): array
    {
        $resolver = new OptionsResolver();
        $resolver
            ->setRequired(['reduction_type'])
            ->setAllowedTypes('reduction_type', 'string')
            ->setAllowedValues('reduction_type', [Reduction::TYPE_AMOUNT, Reduction::TYPE_PERCENTAGE])
        ;

        return $resolver->resolve($options);
    }
}
