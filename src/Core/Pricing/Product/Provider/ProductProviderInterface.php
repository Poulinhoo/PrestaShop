<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Provider;

use PrestaShop\Decimal\DecimalNumber;

interface ProductProviderInterface
{
    /**
     * Returns the base price (tax excluded) for a product.
     */
    public function getBasePrice(int $productId): DecimalNumber;

    /**
     * Returns the combination price impact.
     */
    public function getCombinationPriceImpact(int $productId, int $combinationId): DecimalNumber;
}
