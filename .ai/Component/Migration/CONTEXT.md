# Migration Component

## Purpose

Infrastructure and skills for migrating PrestaShop legacy admin pages (ObjectModel + legacy controllers) to modern Symfony/CQRS architecture. Covers the full lifecycle: audit, domain layer, UI, testing, GA, and legacy deprecation.

## Layers

| Layer | Path |
|-------|------|
| Migration orchestrator skill | `.ai/Component/Migration/skills/legacy-to-symfony-migration/` |
| Audit skills | `audit-legacy-controller`, `audit-object-model`, `generate-migration-manifest` |
| Lifecycle skills | `promote-feature-flag-to-stable`, `write-upgrade-sql`, `add-legacy-deprecation-notice`, `write-changelog-deprecation`, `create-removal-issue` |

## Reference pages by complexity

All fully migrated unless noted:

| Tier | Page | Fields | Grids | Tabs | JS pattern | Key specificity |
|------|------|--------|-------|------|------------|-----------------|
| Simple | Tax | 3 | 1 | No | `initComponents()` | Baseline CRUD + options panel |
| Simple | Contact | 5 | 1 | No | `initComponents()` | Translatable fields, multistore |
| Medium | Manufacturer | 8 | 2 | No | `initComponents()` | Sub-resource (addresses), image upload, export |
| Medium | Category | 13 | 1 | No | `initComponents()` | Position management, tree hierarchy, SEO |
| Medium | Employee | 12 | 1 | Yes | `initComponents()` | Profile-based permissions, password policy |
| Complex | Customer | 20+ | 7 | Yes | Vue | Multiple related grids, B2B fields |
| Complex | Order | view-only | 5+ | Yes | Vue | 48 actions, sub-resources (shipments, payments) |
| Complex | Carrier | many | 1 | Yes | Vue | Multi-tab form (still has `_legacy_feature_flag`) |

> **Note:** Vue.js is the exception, not the default. Most pages use `initComponents()` with standard PS JS components. Only use Vue for complex UX synchronization.

## Key PS-specific rules (always apply during migration)

- Multistore has three tiers: (1) no shop relation, (2) simple shop association, (3) per-shop content. `AbstractMultiShopObjectModelRepository` is required for tier 3, useful for tier 2, not needed for tier 1
- Sub-resources get their own Command, never merged into `EditXxxCommand`
- Feature flag is the routing mechanism, not a cosmetic toggle — every route must carry `_legacy_feature_flag`
- Legacy controller is **never deleted**, only gets a deprecation banner
- `IdentifiableObject` DataProvider + DataHandler pattern replaces Symfony form events
- `NavigationTabType` for multi-tab forms — not standard Symfony tabs (exception, not default)
- File uploaders are a domain interface (in `src/Core/Domain/`), implemented in Adapter
- Listing and form migrations can be separate milestones, often months or years apart
- `stability="beta"` to `"stable"` is a formal step requiring its own PR — never merge all migration work in one PR (3+ PRs minimum)
- Vue components are only needed when a form field is too dynamic for standard JS components — most pages use `initComponents()`

## Lifecycle rules

### GA (promote to stable)

- Upgrade SQL must be **idempotent** — safe to run multiple times (use `INSERT ... ON DUPLICATE KEY UPDATE` for feature flag rows)
- Changelog entry documents the new page as stable

### Deprecation (6-12 months after GA)

- Deprecation banner goes in `$this->warnings[]` (yellow) — **not** `$this->errors[]` (red)
- The banner checks availability via a private `isNewPageAvailable()` method using `Configuration::get('PS_FEATURE_FLAG_{DOMAIN}')` or `FeatureFlagRepository`
- **No `@trigger_error()` warnings** — these are forbidden to avoid merchant log noise
- Changelog entry targets removal in the **next major version**: "will be removed in PrestaShop X.0"

### Removal (next major version)

- Requires a **2+ minor release** deprecation period before the removal PR
- Removal issue must reference both the deprecation PR and the GA PR by number

## Dependency graph

```
Audit (audit-legacy-controller, audit-object-model, generate-migration-manifest)
                                    |
                                    v
                          Domain layer (create-cqrs-commands, create-cqrs-queries)
                                    |
                                    v
                          Adapter layer (implement-cqrs-handlers, create-doctrine-repository)
                                    |
                                    v
                          Behat tests (create-behat-context, write-behat-scenarios) — GATE: all green
                                ╔════════════════════════════╗
                                ║      PARALLEL BAND A       ║
                         Grid (create-grid-definition,    Form (create-form-type,
                          create-grid-query-builder)     create-form-data-handling)
                                ╚════════════════════════════╝
                                    |
                          Controller + Routing + Feature Flag (commit together)
                                ╔════════════════════════════╗
                                ║      PARALLEL BAND B       ║
                         JS (create-ts-entry-point,    Twig (create-twig-index-template,
                          init-grid-extensions,          create-twig-form-template)
                          init-js-components)
                                ╚════════════════════════════╝
                                    |
                          Playwright tests (create-playwright-page-objects,
                           create-playwright-test-data, write-playwright-campaigns)
                                    |
                          GA (promote-feature-flag-to-stable, write-upgrade-sql)
                                    |
                          Deprecation (~6-12 months later: add-legacy-deprecation-notice,
                           write-changelog-deprecation, create-removal-issue)
```

## Conditional activation

