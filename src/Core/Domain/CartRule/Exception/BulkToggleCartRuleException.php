<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Exception;

use Exception;

class BulkToggleCartRuleException extends CartRuleException
{
    /**
     * @var int[]
     */
    private $cartRuleIds;

    /**
     * @param int[] $cartRuleIds
     * @param string $message
     * @param int $code
     * @param Exception $previous
     */
    public function __construct(array $cartRuleIds, $message = '', $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->cartRuleIds = $cartRuleIds;
    }

    /**
     * @return int[]
     */
    public function getCartRuleIds(): array
    {
        return $this->cartRuleIds;
    }
}
