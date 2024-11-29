<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\PrestaShop\Core\Checkout;

use PrestaShop\PrestaShop\Core\Checkout\DeliveryOption;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Context;
use Configuration;
use Country;
use Product;
use Carrier;

class DeliveryOptionsBuilder
{
    /**
     * @var Context
     */
    private $context;

    /**
     * @var PriceFormatter
     */
    private $priceFormatter;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var array
     */
    private $deliveryOptions = [];

    public function __construct(Context $context, TranslatorInterface $translator)
    {
        $this->context = $context;
        $this->priceFormatter = new PriceFormatter();
        $this->translator = $translator;
    }

    public function getSelectedCarriers()
    {

    }

    public function setDeliveryOption($deliveryOption = null)
    {
        $result = json_encode($deliveryOption);

        $this->context->cart->delivery_option = $result;

        \CartRule::autoRemoveFromCart();
        \CartRule::autoAddToCart();
    }

    public function getDeliveryOption()
    {
        $delivery_option = $this->context->cart->delivery_option;
        $delivery_option_list = $this->getDeliveryOptions();

        if (isset($delivery_option) && $delivery_option != '') {
            $delivery_option = json_decode($delivery_option, true);
            $validated = true;

            if (is_array($delivery_option)) {
                foreach ($delivery_option as $carrierIds) {
                    foreach($delivery_option_list as $details)
                    {
                        if (!isset($details['details'])) {
                            $validated = false;

                            break;
                        }
                    }
                }

                if ($validated) {
                    return $delivery_option;
                }
            }
        }

        return $delivery_option;
    }

    public function getDeliveryOptions()
    {
        $this->findAllCombinations($this->context->cart->getProducts(), 0, null);
        $this->calculateShippingCost();

        return $this->deliveryOptions;
    }

    public function findAllCombinations(array $products, int $currentPosition, $deliveryOption)
    {
        if (count($products) === 0) {
            return [];
        }

        $carriersFromCurrentPosition = (new Product($products[$currentPosition]['id_product']))->getCarriers();
        $nextPosition = isset($products[$currentPosition + 1]) ? $currentPosition + 1 : -1;

        foreach($carriersFromCurrentPosition as $carrier) {
            if ($deliveryOption === null) {
                $deliveryOption = new DeliveryOption();
            }

            $cpyDeliveryOption = clone($deliveryOption);

            $cpyDeliveryOption->addCombination([
                'product' => $products[$currentPosition],
                'id_carrier' => $carrier['id_carrier'],
                'display_name' => $carrier['name']
            ]);

            if ($nextPosition !== -1) {
                $this->findAllCombinations($products, $nextPosition, $cpyDeliveryOption);
            } else {
                $this->deliveryOptions[]['productsGroupedByCarrier'] = $cpyDeliveryOption->getCombinationByCarrier();
            }
        }
    }

    private function calculateShippingCost()
    {
        $includeTaxes = !Product::getTaxCalculationMethod((int) $this->context->cart->id_customer) && (int) Configuration::get('PS_TAX');
        $displayTaxesLabel = (Configuration::get('PS_TAX') && $this->context->country->display_tax_label && !Configuration::get('AEUC_LABEL_TAX_INC_EXC'));
        $priceWithoutTax = 0;
        $priceWithTax = 0;

        // TODO: maybe split this to distinct function
        foreach($this->deliveryOptions as $key => $deliveryOption) {
            $this->deliveryOptions[$key]['details']['price_without_tax'] = 0;
            $this->deliveryOptions[$key]['details']['price_with_tax'] = 0;

            foreach($deliveryOption['productsGroupedByCarrier'] as $carrierId => $products) {
                $priceWithoutTax += $this->context->cart->getPackageShippingCost($carrierId, false, new Country($this->context->language->id), $products['products']);
                $priceWithTax += $this->context->cart->getPackageShippingCost($carrierId, true, new Country($this->context->language->id), $products['products']);
                $this->deliveryOptions[$key]['details']['price_without_tax'] = $priceWithoutTax;
                $this->deliveryOptions[$key]['details']['price_with_tax'] = $priceWithTax;

                if ($this->isFreeShipping($carrierId)) {
                    $this->deliveryOptions[$key]['details']['price'] = $this->translator->trans(
                        'Free',
                        [],
                        'Shop.Theme.Checkout'
                    );
                } else {
                    if ($includeTaxes) {
                        $this->deliveryOptions[$key]['details']['price'] = $this->priceFormatter->format($priceWithTax);
                        if ($displayTaxesLabel) {
                            $this->deliveryOptions[$key]['details']['price'] = $this->translator->trans(
                                '%price% tax incl.',
                                ['%price%' => $this->deliveryOptions[$key]['details']['price']],
                                'Shop.Theme.Checkout'
                            );
                        }
                    } else {
                        $this->deliveryOptions[$key]['details']['price'] = $this->priceFormatter->format($priceWithoutTax);
                        if ($displayTaxesLabel) {
                            $this->deliveryOptions[$key]['details']['price'] = $this->translator->trans(
                                '%price% tax excl.',
                                ['%price%' => $this->deliveryOptions[$key]['details']['price']],
                                'Shop.Theme.Checkout'
                            );
                        }
                    }
                }
                $this->deliveryOptions[$key]['details']['carrier_name'] = $this->getCarrierName($key)['carrierName'];
                $this->deliveryOptions[$key]['details']['ids_carriers'] = $this->getCarrierName($key)['idsCarriers'];

            }
            $priceWithoutTax = 0;
            $priceWithTax = 0;
        }
    }

    private function isFreeShipping(int $carrierId)
    {
        $free_shipping = false;
        $carrier = new Carrier($carrierId);

        if ($carrier->is_free) {
            $free_shipping = true;
        } else {
            foreach ($this->context->cart->getCartRules() as $rule) {
                if ($rule['free_shipping'] && !$rule['carrier_restriction']) {
                    $free_shipping = true;

                    break;
                }
            }
        }

        return $free_shipping;
    }

    private function getCarrierName($index)
    {
        $details = [];

        foreach($this->deliveryOptions[$index]['productsGroupedByCarrier'] as $carrierId => $products) {
            $carrier = new Carrier($carrierId);
            $details['carrierName'][] = $carrier->name;
            $details['idsCarriers'][] = $carrierId;
        }

        $details['carrierName'] = implode(' + ', $details['carrierName']);
        $details['idsCarriers'] = implode(',', $details['idsCarriers']);

        return $details;
    }

    public function getTotalShippingCost($delivery_option = null, $use_tax = true)
    {
        if (null === $delivery_option) {
            $delivery_option = $this->getDeliveryOption();
        }

        $_total_shipping = [
            'with_tax' => 0,
            'without_tax' => 0,
        ];

        $delivery_option_list = $this->getDeliveryOptions();

        if ($delivery_option === "") {
            return ($use_tax) ? $_total_shipping['with_tax'] : $_total_shipping['without_tax'];
        }

        foreach($delivery_option_list as $details)
        {
            if ($details['details']['ids_carriers'] === current($delivery_option)) {
                $_total_shipping['with_tax'] += $details['details']['price_with_tax'];
                $_total_shipping['without_tax'] += $details['details']['price_without_tax'];
                break;
            }
        }

        return ($use_tax) ? $_total_shipping['with_tax'] : $_total_shipping['without_tax'];
    }
}
