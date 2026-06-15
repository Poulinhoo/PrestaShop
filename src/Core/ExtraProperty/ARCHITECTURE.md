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
| `COMMON` | `{entity}_extra` | Same value across all shops and languages |
| `LANG` | `{entity}_extra_lang` | Value varies per language (and per shop if multilang_shop) |
| `SHOP` | `{entity}_extra_shop` | Value varies per shop |

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
  `associated_forms` text DEFAULT NULL,
  `form_field_type` varchar(255) DEFAULT NULL,
  `form_options` text DEFAULT NULL,
  `label_wording` varchar(191) DEFAULT NULL,
  `label_domain` varchar(255) DEFAULT NULL,
  `description_wording` varchar(191) DEFAULT NULL,
  `description_domain` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_extra_property_definition`),
  UNIQUE KEY `extra_property_definition_unique` (`entity_name`, `module_name`, `property_name`),
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
- `default_value`: SQL DEFAULT clause value (stored as varchar, cast to the appropriate PHP type via `ExtraPropertyValueCaster::castFromDb()` when read back)
- `display_api`: when `1`, the field is included in Admin API responses
- `display_form`: when `1`, the field is included in BO forms
- `display_front`: when `1`, the field is readable through FO bags (`ExtraPropertiesBag::createForEntity(..., forFrontOffice: true)`) for FO templates
- `associated_grids`: JSON-encoded array of grid placement entries in `"gridId[:columnId[:before|after]]"` format (e.g. `["product:reference:after","product_catalog"]`); `NULL` = not shown in any grid. Each gridId must be unique within the array. Parsed by `ExtraPropertyDefinition::getGridEntry()`.
- `associated_forms`: JSON-encoded array of form placement entries in `"formId[:path[:before|after]]"` format. The `":"` separates the form id from the field path and the field path from the optional mode; nesting *within* the path still uses `"."` (e.g. `"product:options.suppliers:before"`). No mode → the path is a container (field appended inside it); mode → the last path segment is an anchor (field placed before/after it). Parsed by `ExtraPropertyDefinition::getFormEntry()`.
- `form_field_type`: optional Symfony form type FQCN override for BO forms
- `form_options`: optional JSON-encoded array of extra options merged into the Symfony form type constructor call
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
| `INT` | `INT(11) DEFAULT NULL` |
| `BOOL` | `TINYINT(1) UNSIGNED DEFAULT 0` |
| `STRING` | `VARCHAR({size}) DEFAULT NULL` (size defaults to 255) |
| `FLOAT` | `DECIMAL(20,6) DEFAULT NULL` |
| `DATE` | `DATETIME DEFAULT NULL` |
| `HTML` | `TEXT DEFAULT NULL` |
| `JSON` | `LONGTEXT DEFAULT NULL` |
| `CHOICE` | `ENUM('val1','val2',...) DEFAULT NULL` |

The `CHOICE` type generates a MySQL `ENUM` DDL. The allowed values come from `ExtraPropertyDefinition::$enumValues`. `ColumnDefinitionMapper` handles `$size` (String type only), `$defaultValue` (numeric types unquoted, string types single-quoted), and `$nullable`.

---

## 3. Core Services

### 3.1. Directory Structure

```
src/Core/ExtraProperty/
├── ARCHITECTURE.md
├── Definition/
│   ├── ExtraPropertyType.php                         ← string-backed enum
│   ├── ExtraPropertyScope.php                        ← string-backed enum
│   ├── ExtraPropertySqlIndex.php                     ← string-backed enum
│   ├── ExtraPropertyDefinition.php                   ← immutable VO + naming methods + static factory
│   ├── ExtraPropertyDefinitionCollection.php         ← typed collection with chainable filter*() methods
│   ├── ExtraPropertyDefinitionRepositoryInterface.php ← read contract (2 methods)
│   ├── ExtraPropertyDefinitionRepository.php         ← DBAL implementation (read + write)
│   ├── ExtraPropertyDefinitionWriterInterface.php    ← write contract (save / delete / deleteByDefinition)
│   ├── CachedExtraPropertyDefinitionRepository.php  ← read cache decorator + write cache invalidation
│   ├── ExtraPropertyRegistryInterface.php            ← register / unregister
│   └── ExtraPropertyRegistry.php                     ← implementation (cache invalidation via the definition writer)
├── Exception/
│   ├── ExtraPropertyException.php                    ← base exception (extends CoreException)
│   └── InvalidExtraPropertyDefinitionException.php  ← thrown by ExtraPropertyDefinition constructor
├── Form/
│   ├── ExtraPropertiesFormBuilderModifier.php
│   └── ExtraPropertiesFormDataPersister.php
├── Grid/
│   ├── ExtraPropertiesGridDefinitionModifier.php
│   └── ExtraPropertiesGridQueryBuilderModifier.php
├── Schema/
│   ├── ExtraPropertySchemaManagerInterface.php
│   ├── ColumnDefinitionMapper.php
│   └── ExtraPropertySchemaManager.php               ← raw DDL via DBAL (BO-only service)
├── Validation/
│   ├── ExtraPropertyValidatorInterface.php
│   └── ExtraPropertyValidator.php
└── Value/
    ├── ExtraPropertyReaderInterface.php
    ├── ExtraPropertyWriterInterface.php
    ├── ExtraPropertyReader.php
    ├── ExtraPropertyWriter.php
    ├── ExtraPropertyValueCaster.php                  ← static cast helpers (DB ↔ PHP)
    ├── ExtraPropertiesBag.php                        ← lazy-loading grouped bag (BO write path + FO read path)
    └── ModuleFieldsBag.php                           ← per-module ArrayAccess bag (dirty-tracking)

