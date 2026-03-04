<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\Shipment;

use PrestaShop\PrestaShop\Core\Grid\Action\Row\AbstractRowAction;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DeliverySlipShipmentRowAction extends AbstractRowAction
{
    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return 'delivery_slip_shipment_row_action';
    }

    /**
     * {@inheritdoc}
     */
    public function isApplicable(array $record)
    {
        // if shipment if not fulfill (tracking number is set and has a packed data)
        // the merchant cannot download the delivery slip of the given shipment
        if (!isset($record['tracking_number']) || !isset($record['packed_at'])) {
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
                'route',
                'route_param_name',
                'route_param_field',
            ])
            ->setDefaults([
                'extra_route_params' => [],
            ])
            ->setAllowedTypes('route', 'string')
            ->setAllowedTypes('route_param_name', 'string')
            ->setAllowedTypes('route_param_field', 'string')
            ->setAllowedTypes('extra_route_params', 'array')
        ;
    }
}
