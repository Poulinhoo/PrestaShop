<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\Shipment\CommandHandler;

use Exception;
use Order;
use PrestaShop\PrestaShop\Adapter\Address\Repository\AddressRepository;
use PrestaShop\PrestaShop\Adapter\Carrier\ShippingCostCalculator;
use PrestaShop\PrestaShop\Adapter\Country\Repository\CountryRepository;
use PrestaShop\PrestaShop\Adapter\Order\Repository\OrderRepository;
use PrestaShop\PrestaShop\Adapter\Product\Repository\ProductRepository;
use PrestaShop\PrestaShop\Adapter\Shop\Context as ShopContext;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\Address\ValueObject\AddressId;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ValueObject\ShippingCalculationRequest;
use PrestaShop\PrestaShop\Core\Domain\Country\ValueObject\CountryId;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductId;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Command\CreateShipment;
use PrestaShop\PrestaShop\Core\Domain\Shipment\CommandHandler\CreateShipmentHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Exception\ShipmentException;
use PrestaShop\PrestaShop\Core\Domain\Shipment\Exception\ShipmentNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopId;
use PrestaShopBundle\Entity\Repository\ShipmentRepository;
use PrestaShopBundle\Entity\Shipment;

#[AsCommandHandler]
class CreateShipmentHandler implements CreateShipmentHandlerInterface
{
    public function __construct(
        private readonly ShipmentRepository $shipmentRepository,
        private readonly OrderRepository $orderRepository,
        private readonly AddressRepository $addressRepository,
        private readonly ProductRepository $productRepository,
        private readonly CountryRepository $countryRepository,
        private readonly ShippingCostCalculator $shippingCostCalculator,
        private readonly ShopContext $shopContext,
    ) {
    }

    public function handle(CreateShipment $command): int
    {
        $order = $this->orderRepository->get($command->getOrderId());
        $carrierId = $command->getCarrierId()->getValue();
        $productId = $command->getProductId();
        $quantity = $command->getQuantity();

        if ($order === null) {
            throw new ShipmentNotFoundException(sprintf('No order found with id %s found', $command->getOrderId()->getValue()));
        }

        $shipment = new Shipment();
        $shipment->setOrderId((int) $order->id);
        $shipment->setCarrierId((int) $carrierId);
        $shipment->setAddressId((int) $order->id_address_delivery);
        $shipment->setTrackingNumber(null);

        $shippingCosts = $this->calculateShippingCosts($order, $carrierId, $productId, $quantity);
        $shipment->setShippingCostTaxExcluded($shippingCosts['tax_excluded']);
        $shipment->setShippingCostTaxIncluded($shippingCosts['tax_included']);

        $shipment->setDeliveredAt(null);
        $shipment->setShippedAt(null);
        $shipment->setCancelledAt(null);

        try {
            return $this->shipmentRepository->save($shipment);
        } catch (Exception $e) {
            throw new ShipmentException(sprintf('Failed to add products from shipment with id "%s"', $shipment), 0, $e);
        }
    }

    /**
     * @return array{tax_excluded: float, tax_included: float}
     */
    private function calculateShippingCosts(Order $order, int $carrierId, ProductId $productId, int $quantity): array
    {
        $product = $this->productRepository->get($productId, new ShopId($this->shopContext->getContextShopID()));

        if ($product === null) {
            return [
                'tax_excluded' => 0.0,
                'tax_included' => 0.0,
            ];
        }

        $productArray = [
            'id_product' => (int) $product->id,
            'id_product_attribute' => 0,
            'quantity' => $quantity,
            'weight' => (float) $product->weight,
            'weight_attribute' => null,
            'is_virtual' => (int) $product->is_virtual,
            'additional_shipping_cost' => (float) $product->additional_shipping_cost,
            'price_wt' => (float) $product->price,
        ];

        $products = [$productArray];

        $address = $this->addressRepository->get(new AddressId((int) $order->id_address_delivery));
        $country = $this->countryRepository->get(new CountryId((int) $address->id_country));

        $productPrice = (float) $product->price;
        $orderTotal = $productPrice * $quantity;

        $request = new ShippingCalculationRequest(
            $products,
            $carrierId,
            null,
            (int) $order->id_address_delivery,
            $country,
            (int) $order->id_currency,
            (int) $order->id_customer,
            $orderTotal
        );

        $result = $this->shippingCostCalculator->calculate($request);

        if ($result === null) {
            return [
                'tax_excluded' => 0.0,
                'tax_included' => 0.0,
            ];
        }

        return [
            'tax_excluded' => $result->getTaxExcludedAsFloat(),
            'tax_included' => $result->getTaxIncludedAsFloat(),
        ];
    }
}
