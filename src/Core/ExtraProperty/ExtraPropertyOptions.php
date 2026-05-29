<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty;

/**
 * Value object holding all options for registering an extra property definition.
 *
 * Pass an instance of this class to Module::registerExtraProperty() to configure the field.
 * All properties are public readonly to allow direct access without getters, and to ensure
 * immutability. Use withModuleName() to derive a copy with a resolved module name.
 *
 * About BO label translations:
 * - label/description are not stored as per-language values in SQL;
 * - use wording + domain pairs (labelWording/labelDomain and descriptionWording/descriptionDomain);
 * - BO rendering translates them at runtime with Translator::trans();
 * - for BO translation pages to discover those strings, modules must expose the same wordings through
 *   explicit $this->trans('...', [], 'Modules.<Module>.Admin') calls (and/or module XLF files).
 *
 * @see \PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface::register()
 * @see Schema\ColumnDefinitionMapper
 */
final class ExtraPropertyOptions
{
    /**
     * @param ExtraPropertyType $type
     *                                Field storage type. Determines the SQL column type via ColumnDefinitionMapper.
     * @param ExtraPropertyScope $scope
     *                                  Storage scope: Common (entity-level), Lang (per-language), Shop (per-shop)
     * @param list<string>|null $enumValues
     *                                      For Choice type: the SQL ENUM allowed values. Generates ENUM('v1','v2') DDL.
     *                                      Ignored for other types.
     * @param scalar|null $defaultValue
     *                                  If provided, adds a DEFAULT clause in the DDL, quoted according to field type.
     *                                  Also persisted in the registry so the configured default is always retrievable.
     * @param bool $nullable
     *                       Controls NULL vs NOT NULL in the DDL
     * @param bool $formRequired
     *                           When true, marks the BO form field as required (HTML required + Symfony NotBlank constraint).
     *                           Independent of $nullable: a field can be NOT NULL with a default and still be optional in the form.
     * @param int|null $size
     *                       For ExtraPropertyType::STRING: the varchar column length (1–16383).
     *                       Defaults to 255 when null. Ignored for all other types.
     * @param string|null $moduleName
     *                                Override the owning module name. Null means use the calling module's name.
     *                                Automatically populated by Module::registerExtraProperty() when left null.
     * @param string|null $labelWording
     *                                  Translation wording key shown in BO forms. Example: "Theme color".
     * @param string|null $labelDomain
     *                                 Translation domain used for the label wording. Example: "Modules.MyModule.Admin".
     * @param string|null $descriptionWording
     *                                        Translation wording key shown as BO help text
     * @param string|null $descriptionDomain
     *                                       Translation domain used for the description wording
     * @param ExtraPropertySqlIndex $sqlIndex
     *                                        SQL index strategy on the storage column
     * @param string|null $formFieldType
     *                                   Fully-qualified Symfony Form type class name used by the BO form renderer.
     *                                   When null, the default mapping from ExtraPropertyType is applied.
     * @param array<string, mixed>|null $formOptions
     *                                               Extra options passed verbatim to the Symfony form type constructor.
     *                                               Merged with the automatically-resolved options; developer-supplied values win.
     * @param string|null $validator
     *                               PrestaShop Validate method name (e.g. "isUrl", "isBool") applied before persistence.
     * @param bool $displayApi
     *                         Include this field in Admin API JSON responses
     * @param list<string>|null $associatedForms
     *                                           Form placement entries. Each entry uses the format "formId[.path[:before|after]]":
     *                                           - "category"                               → appears in the category form, default extra_fields section
     *                                           - "category.options.extra_properties"      → injected at "options.extra_properties" path
     *                                           - "category.options.name:before"           → injected BEFORE "name" inside "options"
     *                                           - "category.options.name:after"            → injected AFTER "name" inside "options"
     *                                           Null or empty means the field is not shown in any BO form.
     *                                           Each formId must be unique within the list.
     * @param list<string>|null $associatedGrids
     *                                           Grid placement entries. Each entry uses the format "gridId[.columnId[:before|after]]":
     *                                           - "product"                  → appears in product grid, appended at end
     *                                           - "product.reference"        → appears after the 'reference' column (default :after)
     *                                           - "product.reference:before" → appears before the 'reference' column
     *                                           - "product.reference:after"  → appears after the 'reference' column (explicit)
     *                                           Null or empty means the field is not displayed in any grid.
     *                                           Each gridId must be unique within the list.
     * @param bool $displayFront
     *                           Allow this field to be exposed in front-office presenters.
     *                           Set to false for BO-only or API-only fields.
     */
    public function __construct(
        public readonly ExtraPropertyType $type = ExtraPropertyType::STRING,
        public readonly ExtraPropertyScope $scope = ExtraPropertyScope::Common,
        public readonly ?array $enumValues = null,
        public readonly int|float|string|bool|null $defaultValue = null,
        public readonly bool $nullable = true,
        public readonly bool $formRequired = false,
        public readonly ?int $size = null,
        public readonly ?string $moduleName = null,
        public readonly ?string $labelWording = null,
        public readonly ?string $labelDomain = null,
        public readonly ?string $descriptionWording = null,
        public readonly ?string $descriptionDomain = null,
        public readonly ExtraPropertySqlIndex $sqlIndex = ExtraPropertySqlIndex::None,
        public readonly ?string $formFieldType = null,
        public readonly ?array $formOptions = null,
        public readonly ?string $validator = null,
        public readonly bool $displayApi = false,
        public readonly ?array $associatedForms = null,
        public readonly ?array $associatedGrids = null,
        public readonly bool $displayFront = true,
    ) {
    }

    /**
     * Returns a copy of this options object with the given module name set.
     *
     * Used by Module::registerExtraProperty() to inject the calling module's name
     * when moduleName was left null by the developer.
     *
     * @param string $moduleName
     *
     * @return self
     */
    public function withModuleName(string $moduleName): self
    {
        return new self(
            type: $this->type,
            scope: $this->scope,
            enumValues: $this->enumValues,
            defaultValue: $this->defaultValue,
            nullable: $this->nullable,
            formRequired: $this->formRequired,
            size: $this->size,
            moduleName: $moduleName,
            labelWording: $this->labelWording,
            labelDomain: $this->labelDomain,
            descriptionWording: $this->descriptionWording,
            descriptionDomain: $this->descriptionDomain,
            sqlIndex: $this->sqlIndex,
            formFieldType: $this->formFieldType,
            formOptions: $this->formOptions,
            validator: $this->validator,
            displayApi: $this->displayApi,
            associatedForms: $this->associatedForms,
            associatedGrids: $this->associatedGrids,
            displayFront: $this->displayFront,
        );
    }
}
