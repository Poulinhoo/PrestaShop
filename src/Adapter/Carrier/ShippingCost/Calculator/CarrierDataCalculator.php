<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\Carrier\ShippingCost\Calculator;

use PrestaShop\PrestaShop\Core\Domain\Carrier\Exception\CarrierNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ShippingCost\Calculator\ShippingCostCalculatorInterface;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ShippingCost\Provider\CarrierDataProviderInterface;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ShippingCost\ShippingCostContext;

class CarrierDataCalculator implements ShippingCostCalculatorInterface
{
    public function __construct(
        private readonly CarrierDataProviderInterface $carrierDataProvider,
    ) {
    }

    public function compute(ShippingCostContext $context): void
    {
        $carrierData = $this->carrierDataProvider->getCarrierShippingData($context->getCarrierId());

        if ($carrierData === null) {
            throw new CarrierNotFoundException(sprintf('Carrier with id "%d" was not found.', $context->getCarrierId()));
        }

        $context->setCarrierData($carrierData);
        $context->setSelectedCarrierId($carrierData->getCarrierId());

        if ($carrierData->isFreeShippingMethod()) {
            $context->setFreeShipping(true);
        }
    }
}
