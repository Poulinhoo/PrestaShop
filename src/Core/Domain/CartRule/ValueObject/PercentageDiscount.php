<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject;

use PrestaShop\Decimal\DecimalNumber;

/**
 * Percentage discount
 */
class PercentageDiscount
{
    /**
     * @var DecimalNumber
     */
    private $percentage;

    /**
     * @var bool
     */
    private $applyToDiscountedProducts;

    /**
     * @param DecimalNumber $percentage
     * @param bool $includeDiscountedProducts
     */
    public function __construct(
        DecimalNumber $percentage,
        bool $includeDiscountedProducts
    ) {
        $this->percentage = $percentage;
        $this->applyToDiscountedProducts = $includeDiscountedProducts;
    }

    /**
     * @return DecimalNumber
     */
    public function getPercentage(): DecimalNumber
    {
        return $this->percentage;
    }

    /**
     * @return bool
     */
    public function applyToDiscountedProducts(): bool
    {
        return $this->applyToDiscountedProducts;
    }
}