src/PrestaShopBundle/ApiPlatform/ExtraProperties/
└── ExtraPropertiesApiService.php
```

### 3.2. ExtraPropertyType

String-backed PHP enum in `Definition/`. Case names are uppercase (`INT`, `BOOL`, `STRING`, …); values are lowercase SQL literals matching the DB ENUM column.

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

String-backed PHP enum in `Definition/`. Values match the DB ENUM literals.

```php
enum ExtraPropertyScope: string
{
    case COMMON = 'common';
    case LANG = 'lang';
    case SHOP = 'shop';
}
```

### 3.4. ExtraPropertyDefinition

Immutable value object located in `src/Core/ExtraProperty/Definition/ExtraPropertyDefinition.php`. Serves two roles:

- **Module-facing configuration object**: passed to `Module::registerExtraProperty()` — the developer constructs it with all the registration options.
- **Internal read DTO**: returned by all repository methods. Built from a raw DB row via the static factory `ExtraPropertyDefinition::fromRow(array $row): self`.

There is no separate "options" DTO — `ExtraPropertyDefinition` is the single representation used everywhere.

`nullable` and `enumValues` are **not persisted** in the registry table: the live schema of the storage column is their source of truth. `ExtraPropertyDefinitionRepository::enrichRowsWithColumnMetadata()` introspects the related extra table (one `SHOW COLUMNS` per distinct table, in both `getAllDefinitions()` and `findDefinitionByModuleAndField()`) and injects the synthetic `nullable` / `enum_values` row keys consumed by `fromRow()` — NULL/NOT NULL clause for `nullable`, ENUM literals for CHOICE columns. The cost is amortized by the `getAllDefinitions()` cache; when the storage column does not exist yet, `fromRow()` keeps its safe defaults (nullable, no enum).

**Naming methods** (centralised here, no separate `ExtraPropertyNaming` utility class):

```php
public const CORE_MODULE_KEY = '_core';

/** Returns the storage column name: "{module}_{field}" (or "{field}" for core, module = null) */
public function getStorageColumnName(): string;

/** Returns the BO form field name: "extra_{scope}_{module}_{field}" */
public function getFormFieldName(): string;

/** Returns the normalized module key: '_core' for core fields, module name otherwise (stored field, computed at construction) */
public function getNormalizedModuleKey(): string;

/** Returns the extra table name: "{entity}_extra[_lang|_shop]" depending on scope */
public function getExtraTableName(): string;

/** Returns the base entity table name: "{entity}_lang" for LANG scope, "{entity}" otherwise */
public function getBaseTableName(): string;

/** Returns the entity primary key column name: "id_{entity}" (also the FK column of the *_extra tables) */
public function getPrimaryKeyName(): string;
```

`entityName` is normalized to lower snake_case at construction (Doctrine `tableize()`: CamelCase → snake_case, e.g. `ProductAttribute` → `product_attribute`; hyphens → underscores): it is the fragment behind the extra table names and the primary key column, so casing never leaks into SQL identifiers.

**Parsed grid/form entries:**

```php
/** Returns ['gridId', 'columnId', 'mode'] or null when $gridId is not in associated_grids */
public function getGridEntry(string $gridId): ?array;

