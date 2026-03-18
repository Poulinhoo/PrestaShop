<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Rounding;

use PrestaShop\Decimal\DecimalNumber;

interface RoundingServiceInterface
{
    public function round(DecimalNumber $value, ?int $precision = null): DecimalNumber;
}
