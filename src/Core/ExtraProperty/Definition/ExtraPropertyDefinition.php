<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertySqlIndex;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyType;

/**
 * Immutable value object representing an extra property definition.
 *
 * Serves as both the module-facing configuration object (passed to Module::registerExtraProperty())
 * and the internal read DTO (returned by repository methods). All fields use typed Enum values
 * instead of raw strings for type safety and PHPStan coverage.
 *
 * Fields used only at schema-creation time (not persisted in the registry):
 *   - $nullable: NULL vs NOT NULL in the DDL
 *   - $enumValues: ENUM literals for ExtraPropertyType::CHOICE fields
 *
 * Use the static factory ExtraPropertyDefinition::fromRow() to build an instance from a DB row.
 * Use withModuleName() / withEntityName() to derive copies with injected context.
 *
 * About BO label translations:
 * - label/description are not stored as per-language values in SQL;
 * - use wording + domain pairs (labelWording/labelDomain and descriptionWording/descriptionDomain);
 * - BO rendering translates them at runtime with Translator::trans();
 * - for BO translation pages to discover those strings, modules must expose the same wordings through
 *   explicit $this->trans('...', [], 'Modules.<Module>.Admin') calls (and/or module XLF files).
 *
 * @see \PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface::register()
 * @see \PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ColumnDefinitionMapper
 */