/** Returns the resolved ['formId', 'mode', 'path', 'anchor'] or null when $formId is not in associated_forms */
public function getFormEntry(string $formId): ?array;
```

**Copy-with factory:**

```php
/** Returns a copy with the module name set. Used by Module::registerExtraProperty() to inject $this->name. */
public function withModuleName(string $moduleName): self;
```

**Constructor validation** (throws `InvalidExtraPropertyDefinitionException`):
- `entityName` and `propertyName` must be non-empty and valid SQL identifiers.
- `moduleName` (when non-null and non-`_core`) must be a valid PrestaShop module name.
- The resulting storage column name must be ≤ 64 characters.
- `associated_forms`/`associated_grids` entries must match the `formId[:path[:before|after]]` / `gridId[:columnId[:before|after]]` pattern; no duplicate IDs within the array.
- `labelWording` is required when `associated_forms` or `associated_grids` is non-empty.

**Default value casting**: `fromRow()` calls `ExtraPropertyValueCaster::castFromDb($type, $rawDefaultValue)` so the in-memory default value is already typed (bool, int, float, string) rather than always a raw string. `getDefaultValue()` returns `int|float|string|bool|null`.

### 3.5. ExtraPropertyDefinitionCollection

Immutable collection of `ExtraPropertyDefinition` in `Definition/`. Chainable `filter*()` methods:

- `filterByEntity(string $entityName): self`
- `filterByModuleName(?string $moduleName): self`
- `filterByScope(ExtraPropertyScope $scope): self`
- `filterByForm(string $formId): self` — only definitions whose `associated_forms` contains `$formId`
- `filterByGrid(string $gridId): self` — only definitions whose `associated_grids` contains `$gridId`
- `filterForFrontOffice(): self` — only definitions with `display_front = true`
- `filterByApi(): self` — only definitions with `display_api = true`
- `first(): ?ExtraPropertyDefinition`
- `isEmpty(): bool`
- `count(): int`

### 3.6. ExtraPropertyRegistry

The registry is split into four layers, all in the `Definition/` namespace:

**`ExtraPropertyDefinitionRepositoryInterface`** (read, 2 methods):
- `getAllDefinitions(): ExtraPropertyDefinitionCollection` — returns **all** definitions; use collection filter helpers to narrow (replaces the former `getDefinitionCollection($entityName)`)
- `findDefinitionByModuleAndField(string $entityName, ?string $moduleName, string $fieldName): ?ExtraPropertyDefinition` — targeted write-path lookup, never cached. No scope parameter: (entity, module, property) is unique across scopes (registry rule + DB unique key)

All read methods return typed `ExtraPropertyDefinition` value objects. The interface is resolved to `CachedExtraPropertyDefinitionRepository` in the DI container.

**`ExtraPropertyDefinitionWriterInterface`** (write, 3 methods):
- `save(ExtraPropertyDefinition $definition): int|false` — insert or update resolved internally from the (entity, module, property) unique key; returns the definition id
- `delete(int $id): bool`
- `deleteByDefinition(ExtraPropertyDefinition $definition): bool` — delegates to `findIdByUniqueKey` + `delete`

**`ExtraPropertyRegistryInterface`** (register/unregister):
- `register(ExtraPropertyDefinition $definition): bool`
- `unregister(ExtraPropertyDefinition $definition, bool $dropColumn = false): bool`

**`ExtraPropertyRegistry`** (pure, no cache): orchestrates register/unregister — validates input, refuses **destructive** schema changes on already-registered definitions (`type`/`scope` change, STRING size decrease, nullable tightening, CHOICE enum value removal), calls `ExtraPropertySchemaManagerInterface` to create tables/columns, calls `ExtraPropertyDefinitionWriterInterface` to persist. **Non-destructive** schema changes (`defaultValue` change, size increase, nullable relaxing, enum value addition) are synced onto the live column by `ensureExtraTableAndColumn()` itself (it compares the SHOW COLUMNS state with the definition and issues `ALTER TABLE … MODIFY COLUMN` when they differ — same pattern as the index sync). Operation order: check changes → save → DDL (schema change happens only after definition is accepted).

**`CachedExtraPropertyDefinitionRepository`**: unified cache class implementing **both** `ExtraPropertyDefinitionRepositoryInterface` (reads from cache) **and** `ExtraPropertyDefinitionWriterInterface` (delegates writes and invalidates cache). Uses a single `CacheInterface $definitionCache` (filesystem pool). Cache invalidation is triggered by every write (`save`, `delete`, `deleteByDefinition`).

`ExtraPropertyRegistryInterface` is resolved directly to `ExtraPropertyRegistry` in the DI container — no decorator needed, since cache management lives entirely in `CachedExtraPropertyDefinitionRepository` (the registry's injected definition writer).

### 3.7. ExtraPropertyValidatorInterface

Located in `src/Core/ExtraProperty/Validation/`. Concrete implementation: `ExtraPropertyValidator`.

```php
interface ExtraPropertyValidatorInterface
{
    /**
     * Validates one extra property value against its definition's validator.
     * Returns true on success, or a translated error message string on failure.
     */
    public function validateValue(ExtraPropertyDefinition $definition, mixed $value): bool|string;

