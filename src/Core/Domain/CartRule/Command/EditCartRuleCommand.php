<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\Command;

use DateTimeImmutable;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleAction;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;
use PrestaShop\PrestaShop\Core\Domain\Currency\ValueObject\CurrencyId;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\CustomerId;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\CustomerIdInterface;
use PrestaShop\PrestaShop\Core\Domain\Customer\ValueObject\NoCustomerId;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Money;

class EditCartRuleCommand
{
    /**
     * @var CartRuleId
     */
    private $cartRuleId;

    /**
     * @var string|null
     */
    private $description;

    /**
     * @var string|null
     */
    private $code;

    /**
     * @var Money|null
     */
    private $minimumAmount;

    /**
     * @var bool|null
     */
    private $minimumAmountShippingIncluded;

    /**
     * @var CustomerIdInterface|null
     */
    private $customerId;

    /**
     * @var array<int, string>|null
     */
    private $localizedNames;

    /**
     * @var bool|null
     */
    private $highlightInCart;

    /**
     * @var bool|null
     */
    private $allowPartialUse;

    /**
     * @var int|null
     */
    private $priority;

    /**
     * @var bool|null
     */
    private $active;

    /**
     * @var DateTimeImmutable|null
     */
    private $validFrom;

    /**
     * @var DateTimeImmutable|null
     */
    private $validTo;

    /**
     * @var int|null
     */
    private $totalQuantity;

    /**
     * @var int|null
     */
    private $quantityPerUser;

    /**
     * @var CartRuleAction|null
     */
    private $cartRuleAction;

    public function __construct(
        int $cartRuleId
    ) {
        $this->cartRuleId = new CartRuleId($cartRuleId);
    }

    public function getCartRuleId(): CartRuleId
    {
        return $this->cartRuleId;
    }

    /**
     * @param array<int, string> $localizedNames names index by language id
     *
     * @return EditCartRuleCommand
     */
    public function setLocalizedNames(array $localizedNames): EditCartRuleCommand
    {
        $this->localizedNames = $localizedNames;

        return $this;
    }

    /**
     * @return array<int, string>|null
     */
    public function getLocalizedNames(): ?array
    {
        return $this->localizedNames;
    }

    public function setDescription(string $description): EditCartRuleCommand
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function highlightInCart(): ?bool
    {
        return $this->highlightInCart;
    }

    public function setHighlightInCart(bool $highlightInCart): EditCartRuleCommand
    {
        $this->highlightInCart = $highlightInCart;

        return $this;
    }

    public function allowPartialUse(): ?bool
    {
        return $this->allowPartialUse;
    }

    public function setAllowPartialUse(?bool $allowPartialUse): EditCartRuleCommand
    {
        $this->allowPartialUse = $allowPartialUse;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): EditCartRuleCommand
    {
        $this->active = $active;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): EditCartRuleCommand
    {
        $this->code = $code;

        return $this;
    }

    public function getCustomerId(): ?CustomerIdInterface
    {
        return $this->customerId;
    }

    public function setCustomerId(int $customerId): EditCartRuleCommand
    {
        if ($customerId) {
            $this->customerId = new CustomerId($customerId);
        } else {
            $this->customerId = new NoCustomerId();
        }

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): EditCartRuleCommand
    {
        if (0 >= $priority) {
            throw new CartRuleConstraintException(
                sprintf('Invalid cart rule priority "%s". Must be a positive integer.', $priority),
                CartRuleConstraintException::INVALID_PRIORITY
            );
        }

        $this->priority = $priority;

        return $this;
    }

    public function setValidityDateRange(DateTimeImmutable $validFrom, DateTimeImmutable $validTo): EditCartRuleCommand
    {
        $this->assertDateRangeIsValid($validFrom, $validTo);
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;

        return $this;
    }

    public function getValidFrom(): ?DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function getTotalQuantity(): ?int
    {
        return $this->totalQuantity;
    }

    public function setTotalQuantity(int $quantity): EditCartRuleCommand
    {
        if (0 > $quantity) {
            throw new CartRuleConstraintException(sprintf('Quantity cannot be lower than zero, %d given', $quantity), CartRuleConstraintException::INVALID_QUANTITY);
        }

        $this->totalQuantity = $quantity;

        return $this;
    }

    public function getQuantityPerUser(): ?int
    {
        return $this->quantityPerUser;
    }

    public function setQuantityPerUser(int $quantity): EditCartRuleCommand
    {
        if (0 > $quantity) {
            throw new CartRuleConstraintException(sprintf('Quantity per user cannot be lower than zero, %d given', $quantity), CartRuleConstraintException::INVALID_QUANTITY_PER_USER);
        }

        $this->quantityPerUser = $quantity;

        return $this;
    }

    public function getCartRuleAction(): ?CartRuleAction
    {
        return $this->cartRuleAction;
    }

    public function setMinimumAmount(
        string $minimumAmount,
        int $currencyId,
        bool $taxIncluded,
        bool $shippingIncluded
    ): EditCartRuleCommand {
        $this->minimumAmount = new Money(
            new DecimalNumber($minimumAmount),
            new CurrencyId($currencyId),
            $taxIncluded
        );
        $this->minimumAmountShippingIncluded = $shippingIncluded;

        return $this;
    }

    public function getMinimumAmount(): ?Money
    {
        return $this->minimumAmount;
    }

    public function isMinimumAmountShippingIncluded(): ?bool
    {
        return $this->minimumAmountShippingIncluded;
    }

    public function setCartRuleAction(CartRuleAction $cartRuleAction): EditCartRuleCommand
    {
        $this->cartRuleAction = $cartRuleAction;

        return $this;
    }

    private function assertDateRangeIsValid(DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo): void
    {
        if ($dateFrom > $dateTo) {
            throw new CartRuleConstraintException('Date from cannot be greater than date to.', CartRuleConstraintException::DATE_FROM_GREATER_THAN_DATE_TO);
        }
    }
}