final class ExtraPropertyDefinition
{
    /**
     * @param ExtraPropertyType $type
     *                                Field storage type. Determines the SQL column type via ColumnDefinitionMapper.
     * @param ExtraPropertyScope $scope
     *                                  Storage scope: COMMON (entity-level), LANG (per-language), SHOP (per-shop)
     * @param string $propertyName
     *                             Property name as declared by the module (e.g. 'video_link').
     * @param string $entityName
     *                           Entity table name (e.g. 'product'). Empty string when created by a module before registration.
     *                           Filled automatically by ExtraPropertyRegistry on register() and by fromRow() on DB read.
     * @param string|null $moduleName
     *                                Override the owning module name. Null means use the calling module's name.
     *                                Automatically populated by Module::registerExtraProperty() when left null.
     * @param list<string>|null $enumValues
     *                                      For CHOICE type: the SQL ENUM allowed values. Generates ENUM('v1','v2') DDL.
     *                                      Ignored for other types. Not persisted in the registry — only used during schema creation.
     * @param scalar|null $defaultValue
     *                                  If provided, adds a DEFAULT clause in the DDL, quoted according to field type.
     *                                  Also persisted in the registry so the configured default is always retrievable.
     * @param bool $nullable
     *                       Controls NULL vs NOT NULL in the DDL. Not persisted — only used during schema creation.
     * @param bool $formRequired
     *                           When true, marks the BO form field as required (HTML required + Symfony NotBlank constraint).
     *                           Independent of $nullable: a field can be NOT NULL with a default and still be optional.
     * @param int|null $size
     *                       For ExtraPropertyType::STRING: the varchar column length (1–16383). Defaults to 255 when null.
     * @param ExtraPropertySqlIndex $sqlIndex
     *                                        SQL index strategy on the storage column
     * @param bool $displayApi
     *                         Include this field in Admin API JSON responses
     * @param bool $displayFront
     *                           Allow this field to be exposed in front-office presenters
     * @param list<string>|null $associatedForms
     *                                           Form placement entries. Each entry uses the format "formId[.path[:before|after]]":
     *                                           - "category"                               → appears in the category form, default extra_fields section
     *                                           - "category.options.extra_properties"      → injected at "options.extra_properties" path
     *                                           - "category.options.name:before"           → injected BEFORE "name" inside "options"
     *                                           - "category.options.name:after"            → injected AFTER "name" inside "options"
     *                                           Null or empty means the field is not shown in any BO form. Each formId must be unique.
     * @param list<string>|null $associatedGrids
     *                                           Grid placement entries. Each entry uses the format "gridId[.columnId[:before|after]]":
     *                                           - "product"                  → appears in product grid, appended at end
     *                                           - "product.reference:before" → appears before the 'reference' column
     *                                           Null or empty means the field is not displayed in any grid. Each gridId must be unique.
     * @param string|null $formFieldType
     *                                   Fully-qualified Symfony Form type class name used by the BO form renderer.
     *                                   When null, the default mapping from ExtraPropertyType is applied.
     * @param array<string, mixed>|null $formOptions
     *                                               Extra options passed verbatim to the Symfony form type constructor
     * @param string|null $validator
     *                               PrestaShop Validate method name (e.g. "isUrl", "isBool") applied before persistence.
     * @param string|null $labelWording
     *                                  Translation wording key shown in BO forms
     * @param string|null $labelDomain
     *                                 Translation domain used for the label wording
     * @param string|null $descriptionWording
     *                                        Translation wording key shown as BO help text
     * @param string|null $descriptionDomain
     *                                       Translation domain used for the description wording
     */
    public function __construct(
        public readonly ExtraPropertyType $type = ExtraPropertyType::STRING,
        public readonly ExtraPropertyScope $scope = ExtraPropertyScope::COMMON,
        public readonly string $propertyName = '',
        public readonly string $entityName = '',
        public readonly ?string $moduleName = null,
        public readonly ?array $enumValues = null,
        public readonly int|float|string|bool|null $defaultValue = null,
        public readonly bool $nullable = true,
        public readonly bool $formRequired = false,
        public readonly ?int $size = null,
        public readonly ExtraPropertySqlIndex $sqlIndex = ExtraPropertySqlIndex::NONE,
        public readonly bool $displayApi = false,
        public readonly bool $displayFront = true,
        public readonly ?array $associatedForms = null,
        public readonly ?array $associatedGrids = null,
        public readonly ?string $formFieldType = null,
        public readonly ?array $formOptions = null,
        public readonly ?string $validator = null,
        public readonly ?string $labelWording = null,
        public readonly ?string $labelDomain = null,
        public readonly ?string $descriptionWording = null,
        public readonly ?string $descriptionDomain = null,
    ) {
    }

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    /**
     * Builds an instance from a raw registry row (as returned by ExtraPropertyDefinitionRepository).
     *
     * Fields not stored in the registry (nullable, enumValues) receive safe defaults:
     * nullable defaults to true and enumValues to null.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $formOptionsRaw = $row['form_options'] ?? null;
        $formOptions = (is_string($formOptionsRaw) && '' !== $formOptionsRaw)
            ? json_decode($formOptionsRaw, true)
            : null;

        $associatedFormsRaw = $row['associated_forms'] ?? null;
        $associatedForms = (is_string($associatedFormsRaw) && '' !== $associatedFormsRaw)
            ? (array_values(array_filter((array) json_decode($associatedFormsRaw, true), static fn (mixed $v): bool => is_string($v) && '' !== $v)) ?: null)
            : null;

        $associatedGridsRaw = $row['associated_grids'] ?? null;
        $associatedGrids = (is_string($associatedGridsRaw) && '' !== $associatedGridsRaw)
            ? (array_values(array_filter((array) json_decode($associatedGridsRaw, true), static fn (mixed $v): bool => is_string($v) && '' !== $v)) ?: null)
            : null;

        return new self(
            type: ExtraPropertyType::from((string) ($row['type'] ?? 'string')),
            scope: ExtraPropertyScope::from((string) ($row['scope'] ?? 'common')),
            propertyName: (string) ($row['property_name'] ?? ''),
            entityName: (string) ($row['entity_name'] ?? ''),
            moduleName: isset($row['module_name']) && '' !== $row['module_name'] ? (string) $row['module_name'] : null,
            // enumValues and nullable are not persisted; use safe defaults
            enumValues: null,
            defaultValue: isset($row['default_value']) && '' !== $row['default_value'] ? (string) $row['default_value'] : null,
            nullable: true,
            formRequired: !empty($row['form_required']),
            size: isset($row['size']) && '' !== $row['size'] ? (int) $row['size'] : null,
            sqlIndex: ExtraPropertySqlIndex::from((string) ($row['sql_index'] ?? 'none')),
            displayApi: !empty($row['display_api']),
            displayFront: !empty($row['display_front']),
            associatedForms: is_array($associatedForms) ? $associatedForms : null,
            associatedGrids: is_array($associatedGrids) ? $associatedGrids : null,
            formFieldType: isset($row['form_field_type']) && '' !== $row['form_field_type'] ? (string) $row['form_field_type'] : null,
            formOptions: is_array($formOptions) ? $formOptions : null,
            validator: isset($row['validator']) && '' !== $row['validator'] ? (string) $row['validator'] : null,
            labelWording: isset($row['label_wording']) && '' !== $row['label_wording'] ? (string) $row['label_wording'] : null,
            labelDomain: isset($row['label_domain']) && '' !== $row['label_domain'] ? (string) $row['label_domain'] : null,
            descriptionWording: isset($row['description_wording']) && '' !== $row['description_wording'] ? (string) $row['description_wording'] : null,
            descriptionDomain: isset($row['description_domain']) && '' !== $row['description_domain'] ? (string) $row['description_domain'] : null,
        );
    }

    // -------------------------------------------------------------------------
    // Copy-with methods
    // -------------------------------------------------------------------------

    /**
     * Returns a copy of this definition with the given module name set.
     *
     * Used by Module::registerExtraProperty() to inject the calling module's name
     * when moduleName was left null by the developer.
     */
    public function withModuleName(string $moduleName): self
    {
        return new self(
            type: $this->type,
            scope: $this->scope,
            propertyName: $this->propertyName,
            entityName: $this->entityName,
            moduleName: $moduleName,
            enumValues: $this->enumValues,
            defaultValue: $this->defaultValue,
            nullable: $this->nullable,
            formRequired: $this->formRequired,
            size: $this->size,
            sqlIndex: $this->sqlIndex,
            displayApi: $this->displayApi,
            displayFront: $this->displayFront,
            associatedForms: $this->associatedForms,
            associatedGrids: $this->associatedGrids,
            formFieldType: $this->formFieldType,
            formOptions: $this->formOptions,
            validator: $this->validator,
            labelWording: $this->labelWording,
            labelDomain: $this->labelDomain,
            descriptionWording: $this->descriptionWording,
            descriptionDomain: $this->descriptionDomain,
        );
    }