    /**
     * Validates a batch of extra property values against their definitions.
     * $valuesByModule is grouped like the reader output: [moduleKey => [propertyName => value]].
     * Returns true on success, or the first error message string encountered.
     */
    public function validate(array $valuesByModule, ExtraPropertyDefinitionCollection $definitions): bool|string;
}
```

The concrete `ExtraPropertyValidator` additionally exposes two `static` structural helpers — `isTableOrIdentifier()` (valid SQL identifier: 1–64 chars, MySQL identifier limit, `[a-zA-Z0-9_-]`) and `isModuleName()`. They are intentionally NOT part of the interface: statics cannot be called through an injected interface, and their only caller is the `ExtraPropertyDefinition` constructor, which cannot receive services. The constructor validates `entityName`, `propertyName` and the computed storage column name with `isTableOrIdentifier()` — this is the safety contract that lets DDL consumers (`ExtraPropertySchemaManager`) embed identifiers from a definition in SQL without re-validating.

### 3.8. ExtraPropertySchemaManager

Located in `src/Core/ExtraProperty/Schema/`. **BO-only service** (defined in `config/services/admin/extra_property.yml`). Receives `LoggerInterface $logger` as a required (non-nullable) constructor argument.

```php
interface ExtraPropertySchemaManagerInterface
{
    /** Creates the *_extra table if needed, then adds the column for $definition. */
    public function ensureExtraTableAndColumn(ExtraPropertyDefinition $definition): void;

    /** Drops the column from the *_extra table; drops the table itself when it becomes empty. */
    public function dropExtraColumnIfExists(ExtraPropertyDefinition $definition): void;
}
```

Both methods derive all required information (`entityName`, `scope`, `storageColumnName`, SQL type, `sqlIndex`) directly from the `ExtraPropertyDefinition` object. `ColumnDefinitionMapper::getSqlDefinition($definition)` is called internally to build the DDL fragment. No internal table/column caches — every call reads the current schema state from the DBAL schema manager.

### 3.9. ExtraPropertyReader

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
    ?ExtraPropertyDefinitionCollection $definitions = null,
): array;
```

`$definitions`: optional pre-filtered collection. When provided, the reader skips the repository fetch and uses it directly. Callers pre-filter before passing:
- FO (LazyArray): `filterForFrontOffice()`
- BO forms: `filterByForm($formId)`
- Grid: `filterByGrid($gridId)`
- Admin API: `filterByApi()`

Return format: `['module_key' => ['field_name' => value]]` where `module_key` is `$definition->getNormalizedModuleKey()` (`'_core'` for core fields).

Lang-scope semantics:
- `$langId = null` → lang-scope fields returned as `[id_lang => value]` (all languages; BO forms, Admin API)
- `$langId = int` → lang-scope fields returned as scalar (specific language; FO pattern)

Shop-scope fields always return a single scalar for the given `ShopConstraint` (no group-by-shop array).

Performance: if no definitions exist for the entity, returns `[]` immediately without DB query.

### 3.10. ExtraPropertyWriter

Writes extra property values to `_extra` tables via `INSERT ... ON DUPLICATE KEY UPDATE`.

Callers pass values **grouped the same way the reader returns them** ([moduleKey => [propertyName => value]]); the writer resolves the definitions (via the definition repository) and routes each value to the table matching its registered scope. Scope splitting, storage column resolution and nullable NULL handling are centralized here — no caller pre-splits.

**Interface**: `ExtraPropertyWriterInterface` (`src/Core/ExtraProperty/Value/`)

```php
public function writeAll(
    string $entityName,
    string $primaryKeyName,
    int $entityId,
    array $valuesByModule,        // [moduleKey => [propertyName => value]]; lang values: [id_lang => value] or scalar
    ShopConstraint $shopConstraint,
    ?int $defaultLangId = null,   // lang used for scalar lang values; null skips them
): void;

public function toggleExtraProperty(
    ExtraPropertyDefinition $definition,
    int $entityId,
    ShopConstraint $shopConstraint,
): void;

public function deleteAll(string $entityName, string $primaryKeyName, int $entityId): void;
```

`ShopConstraint::allShops()` skips lang and shop-scope writes (caller must iterate shops for broad writes). `deleteAll()` silently skips tables that do not exist yet. `toggleExtraProperty()` performs an UPSERT that flips the current boolean value; the storage primary key is deduced from the definition's entity name, and the toggle endpoint resolves the `ShopConstraint` server-side from ShopContext (no shop id in the route). Throws `\InvalidArgumentException` if the definition is not of type `BOOL`, or when a SHOP-scoped definition is toggled without a single-shop constraint.

### 3.11. ExtraPropertyValueCaster

Located in `src/Core/ExtraProperty/Value/`. All methods are **static**. Converts values between DB storage format (raw PDO strings) and typed PHP values.

