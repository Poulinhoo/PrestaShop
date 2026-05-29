<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\ExtraProperty\CommandHandler;

use Doctrine\DBAL\Connection;
use PrestaShop\PrestaShop\Core\CommandBus\Attributes\AsCommandHandler;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\AddExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\CommandHandler\AddExtraPropertyDefinitionHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\ExtraPropertyException;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\ValueObject\ExtraPropertyDefinitionId;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertySqlIndex;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyType;
use PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;

/**
 * Creates a new core extra property definition via the registry.
 *
 * Delegates to ExtraPropertyRegistryInterface::register() which creates the definition
 * row and the physical SQL column. After registration, loads the definition to return its id.
 */
#[AsCommandHandler]
final class AddExtraPropertyDefinitionHandler extends AbstractExtraPropertyDefinitionHandler implements AddExtraPropertyDefinitionHandlerInterface
{
    /**
     * @param ExtraPropertyRegistryInterface $registry
     * @param ExtraPropertyDefinitionRepositoryInterface $readRepository
     * @param Connection $connection
     * @param string $prefix
     */
    public function __construct(
        private readonly ExtraPropertyRegistryInterface $registry,
        private readonly ExtraPropertyDefinitionRepositoryInterface $readRepository,
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
    public function handle(AddExtraPropertyDefinitionCommand $command): ExtraPropertyDefinitionId
    {
        $formOptionsDecoded = null;
        if (null !== $command->getFormOptions() && '' !== $command->getFormOptions()) {
            $formOptionsDecoded = json_decode($command->getFormOptions(), true);
        }

        $associatedFormsDecoded = null;
        if (null !== $command->getAssociatedForms() && '' !== $command->getAssociatedForms()) {
            $decoded = json_decode($command->getAssociatedForms(), true);
            if (is_array($decoded)) {
                $associatedFormsDecoded = array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v) && '' !== $v));
            }
        }

        $associatedGridsDecoded = null;
        if (null !== $command->getAssociatedGrids() && '' !== $command->getAssociatedGrids()) {
            $decoded = json_decode($command->getAssociatedGrids(), true);
            if (is_array($decoded)) {
                $associatedGridsDecoded = array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v) && '' !== $v));
            }
        }

        $options = new ExtraPropertyOptions(
            type: ExtraPropertyType::from($command->getFieldType()),
            scope: ExtraPropertyScope::from($command->getFieldScope()),
            enumValues: null,
            defaultValue: '' !== ($command->getDefaultValue() ?? '') ? $command->getDefaultValue() : null,
            nullable: true,
            formRequired: $command->isFormRequired(),
            size: '' !== ($command->getSize() ?? '') ? (int) $command->getSize() : null,
            moduleName: null,
            labelWording: '' !== ($command->getLabelWording() ?? '') ? $command->getLabelWording() : null,
            labelDomain: '' !== ($command->getLabelDomain() ?? '') ? $command->getLabelDomain() : null,
            descriptionWording: '' !== ($command->getDescriptionWording() ?? '') ? $command->getDescriptionWording() : null,
            descriptionDomain: '' !== ($command->getDescriptionDomain() ?? '') ? $command->getDescriptionDomain() : null,
            sqlIndex: ExtraPropertySqlIndex::from($command->getSqlIndex()),
            formFieldType: '' !== ($command->getFormFieldType() ?? '') ? $command->getFormFieldType() : null,
            formOptions: is_array($formOptionsDecoded) ? $formOptionsDecoded : null,
            validator: '' !== ($command->getValidator() ?? '') ? $command->getValidator() : null,
            displayApi: $command->isDisplayApi(),
            associatedForms: $associatedFormsDecoded,
            associatedGrids: $associatedGridsDecoded,
            displayFront: $command->isDisplayFront(),
        );

        $registered = $this->registry->register(
            $command->getEntityName(),
            $command->getPropertyName(),
            $options
        );

        if (!$registered) {
            throw new ExtraPropertyException(
                sprintf(
                    'Failed to register extra property "%s" on entity "%s".',
                    $command->getPropertyName(),
                    $command->getEntityName()
                )
            );
        }

        // Fetch the newly created definition to retrieve its auto-generated id.
        $definition = $this->readRepository->findDefinitionByModuleAndField(
            $command->getEntityName(),
            null,
            $command->getPropertyName(),
            $command->getFieldScope()
        );

        if (null === $definition) {
            throw new ExtraPropertyException(
                sprintf(
                    'Extra property "%s" on entity "%s" was registered but could not be loaded afterwards.',
                    $command->getPropertyName(),
                    $command->getEntityName()
                )
            );
        }

        return new ExtraPropertyDefinitionId($definition->getId());
    }
}
