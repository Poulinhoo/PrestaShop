<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShopBundle\Form\Admin\Sell\CartRule\EventListener;

use PrestaShop\PrestaShop\Core\Form\ChoiceProvider\DiscountApplicationChoiceProvider;
use PrestaShopBundle\Form\FormCloner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class DiscountListener implements EventSubscriberInterface
{
    /**
     * @var DiscountApplicationChoiceProvider
     */
    private $discountApplicationChoiceProvider;

    /**
     * @var FormCloner
     */
    private $formCloner;

    public function __construct(
        DiscountApplicationChoiceProvider $discountApplicationChoiceProvider,
        FormCloner $formCloner
    ) {
        $this->discountApplicationChoiceProvider = $discountApplicationChoiceProvider;
        $this->formCloner = $formCloner;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::PRE_SET_DATA => 'adaptDiscountChoices',
            FormEvents::PRE_SUBMIT => 'adaptDiscountChoices',
        ];
    }

    /**
     * @param FormEvent $event
     */
    public function adaptDiscountChoices(FormEvent $event): void
    {
        $data = $event->getData();
        if (!isset($data['reduction']['type'])) {
            return;
        }

        $form = $event->getForm();
        $discountApplicationField = $form->get('discount_application');
        // adjust discount application choices depending on reduction type data
        $newDiscountApplicationField = $this->formCloner->cloneForm($discountApplicationField, [
            'choices' => $this->discountApplicationChoiceProvider->getChoices([
                'reduction_type' => $data['reduction']['type'],
            ]),
        ]);

        // replace previous form with the new one, containing updated options
        $form->add($newDiscountApplicationField);
    }
}