```php
// DB → PHP (canonical cast point: ExtraPropertyReader; also default value hydration, grid records)
public static function castFromDb(ExtraPropertyType $type, mixed $rawValue, bool $nullable = false): mixed;

// PHP → DB (for form submission, ObjectModel persistence)
public static function castForDb(ExtraPropertyDefinition $definition, mixed $value): mixed;
```

`castFromDb` takes the bare `ExtraPropertyType` (not the full definition) so `ExtraPropertyDefinition::fromRow()` can cast the `defaultValue` field without a circular dependency on itself. LANG-scoped values (`[id_lang => scalar]` arrays) are cast entry by entry at the call site — the reader does this when hydrating lang rows.

NULL handling is **nullable-aware** (`nullable` is deduced from the live column schema): a NULL on a nullable column is preserved for every type; on NOT NULL columns (only possible when the row is missing) BOOL coerces to `false`, other types stay NULL.

`ExtraPropertyReader` applies the caster to every value it returns, so all its consumers receive typed values: ObjectModel bags, FO presenter lazy arrays, the BO form modifier (no second cast needed) and the Admin API service. Grid records do not flow through the reader: `ExtraPropertiesGridQueryBuilderModifier::castExtraProperties()` casts the fetched rows in `DoctrineGridDataFactory` right after the query runs, before `GridData` is built.

### 3.12. Service Configuration

**Common (FO + BO)**: `config/services/extra_property.yml`

```yaml
services:
  # Cache pool shared by the repository (reads) and the cached writer (invalidation).
  prestashop.extra_property.definition.filesystem_cache:
    class: Symfony\Component\Cache\Adapter\FilesystemAdapter
    arguments: ['', 0, '%ps_cache_dir%/extra_property_definition']

  # Repository: uncached DBAL implementation
  PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepository: ~

  # In FO: WriterInterface → raw repository (no cache, no write operations happen in FO)
  PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionWriterInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepository'

  # Cached repository decorator: implements both read and write interfaces
  PrestaShop\PrestaShop\Core\ExtraProperty\Definition\CachedExtraPropertyDefinitionRepository: ~

  # RepositoryInterface → cached read decorator
  PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Definition\CachedExtraPropertyDefinitionRepository'
    public: true

  # Validation
  PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidatorInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidator'
    public: true

  # Reader / Writer
  PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReader'
    public: true

  PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriter'
    public: true
```

**BO-only override**: `config/services/admin/extra_property.yml`

```yaml
services:
  # In BO: WriterInterface → cached decorator (write operations also invalidate the cache)
  PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionWriterInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Definition\CachedExtraPropertyDefinitionRepository'

  # Schema manager (DDL, BO-only — requires logger)
  PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ExtraPropertySchemaManagerInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Schema\ExtraPropertySchemaManager'

  # Registry: cache invalidation handled by the injected definition writer
  PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyRegistryInterface:
    alias: 'PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyRegistry'
    public: true

  # Back-office form services, Admin API service…
```

**Grid services**: `src/PrestaShopBundle/Resources/config/services/core/grid/grid_extra_properties.yml`

---

## 4. Module Integration

### 4.1. New Methods on Module Class

File: `classes/module/Module.php`

```php
public function registerExtraProperty(ExtraPropertyDefinition $definition): bool
```

Injects `$this->name` as module name when `$definition->getModuleName()` is null, then calls `ExtraPropertyRegistryInterface::register()`.

```php
public function unregisterExtraProperty(ExtraPropertyDefinition $definition, bool $dropData = false): bool
```

Same auto-injection of module name. `$dropData = true` drops the physical column in addition to deleting the definition row.

### 4.2. Automatic Cleanup on Uninstall

Not yet implemented. Modules are responsible for calling `unregisterExtraProperty()` explicitly in their `uninstall()` method.

### 4.3. Module Usage Example

```php
// In install():
$this->registerExtraProperty(new ExtraPropertyDefinition(
    entityName: 'product',
    propertyName: 'video_link',
    type: ExtraPropertyType::STRING,
    scope: ExtraPropertyScope::LANG,
    labelWording: 'Video link',
    labelDomain: 'Modules.Extrafieldproduct.Admin',
    descriptionWording: 'Video URL per language',
    descriptionDomain: 'Modules.Extrafieldproduct.Admin',
    displayApi: true,
    displayForm: true,
    displayFront: true,
    associatedGrids: ['product'],
    validator: 'isUrl',
));

// In uninstall():
$this->unregisterExtraProperty(new ExtraPropertyDefinition(
    entityName: 'product',
    propertyName: 'video_link',
    scope: ExtraPropertyScope::LANG,
), dropData: true);
```

### 4.4. Handling Two Modules on the Same Entity

