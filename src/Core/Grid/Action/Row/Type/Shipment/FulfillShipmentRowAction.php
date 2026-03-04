<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\Shipment;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\AbstractRowAction;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FulfillShipmentRowAction extends AbstractRowAction
{
    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return 'fulfill_shipment_row_action';
    }

    /**
     * {@inheritdoc}
     */
    public function isApplicable(array $record)
    {
        if (isset($record['tracking_number'])) {
            return false;
        }

        if (isset($record['packed_at'])) {
            return false;
        }

        return true;
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
            ])
            ->setAllowedTypes('shipment_id_field', 'string')
            ->setAllowedTypes('order_id_field', 'string');
    }
}
