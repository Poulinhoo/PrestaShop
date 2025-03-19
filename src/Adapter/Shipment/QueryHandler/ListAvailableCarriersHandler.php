<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PrestaShop\PrestaShop\Adapter\Shipment\QueryHandler;

use Carrier;
use Context;
use OrderDetail;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsQueryHandler;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Query\ListAvailableCarriers;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Query\ListAvailableShipments;
use PrestaShop\PrestaShop\Core\Domain\Shipment\QueryHandler\ListAvailableCarriersHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Shipment\QueryResult\AvailableCarriers;
use Product;

#[AsQueryHandler]
class ListAvailableCarriersHandler implements ListAvailableCarriersHandlerInterface
{
    public function __construct(
    ) {
    }

    /**
     * @param ListAvailableShipments $query
     *
     * @return AvailableCarriers[]
     */
    public function handle(ListAvailableCarriers $query)
    {
        $carriers = [];
        $orderDetailsIds = $query->getOrderIdDetails()->getValue();

        foreach ($orderDetailsIds as $orderDetailId) {
            $orderDetail = new OrderDetail($orderDetailId);
            $carriersProduct = array_map(function ($carrier) {
                return $carrier['id_carrier'];
            }, (new Product($orderDetail->product_id)->getCarriers()));
            $carriers = Carrier::getCarriers(Context::getContext()->language->id, true, false, false, null, Carrier::ALL_CARRIERS);

            foreach ($carriers as $carrier) {
                $carrierHandleProduct = in_array($carrier['id_carrier'], $carriersProduct);
                $carrier[] = new AvailableCarriers($carrier['id_carrier'], $carrier['name'], $orderDetail->product_id, $carrierHandleProduct);
            }

        }

        return $carriers;
    }
}
