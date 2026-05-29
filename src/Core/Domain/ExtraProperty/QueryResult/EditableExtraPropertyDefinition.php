<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\ExtraProperty\QueryResult;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\ValueObject\ExtraPropertyDefinitionId;

/**
 * Read-only DTO carrying all data for an extra property definition edit form.
 *
 * Structural fields (entity_name, property_name, type, scope, size, sql_index)
 * are included for display purposes in the edit form (shown as read-only / disabled).
 * Only the non-structural fields may be mutated via EditExtraPropertyDefinitionCommand.
 */
class EditableExtraPropertyDefinition
{
    /**
     * @param ExtraPropertyDefinitionId $id
     * @param string $entityName
     * @param string|null $moduleName Null for core fields; non-null = module-owned (read-only)
     * @param string $propertyName
     * @param string $fieldType Type literal (e.g. 'string', 'bool')
     * @param string $fieldScope Scope literal ('common', 'lang', 'shop')
     * @param string $sqlIndex SQL index strategy ('none', 'key', 'unique')
     * @param int|null $size Varchar size for string fields
     * @param string|null $defaultValue
     * @param bool $displayApi
     * @param bool $displayFront
     * @param bool $formRequired
     * @param string|null $labelWording
     * @param string|null $labelDomain
     * @param string|null $descriptionWording
     * @param string|null $descriptionDomain
     * @param string|null $validator
     * @param string|null $formFieldType
     * @param string|null $formOptions JSON-encoded options
     * @param string|null $associatedForms JSON-encoded form placement array
     * @param string|null $associatedGrids JSON-encoded grid placement array
     */
    public function __construct(
        protected readonly ExtraPropertyDefinitionId $id,
        protected readonly string $entityName,
        protected readonly ?string $moduleName,
        protected readonly string $propertyName,
        protected readonly string $fieldType,
        protected readonly string $fieldScope,
        protected readonly string $sqlIndex,
        protected readonly ?int $size,
        protected readonly ?string $defaultValue,
        protected readonly bool $displayApi,
        protected readonly bool $displayFront,
        protected readonly bool $formRequired,
        protected readonly ?string $labelWording,
        protected readonly ?string $labelDomain,
        protected readonly ?string $descriptionWording,
        protected readonly ?string $descriptionDomain,
        protected readonly ?string $validator,
        protected readonly ?string $formFieldType,
        protected readonly ?string $formOptions,
        protected readonly ?string $associatedForms,
        protected readonly ?string $associatedGrids,
    ) {
    }

    public function getId(): ExtraPropertyDefinitionId
    {
        return $this->id;
    }

    public function getEntityName(): string
    {
        return $this->entityName;
    }

    public function getModuleName(): ?string
    {
        return $this->moduleName;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getFieldType(): string
    {
        return $this->fieldType;
    }

    public function getFieldScope(): string
    {
        return $this->fieldScope;
    }

    public function getSqlIndex(): string
    {
        return $this->sqlIndex;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function isDisplayApi(): bool
    {
        return $this->displayApi;
    }

    public function isDisplayFront(): bool
    {
        return $this->displayFront;
    }

    public function isFormRequired(): bool
    {
        return $this->formRequired;
    }

    public function getLabelWording(): ?string
    {
        return $this->labelWording;
    }

    public function getLabelDomain(): ?string
    {
        return $this->labelDomain;
    }

    public function getDescriptionWording(): ?string
    {
        return $this->descriptionWording;
    }

    public function getDescriptionDomain(): ?string
    {
        return $this->descriptionDomain;
    }

    public function getValidator(): ?string
    {
        return $this->validator;
    }

    public function getFormFieldType(): ?string
    {
        return $this->formFieldType;
    }

    public function getFormOptions(): ?string
    {
        return $this->formOptions;
    }

    public function getAssociatedForms(): ?string
    {
        return $this->associatedForms;
    }

    public function getAssociatedGrids(): ?string
    {
        return $this->associatedGrids;
    }

    public function isModuleOwned(): bool
    {
        return null !== $this->moduleName;
    }
}