Modules share the same `{entity}_extra` table but have distinct column names due to the `{module_name}_` prefix. Uninstalling one module removes only its columns.

---

## 5. ObjectModel Integration (Front-Office)

### 5.1. ExtraPropertiesBag::createForEntity()

`ExtraPropertiesBag` is the single lazy value resolver for both BO (`ObjectModel`) and FO (presenter lazy arrays). The static factory builds the loader closure once:

```php
public static function createForEntity(
    ?ContainerInterface $container,   // resolved by the caller — no ContainerFinder/Context in this namespace
    string $objectModelClassName,
    int $entityId,
    ?int $langId,                     // null = all languages ([id_lang => value] arrays)
    ShopConstraint $shopConstraint,
    bool $forFrontOffice = true,      // default true (consistent with ExtraPropertyDefinition::$displayFront);
                                      // true = only display_front definitions are read, BO callers pass false
): self;
```

All guards live inside the loader closure (cheap construction, never throws):
- Resolves to an empty bag when the container is null, `entityId <= 0`, the class is not an `ObjectModel` subclass, or the definition lacks `table`/`primary`
- Calls `repository->getAllDefinitions()->filterByEntity()` (+ `->filterForFrontOffice()` when `$forFrontOffice`) — skips the DB read when no matching fields are registered
- Passes the pre-filtered definitions to the reader (no redundant re-fetch, no post-filtering needed)

Callers resolve the container themselves: `ObjectModel::createExtraPropertiesBag()` uses `static::findContainer()`; FO presenters use `AbstractLazyArray::initExtraPropertiesBag()`, which performs the `ContainerFinder(Context::getContext())` resolution in the Adapter layer and passes the entity's own lang id when it carries one. Both derive `forFrontOffice` from `Context::isFrontOfficeContext()`: current controller type `'front'`/`'modulefront'` → filtered; BO, CLI, API and programmatic access → unfiltered; when the context controller is not available yet, it falls back to the `_PS_FRONT_DIR_` constant (only defined by the FO entry point). Native `$entity->extra_properties` access is therefore FO-safe automatically.

**LazyArrays that expose `extra_properties`** (via `$this->initExtraPropertiesBag(...)` in their constructor):
- `ProductLazyArray`
- `CategoryLazyArray`, `SupplierLazyArray`, `ManufacturerLazyArray`, `StoreLazyArray`
- `OrderLazyArray`, `OrderDetailLazyArray`, `OrderReturnLazyArray`
- `CartLazyArray`

`AbstractLazyArray::getExtraProperties()` returns the bag itself — `extra_properties` is an `ExtraPropertiesBag` in BO ObjectModel and FO lazy arrays alike (both bag levels implement `ArrayAccess` + `JsonSerializable`).

The `extra_properties` array index is **opt-in**: it is registered manually when `initExtraPropertiesBag()` is called (no auto-registration via `LazyArrayAttribute`), so lazy arrays that never initialize the bag (e.g. `OrderSubtotalLazyArray`) have no such key at all — generic templates iterating every entry never meet a non-printable bag object.

`ObjectPresenter::present()` also sets the `extra_properties` key on the plain arrays it produces (e.g. the Smarty `$customer`, `$country`, `$language` globals built by `FrontController`), so entities presented as arrays keep their extra properties too.

### 5.2. ObjectModel Integration

File: `classes/ObjectModel.php`

ObjectModel holds a single protected field `$extra_properties` typed as `?ExtraPropertiesBag`. Accessed from outside the class via `__get('extra_properties')`, which initializes the bag on first call.

**`ExtraPropertiesBag`** (`src/Core/ExtraProperty/Value/ExtraPropertiesBag.php`): lazy-loading grouped bag implementing `ArrayAccess`, `IteratorAggregate`, and `JsonSerializable`. Keys are module names (`'mymodule'`, `'_core'`); values are `ModuleFieldsBag` instances.

**`ModuleFieldsBag`** (`src/Core/ExtraProperty/Value/ModuleFieldsBag.php`): per-module `ArrayAccess` bag. Keys are field names; values are scalars (or `[id_lang => value]` arrays for lang-scoped fields). Tracks its own dirty fields; `getModifiedValues()` returns them as `[propertyName => value]` (storage columns are resolved inside the writer).

```php
$product->extra_properties['mymodule']['video_link']           // read (grouped)
$product->extra_properties['mymodule']['video_link'] = '...'   // write + mark dirty
```

`offsetGet()` on `ExtraPropertiesBag` auto-creates an empty `ModuleFieldsBag` for unknown keys, which makes chained writes work without a prior load. `getModifiedValues()` on the parent bag returns the dirty fields grouped `[moduleKey => [propertyName => value]]` — the same shape the reader outputs and the writer accepts.

