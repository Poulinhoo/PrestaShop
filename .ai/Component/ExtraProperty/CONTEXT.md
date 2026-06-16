# ExtraProperty Component

> **Status:** Draft — feature is under active development (Epic [#41422](https://github.com/PrestaShop/PrestaShop/issues/41422)). This file describes the merged code; see *Status & roadmap* for what is not done yet.

## Purpose

Lets any module register **typed extra fields** on existing PrestaShop entities (Product, Order, Customer…) without altering core tables. A single `Module::registerExtraProperty(ExtraPropertyDefinition)` call provisions storage and surfaces the field in the front-office (Smarty lazy arrays), the migrated back-office (Symfony forms + grids) and the Admin API. Storage is **column-based** (one DB column per property), not EAV — so values are indexable and joinable.

## Layers

| Layer | Path |
|-------|------|
| Definition: registry VO, enums, repository, cache | `src/Core/ExtraProperty/Definition/` |
| Schema: dynamic DDL (table/column create/alter/drop) | `src/Core/ExtraProperty/Schema/` |
| Value: reader, writer, caster, lazy bags | `src/Core/ExtraProperty/Value/` |
| Validation | `src/Core/ExtraProperty/Validation/` |
| BO form integration | `src/Core/ExtraProperty/Form/` |
| BO grid integration | `src/Core/ExtraProperty/Grid/` |
| Admin API service | `src/PrestaShopBundle/ApiPlatform/ExtraProperties/ExtraPropertiesApiService.php` |
| Legacy + FO hooks | `classes/module/Module.php`, `classes/ObjectModel.php`, `src/Adapter/Presenter/AbstractLazyArray.php` |
| Registry table DDL | `install-dev/data/db_structure.sql` (`ps_extra_property_definition`) |
| Service wiring | `…/config/services/extra_property/{common,backend}.yml`, `…/config/services/core/grid/grid_extra_properties.yml` |

## Database structure

**Central registry — `ps_extra_property_definition`** (created at install). One row per registered property. Real columns: `entity_name`, `module_name` (NULL = core field, no module owner), `property_name`, `type` ENUM(`int,bool,string,float,date,html,json,choice`), `scope` ENUM(`common,lang,shop`), `sql_index` ENUM(`none,key,unique`), `size`, `default_value`, `validator`, `display_front`, `display_api`, `associated_grids`/`associated_forms` (JSON placement DSL), `form_field_type`, `form_required`, `form_options`, and `label_*`/`description_*` wording+domain pairs. Unique key: `(entity_name, module_name, property_name)`.

**Dynamic per-entity value tables**, created lazily by `ExtraPropertySchemaManager` on first registration for an entity, one per scope:

| Scope | Table | Same value across… |
|-------|-------|--------------------|
| `common` | `{entity}_extra` | all shops + langs |
| `lang` | `{entity}_extra_lang` | per language (per shop too) |
| `shop` | `{entity}_extra_shop` | per shop |

The non-obvious part: each extra table is **built by mirroring the primary key of the matching native base table** via Doctrine introspection (`createExtraTableFromBaseTable`), not from hardcoded columns. Base table per scope: `common`→`{entity}`, `lang`→`{entity}_lang`, `shop`→`{entity}_shop` (`ExtraPropertyDefinition::getBaseTableName()`). So `product_extra_lang`'s PK is exactly `product_lang`'s PK (`id_product,id_lang,id_shop`). This guarantees every grid LEFT JOIN matches **at most one row** (1:1), so no `GROUP BY` is ever needed.

Each property is one column added with `ALTER TABLE … ADD COLUMN`; storage column name = `{module}_{property}` (or `{property}` for core fields). SQL type comes from `ColumnDefinitionMapper`: INT(11), TINYINT(1) UNSIGNED (bool), VARCHAR(size|255), DECIMAL(20,6), DATETIME, TEXT (html), LONGTEXT (json), `ENUM(...)` for choice (VARCHAR(64) fallback if no values). On unregister the column is dropped, and the table itself is dropped once only PK columns remain.

## Module-facing usage

A module registers a property in `install()` and removes it in `uninstall()` (see the **`demoextrafield`** example module: <https://github.com/PrestaShop/example-modules/tree/master/demoextrafield>):

```php
$this->registerExtraProperty(new ExtraPropertyDefinition(
    entityName: 'product', propertyName: 'video_link',
    type: ExtraPropertyType::STRING, scope: ExtraPropertyScope::LANG,
    labelWording: 'Video link', labelDomain: 'Modules.Demoextrafield.Admin',
    displayFront: true, displayApi: true, associatedForms: ['product'],
));
// uninstall():  $this->unregisterExtraProperty($definition, dropData: true);
```

**Read/write values** — grouped by module key (`'_core'` for core fields), persisted on `ObjectModel::save()`:

```php
$product->extra_properties['demoextrafield']['video_link'];                // read
$product->extra_properties['demoextrafield']['video_link'] = 'https://…';  // write (saved on update())
```

```smarty
{$product.extra_properties.demoextrafield.video_link|default:''}
```

The Smarty/array index key is `extra_properties` (snake_case), and only present on entities whose lazy array opted in (Product, Category, …).

**Field placement** is declared as string entries (parsed in the VO, see `getFormEntry`/`getGridEntry`):
- `associatedForms`: `"formId[:path[:before|after]]"` — no mode → field appended inside the path container; `:before`/`:after` → placed relative to the last path segment as an anchor (e.g. `"product:options.suppliers:before"`). Empty → a dedicated extra-properties form section.
- `associatedGrids`: `"gridId[:columnId[:before|after]]"` (e.g. `"product:reference:after"`). Empty → placed before the grid `actions` column.

**Rendering** (derived from the logical type, not the override):
- BO form field = `form_field_type` FQCN if set, else **`TextType`**; LANG scope wrapped in `TranslatableType`; `form_required` adds a `NotBlank`, `validator` adds a `Callback` constraint. (There is no per-type form-type map — `TextType` is the default for every untyped field.)
- Grid column: BOOL → `ToggleColumn` (route `admin_common_extra_properties_toggle`, shop resolved server-side), DATE → `DateTimeColumn`, JSON → skipped, everything else → `DataColumn`.
- Form field name, grid column id and grid SELECT alias all share `getFormFieldName()` = `extra_{scope}_{module}_{property}`.

## Non-obvious patterns

- **`nullable` and `enumValues` are NOT stored in the registry** — the live storage column schema is their source of truth. `ExtraPropertyDefinitionRepository::enrichRowsWithColumnMetadata()` runs `SHOW COLUMNS` and injects synthetic `nullable`/`enum_values` keys consumed by `ExtraPropertyDefinition::fromRow()`.
- **Two-tier service wiring.** `common.yml` (FO+BO): repository, cached read decorator, reader, writer, validator. `backend.yml` (BO-only): schema manager (DDL, needs logger), registry, form services, API service — registration/DDL only happen back-office. In FO the `WriterInterface` aliases the plain repository (no writes, no cache invalidation).
- **`CachedExtraPropertyDefinitionRepository` implements BOTH read and write interfaces** over one filesystem cache pool; every write invalidates. The registry itself is uncached and gets the cached repo injected as its writer.
- **The registry refuses destructive schema changes** on an already-registered property (type/scope change, STRING size decrease, NULL→NOT NULL, ENUM value removal) — those require unregister + re-register. Non-destructive drift (default change, size increase, nullable relax, enum addition) is reconciled onto the live column via `ALTER TABLE … MODIFY COLUMN`.
- **Grouped value shape everywhere.** Reader returns / writer accepts `[moduleKey => [propertyName => value]]` (`'_core'` for core fields). For lang scope, `langId = null` yields `[id_lang => value]` arrays (BO/API edit-all-langs), an int yields a scalar (FO).
- **`ExtraPropertiesBag`** is the single lazy resolver for both `ObjectModel::$extra_properties` and FO lazy arrays; it dirty-tracks writes. ObjectModel persists modified values in `add()`/`update()` via `persistExtraProperties()`. FO arrays opt in via `AbstractLazyArray::initExtraPropertiesBag()` (Product, Category, Manufacturer, Supplier, Store, Order, OrderDetail, OrderReturn, Cart). FO vs BO is **auto-detected** inside `createForEntity()` via `Context::isFrontOfficeContext()` (fallback `_PS_FRONT_DIR_`): FO reads see only `display_front = 1` fields, BO/CLI/API see all — so native `$entity->extra_properties` access is FO-safe with no module code.
- **Identifier safety contract.** The `ExtraPropertyDefinition` constructor validates `entityName`, `propertyName` and the computed storage column as SQL identifiers (≤64 chars), so DDL consumers embed them in SQL without re-validating. `entityName` is normalized to snake_case (`tableize()`) at construction.

## Status & roadmap

- **Admin API integration is incomplete.** `ExtraPropertiesApiService` exists and is DI-wired but is **not yet invoked** by the serialization pipeline — being reworked under [#41543](https://github.com/PrestaShop/PrestaShop/issues/41543) (+ definitions via API [#41542](https://github.com/PrestaShop/PrestaShop/issues/41542)). There is **no CQRS domain** (`src/Core/Domain/ExtraProperty`) nor Adapter layer, despite earlier drafts.
- **Module uninstall cleanup is not automatic** — modules must call `unregisterExtraProperty($definition, dropData: true)` themselves.
- Open sub-issues: advanced form placement via `property_path` [#41425], native no-code BO management panel [#41426], multishop handling [#41568], automated tests [#41541], validation hardening [#41544], example module [#41428], docs [#41429], security review [#41640], translation scanner support [#41725], cart entity [#41424].

## Canonical examples

- `src/Core/ExtraProperty/Definition/ExtraPropertyDefinition.php` — immutable VO; registration config + read DTO + all naming/placement logic
- `src/Core/ExtraProperty/Schema/ExtraPropertySchemaManager.php` — dynamic table/column DDL (mirrors base-table PK)
- `src/Core/ExtraProperty/Value/ExtraPropertiesBag.php` — lazy grouped bag shared by ObjectModel + FO
- `src/Core/ExtraProperty/Schema/ColumnDefinitionMapper.php` — ExtraPropertyType → SQL column fragment

## Related

- [MULTISTORE.md](../../MULTISTORE.md) — scope routing (`common`/`lang`/`shop`) resolves through `ShopConstraint`/`ShopContext`/`LanguageContext`
- [Grid Component](../Grid/CONTEXT.md) — extra columns are injected via grid definition/query-builder modifiers; the PK-mirroring 1:1 join invariant lets them skip `GROUP BY`
