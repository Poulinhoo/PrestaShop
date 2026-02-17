<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Form\IdentifiableObject\DataProvider;

use DateTimeImmutable;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Query\GetCartRuleForEditing;
use PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult\CartRuleForEditing;
use PrestaShop\PrestaShop\Core\Domain\Configuration\ShopConfigurationInterface;
use PrestaShop\PrestaShop\Core\Domain\ValueObject\Reduction;
use PrestaShop\PrestaShop\Core\Util\DateTime\DateTime as DateTimeUtil;

class CartRuleFormDataProvider implements FormDataProviderInterface
{
    /**
     * @var CommandBusInterface
     */
    private $queryBus;

    /**
     * @var ShopConfigurationInterface
     */
    private $configuration;

    public function __construct(
        CommandBusInterface $queryBus,
        ShopConfigurationInterface $configuration
    ) {
        $this->queryBus = $queryBus;
        $this->configuration = $configuration;
    }

    /**
     * {@inheritDoc}
     */
    public function getData($id)
    {
        /** @var CartRuleForEditing $editableCartRule */
        $editableCartRule = $this->queryBus->handle(new GetCartRuleForEditing($id));

        // @todo: finish up in a dedicated PR when EditCartRuleCommand is introduced
        return [
            'information' => [
                'name' => $editableCartRule->getInformation()->getLocalizedNames(),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultData()
    {
        $now = new DateTimeImmutable();

        return [
            'information' => [
                'highlight' => false,
                'partial_use' => true,
                'priority' => 1,
                'active' => true,
            ],
            'conditions' => [
                'valid_date_range' => [
                    'from' => $now->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT),
                    'to' => $now->modify('+1 month')->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT),
                ],
                'minimum_amount' => [
                    'amount' => 0,
                    'currency' => (int) $this->configuration->get('PS_CURRENCY_DEFAULT'),
                    'tax_included' => false,
                    'shipping_included' => false,
                ],
                'total_available' => 1,
                'available_per_user' => 1,
                'restrictions' => [],
                'customer' => [],
            ],
            'actions' => [
                'free_shipping' => false,
                'discount' => [
                    'reduction' => [
                        'value' => 0,
                        'type' => Reduction::TYPE_PERCENTAGE,
                        'currency' => (int) $this->configuration->get('PS_CURRENCY_DEFAULT'),
                        'tax_included' => true,
                    ],
                    'specific_product' => [],
                    'apply_to_discounted_products' => true,
                ],
                'gift_product' => [],
            ],
        ];
    }
}
