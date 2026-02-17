<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Exception;

/**
 * Is thrown when cannot update cart rule
 */
class UpdateCartRuleException extends CartRuleException
{
    /**
     * When fails to update single cart rule status
     */
    public const FAILED_UPDATE_STATUS = 10;

    /**
     * When fails to update cart rule status in bulk action
     */
    public const FAILED_BULK_UPDATE_STATUS = 20;
}