| Condition (from audit) | What it activates |
|---|---|
| Has enum-like fields | Semantic value objects |
| Has sub-resources with own table | Sub-resource commands, repositories, handlers, behat scenarios |
| Has computed/image columns in grid | GridDataFactory decorator |
| Has multistore (tier 2/3) | Multistore repository pattern, multistore behat scenarios |
| Has file uploads | File uploader interface + implementation |
| Has i18n fields | TranslatableType fields, i18n behat scenarios |
| Has `position` column | PositionColumn, position grid extension, position Playwright campaign |
| Has dynamic form fields (Vue needed) | Vue component, form manager, webpack entry |
| Has upgrade path for existing installs | Upgrade SQL |

## Inter-skill communication contracts

Key artifacts that cross skill boundaries:

| From | To | Contract |
|---|---|---|
| Audit manifest | All downstream skills | Entity name, table, field definitions, action list |
| Domain layer | Adapter layer | Interface FQCNs, exception class names, DTO getter list |
| Domain exceptions | Behat tests | Exception class names for error scenario assertions |
| DTO getters | Form DataProvider | `getData()` array keys match DTO structure |
| Command signatures | Form DataHandler | `create()`/`update()` dispatch commands using form data |
| Grid definition (GRID_ID) | Controller + JS | Grid factory service ID, `{Domain}Filters` class, JS Grid constructor ID |
| Form services | Controller | FormBuilder + FormHandler service IDs |
| Route names | Twig templates + JS | `path()` calls, grid action URLs |
| Feature flag name | Playwright tests + routing | Flag matching between XML, routing YAML, and test setup |
| Webpack bundle name | Twig template | `<script>` asset reference |

## Carrier migration PR timeline (historical reference)

| Phase | PR | Title | Date | Focus |
|---|---|---|---|---|
| 1 - Listing | #20737 | Migrate carriers listing | 2021-03 | Grid + 5 CQRS commands + routing + Twig + JS |
| 2 - CQRS | #36063 | Add/Get/Update/UploadLogo | 2024-05 | Commands, adapters, Behat |
| 3 - First form | #36271 | Basic general form | 2024-06 | Controller, CarrierType, DataProvider/Handler, routing, Twig, TS |
| 4 - Fields | #36246, #36300 | More fields + shipping costs CQRS | 2024-06 | Dimensional constraints, zone/cost fields |
| 5 - Tabs | #36381 | Size/weight tab + group access | 2024-06 | Tab form types, customer group field |
| 6 - Ranges | #36380, #36387 | Ranges CQRS + multistore | 2024-06 | Sub-resource commands, multistore behat |
| 7 - Polish | #36434, #36706 | Edit optimization + feature flag | 2024-07 | Skip unchanged fields, beta flag registration |
| 8 - Complex UI | #36534, #36537, #36655 | Ranges Vue component | 2024-07 | Vue SFC, form manager, form theme |
| 9 - UI tests | #36112-#36954 | Playwright suites | 2024-05-09 | 7 campaigns (CRUD, bulk, position, tabs) |
| 10 - Refactor | #36818 | Dissociate zones from ranges | 2024-09 | Architectural fix in CQRS + Vue |
| 11 - Fixes | #36876-#37297 | Various bug fixes | 2024-09-11 | Logo upload, tab redirect, validation |
| 12 - GA | #37638 | Feature flag to stable | 2025-02 | `stability="stable"`, full Playwright migration |
| 13 - Deprecation | #39050 | Migration banner | 2025-07 | Legacy controller deprecation notice |

## Skills

| Skill | Trigger |
|-------|---------|
| [`legacy-to-symfony-migration`](skills/legacy-to-symfony-migration/SKILL.md) | "migrate the Xxx admin page" |
| [`audit-legacy-controller`](skills/audit-legacy-controller/SKILL.md) | "audit AdminXxxController" |
| [`audit-object-model`](skills/audit-object-model/SKILL.md) | "audit Xxx ObjectModel" |
| [`generate-migration-manifest`](skills/generate-migration-manifest/SKILL.md) | "generate migration manifest" |
| [`promote-feature-flag-to-stable`](skills/promote-feature-flag-to-stable/SKILL.md) | "promote {Domain} to GA" |
| [`write-upgrade-sql`](skills/write-upgrade-sql/SKILL.md) | "write upgrade SQL for {Domain}" |
| [`add-legacy-deprecation-notice`](skills/add-legacy-deprecation-notice/SKILL.md) | "add deprecation notice to AdminXxx" |
| [`write-changelog-deprecation`](skills/write-changelog-deprecation/SKILL.md) | "write changelog deprecation for {Domain}" |
| [`create-removal-issue`](skills/create-removal-issue/SKILL.md) | "create removal issue for AdminXxx" |

## Related

- [CQRS Component](../CQRS/CONTEXT.md) — commands, queries, handlers
- [Controller Component](../Controller/CONTEXT.md) — Symfony admin controllers
- [Forms Component](../Forms/CONTEXT.md) — form types, DataProvider/DataHandler
- [Grid Component](../Grid/CONTEXT.md) — grid definitions, query builders
- [Behat Component](../Behat/CONTEXT.md) — integration tests
- [Playwright Component](../Playwright/CONTEXT.md) — UI tests
- [Javascript Component](../Javascript/CONTEXT.md) — JS entry points, components, grid extensions
- [Twig Component](../Twig/CONTEXT.md) — admin templates