Persistence via `persistExtraProperties()` (called from `add()`/`update()` after `actionObject*After` hooks): hands the bag's grouped modified values straight to `ExtraPropertyWriterInterface::writeAll()` with the context `ShopConstraint` and `resolveCurrentLangId()` as the scalar-lang fallback — scope routing happens inside the writer.

`$this->id_lang` is passed as the bag `$langId` when the ObjectModel was constructed with a language (lang-scope fields return a scalar for that language — FO pattern), and `null` otherwise (full `[id_lang => value]` arrays — BO/programmatic access, enabling read-modify-save of all languages at once).

### 5.3. Front-Office Template Access

**In Smarty templates** (via presenter):

```smarty
{$product.extra_properties.ps_extrafield_product.video_link|default:''}
{$category.extra_properties.ps_extrafield_category.theme_color|default:''}
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
- **Read**: calls `ExtraPropertyReaderInterface::getExtraProperties()` with pre-filtered definitions (`filterByApi()`); converts `id_lang` → locale strings for the response
- **Write**: validates payload, dispatches write via `ExtraPropertyWriterInterface::writeAll()`
- **Validation**: checks submitted field names against registry definitions filtered to `display_api = 1`

---

## 7. Back-Office Form Integration

### 7.1. Strategy

`ExtraPropertiesFormBuilderModifier` adds extra property fields to BO entity forms. It is injected into `FormHandler` and called directly — no hook required. `ExtraPropertiesFormDataPersister` is injected into `FormHandler` for persistence.

- `ExtraPropertiesFormBuilderModifier::apply()`: calls `repository->getAllDefinitions()->filterByEntity()->filterByForm($formId)` directly; loads existing values via `ExtraPropertyReaderInterface` with pre-filtered definitions, null langId (all langs) and `ShopContext::getShopConstraint()`
- `ExtraPropertiesFormDataPersister`: uses `$definitions->first()->getEntityName()` to resolve the entity name; calls `ExtraPropertyWriterInterface::writeAll()` directly

### 7.2. Placement Logic

- `associated_forms` empty → fields added to a dedicated `extra_fields.extra_properties` section (created if missing). On forms where a `NavigationTabType` is found anywhere in the parent chain, fields are injected at the root level instead.
- `associated_forms` set → `getFormEntry($formId)` returns the fully-resolved entry `['formId', 'mode', 'path', 'anchor']`, where `path` is the form node the field belongs to. Placement is resolved once inside `getFormEntry()` (container vs anchor); `path` is null only for fallback placement. Both `ExtraPropertiesFormBuilderModifier` (uses `path` + `anchor` to place the field) and `ExtraPropertiesFormDataPersister` (uses `path` to read it back) consume the same values and cannot drift.
- No mode → the path is a container: it is navigated in full (every segment must exist) and the field is appended inside. Example: `product:options` appends the field inside the `options` sub-builder.
- `:before`/`:after` mode → the last path segment is an anchor: relative placement via `FormBuilderModifier::addBefore()`/`addAfter()` inside the parent builder. Example: `product:options.suppliers:before` inserts the field just before the `suppliers` field inside the `options` sub-builder.
- A path whose container/anchor cannot be resolved throws `InvalidArgumentException` (no silent fallback).

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

For `LANG` fields, the form type is wrapped in `TranslatableType`.

### 7.4. i18n Labels

Labels and descriptions use `label_wording` + `label_domain` stored in the registry, resolved at runtime via the Symfony translator.

`label_wording` is **required** when `associated_forms` or `associated_grids` is non-empty. `ExtraPropertyRegistry::register()` returns `false` (with a logged error) if this constraint is violated.

---

## 8. Grid Integration

### 8.1. Strategy

`ExtraPropertiesGridDefinitionModifier` adds columns and filters to BO grids. It is injected into `DoctrineGridDataFactory`. `ExtraPropertiesGridQueryBuilderModifier` adds LEFT JOINs.

`filterByGrid(string $gridId)` selects only definitions whose `associated_grids` JSON array contains an entry whose `gridId` component matches — via `ExtraPropertyDefinition::getGridEntry($gridId)`. JSON fields are skipped (no meaningful grid representation).

`ExtraPropertiesGridQueryBuilderModifier` injects `LanguageContext` to resolve the current language id.

### 8.2. Query Builder Modification

```sql
SELECT p.*, extra.mymodule_custom_size AS extra_common_mymodule_custom_size
FROM ps_product p
LEFT JOIN ps_product_extra extra ON extra.id_product = p.id_product
```

SELECT aliases follow `ExtraPropertyDefinition::getFormFieldName()`: `extra_{scope}_{module}_{field}`.

**Cardinality invariant**: every LEFT JOIN added by `ExtraPropertiesGridQueryBuilderModifier` covers the **full primary key** of its extra table (`{e}_extra`: `id_e`; `{e}_extra_lang`: `id_e` + `id_lang` + `id_shop`; `{e}_extra_shop`: `id_e` + `id_shop`), so each join matches at most one row per existing grid row — joins enrich rows 1:1 and can never multiply them. Pagination and the COUNT query stay correct without any `GROUP BY` (forbidden in this service). The shop pin of lang/shop joins is resolved in order: base `{e}_lang`/`{e}_shop` join alias of the builder → the builder's own `:shopId` parameter → ShopContext (single-shop constraint → its id, otherwise the current shop id).

Joins, parameters, and filter WHEREs are built **independently per builder** (search and count): their query shapes usually differ (count builders rarely carry the base lang/shop joins), so aliases resolved on one builder are never reused on the other, and filters always apply to both so the count matches the page.

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
| Boolean | `ExtraPropertyType::BOOL` | `tinyint(1) unsigned` | |
| Integer | `ExtraPropertyType::INT` | `int(11)` | |
| String | `ExtraPropertyType::STRING` | `varchar({size})` | size default: 255 |
| Float | `ExtraPropertyType::FLOAT` | `decimal(20,6)` | |
| DateTime | `ExtraPropertyType::DATE` | `datetime` | |
| Choice | `ExtraPropertyType::CHOICE` | `ENUM(...)` | values from `$enumValues` |
| JSON | `ExtraPropertyType::JSON` | `longtext` | not shown in grid |
| HTML | `ExtraPropertyType::HTML` | `text` | rich text, purified |

---

## 10. Performance Considerations

1. **Definition caching**: `CachedExtraPropertyDefinitionRepository` uses a `FilesystemAdapter` pool. Cache is invalidated on every write (`save`, `delete`, `deleteByDefinition`). The BO container overrides `ExtraPropertyDefinitionWriterInterface` → `CachedExtraPropertyDefinitionRepository` so that write operations in BO also invalidate the cache.
2. **Lazy loading in ObjectModel**: Extra properties are NOT loaded on object construction. They are loaded on first `extra_properties` access.
3. **No-op when unused**: Reader checks definitions first; returns `[]` immediately without DB query when none exist.
4. **FO whitelist pre-check**: `ExtraPropertiesBag::createForEntity(..., forFrontOffice: true)` chains `filterForFrontOffice()` before querying values — skips the reader entirely when no FO fields are registered. The pre-filtered collection is passed directly to the reader, avoiding a redundant fetch inside it.
5. **Bulk reading in grids**: `ExtraPropertiesGridQueryBuilderModifier` adds LEFT JOINs to existing grid queries — no N+1 problem. The post-fetch `castExtraProperties()` pass reuses the cached definition collection (no extra query).
6. **Column-based storage**: Unlike EAV meta tables, extra properties are stored as columns — enables SQL indexing, no row multiplication.
7. **No DDL cache**: `ExtraPropertySchemaManager` performs no internal caching — DDL operations are rare (install/uninstall only) and always reflect current DB state.

---

## 11. Conflict Handling

1. **Column name uniqueness**: `{module_name}_{property_name}` is unique per `(entity_name, module_name, property_name, scope)` via DB unique key.
2. **Core fields**: `module_name IS NULL` → column name = `{property_name}` (no prefix).
3. **Column name length**: enforced ≤ 64 characters; `ExtraPropertyDefinition` constructor throws `InvalidExtraPropertyDefinitionException` if exceeded.
4. **Destructive schema changes refused**: `ExtraPropertyRegistry::register()` refuses changes that risk data already stored in the extra column: `type`/`scope` change, STRING size decrease (effective lengths compared, `null` ≡ 255), nullable tightening (NULL → NOT NULL), and CHOICE enum value removal (or switching between ENUM and the VARCHAR fallback). Such changes require `unregister()` + `register()` — no automatic data migration. Non-destructive changes (`defaultValue` change, size increase, nullable relaxing, enum value addition/reordering) are accepted and applied to the live column via `ALTER TABLE … MODIFY COLUMN`. The `nullable`/`enumValues` comparison relies on the repository deducing both from the live column schema.
5. **Scope uniqueness per module+property**: a module cannot register the same `propertyName` in two different scopes for the same entity.

---

## 12. Testing Strategy

### Unit Tests

- `ExtraPropertyDefinition` constructor validation and naming methods (`tests/Unit/Core/ExtraProperty/`)
- `ColumnDefinitionMapper` type-to-SQL mapping (`tests/Unit/Core/ExtraProperty/Schema/ColumnDefinitionMapperTest.php`)
- `ExtraPropertyDefinitionCollection` filter methods
- `ExtraPropertyValueCaster` scalar cast coverage

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
