<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Adapter\CartRule\CommandHandler;

use CartRule;
use DateTimeImmutable;
use PrestaShop\PrestaShop\Adapter\CartRule\CartRuleActionFiller;
use PrestaShop\PrestaShop\Adapter\CartRule\Repository\CartRuleRepository;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Command\AddCartRuleCommand;
use PrestaShop\PrestaShop\Core\Domain\CartRule\CommandHandler\AddCartRuleHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\CartRuleId;
use PrestaShop\PrestaShop\Core\Util\DateTime\DateTime as DateTimeUtil;
use PrestaShopException;

/**
 * Handles adding new cart rule using legacy logic.
 */
#[AsCommandHandler]
class AddCartRuleHandler implements AddCartRuleHandlerInterface
{
    /**
     * @var CartRuleRepository
     */
    private $cartRuleRepository;

    /**
     * @var CartRuleActionFiller
     */
    private $cartRuleActionFiller;

    /**
     * @param CartRuleRepository $cartRuleRepository
     * @param CartRuleActionFiller $cartRuleActionFiller
     */
    public function __construct(
        CartRuleRepository $cartRuleRepository,
        CartRuleActionFiller $cartRuleActionFiller
    ) {
        $this->cartRuleRepository = $cartRuleRepository;
        $this->cartRuleActionFiller = $cartRuleActionFiller;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(AddCartRuleCommand $command): CartRuleId
    {
        $cartRule = $this->cartRuleRepository->add($this->buildCartRuleFromCommandData($command));

        return new CartRuleId((int) $cartRule->id);
    }

    /**
     * @param AddCartRuleCommand $command
     *
     * @return CartRule
     *
     * @throws PrestaShopException
     */
    private function buildCartRuleFromCommandData(AddCartRuleCommand $command): CartRule
    {
        $cartRule = new CartRule();

        $cartRule->name = $command->getLocalizedNames();
        $cartRule->description = $command->getDescription();
        $cartRule->code = $command->getCode();
        $cartRule->highlight = $command->isHighlightInCart();
        $cartRule->partial_use = $command->allowPartialUse();
        $cartRule->priority = $command->getPriority();
        $cartRule->active = $command->isActive();

        $this->fillCartRuleConditionsFromCommandData($cartRule, $command);
        $this->cartRuleActionFiller->fillUpdatableProperties($cartRule, $command->getCartRuleAction());

        return $cartRule;
    }

    /**
     * Fills cart rule with conditions data from command.
     *
     * @param CartRule $cartRule
     * @param AddCartRuleCommand $command
     */
    private function fillCartRuleConditionsFromCommandData(CartRule $cartRule, AddCartRuleCommand $command): void
    {
        $cartRule->id_customer = null !== $command->getCustomerId() ? $command->getCustomerId()->getValue() : null;

        if (null === $command->getValidFrom() || null === $command->getValidTo()) {
            $now = new DateTimeImmutable();

            $cartRule->date_from = $now->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT);
            $cartRule->date_to = $now->modify('+1 month')->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT);
        } else {
            $cartRule->date_from = $command->getValidFrom()->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT);
            $cartRule->date_to = $command->getValidTo()->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT);
        }

        $minimumAmount = $command->getMinimumAmount();
        if ($minimumAmount) {
            $cartRule->minimum_amount = (float) (string) $minimumAmount->getAmount();
            $cartRule->minimum_amount_currency = $minimumAmount->getCurrencyId()->getValue();
            $cartRule->minimum_amount_shipping = $command->isMinimumAmountShippingIncluded();
            $cartRule->minimum_amount_tax = $minimumAmount->isTaxIncluded();
        }

        $cartRule->quantity = $command->getTotalQuantity();
        $cartRule->quantity_per_user = $command->getQuantityPerUser();
    }
}
