<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Provider;

use PrestaShop\Decimal\DecimalNumber;

final class MockProductProvider implements ProductProviderInterface
{
    /**
     * @param array<int, string> $basePrices productId => price string
     * @param array<string, string> $combinationImpacts "productId-combinationId" => price impact string
     */
    public function __construct(
        private readonly array $basePrices = [],
        private readonly array $combinationImpacts = [],
    ) {
    }

    public function getBasePrice(int $productId): DecimalNumber
    {
        if (!isset($this->basePrices[$productId])) {
            return new DecimalNumber('0');
        }

        return new DecimalNumber($this->basePrices[$productId]);
    }

    public function getCombinationPriceImpact(int $productId, int $combinationId): DecimalNumber
    {
        $key = $productId . '-' . $combinationId;
        if (!isset($this->combinationImpacts[$key])) {
            return new DecimalNumber('0');
        }

        return new DecimalNumber($this->combinationImpacts[$key]);
    }
}
