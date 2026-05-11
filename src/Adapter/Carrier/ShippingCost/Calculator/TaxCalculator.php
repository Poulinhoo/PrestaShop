<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\Carrier\ShippingCost\Calculator;

use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\Decimal\Operation\Rounding;
use PrestaShop\PrestaShop\Adapter\Configuration as AdapterConfiguration;
use PrestaShop\PrestaShop\Core\Context\CurrencyContext;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ShippingCost\Calculator\ShippingCostCalculatorInterface;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ShippingCost\Provider\ShippingTaxRateProviderInterface;
use PrestaShop\PrestaShop\Core\Domain\Carrier\ShippingCost\ShippingCostContext;

class TaxCalculator implements ShippingCostCalculatorInterface
{
    public function __construct(
        private readonly AdapterConfiguration $configuration,
        private readonly ShippingTaxRateProviderInterface $taxRateProvider,
        private readonly CurrencyContext $currencyContext,
    ) {
    }

    public function compute(ShippingCostContext $context): void
    {
        $precision = $this->currencyContext->getPrecision();
        $context->setPrecision($precision);

        if ($context->isFreeShipping()) {
            $zero = new DecimalNumber('0');
            $context->setTaxExcluded($zero);
            $context->setTaxIncluded($zero);

            return;
        }

        $cost = $context->getCost();

        $addressId = $context->getAddressId();
        $carrierId = $context->getSelectedCarrierId() ?? $context->getCarrierId();
        $taxIncluded = $cost;

        if (
            $this->configuration->get('PS_TAX')
            && $addressId !== null
            && !$this->configuration->get('PS_ATCP_SHIPWRAP')
        ) {
            $taxRate = $this->taxRateProvider->getTaxRate($carrierId, $addressId);
            $taxIncluded = $cost->times(new DecimalNumber((string) (1 + ($taxRate / 100))));
        }

        $context->setTaxExcluded($this->roundAmount($cost, $precision));
        $context->setTaxIncluded($this->roundAmount($taxIncluded, $precision));
    }

    private function roundAmount(DecimalNumber $amount, int $precision): DecimalNumber
    {
        return new DecimalNumber($amount->round($precision, Rounding::ROUND_HALF_UP));
    }
}
