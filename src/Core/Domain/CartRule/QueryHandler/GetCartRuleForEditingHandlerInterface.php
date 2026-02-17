<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryHandler;

use PrestaShop\PrestaShop\Core\Domain\CartRule\Query\GetCartRuleForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleForEditing;

/**
 * Defines contract for GetCartRuleForEditingHandler
 */
interface GetCartRuleForEditingHandlerInterface
{
    /**
     * @param GetCartRuleForEditing $query
     *
     * @return CartRuleForEditing
     */
    public function handle(GetCartRuleForEditing $query): CartRuleForEditing;
}
