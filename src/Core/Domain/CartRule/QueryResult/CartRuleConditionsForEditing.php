<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

use DateTime;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\CustomerIdInterface;

class CartRuleConditionsForEditing
{
    /**
     * @var CustomerIdInterface
     */
    private $customerId;

    /**
     * @var DateTime|null
     */
    private $dateFrom;

    /**
     * @var DateTime|null
     */
    private $dateTo;

    /**
     * @var int
     */
    private $quantity;

    /**
     * @var int
     */
    private $quantityPerUser;

    /**
     * @var CartRuleMinimumForEditing|null
     */
    private $minimum;

    /**
     * @var CartRuleRestrictionsForEditing
     */
    private $restrictions;

    public function __construct(
        CustomerIdInterface $customerId,
        ?DateTime $dateFrom,
        ?DateTime $dateTo,
        int $quantity,
        int $quantityPerUser,
        ?CartRuleMinimumForEditing $minimum,
        CartRuleRestrictionsForEditing $restrictions
    ) {
        $this->customerId = $customerId;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->quantity = $quantity;
        $this->quantityPerUser = $quantityPerUser;
        $this->minimum = $minimum;
        $this->restrictions = $restrictions;
    }

    /**
     * @return CustomerIdInterface
     */
    public function getCustomerId(): CustomerIdInterface
    {
        return $this->customerId;
    }

    /**
     * @return DateTime|null
     */
    public function getDateFrom(): ?DateTime
    {
        return $this->dateFrom;
    }

    /**
     * @return DateTime|null
     */
    public function getDateTo(): ?DateTime
    {
        return $this->dateTo;
    }

    /**
     * @return int
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * @return int
     */
    public function getQuantityPerUser(): int
    {
        return $this->quantityPerUser;
    }

    /**
     * @return CartRuleMinimumForEditing|null
     */
    public function getMinimum(): ?CartRuleMinimumForEditing
    {
        return $this->minimum;
    }

    /**
     * @return CartRuleRestrictionsForEditing
     */
    public function getRestrictions(): CartRuleRestrictionsForEditing
    {
        return $this->restrictions;
    }
}
