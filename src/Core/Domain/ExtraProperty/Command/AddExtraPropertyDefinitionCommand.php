<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command;

/**
 * Registers a new core extra property definition (module_name = null).
 *
 * Structural fields (entity_name, property_name, type, scope, size, sql_index)
 * are immutable once created. Only label, display, and validation metadata
 * can be changed afterwards via EditExtraPropertyDefinitionCommand.
 */
class AddExtraPropertyDefinitionCommand
{
    /**
     * @param string $entityName Entity table name (e.g. 'product', 'customer')
     * @param string $propertyName Property identifier (e.g. 'internal_code')
     * @param string $fieldType Type literal: 'int', 'bool', 'string', 'float', 'date', 'html', 'json', 'choice'
     * @param string $fieldScope Scope literal: 'common', 'lang', 'shop'
     * @param string $sqlIndex SQL index strategy: 'none', 'key', 'unique'
     * @param bool $displayApi Whether to expose in Admin API responses
     * @param bool $displayFront Whether to include in FO presenters
     * @param bool $formRequired Whether the BO form field is required (NotBlank)
     * @param string|null $size Varchar size for string type (null → 255)
     * @param string|null $defaultValue SQL DEFAULT clause value
     * @param string|null $labelWording i18n wording for the BO label (required when associatedForms or associatedGrids)
     * @param string|null $labelDomain Translation domain for the label
     * @param string|null $descriptionWording i18n wording for the BO description
     * @param string|null $descriptionDomain Translation domain for the description
     * @param string|null $validator Validate:: method name for value validation
     * @param string|null $formFieldType Symfony form type FQCN override
     * @param string|null $formOptions JSON-encoded extra options for the Symfony form type
     * @param string|null $associatedForms JSON-encoded array of form placement entries
     * @param string|null $associatedGrids JSON-encoded array of grid placement entries
     */
    public function __construct(
        protected readonly string $entityName,
        protected readonly string $propertyName,
        protected readonly string $fieldType,
        protected readonly string $fieldScope,
        protected readonly string $sqlIndex,
        protected readonly bool $displayApi,
        protected readonly bool $displayFront,
        protected readonly bool $formRequired,
        protected readonly ?string $size,
        protected readonly ?string $defaultValue,
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

    public function getEntityName(): string
    {
        return $this->entityName;
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

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
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
}
