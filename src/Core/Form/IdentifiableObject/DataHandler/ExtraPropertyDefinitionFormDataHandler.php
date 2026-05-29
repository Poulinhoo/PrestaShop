<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Form\IdentifiableObject\DataHandler;

use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\AddExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\EditExtraPropertyDefinitionCommand;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\ValueObject\ExtraPropertyDefinitionId;

/**
 * Handles form data submission for extra property definitions.
 *
 * create() dispatches AddExtraPropertyDefinitionCommand (no module_name — always null for BO-created fields).
 * update() dispatches EditExtraPropertyDefinitionCommand (structural fields are intentionally excluded).
 */
final class ExtraPropertyDefinitionFormDataHandler implements FormDataHandlerInterface
{
    /**
     * @param CommandBusInterface $commandBus
     */
    public function __construct(protected readonly CommandBusInterface $commandBus)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @param array<string, mixed> $data
     *
     * @return int
     */
    public function create(array $data): int
    {
        /** @var ExtraPropertyDefinitionId $id */
        $id = $this->commandBus->handle(new AddExtraPropertyDefinitionCommand(
            entityName: (string) ($data['entity_name'] ?? ''),
            propertyName: (string) ($data['property_name'] ?? ''),
            fieldType: (string) ($data['type'] ?? 'string'),
            fieldScope: (string) ($data['scope'] ?? 'common'),
            sqlIndex: (string) ($data['sql_index'] ?? 'none'),
            displayApi: (bool) ($data['display_api'] ?? false),
            displayFront: (bool) ($data['display_front'] ?? false),
            formRequired: (bool) ($data['form_required'] ?? false),
            size: $this->nullableString($data['size'] ?? null),
            defaultValue: $this->nullableString($data['default_value'] ?? null),
            labelWording: $this->nullableString($data['label_wording'] ?? null),
            labelDomain: $this->nullableString($data['label_domain'] ?? null),
            descriptionWording: $this->nullableString($data['description_wording'] ?? null),
            descriptionDomain: $this->nullableString($data['description_domain'] ?? null),
            validator: $this->nullableString($data['validator'] ?? null),
            formFieldType: $this->nullableString($data['form_field_type'] ?? null),
            formOptions: $this->nullableString($data['form_options'] ?? null),
            associatedForms: $this->nullableString($data['associated_forms'] ?? null),
            associatedGrids: $this->nullableString($data['associated_grids'] ?? null),
        ));

        return $id->getValue();
    }

    /**
     * {@inheritdoc}
     *
     * @param int $id
     * @param array<string, mixed> $data
     */
    public function update($id, array $data): void
    {
        $command = (new EditExtraPropertyDefinitionCommand((int) $id))
            ->setDisplayApi((bool) ($data['display_api'] ?? false))
            ->setDisplayFront((bool) ($data['display_front'] ?? false))
            ->setFormRequired((bool) ($data['form_required'] ?? false))
            ->setLabelWording($this->nullableString($data['label_wording'] ?? null))
            ->setLabelDomain($this->nullableString($data['label_domain'] ?? null))
            ->setDescriptionWording($this->nullableString($data['description_wording'] ?? null))
            ->setDescriptionDomain($this->nullableString($data['description_domain'] ?? null))
            ->setValidator($this->nullableString($data['validator'] ?? null))
            ->setFormFieldType($this->nullableString($data['form_field_type'] ?? null))
            ->setFormOptions($this->nullableString($data['form_options'] ?? null))
            ->setAssociatedForms($this->nullableString($data['associated_forms'] ?? null))
            ->setAssociatedGrids($this->nullableString($data['associated_grids'] ?? null));

        $this->commandBus->handle($command);
    }

    /**
     * Converts an empty string or null to null; leaves other values as strings.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    protected function nullableString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }
}
