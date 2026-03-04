<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\Shipment;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\AbstractRowAction;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SplitShipmentRowAction extends AbstractRowAction
{
    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return 'split_shipment_row_action';
    }

    /**
     * {@inheritdoc}
     */
    public function isApplicable(array $record)
    {
        $options = $this->getOptions();
        $itemsField = $options['items'];

        // if shipment if fulfill (tracking number is set and has a packed data)
        // the merchant cannot anymore proceed to a merge
        if (isset($record['tracking_number']) || isset($record['packed_at'])) {
            return false;
        }

        // Show split action only if items > 1
        return isset($record[$itemsField]) && $record[$itemsField] > 1;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setRequired([
                'shipment_id_field',
                'order_id_field',
                'items',
            ])
            ->setDefaults([
                'total_shipments' => null,
            ])
            ->setAllowedTypes('shipment_id_field', 'string')
            ->setAllowedTypes('items', 'string')
            ->setAllowedTypes('total_shipments', ['string', 'null'])
            ->setAllowedTypes('order_id_field', 'string');
    }
}