    /**
     * Returns a copy of this definition with the given entity name set.
     *
     * Used by ExtraPropertyRegistry to inject the entity name after registration.
     */
    public function withEntityName(string $entityName): self
    {
        return new self(
            type: $this->type,
            scope: $this->scope,
            propertyName: $this->propertyName,
            entityName: $entityName,
            moduleName: $this->moduleName,
            enumValues: $this->enumValues,
            defaultValue: $this->defaultValue,
            nullable: $this->nullable,
            formRequired: $this->formRequired,
            size: $this->size,
            sqlIndex: $this->sqlIndex,
            displayApi: $this->displayApi,
            displayFront: $this->displayFront,
            associatedForms: $this->associatedForms,
            associatedGrids: $this->associatedGrids,
            formFieldType: $this->formFieldType,
            formOptions: $this->formOptions,
            validator: $this->validator,
            labelWording: $this->labelWording,
            labelDomain: $this->labelDomain,
            descriptionWording: $this->descriptionWording,
            descriptionDomain: $this->descriptionDomain,
        );
    }

    // -------------------------------------------------------------------------
    // Getters (for compatibility with previous ExtraPropertyDefinition API)
    // -------------------------------------------------------------------------

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

    public function getType(): ExtraPropertyType
    {
        return $this->type;
    }

