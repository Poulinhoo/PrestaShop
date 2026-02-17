<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

use DateTime;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;

/**
 * Provides data for editing CatalogPriceRule
 */
class CartRuleForEditing
{
    /**
     * @var CartRuleId
     */
    private $cartRuleId;

    /**
     * @var CartRuleInformationForEditing
     */
    private $information;

    /**
     * @var CartRuleConditionsForEditing
     */
    private $conditions;

    /**
     * @var CartRuleActionForEditing
     */
    private $actions;

    /**
     * @var DateTime|null
     */
    private $dateAdd;

    /**
     * @var DateTime|null
     */
    private $dateUpd;

    public function __construct(
        CartRuleId $cartRuleId,
        CartRuleInformationForEditing $information,
        CartRuleConditionsForEditing $conditions,
        CartRuleActionForEditing $actions,
        ?DateTime $dateAdd,
        ?DateTime $dateUpd
    ) {
        $this->cartRuleId = $cartRuleId;
        $this->information = $information;
        $this->conditions = $conditions;
        $this->actions = $actions;
        $this->dateAdd = $dateAdd;
        $this->dateUpd = $dateUpd;
    }

    /**
     * @return CartRuleId
     */
    public function getCartRuleId(): CartRuleId
    {
        return $this->cartRuleId;
    }

    /**
     * @return CartRuleInformationForEditing
     */
    public function getInformation(): CartRuleInformationForEditing
    {
        return $this->information;
    }

    /**
     * @return CartRuleConditionsForEditing
     */
    public function getConditions(): CartRuleConditionsForEditing
    {
        return $this->conditions;
    }

    /**
     * @return CartRuleActionForEditing
     */
    public function getActions(): CartRuleActionForEditing
    {
        return $this->actions;
    }

    /**
     * @return DateTime|null
     */
    public function getDateAdd(): ?DateTime
    {
        return $this->dateAdd;
    }

    /**
     * @return DateTime|null
     */
    public function getDateUpd(): ?DateTime
    {
        return $this->dateUpd;
    }
}
