<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\ExtraProperty\CommandHandler;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\DeleteExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\CommandHandler\DeleteExtraPropertyDefinitionHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\ExtraPropertyException;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface;

/**
 * Deletes a core extra property definition and optionally its physical SQL column.
 *
 * Delegates to ExtraPropertyRegistryInterface::unregister() which handles
 * the column drop (when requested) and cache invalidation.
 */
#[AsCommandHandler]
final class DeleteExtraPropertyDefinitionHandler extends AbstractExtraPropertyDefinitionHandler implements DeleteExtraPropertyDefinitionHandlerInterface
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
     *
     * @throws ExtraPropertyException
     */
    public function handle(DeleteExtraPropertyDefinitionCommand $command): void
    {
        $row = $this->findRawRowById($command->getId()->getValue());
        $this->assertIsNotModuleOwned($row);

        $unregistered = $this->registry->unregister(
            (string) $row['entity_name'],
            (string) $row['property_name'],
            null,
            ExtraPropertyScope::from((string) $row['scope']),
            $command->shouldDropColumn()
        );

        if (!$unregistered) {
            throw new ExtraPropertyException(
                sprintf(
                    'Failed to delete extra property definition with id %d.',
                    $command->getId()->getValue()
                )
            );
        }
    }
}