    public function getScope(): ExtraPropertyScope
    {
        return $this->scope;
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

    public function getValidator(): ?string
    {
        return $this->validator;
    }

    public function getFormFieldType(): ?string
    {
        return $this->formFieldType;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFormOptions(): ?array
    {
        return $this->formOptions;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getDefaultValue(): ?string
    {
        return null !== $this->defaultValue ? (string) $this->defaultValue : null;
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

    /**
     * @return list<string>|null
     */
    public function getAssociatedForms(): ?array
    {
        return $this->associatedForms;
    }

    /**
     * Returns the raw placement entry for a specific form, or null if not associated.
     *
     * @param string $formId Form identifier (usually equals form block_prefix, e.g. 'category')
     */
    public function getFormEntry(string $formId): ?string
    {
        if (null === $this->associatedForms) {
            return null;
        }
        foreach ($this->associatedForms as $entry) {
            $parsed = self::parseFormEntry($entry);
            if ($parsed['formId'] === $formId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    public function getAssociatedGrids(): ?array
    {
        return $this->associatedGrids;
    }

    /**
     * Returns the raw placement entry for a specific grid, or null if not associated.
     *
     * @param string $gridId Grid identifier (e.g. 'product')
     */
    public function getGridEntry(string $gridId): ?string
    {
        if (null === $this->associatedGrids) {
            return null;
        }
        foreach ($this->associatedGrids as $entry) {
            $parsed = self::parseGridEntry($entry);
            if ($parsed['gridId'] === $gridId) {
                return $entry;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Naming helpers (previously in ExtraPropertyNaming)
    // -------------------------------------------------------------------------

    /**
     * Module-name key used in grouped arrays for fields that have no owning module.
     */
    public const CORE_MODULE_KEY = '_core';

    /**
     * Returns the physical SQL storage column name for this definition.
     *
     * Core fields (null/empty module_name) use the bare field name.
     * Module fields use '{module_name}_{property_name}'.
     */
    public function getStorageColumnName(): string
    {
        return self::buildStorageColumnName($this->moduleName, $this->propertyName);
    }

    /**
     * Returns the form field name and grid column identifier for this definition.
     *
     * The same format is used for unmapped Symfony form fields and SELECT aliases in grids.
     */
    public function getFormFieldName(): string
    {
        return 'extra_' . $this->scope->value . '_' . self::normalizeModuleKey($this->moduleName) . '_' . $this->propertyName;
    }

    /**
     * Returns the display key for this module in grouped extra-property arrays.
     *
     * Maps null to the canonical '_core' key; all other values are returned as-is.
     */
    public function getDisplayModuleKey(): string
    {
        return self::normalizeModuleKey($this->moduleName);
    }

    /**
     * Returns the name of the extra value table (without DB prefix) for this definition's scope.
     */
    public function getExtraTableName(): string
    {
        return self::buildExtraTableName($this->entityName, $this->scope);
    }

    /**
     * Returns the extra value table name for a given entity and scope.
     *
     * @param string $entityName Entity table name (e.g. 'product')
     * @param ExtraPropertyScope $scope
     *
     * @return string e.g. 'product_extra', 'product_extra_lang', 'product_extra_shop'
     */
    public static function buildExtraTableName(string $entityName, ExtraPropertyScope $scope): string
    {
        return match ($scope) {
            ExtraPropertyScope::LANG => $entityName . '_extra_lang',
            ExtraPropertyScope::SHOP => $entityName . '_extra_shop',
            default => $entityName . '_extra',
        };
    }

    /**
     * Returns the storage column name for a given module and property name.
     *
     * Static version used where no instance is available.
     */
    public static function buildStorageColumnName(?string $moduleName, string $propertyName): string
    {
        if (null === $moduleName || '' === $moduleName || self::CORE_MODULE_KEY === $moduleName) {
            return $propertyName;
        }

        return $moduleName . '_' . $propertyName;
    }

    /**
     * Parses one entry from the associated_grids JSON array into its components.
     *
     * Format: "gridId[.columnId[:before|after]]"
     *
     * @return array{gridId: string, columnId: string|null, mode: 'before'|'after'|null}
     */
    public static function parseGridEntry(string $entry): array
    {
        $dotPos = strpos($entry, '.');
        if (false === $dotPos) {
            return ['gridId' => $entry, 'columnId' => null, 'mode' => null];
        }

        $gridId = substr($entry, 0, $dotPos);
        $rest = substr($entry, $dotPos + 1);

        $mode = 'after';
        foreach ([':before', ':after'] as $suffix) {
            if (str_ends_with($rest, $suffix)) {
                $mode = ltrim($suffix, ':');
                $rest = substr($rest, 0, -strlen($suffix));
                break;
            }
        }

        return ['gridId' => $gridId, 'columnId' => '' !== $rest ? $rest : null, 'mode' => '' !== $rest ? $mode : null];
    }

    /**
     * Parses one entry from the associated_forms JSON array into its components.
     *
     * Format: "formId[.path[:before|after]]"
     *
     * @return array{formId: string, path: string|null, mode: 'before'|'after'|null}
     */
    public static function parseFormEntry(string $entry): array
    {
        $dotPos = strpos($entry, '.');
        if (false === $dotPos) {
            return ['formId' => $entry, 'path' => null, 'mode' => null];
        }

        $formId = substr($entry, 0, $dotPos);
        $rest = substr($entry, $dotPos + 1);

        $mode = null;
        foreach ([':before', ':after'] as $suffix) {
            if (str_ends_with($rest, $suffix)) {
                $mode = ltrim($suffix, ':');
                $rest = substr($rest, 0, -strlen($suffix));
                break;
            }
        }

        return ['formId' => $formId, 'path' => '' !== $rest ? $rest : null, 'mode' => $mode];
    }

    /**
     * Normalizes a module name for use in identifiers.
     *
     * Returns '_core' for empty/null/core-sentinel values.
     */
    private static function normalizeModuleKey(?string $moduleName): string
    {
        return (null === $moduleName || '' === $moduleName) ? self::CORE_MODULE_KEY : $moduleName;
    }
}
