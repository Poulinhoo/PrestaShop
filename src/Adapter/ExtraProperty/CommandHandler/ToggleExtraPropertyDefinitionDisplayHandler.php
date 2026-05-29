<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\ExtraProperty\CommandHandler;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\ToggleExtraPropertyDefinitionDisplayCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\CommandHandler\ToggleExtraPropertyDefinitionDisplayHandlerInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface;

/**
 * Inverts one of the display boolean flags (display_api, display_front)
 * on a core extra property definition.
 *
 * Reads the current value from the DB row and toggles it by re-registering with the inverted flag.
 * The registry handles cache invalidation.
 */
#[AsCommandHandler]
final class ToggleExtraPropertyDefinitionDisplayHandler extends AbstractExtraPropertyDefinitionHandler implements ToggleExtraPropertyDefinitionDisplayHandlerInterface
{
    /**
     * @param ExtraPropertyRegistryInterface $registry
     * @param Connection $connection
     * @param string $prefix
     */
    public function __construct(
        private readonly ExtraPropertyRegistryInterface $registry,
        Connection $connection,
        string $prefix,
    ) {
        parent::__construct($connection, $prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(ToggleExtraPropertyDefinitionDisplayCommand $command): void
    {
        $row = $this->findRawRowById($command->getId()->getValue());
        $this->assertIsNotModuleOwned($row);

        $fieldToParam = [
            ToggleExtraPropertyDefinitionDisplayCommand::DISPLAY_API => 'displayApi',
            ToggleExtraPropertyDefinitionDisplayCommand::DISPLAY_FRONT => 'displayFront',
        ];

        $param = $fieldToParam[$command->getDisplayField()];
        $currentValue = !empty($row[$command->getDisplayField()]);

        $options = $this->buildOptionsFromRow($row, [$param => !$currentValue]);

        // Re-register with the toggled flag; the registry will UPDATE the existing row.
        $this->registry->register(
            (string) $row['entity_name'],
            (string) $row['property_name'],
            $options
        );
    }
}
