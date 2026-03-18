<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product;

use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;

interface ProductPriceInterface
{
    public function getProductId(): int;

    public function getCombinationId(): int;

    public function getUnitPrice(): TaxablePrice;

    public function setUnitPrice(TaxablePrice $unitPrice): void;

    public function getTotalPrice(): TaxablePrice;

    public function setTotalPrice(TaxablePrice $totalPrice): void;

    public function getOriginalPrice(): TaxablePrice;

    public function setOriginalPrice(TaxablePrice $originalPrice): void;
}
