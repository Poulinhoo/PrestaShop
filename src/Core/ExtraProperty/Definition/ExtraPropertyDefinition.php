<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Definition;

use PrestaShop\PrestaShop\Core\ExtraProperty\Exception\InvalidExtraPropertyDefinitionException;
use PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValueValidator;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyValueCaster;

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
 * Use withModuleName() to derive a copy with injected module context.
 *
 * Constructor validation:
 * - entityName and propertyName are required and must be non-empty.
 * - associatedForms: each entry must match "formId[.path[:before|after]]"; no duplicate formId.
 * - associatedGrids: each entry must match "gridId[.columnId[:before|after]]"; no duplicate gridId.
 * - labelWording is required when associatedForms or associatedGrids is non-empty.
 *
 * @see \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyRegistryInterface::register()
 * @see \PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ColumnDefinitionMapper
 */
final class ExtraPropertyDefinition
{
    /**
     * Module-name key used in grouped arrays for fields that have no owning module (core fields).
     */
    public const CORE_MODULE_KEY = '_core';

    /**
     * @param string $entityName Entity table name (e.g. 'product'). Required — must be non-empty.
     * @param string $propertyName Property name as declared by the module (e.g. 'video_link'). Required.
     * @param ExtraPropertyType $type Field storage type. Determines the SQL column type via ColumnDefinitionMapper.
     * @param ExtraPropertyScope $scope Storage scope: COMMON (entity-level), LANG (per-language), SHOP (per-shop)
     * @param string|null $moduleName Owning module name. Null = core field. Auto-populated by Module::registerExtraProperty().
     * @param list<string>|null $enumValues For CHOICE type: SQL ENUM allowed values. Not persisted — schema creation only.
     * @param scalar|null $defaultValue Adds a DEFAULT clause in DDL. Also persisted in registry.
     * @param bool $nullable Controls NULL vs NOT NULL in DDL. Not persisted — schema creation only.
     * @param bool $formRequired when true, marks the BO form field as required
     * @param int|null $size for STRING type: varchar column length (defaults to 255)
     * @param ExtraPropertySqlIndex $sqlIndex SQL index strategy on the storage column
     * @param bool $displayApi include this field in Admin API JSON responses
     * @param bool $displayFront allow this field to be exposed in front-office presenters
     * @param list<string>|null $associatedForms Form placement entries: "formId[.path[:before|after]]". Each formId must be unique.
     * @param list<string>|null $associatedGrids Grid placement entries: "gridId[.columnId[:before|after]]". Each gridId must be unique.
     * @param string|null $formFieldType fully-qualified Symfony Form type FQCN override for BO forms
     * @param array<string, mixed>|null $formOptions extra options passed verbatim to the Symfony form type constructor
     * @param string|null $validator prestaShop Validate method name applied before persistence
     * @param string|null $labelWording Translation wording key shown in BO. Required when associatedForms or associatedGrids is set.
     * @param string|null $labelDomain translation domain for label wording
     * @param string|null $descriptionWording translation wording key shown as BO help text
     * @param string|null $descriptionDomain translation domain for description wording
     *
     * @throws InvalidExtraPropertyDefinitionException when entityName or propertyName is empty or not a valid SQL identifier, when associatedForms/associatedGrids have invalid format or duplicates, when labelWording is missing despite being required, or when the computed storage column name exceeds 64 characters
     */
    public function __construct(
        protected readonly string $entityName,
        protected readonly string $propertyName,
        protected readonly ExtraPropertyType $type = ExtraPropertyType::STRING,
        protected readonly ExtraPropertyScope $scope = ExtraPropertyScope::COMMON,
        protected readonly ?string $moduleName = null,
        protected readonly ?array $enumValues = null,
        protected readonly int|float|string|bool|null $defaultValue = null,
        protected readonly bool $nullable = true,
        protected readonly bool $formRequired = false,
        protected readonly ?int $size = null,
        protected readonly ExtraPropertySqlIndex $sqlIndex = ExtraPropertySqlIndex::NONE,
        protected readonly bool $displayApi = false,
        protected readonly bool $displayFront = true,
        protected readonly ?array $associatedForms = null,
        protected readonly ?array $associatedGrids = null,
        protected readonly ?string $formFieldType = null,
        protected readonly ?array $formOptions = null,
        protected readonly ?string $validator = null,
        protected readonly ?string $labelWording = null,
        protected readonly ?string $labelDomain = null,
        protected readonly ?string $descriptionWording = null,
        protected readonly ?string $descriptionDomain = null,
    ) {
        if (!ExtraPropertyValueValidator::isTableOrIdentifier($entityName)) {
            throw new InvalidExtraPropertyDefinitionException(sprintf(
                'ExtraPropertyDefinition: entityName "%s" must be a valid SQL identifier ([a-zA-Z0-9_-]+).',
                $entityName
            ));
        }
        if (!ExtraPropertyValueValidator::isTableOrIdentifier($propertyName)) {
            throw new InvalidExtraPropertyDefinitionException(sprintf(
                'ExtraPropertyDefinition: propertyName "%s" must be a valid SQL identifier ([a-zA-Z0-9_-]+).',
                $propertyName
            ));
        }

        // Non-empty, non-sentinel module names must match PrestaShop module naming rules.
        $resolvedModuleName = (null === $moduleName || '' === $moduleName || self::CORE_MODULE_KEY === $moduleName)
            ? null
            : $moduleName;
        if (null !== $resolvedModuleName && !ExtraPropertyValueValidator::isModuleName($resolvedModuleName)) {
            throw new InvalidExtraPropertyDefinitionException(sprintf(
                'ExtraPropertyDefinition: moduleName "%s" is not a valid PrestaShop module name.',
                $moduleName
            ));
        }

        // Storage column names must be valid SQL identifiers: 1–64 chars, [A-Za-z0-9_] only.
        $storageColumn = self::buildStorageColumnName($resolvedModuleName, $propertyName);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $storageColumn)) {
            throw new InvalidExtraPropertyDefinitionException(sprintf(
                'ExtraPropertyDefinition: computed storage column name "%s" must be 1–64 characters and match [A-Za-z0-9_].',
                $storageColumn
            ));
        }

        if (!empty($associatedForms)) {
            $seenFormIds = [];
            foreach ($associatedForms as $entry) {
                $parsed = self::parseFormEntry((string) $entry);
                if ('' === $parsed['formId']) {
                    throw new InvalidExtraPropertyDefinitionException(sprintf(
                        'ExtraPropertyDefinition: invalid associatedForms entry "%s" — formId must not be empty.',
                        $entry
                    ));
                }
                if (isset($seenFormIds[$parsed['formId']])) {
                    throw new InvalidExtraPropertyDefinitionException(sprintf(
                        'ExtraPropertyDefinition: duplicate formId "%s" in associatedForms.',
                        $parsed['formId']
                    ));
                }
                $seenFormIds[$parsed['formId']] = true;
            }
        }

        if (!empty($associatedGrids)) {
            $seenGridIds = [];
            foreach ($associatedGrids as $entry) {
                $parsed = self::parseGridEntry((string) $entry);
                if ('' === $parsed['gridId']) {
                    throw new InvalidExtraPropertyDefinitionException(sprintf(
                        'ExtraPropertyDefinition: invalid associatedGrids entry "%s" — gridId must not be empty.',
                        $entry
                    ));
                }
                if (isset($seenGridIds[$parsed['gridId']])) {
                    throw new InvalidExtraPropertyDefinitionException(sprintf(
                        'ExtraPropertyDefinition: duplicate gridId "%s" in associatedGrids.',
                        $parsed['gridId']
                    ));
                }
                $seenGridIds[$parsed['gridId']] = true;
            }
        }

        if ((!empty($associatedForms) || !empty($associatedGrids)) && (null === $labelWording || '' === trim($labelWording))) {
            throw new InvalidExtraPropertyDefinitionException(sprintf(
                'ExtraPropertyDefinition: labelWording is required when associatedForms or associatedGrids is set (entity "%s", property "%s").',
                $entityName,
                $propertyName
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    /**
     * Builds an instance from a raw registry DB row.
     *
     * nullable and enumValues are not persisted in the registry table: the live DB schema of
     * the storage column is their source of truth. The repository injects them into the row
     * under the synthetic 'nullable' and 'enum_values' keys (see
     * ExtraPropertyDefinitionRepository::enrichRowsWithColumnMetadata()). When the keys are
     * absent (e.g. storage column not created yet), safe defaults apply (nullable, no enum).
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidExtraPropertyDefinitionException when entityName or propertyName is empty in the row
     */
    public static function fromRow(array $row): self
    {
        $formOptions = ($raw = (string) ($row['form_options'] ?? '')) !== ''
            ? json_decode($raw, true)
            : null;

        $associatedForms = array_values(array_filter(
            (array) json_decode((string) ($row['associated_forms'] ?? ''), true),
            static fn (mixed $v): bool => is_string($v) && '' !== $v
        )) ?: null;

        $associatedGrids = array_values(array_filter(
            (array) json_decode((string) ($row['associated_grids'] ?? ''), true),
            static fn (mixed $v): bool => is_string($v) && '' !== $v
        )) ?: null;

        $type = ExtraPropertyType::from((string) ($row['type'] ?? ExtraPropertyType::STRING->value));
        $rawDefaultValue = isset($row['default_value']) && '' !== $row['default_value'] ? $row['default_value'] : null;

        return new self(
            entityName: (string) ($row['entity_name'] ?? ''),
            propertyName: (string) ($row['property_name'] ?? ''),
            type: $type,
            scope: ExtraPropertyScope::from((string) ($row['scope'] ?? ExtraPropertyScope::COMMON->value)),
            moduleName: isset($row['module_name']) && '' !== $row['module_name'] ? (string) $row['module_name'] : null,
            enumValues: isset($row['enum_values']) && is_array($row['enum_values']) && [] !== $row['enum_values'] ? array_values($row['enum_values']) : null,
            defaultValue: null !== $rawDefaultValue ? ExtraPropertyValueCaster::castScalarFromDb($type, $rawDefaultValue) : null,
            nullable: !array_key_exists('nullable', $row) || (bool) $row['nullable'],
            formRequired: !empty($row['form_required']),
            size: isset($row['size']) && '' !== $row['size'] ? (int) $row['size'] : null,
            sqlIndex: ExtraPropertySqlIndex::from((string) ($row['sql_index'] ?? ExtraPropertySqlIndex::NONE->value)),
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
            entityName: $this->entityName,
            propertyName: $this->propertyName,
            type: $this->type,
            scope: $this->scope,
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

    // -------------------------------------------------------------------------
    // Getters
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

    public function isNullable(): bool
    {
        return $this->nullable;
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

    public function getSqlIndex(): ExtraPropertySqlIndex
    {
        return $this->sqlIndex;
    }

    /**
     * @return list<string>|null
     */
    public function getEnumValues(): ?array
    {
        return $this->enumValues;
    }

    public function getDefaultValue(): int|float|string|bool|null
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

    /**
     * @return list<string>|null
     */
    public function getAssociatedForms(): ?array
    {
        return $this->associatedForms;
    }

    /**
     * Returns the parsed placement entry for a specific form, or null if not associated.
     *
     * @return array{formId: string, path: string|null, mode: 'before'|'after'|null}|null
     */
    public function getFormEntry(string $formId): ?array
    {
        if (null === $this->associatedForms) {
            return null;
        }
        foreach ($this->associatedForms as $entry) {
            $parsed = self::parseFormEntry($entry);
            if ($parsed['formId'] === $formId) {
                return $parsed;
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
     * Returns the parsed placement entry for a specific grid, or null if not associated.
     *
     * @return array{gridId: string, columnId: string|null, mode: 'before'|'after'|null}|null
     */
    public function getGridEntry(string $gridId): ?array
    {
        if (null === $this->associatedGrids) {
            return null;
        }
        foreach ($this->associatedGrids as $entry) {
            $parsed = self::parseGridEntry($entry);
            if ($parsed['gridId'] === $gridId) {
                return $parsed;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Naming helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the physical SQL storage column name for this definition.
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
     * Returns the name of the base entity table (without DB prefix) for this definition's scope.
     *
     * Used by SchemaManager to verify the base table exists before creating the extra table.
     * LANG scope → {entity}_lang, SHOP scope → {entity}_shop, COMMON → {entity}.
     */
    public function getBaseTableName(): string
    {
        return match ($this->scope) {
            ExtraPropertyScope::LANG => $this->entityName . '_lang',
            ExtraPropertyScope::SHOP => $this->entityName . '_shop',
            default => $this->entityName,
        };
    }

    /**
     * Returns the extra value table name for a given entity and scope.
     *
     * Use this static version only when no ExtraPropertyDefinition instance is available
     * (e.g. in Writer, Reader, SchemaManager which receive entity+scope as separate params).
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
     * Use this static version only when no ExtraPropertyDefinition instance is available
     * (e.g. in ModuleFieldsBag which holds only module name + field name as strings).
     */
    public static function buildStorageColumnName(?string $moduleName, string $propertyName): string
    {
        // Hyphens are valid in property/entity names but not in unquoted SQL identifiers — normalize them.
        $normalizedProperty = str_replace('-', '_', $propertyName);
        if (null === $moduleName || '' === $moduleName || self::CORE_MODULE_KEY === $moduleName) {
            return $normalizedProperty;
        }

        $normalizedModule = str_replace('-', '_', $moduleName);

        return $normalizedModule . '_' . $normalizedProperty;
    }

    /**
     * Normalizes a module name for use in identifiers.
     */
    private static function normalizeModuleKey(?string $moduleName): string
    {
        return (null === $moduleName || '' === $moduleName) ? self::CORE_MODULE_KEY : $moduleName;
    }

    /**
     * Parses one entry from the associated_grids JSON array into its components.
     *
     * Format: "gridId[.columnId[:before|after]]"
     *
     * @return array{gridId: string, columnId: string|null, mode: 'before'|'after'|null}
     */
    protected static function parseGridEntry(string $entry): array
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
    protected static function parseFormEntry(string $entry): array
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
}
