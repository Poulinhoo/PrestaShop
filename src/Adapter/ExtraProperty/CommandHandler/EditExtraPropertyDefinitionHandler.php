<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\ExtraProperty\CommandHandler;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\EditExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\CommandHandler\EditExtraPropertyDefinitionHandlerInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface;

/**
 * Updates editable metadata fields of a core extra property definition.
 *
 * Structural fields (type, scope, size, sql_index) are preserved from the existing row.
 * Uses the registry to ensure cache invalidation occurs after the update.
 */
#[AsCommandHandler]
final class EditExtraPropertyDefinitionHandler extends AbstractExtraPropertyDefinitionHandler implements EditExtraPropertyDefinitionHandlerInterface
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
    public function handle(EditExtraPropertyDefinitionCommand $command): void
    {
        $row = $this->findRawRowById($command->getId()->getValue());
        $this->assertIsNotModuleOwned($row);

        // Build the overrides map from non-null setters in the command.
        $overrides = [];

        if (null !== $command->getDisplayApi()) {
            $overrides['displayApi'] = $command->getDisplayApi();
        }
        if (null !== $command->getDisplayFront()) {
            $overrides['displayFront'] = $command->getDisplayFront();
        }
        if (null !== $command->getFormRequired()) {
            $overrides['formRequired'] = $command->getFormRequired();
        }
        if (null !== $command->getLabelWording()) {
            $overrides['labelWording'] = '' !== $command->getLabelWording() ? $command->getLabelWording() : null;
        }
        if (null !== $command->getLabelDomain()) {
            $overrides['labelDomain'] = '' !== $command->getLabelDomain() ? $command->getLabelDomain() : null;
        }
        if (null !== $command->getDescriptionWording()) {
            $overrides['descriptionWording'] = '' !== $command->getDescriptionWording() ? $command->getDescriptionWording() : null;
        }
        if (null !== $command->getDescriptionDomain()) {
            $overrides['descriptionDomain'] = '' !== $command->getDescriptionDomain() ? $command->getDescriptionDomain() : null;
        }
        if (null !== $command->getValidator()) {
            $overrides['validator'] = '' !== $command->getValidator() ? $command->getValidator() : null;
        }
        if (null !== $command->getFormFieldType()) {
            $overrides['formFieldType'] = '' !== $command->getFormFieldType() ? $command->getFormFieldType() : null;
        }
        if (null !== $command->getFormOptions()) {
            $decoded = '' !== $command->getFormOptions() ? json_decode($command->getFormOptions(), true) : null;
            $overrides['formOptions'] = is_array($decoded) ? $decoded : null;
        }
        if (null !== $command->getAssociatedForms()) {
            $decoded = '' !== $command->getAssociatedForms() ? json_decode($command->getAssociatedForms(), true) : null;
            $overrides['associatedForms'] = is_array($decoded)
                ? (array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v) && '' !== $v)) ?: null)
                : null;
        }
        if (null !== $command->getAssociatedGrids()) {
            $decoded = '' !== $command->getAssociatedGrids() ? json_decode($command->getAssociatedGrids(), true) : null;
            $overrides['associatedGrids'] = is_array($decoded)
                ? (array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v) && '' !== $v)) ?: null)
                : null;
        }

        $options = $this->buildOptionsFromRow($row, $overrides);

        // Re-register with the same structural key triggers an UPDATE via the registry's conflict handling.
        $this->registry->register(
            (string) $row['entity_name'],
            (string) $row['property_name'],
            $options
        );
    }
}
