# Extra Property Feature — Architecture

> **Discussion**: https://github.com/PrestaShop/PrestaShop/discussions/40767
>
> This document describes the architecture for a system that allows modules to register extra properties on existing PrestaShop entities without modifying core database tables.

---

## Table of Contents

1. [Overview & Naming Conventions](#1-overview--naming-conventions)
2. [Database Structure](#2-database-structure)
3. [Core Services](#3-core-services)
4. [Module Integration](#4-module-integration)
5. [ObjectModel Integration (Front-Office)](#5-objectmodel-integration-front-office)
6. [Admin API Integration](#6-admin-api-integration)
7. [Back-Office Form Integration](#7-back-office-form-integration)
8. [Grid Integration](#8-grid-integration)
9. [Supported Types](#9-supported-types)
10. [Performance Considerations](#10-performance-considerations)
11. [Conflict Handling](#11-conflict-handling)
12. [Testing Strategy](#12-testing-strategy)

---

## 1. Overview & Naming Conventions

### Concept

Modules can register **extra properties** on existing entities (Product, Customer, Order, etc.). These properties are stored in dedicated tables separate from core tables, created dynamically when a module registers its first extra property for an entity.

### Naming

| Concept | Convention |
|---------|-----------|
| **Namespace** | `PrestaShop\PrestaShop\Core\ExtraProperty` |
| **Directory** | `src/Core/ExtraProperty/` |
| **DB table suffix** | `_extra`, `_extra_lang`, `_extra_shop` |
| **Column naming** | `{module_name}_{field_name}` (core fields: `{field_name}` only) |
| **Column name max length** | 64 characters (MariaDB identifier limit) |

### Scopes

Extra properties support three scopes, mirroring PrestaShop's native multilang/multishop system:

| Scope | Table | Description |
|-------|-------|-------------|
| `Common` | `{entity}_extra` | Same value across all shops and languages |
| `Lang` | `{entity}_extra_lang` | Value varies per language (and per shop if multilang_shop) |
| `Shop` | `{entity}_extra_shop` | Value varies per shop |

Scope identifiers are lowercase strings (`common`, `lang`, `shop`) matching the string-backed `ExtraPropertyScope` enum values and the `scope` ENUM column in the DB.

---

## 2. Database Structure

### 2.1. Definition Registry Table

This table is the central registry of all registered extra properties. It is created during PrestaShop installation (added to `install-dev/data/db_structure.sql`).

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_extra_property_definition` (
  `id_extra_property_definition` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity_name` varchar(64) NOT NULL,
  `module_name` varchar(64) DEFAULT NULL,
  `property_name` varchar(64) NOT NULL,
  `type` ENUM('int','bool','string','float','date','html','json','choice') NOT NULL DEFAULT 'string',
  `size` smallint(5) unsigned DEFAULT NULL,
  `scope` ENUM('common','lang','shop') NOT NULL DEFAULT 'common',
  `sql_index` ENUM('none','key','unique') NOT NULL DEFAULT 'none',
  `form_required` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `default_value` varchar(255) DEFAULT NULL,
  `validator` varchar(255) DEFAULT NULL,
  `display_api` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `display_form` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `display_front` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `associated_grids` text DEFAULT NULL,
  `form_field_type` varchar(255) DEFAULT NULL,
  `form_options` text DEFAULT NULL,
  `form_position` varchar(255) DEFAULT NULL,
  `label_wording` varchar(191) DEFAULT NULL,
  `label_domain` varchar(255) DEFAULT NULL,
  `description_wording` varchar(191) DEFAULT NULL,
  `description_domain` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_extra_property_definition`),
  UNIQUE KEY `extra_property_definition_unique` (`entity_name`, `module_name`, `property_name`, `scope`),
  KEY `entity_name` (`entity_name`, `scope`),
  KEY `module_name` (`module_name`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4 COLLATION;
```

**Key fields:**
- `entity_name`: the ObjectModel table name (e.g., `product`, `customer`, `order`)
- `module_name`: the module's technical name; `NULL` = core field (no module owner)
- `property_name`: the property name within the module (e.g., `custom_size`)
- `type`: maps to `ExtraPropertyType` string-backed enum values (`'int'`, `'bool'`, `'string'`, …)
- `scope`: maps to `ExtraPropertyScope` string-backed enum values (`'common'`, `'lang'`, `'shop'`)
- `sql_index`: index strategy — `'none'`, `'key'` (standard index), or `'unique'` (unique constraint)
- `size`: for `string` type only: varchar column length (defaults to 255 when `NULL`). Ignored for all other types.
- `default_value`: SQL DEFAULT clause value (stored as varchar, cast to the appropriate type during DDL generation)
- `display_api`: when `1`, the field is included in Admin API responses
- `display_form`: when `1`, the field is included in BO forms
- `display_front`: when `1`, the field is returned by `ExtraPropertiesLazyArray::getValues()` for FO templates
- `associated_grids`: JSON-encoded array of grid placement entries in `"gridId[.columnId[:before|after]]"` format (e.g. `["product.reference:after","product_catalog"]`); `NULL` = not shown in any grid. Each gridId must be unique within the array. Parsed by `ExtraPropertyNaming::parseGridEntry()`.
- `form_field_type`: optional Symfony form type FQCN override for BO forms
- `form_options`: optional JSON-encoded array of extra options merged into the Symfony form type constructor call
- `form_position`: optional dot-notation form path to control where the field is injected in the BO form tree
- `label_wording`, `label_domain`: i18n label for BO (wording + translation domain, resolved at runtime)
- `description_wording`, `description_domain`: i18n description for BO

### 2.2. Dynamic Entity Extra Tables

These tables are created dynamically by the `ExtraPropertySchemaManager` when the first extra property is registered for an entity.

**Common extra table** — `PREFIX_{entity}_extra`:
```sql
CREATE TABLE IF NOT EXISTS `PREFIX_product_extra` (
  `id_product` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_product`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4 COLLATION;
```

**Lang extra table** — `PREFIX_{entity}_extra_lang`:
```sql
CREATE TABLE IF NOT EXISTS `PREFIX_product_extra_lang` (
  `id_product` int(10) unsigned NOT NULL,
  `id_lang` int(10) unsigned NOT NULL,
  `id_shop` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_product`, `id_lang`, `id_shop`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4 COLLATION;
```

**Shop extra table** — `PREFIX_{entity}_extra_shop`:
```sql
CREATE TABLE IF NOT EXISTS `PREFIX_product_extra_shop` (
  `id_product` int(10) unsigned NOT NULL,
  `id_shop` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id_product`, `id_shop`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4 COLLATION;
```

Columns are added dynamically via `ALTER TABLE ADD COLUMN` when a module registers an extra property.

### 2.3. Column Type Mapping

| ExtraPropertyType | SQL Column Type |
|---|---|
| `INT` | `int(10) DEFAULT NULL` |
| `BOOL` | `tinyint(1) DEFAULT 0` |
| `STRING` | `varchar({size}) DEFAULT NULL` (size defaults to 255) |
| `FLOAT` | `decimal(20,6) DEFAULT NULL` |
| `DATE` | `datetime DEFAULT NULL` |
| `HTML` | `text DEFAULT NULL` |
| `JSON` | `text DEFAULT NULL` |
| `CHOICE` | `ENUM('val1','val2',...) DEFAULT NULL` |

The `choice` type generates a MySQL `ENUM` DDL. The allowed values come from `ExtraPropertyOptions::$enumValues`. `ColumnDefinitionMapper` handles `$size` (String type only), `$defaultValue`, and `$nullable`.

---

## 3. Core Services

### 3.1. Directory Structure

```
src/Core/ExtraProperty/
├── ARCHITECTURE.md
├── ExtraPropertyType.php
├── ExtraPropertyScope.php
├── ExtraPropertySqlIndex.php
├── ExtraPropertyNaming.php              ← centralized naming utility
├── ExtraPropertyOptions.php
├── ExtraPropertyDefinitionCollection.php
├── ExtraPropertyScopeGrouper.php
├── ExtraPropertiesBag.php               ← lazy-loading grouped bag (module → ModuleFieldsBag)
├── ModuleFieldsBag.php                  ← per-module ArrayAccess bag (field → value, dirty-tracking)
├── Definition/
│   ├── ExtraPropertyDefinitionInfo.php              ← immutable typed VO (registry read-side)
│   └── CachedExtraPropertyDefinitionRepository.php ← read-only cache decorator
├── Registry/
│   ├── ExtraPropertyRegistryInterface.php    ← write API (register/unregister)
│   ├── ExtraPropertyRegistry.php             ← pure implementation (no cache)
│   └── CachedExtraPropertyRegistry.php      ← cache-invalidating decorator
├── Repository/
│   ├── ExtraPropertyDefinitionRepositoryInterface.php  ← 2-method read contract
│   ├── ExtraPropertyDefinitionWriterInterface.php      ← write contract (save/delete)
│   └── ExtraPropertyDefinitionRepository.php           ← DBAL implementation
├── Schema/
│   ├── ExtraPropertySchemaManagerInterface.php
│   ├── ColumnDefinitionMapper.php
│   └── ExtraPropertySchemaManager.php       ← raw DDL via DBAL
├── Value/
│   ├── ExtraPropertyReaderInterface.php
│   ├── ExtraPropertyWriterInterface.php
│   ├── ExtraPropertyReader.php
│   ├── ExtraPropertyWriter.php
│   └── ExtraPropertiesLazyArray.php         ← FO value bag for presenters
├── Validation/
│   ├── ExtraPropertyValidationInterface.php
│   └── ExtraPropertyValueValidator.php
├── Form/
│   ├── ExtraPropertiesFormBuilderModifier.php
│   └── ExtraPropertiesFormDataPersister.php
└── Grid/
    ├── ExtraPropertiesGridDefinitionModifier.php
    └── ExtraPropertiesGridQueryBuilderModifier.php

src/PrestaShopBundle/ApiPlatform/ExtraProperties/
└── ExtraPropertiesApiService.php

src/Core/Domain/ExtraProperty/
└── Exception/
    └── ExtraPropertyDomainException.php
```

### 3.2. ExtraPropertyType

String-backed PHP enum. Case names are uppercase (`INT`, `BOOL`, `STRING`, …); values are lowercase SQL literals matching the DB ENUM column.

```php
enum ExtraPropertyType: string
{
    case INT = 'int';
    case BOOL = 'bool';
    case STRING = 'string';
    case FLOAT = 'float';
    case DATE = 'date';
    case HTML = 'html';
    case JSON = 'json';
    case CHOICE = 'choice';
}
```

### 3.3. ExtraPropertyScope

String-backed PHP enum. Values match the DB ENUM literals.

```php
enum ExtraPropertyScope: string
{
    case Common = 'common';
    case Lang = 'lang';
    case Shop = 'shop';
}
```

### 3.4. ExtraPropertyDefinitionInfo

Immutable typed VO located in `src/Core/ExtraProperty/Definition/ExtraPropertyDefinitionInfo.php`. Used throughout the Symfony service layer (repository reads, form/grid modifiers, reader, API service). Built from a raw DB row via `ExtraPropertyDefinitionInfo::fromRow(array $row): self`. Exposes typed getters and derives the physical storage column name on-the-fly via `ExtraPropertyNaming::storageColumnName()`.

There is no legacy ObjectModel for extra property definitions — `ExtraPropertyDefinitionInfo` is the only representation.

### 3.5. ExtraPropertyNaming

Centralized naming utility that eliminates duplicated private methods across files.

```php
class ExtraPropertyNaming
{
    public const CORE_MODULE_KEY = '_core';

    /** Returns the extra table name: "{entity}_extra[_{scope}]" */
    public static function extraTableName(string $entityName, string $fieldScope): string;

    /** Returns the storage column name: "{module}_{field}" (or "{field}" for core, module = null) */
    public static function storageColumnName(?string $moduleName, string $fieldName): string;

    /** Returns the BO form field name and grid column alias: "extra_{scope}_{module}_{field}" */
    public static function formFieldName(string $moduleName, string $fieldName, string $scope): string;

    /** Normalizes module_name: null, '' or '_core' → '_core', otherwise returns the value as-is */
    public static function displayModuleKey(?string $moduleName): string;
}
```

Note: `storageColumnName()` accepts `?string $moduleName` — `null` means a core field (no prefix).

### 3.6. ExtraPropertyOptions

DTO passed to `registerExtraProperty()`. All fields are `readonly`.

```php
class ExtraPropertyOptions
{
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
        public readonly bool $displayForm = true,
        public readonly bool $displayFront = true,
        public readonly ?array $associatedGrids = null,  // e.g. ['product.reference:after', 'product_catalog']
        public readonly ?string $formPosition = null,
    ) {}

    public function withModuleName(string $moduleName): self;
}
```

### 3.7. ExtraPropertyRegistry

The registry is split into three layers:

**`ExtraPropertyDefinitionRepositoryInterface`** (read, 2 methods):
- `getDefinitionCollection(string $entityName): ExtraPropertyDefinitionCollection`
- `findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName, string $fieldScope): ?ExtraPropertyDefinitionInfo`

All read methods return typed `ExtraPropertyDefinitionInfo` value objects. The interface is resolved to `CachedExtraPropertyDefinitionRepository` in the DI container.

**`ExtraPropertyRegistryInterface`** (write-only):
- `register(string $entityName, string $propertyName, ExtraPropertyOptions $options): bool`
- `unregister(string $entityName, string $propertyName, ?string $moduleName, ExtraPropertyScope $fieldScope, bool $dropColumn): bool`

**`ExtraPropertyRegistry`** (pure, no cache): orchestrates register/unregister — validates input, calls `ExtraPropertySchemaManagerInterface` to create tables/columns, calls `ExtraPropertyDefinitionWriterInterface` to persist. Blocks storage-critical changes (type, size, scope, defaultValue) on already-registered definitions to prevent destructive ALTER TABLE.

**`CachedExtraPropertyRegistry`** (cache-invalidating decorator): wraps `ExtraPropertyRegistry`. Single point of cache invalidation after every `register()` or `unregister()` call. `ExtraPropertyRegistryInterface` is resolved to this class in the DI container.

**`CachedExtraPropertyDefinitionRepository`**: read-only cache decorator wrapping `ExtraPropertyDefinitionRepository`. Receives a single `CacheInterface $definitionCache` (filesystem pool). The cache key is computed by the public static method `CachedExtraPropertyDefinitionRepository::buildCacheKey(string $entityName)` — used by both the repository and the registry decorator.

### 3.8. ExtraPropertyDefinitionCollection

Immutable collection of `ExtraPropertyDefinitionInfo` with chainable `filter*()` methods:

- `filterByForm(): self` — only definitions with `display_form = true`
- `filterByGrid(string $gridId): self` — only definitions where `associated_grids` contains `$gridId`
- `filterForFrontOffice(): self` — only definitions with `display_front = true`
- `filterByApi(): self` — only definitions with `display_api = true`
- `filterByModuleName(?string $moduleName): self`
- `filterByScope(ExtraPropertyScope|string $scope): self`
- `filterByEntity(string $entityName): self`
- `isEmpty(): bool`
- `static empty(): self` — returns a shared empty singleton

### 3.9. ExtraPropertyValidationInterface

Centralized validation contract:

```php
interface ExtraPropertyValidationInterface
{
    public function isTableOrIdentifier(string $value): bool;
    public function isModuleName(string $value): bool;

    /** Returns true on success, or a translated error message string on failure. */
    public function validateValue(ExtraPropertyDefinitionInfo $definition, mixed $value): bool|string;
}
```

`ExtraPropertyValueValidator` is the concrete implementation. Uses pure regex — no legacy ObjectModel dependency.

### 3.10. ExtraPropertyReader

Reads extra property values from `_extra` tables. Uses `Doctrine\DBAL\Connection`.

**Interface**: `ExtraPropertyReaderInterface` (`src/Core/ExtraProperty/Value/`)

```php
public function getExtraProperties(
    string $entityName,
    string $primaryKeyName,
    int $entityId,
    ?int $langId,
    ShopConstraint $shopConstraint,
    bool $isLangMultishop = false,
): array;
```

Return format: `['module_key' => ['field_name' => value]]` where `module_key` is `ExtraPropertyNaming::displayModuleKey($moduleName)` (`'_core'` for core fields).

Lang-scope semantics:
- `$langId = null` → lang-scope fields returned as `[id_lang => value]` (all languages; BO forms, Admin API)
- `$langId = int` → lang-scope fields returned as scalar (specific language; FO pattern)

Shop-scope semantics:
- `ShopConstraint::shop($id)` → scalar value for that shop
- `ShopConstraint::allShops()` → `[id_shop => value]` (Admin API)

Performance: if no definitions exist for the entity, returns `[]` immediately without DB query.

### 3.11. ExtraPropertyWriter

Writes extra property values to `_extra` tables via `INSERT ... ON DUPLICATE KEY UPDATE`.

**Interface**: `ExtraPropertyWriterInterface` (`src/Core/ExtraProperty/Value/`)

```php
public function writeAll(
    string $entityName,
    string $primaryKeyName,
    int $entityId,
    array $entityValues,          // ['storageColumn' => value] for common scope
    array $langValuesByIdLang,    // [idLang => ['storageColumn' => value]]
    array $shopValues,            // ['storageColumn' => value] for shop scope
    ShopConstraint $shopConstraint,
): void;

public function deleteAll(string $entityName, string $primaryKeyName, int $entityId): void;
```

`ShopConstraint::allShops()` skips lang and shop-scope writes (caller must iterate shops for broad writes). `deleteAll()` silently skips tables that do not exist yet.

### 3.12. Service Configuration

`src/PrestaShopBundle/Resources/config/services/adapter/extra_property.yml`:

```yaml
services:
  _defaults:
    autowire: true
    public: false

  # Single filesystem cache pool shared by repository (reads) and registry (invalidation).
  prestashop.extra_property.definition.filesystem_cache:
    class: Symfony\Component\Cache\Adapter\FilesystemAdapter
    arguments: ['', 0, '%ps_cache_dir%/extra_property_definition']

  # Repository: uncached DBAL implementation + cached read-only decorator
  PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Definition\CachedExtraPropertyDefinitionRepository'
    public: true

  PretraShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionWriterInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepository'

  # Schema manager: raw DDL, no cache decorator
  PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ExtraPropertySchemaManagerInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ExtraPropertySchemaManager'

  # Registry: pure implementation + cache-invalidating decorator
  PrestaShop\PrestaShop\Core\ExtraProperty\Registry\ExtraPropertyRegistryInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Registry\CachedExtraPropertyRegistry'
    public: true

  # Reader / Writer
  PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReader'
    public: true

  PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriter'
    public: true
```

Grid services: `src/PrestaShopBundle/Resources/config/services/core/grid/grid_extra_properties.yml`

---

## 4. Module Integration

### 4.1. New Methods on Module Class

File: `classes/module/Module.php`

```php
public function registerExtraProperty(
    string $entityName,
    string $propertyName,
    ?ExtraPropertyOptions $options = null,
): bool
```

Resolves `$this->name` as the module name (via `$options->withModuleName($this->name)`) and calls `ExtraPropertyRegistryInterface::register()`.

```php
public function unregisterExtraProperty(
    string $entityName,
    string $propertyName,
    ExtraPropertyScope $fieldScope = ExtraPropertyScope::Common,
    bool $dropData = false,
): bool
```

`dropData = true` drops the physical column in addition to deleting the definition row.

### 4.2. Automatic Cleanup on Uninstall

Not yet implemented. Modules are responsible for calling `unregisterExtraProperty()` explicitly in their `uninstall()` method.

### 4.3. Module Usage Example

```php
$this->registerExtraProperty(
    'product',
    'video_link',
    new ExtraPropertyOptions(
        type: ExtraPropertyType::STRING,
        scope: ExtraPropertyScope::Lang,
        labelWording: 'Video link',
        labelDomain: 'Modules.Extrafieldproduct.Admin',
        descriptionWording: 'Video URL per language',
        descriptionDomain: 'Modules.Extrafieldproduct.Admin',
        displayApi: true,
        displayForm: true,
        displayFront: true,
        associatedGrids: ['product'],
        validator: 'isUrl',
    )
);
```

### 4.4. Handling Two Modules on the Same Entity

Modules share the same `{entity}_extra` table but have distinct column names due to the `{module_name}_` prefix. Uninstalling one module removes only its columns.

---

## 5. ObjectModel Integration (Front-Office)

### 5.1. ExtraPropertiesLazyArray

Located in `src/Core/ExtraProperty/Value/ExtraPropertiesLazyArray.php`. Not an `AbstractLazyArray` subclass — it is a collaborator assigned to the `$extraPropertiesLazyArray` protected property on `AbstractLazyArray`. `AbstractLazyArray::getExtraProperties()` delegates to `$extraPropertiesLazyArray->getValues()`.

Private constructor; built exclusively via two static factories:

```php
/** For a loaded ObjectModel instance (e.g. Order). No-op when $object->id <= 0. */
public static function fromObjectModel(ObjectModel $object): self;

/** For array-based data (e.g. product from presenter): resolves PK from ObjectModel static def. */
public static function fromObjectModelClass(string $objectModelClass, int $entityId): self;
```

Both factories resolve `ExtraPropertyReaderInterface` and `ExtraPropertyDefinitionRepositoryInterface` via `ContainerFinder`.

`getValues()`:
- Returns `[]` immediately when provider is null (invalid/unresolvable state or id = 0)
- Calls `repository->getDefinitionCollection()->filterForFrontOffice()` — skips DB read when no FO fields are registered
- Filters the reader result to only fields in the FO whitelist (`display_front = true`)
- Returns: `['module_key' => ['field_name' => value]]`

**LazyArrays that expose `extraProperties`** (via `$this->extraPropertiesLazyArray` assignment):
- `ProductLazyArray`
- `CategoryLazyArray`, `SupplierLazyArray`, `ManufacturerLazyArray`, `StoreLazyArray`
- `OrderLazyArray`, `OrderDetailLazyArray`, `OrderReturnLazyArray`

`CartLazyArray` does **not** expose `extraProperties`.

### 5.2. ObjectModel Integration

File: `classes/ObjectModel.php`

ObjectModel holds a single protected field `$extra_properties` typed as `?ExtraPropertiesBag`. Accessed from outside the class via `__get('extra_properties')`, which initializes the bag on first call.

**`ExtraPropertiesBag`** (`src/Core/ExtraProperty/ExtraPropertiesBag.php`): lazy-loading grouped bag implementing `ArrayAccess`, `IteratorAggregate`, and `JsonSerializable`. Keys are module names (`'mymodule'`, `'_core'`); values are `ModuleFieldsBag` instances.

**`ModuleFieldsBag`** (`src/Core/ExtraProperty/ModuleFieldsBag.php`): per-module `ArrayAccess` bag. Keys are field names; values are scalars (or `[id_lang => value]` arrays for lang-scoped fields). Tracks its own dirty fields and computes storage column names on `getModifiedValues()`.

```php
$product->extra_properties['mymodule']['video_link']           // read (grouped)
$product->extra_properties['mymodule']['video_link'] = '...'   // write + mark dirty
```

`offsetGet()` on `ExtraPropertiesBag` auto-creates an empty `ModuleFieldsBag` for unknown keys, which makes chained writes work without a prior load. `getModifiedValues()` on the parent bag aggregates flat `[storageColumnName => value]` maps from all module bags.

Persistence via `persistExtraProperties()` (called from `add()`/`update()` after `actionObject*After` hooks): iterates definitions to compute scope routing, then delegates to `ExtraPropertyWriterInterface::writeAll()` with `ShopConstraint` from `Context::getContext()->getShopConstraint()`. Multi-shop persistence iterates `$this->id_shop_list` for shop-scoped fields.

### 5.3. Front-Office Template Access

**In Smarty templates** (via presenter):

```smarty
{$product.extraProperties.ps_extrafield_product.video_link|default:''}
{$category.extraProperties.ps_extrafield_category.theme_color|default:''}
```

**On ObjectModel directly** (grouped ArrayAccess):

```php
$product->extra_properties['mymodule']['video_link'];
$product->extra_properties['mymodule']['video_link'] = 'https://...';
```

---

## 6. Admin API Integration

### 6.1. Strategy

Extra properties appear as an `extraProperties` sub-object in entity API responses, grouped by module name. Only properties with `display_api = 1` are included.

```json
{
  "productId": 1,
  "name": "T-shirt",
  "extraProperties": {
    "mymodule": { "custom_size": "XL" },
    "_core": { "internal_code": "ABC" }
  }
}
```

### 6.2. Implementation

`ExtraPropertiesApiService` (`src/PrestaShopBundle/ApiPlatform/ExtraProperties/`) is called from `CQRSApiSerializer` during `normalize()` and `denormalize()`.

Responsibilities:
- **Read**: calls `ExtraPropertyReaderInterface::getExtraProperties()` with `ShopConstraint`; converts `id_lang` → locale strings for the response
- **Write**: validates payload, dispatches write via `ExtraPropertyWriterInterface::writeAll()`
- **Validation**: checks submitted field names against registry definitions filtered to `display_api = 1`

---

## 7. Back-Office Form Integration

### 7.1. Strategy

`ExtraPropertiesFormBuilderModifier` adds extra property fields to BO entity forms. It is injected into `FormHandler` and called directly — no hook required. `ExtraPropertiesFormDataPersister` is injected into `FormHandler` for persistence.

- `ExtraPropertiesFormBuilderModifier::apply()`: calls `repository->getDefinitionCollection($entityName)->filterByForm()` directly; loads existing values via `ExtraPropertyReaderInterface` with `null` langId (all langs) and `ShopContext::getShopConstraint()`
- `ExtraPropertiesFormDataPersister`: calls `ExtraPropertyWriterInterface::writeAll()` directly

### 7.2. Placement Logic

- `form_position` empty → fields added to a dedicated `extra_fields.extra_properties` section (created if missing). On simple forms without tabs, fields are injected at root level instead.
- `form_position` set → must point to an already-existing sub-builder (strict dot-path resolution, no silent creation).
- Optional `:before`/`:after` suffix → relative placement via `FormBuilderModifier::addBefore()`/`addAfter()`. Example: `combination_details.reference:before` inserts the field just before the `reference` field inside the `combination_details` sub-builder.

### 7.3. Type Mapping (ExtraPropertyType → Symfony FormType)

If `form_field_type` is set in the definition, it is used directly. Otherwise:

| ExtraPropertyType | Symfony Form Type |
|---|---|
| `INT` | `IntegerType` |
| `BOOL` | `CheckboxType` |
| `STRING` | `TextType` (default) |
| `FLOAT` | `NumberType` |
| `DATE` | `DateTimeType` |
| `HTML` | `FormattedTextareaType` |
| `JSON` | `TextareaType` |
| `CHOICE` | `ChoiceType` |

For `lang` fields, the form type is wrapped in `TranslatableType`.

### 7.4. i18n Labels

Labels and descriptions use `label_wording` + `label_domain` stored in the registry, resolved at runtime via the Symfony translator.

`label_wording` is **required** when `display_form = true` or `associated_grids` is non-empty. `ExtraPropertyRegistry::register()` returns `false` (with a logged error) if this constraint is violated.

---

## 8. Grid Integration

### 8.1. Strategy

`ExtraPropertiesGridDefinitionModifier` adds columns and filters to BO grids. It is injected into `DoctrineGridDataFactory`. `ExtraPropertiesGridQueryBuilderModifier` adds LEFT JOINs.

`filterByGrid(string $gridId)` selects only definitions whose `associated_grids` JSON array contains an entry whose `gridId` component matches — via `ExtraPropertyDefinitionInfo::getGridEntry($gridId)` (parses each entry with `ExtraPropertyNaming::parseGridEntry()`). `ExtraPropertyDefinitionRepositoryInterface::getDefinitionCollectionByGridId($gridId)` queries the DB directly with `JSON_SEARCH` for cross-entity lookups. JSON fields are skipped (no meaningful grid representation).

### 8.2. Query Builder Modification

```sql
SELECT p.*, extra.mymodule_custom_size AS extra_common_mymodule_custom_size
FROM ps_product p
LEFT JOIN ps_product_extra extra ON extra.id_product = p.id_product
```

SELECT aliases follow `ExtraPropertyNaming::formFieldName()`: `extra_{scope}_{module}_{field}`.

### 8.3. Column Type Mapping

| ExtraPropertyType | Grid Column Type |
|---|---|
| `INT`, `FLOAT` | `DataColumn` |
| `BOOL` | `ToggleColumn` (uses `admin_common_extra_properties_toggle` route) |
| `STRING`, `HTML`, `CHOICE` | `DataColumn` |
| `DATE` | `DateTimeColumn` |
| `JSON` | Not displayed in grid |

---

## 9. Supported Types

| Type | Constant | SQL column | Notes |
|------|----------|------------|-------|
| Boolean | `ExtraPropertyType::BOOL` | `tinyint(1)` | |
| Integer | `ExtraPropertyType::INT` | `int(10)` | |
| String | `ExtraPropertyType::STRING` | `varchar({size})` | size default: 255 |
| Float | `ExtraPropertyType::FLOAT` | `decimal(20,6)` | |
| DateTime | `ExtraPropertyType::DATE` | `datetime` | |
| Choice | `ExtraPropertyType::CHOICE` | `ENUM(...)` | values from `enumValues` |
| JSON | `ExtraPropertyType::JSON` | `text` | not shown in grid |
| HTML | `ExtraPropertyType::HTML` | `text` | rich text, purified |

---

## 10. Performance Considerations

1. **Definition caching**: `CachedExtraPropertyDefinitionRepository` uses a `FilesystemAdapter` pool. Cache is invalidated by `CachedExtraPropertyRegistry` after any `register()` or `unregister()` call.
2. **Lazy loading in ObjectModel**: Extra properties are NOT loaded on object construction. They are loaded on first `extra_properties` access.
3. **No-op when unused**: Reader checks definitions first; returns `[]` immediately without DB query when none exist.
4. **FO whitelist pre-check**: `ExtraPropertiesLazyArray::getValues()` calls `filterForFrontOffice()` before querying values — skips the reader entirely when no FO fields are registered.
5. **Bulk reading in grids**: `ExtraPropertiesGridQueryBuilderModifier` adds LEFT JOINs to existing grid queries — no N+1 problem.
6. **Column-based storage**: Unlike EAV meta tables, extra properties are stored as columns — enables SQL indexing, no row multiplication.

---

## 11. Conflict Handling

1. **Column name uniqueness**: `{module_name}_{property_name}` is unique per `(entity_name, module_name, property_name, scope)` via DB unique key.
2. **Core fields**: `module_name IS NULL` → column name = `{property_name}` (no prefix).
3. **Column name length**: enforced ≤ 64 characters; `register()` returns `false` if exceeded.
4. **Type/size immutability**: `ExtraPropertyRegistry::register()` refuses to change `type`, `size`, `scope`, or `defaultValue` on already-registered definitions to prevent destructive ALTER TABLE.

---

## 12. Testing Strategy

### Unit Tests

- `ExtraPropertyNaming` conventions (`tests/Unit/Core/ExtraProperty/ExtraPropertyNamingTest.php`)
- `ColumnDefinitionMapper` type-to-SQL mapping (`tests/Unit/Core/ExtraProperty/Schema/ColumnDefinitionMapperTest.php`)
- `ExtraPropertyDefinitionCollection` filter methods
- `ExtraPropertyScopeGrouper` grouping logic

### Integration Tests

- Full lifecycle: register → create entity with extras → read → update → unregister
- Multi-module coexistence on same entity
- Schema manager table/column creation and removal
- ObjectModel `add()`/`update()` with extra properties

### Functional Tests

- Admin API CRUD with extra properties
- BO form display and submission
- Grid display, sorting, filtering
- FO display via LazyArray / presenter
