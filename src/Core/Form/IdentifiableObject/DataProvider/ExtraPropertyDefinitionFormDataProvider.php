<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Form\IdentifiableObject\DataProvider;

use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Query\GetExtraPropertyDefinitionForEditing;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult\EditableExtraPropertyDefinition;

/**
 * Provides form data for the extra property definition create / edit form.
 *
 * getData() dispatches GetExtraPropertyDefinitionForEditing and maps the DTO to form field names.
 * getDefaultData() provides sensible defaults for the creation form.
 */
final class ExtraPropertyDefinitionFormDataProvider implements FormDataProviderInterface
{
    /**
     * @param CommandBusInterface $queryBus
     */
    public function __construct(protected readonly CommandBusInterface $queryBus)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @param int $id
     *
     * @return array<string, mixed>
     */
    public function getData($id): array
    {
        /** @var EditableExtraPropertyDefinition $definition */
        $definition = $this->queryBus->handle(new GetExtraPropertyDefinitionForEditing((int) $id));

        return [
            // Structural fields (shown as read-only in edit form)
            'entity_name' => $definition->getEntityName(),
            'property_name' => $definition->getPropertyName(),
            'type' => $definition->getFieldType(),
            'scope' => $definition->getFieldScope(),
            'sql_index' => $definition->getSqlIndex(),
            'size' => $definition->getSize(),
            'default_value' => $definition->getDefaultValue(),
            // Display flags
            'display_api' => $definition->isDisplayApi(),
            'display_front' => $definition->isDisplayFront(),
            'form_required' => $definition->isFormRequired(),
            // Label / description
            'label_wording' => $definition->getLabelWording(),
            'label_domain' => $definition->getLabelDomain(),
            'description_wording' => $definition->getDescriptionWording(),
            'description_domain' => $definition->getDescriptionDomain(),
            // Validation
            'validator' => $definition->getValidator(),
            // Advanced integration
            'form_field_type' => $definition->getFormFieldType(),
            'form_options' => $definition->getFormOptions(),
            'associated_forms' => $definition->getAssociatedForms(),
            'associated_grids' => $definition->getAssociatedGrids(),
            // Meta (used by the form type to adapt behavior, not submitted)
            '_module_name' => $definition->getModuleName(),
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function getDefaultData(): array
    {
        return [
            'type' => 'string',
            'scope' => 'common',
            'sql_index' => 'none',
            'display_api' => false,
            'display_front' => false,
            'form_required' => false,
        ];
    }
}
