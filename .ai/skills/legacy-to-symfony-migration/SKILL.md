---
name: legacy-to-symfony-migration
description: >
  Step-by-step guide for migrating a PrestaShop Legacy admin page to Symfony/CQRS.
  Covers the full lifecycle from audit to GA. Trigger: "migrate the Xxx admin page",
  "create CQRS for Xxx", "add a Symfony form for Xxx", "migrate AdminXxxController".
---

# Legacy to Symfony/CQRS Migration Skill

## When to use this skill

Trigger when asked to:
- "Migrate the Xxx admin page to Symfony"
- "Create CQRS for the Xxx domain"
- "Add a Symfony form for Xxx"
- "Migrate AdminXxxController"

## Background

This skill was derived from the Carrier page migration case study (PRs #20737, #36063-#37638), but the patterns apply to all admin page migrations. The Carrier migration spanned 2021-2025 across 20+ PRs, evolving from a listing-only migration to full CRUD with Vue.js components.

**Reference pages by complexity** (all fully migrated):

| Tier | Page | Key specificity | JS pattern |
|------|------|----------------|------------|
| Simple | Tax | Baseline CRUD + options panel | `initComponents()` |
| Simple | Contact | Translatable fields, multistore | `initComponents()` |
| Medium | Manufacturer | Sub-resource (addresses), image upload, export | `initComponents()` |
| Medium | Category | Position management, tree hierarchy, SEO | `initComponents()` |
| Complex | Customer | Multiple related grids, B2B fields | Vue |
| Complex | Order | 48 actions, sub-resources (shipments, payments) | Vue |
| Complex | Carrier | Multi-tab form (still has `_legacy_feature_flag`) | Vue |

> **Note:** Vue.js is the exception, not the default. Most pages use `initComponents()` with standard PS JS components. Only use Vue for complex UX synchronization (multi-row dynamic tables, real-time field dependencies across tabs).

## Phase index

| # | File | Title | Deliverable |
|---|------|-------|-------------|
| 0 | [step-00-audit.md](step-00-audit.md) | Audit | Field map, action list, milestone decision |
| 1 | [step-01-domain-layer.md](step-01-domain-layer.md) | Domain Layer | Commands, Queries, ValueObjects, Exceptions |
| 2 | [step-02-adapter-layer.md](step-02-adapter-layer.md) | Adapter Layer | Repository, Handlers, Validator |
| 3 | [step-03-behat-tests.md](step-03-behat-tests.md) | Behat Tests | Integration test coverage for CQRS |
| 4 | [step-04-grid.md](step-04-grid.md) | Grid | Listing page with filters and bulk actions |
| 5 | [step-05-symfony-controller.md](step-05-symfony-controller.md) | Symfony Controller | All admin actions wired to command/query bus |
| 6 | [step-06-routing.md](step-06-routing.md) | Routing | YAML routes with feature flag |
| 7 | [step-07-form.md](step-07-form.md) | Form | Tab-based add/edit form with DataProvider/Handler |
| 8 | [step-08-frontend.md](step-08-frontend.md) | Frontend | JS entry point (initComponents or Vue) |
| 9 | [step-09-twig-templates.md](step-09-twig-templates.md) | Twig Templates | index and form templates |
| 10 | [step-10-feature-flag.md](step-10-feature-flag.md) | Feature Flag | Beta registration in feature_flag.xml |
| 11 | [step-11-playwright-tests.md](step-11-playwright-tests.md) | Playwright Tests | UI test campaigns per feature area |
| 12 | [step-12-general-availability.md](step-12-general-availability.md) | General Availability | Promote flag to stable |
| 13 | [step-13-legacy-deprecation.md](step-13-legacy-deprecation.md) | Legacy Deprecation | Banner in legacy controller |

## Key PS-specific rules (always apply)

- Multistore has three tiers: (1) no shop relation at all, (2) simple shop association (content is the same, just linked to a list of shops), (3) per-shop content (fields change by shop, like Product or Category). `AbstractMultiShopObjectModelRepository` is required for tier 3 and can be useful for tier 2 (some helpers apply even if per-shop content isn't needed). Not needed for tier 1
- Sub-resources get their own Command, never merged into `EditXxxCommand`
- Feature flag is the routing mechanism, not a cosmetic toggle -- every route must carry `_legacy_feature_flag`
- Legacy controller is **never deleted**, only gets a deprecation banner
- `IdentifiableObject` DataProvider + DataHandler pattern replaces Symfony form events
- `NavigationTabType` for multi-tab forms -- not standard Symfony tabs
- File uploaders are a domain interface (in `src/Core/Domain/`), implemented in Adapter
- Listing and form migrations can be separate milestones, often months or years apart
- `stability="beta"` to `"stable"` is a formal step requiring its own PR
- Vue components are only needed when a form field is too dynamic for Symfony form types alone -- most pages use `initComponents()` with standard PS JS components

## Dependency graph

```
A1 (audit controller) ──┐
                         ├──> A3 (manifest) ──> D1-D14 (domain layer)
A2 (audit model) ────────┘                          |
                                                     v
                                              P1-P10 (adapter layer)
                                                     |
                                                     v
                                              B1-B6 (behat tests) -- GATE: all green
                                      ╔══════════════════════════╗
                                      ║     PARALLEL BAND A      ║
                               G1-G5 (grid)       F1-F6 (form)
                                      ╚══════════════════════════╝
                                                     |
                                              H1 (controller)
                                              H2 (routing)
                                              H3 (feature flag)
                                      ╔══════════════════════════╗
                                      ║     PARALLEL BAND B      ║
                               JS1-JS5 (frontend)    T1-T4 (twig)
                                      ╚══════════════════════════╝
                                                     |
                                    E1-E2 (test fixtures -- unblocked after A3)
                                    E3-E7 (playwright -- needs full stack)
                                                     |
                                    R1 > R2+R3 > R4 > R5 > R6
```

**Parallel Band A** (Grid + Form) is the longest parallel opportunity -- both can progress once the domain layer is finalized.

**E1 + E2** (test fixtures and resetter) can start as soon as the manifest exists.

## Conditional activation matrix

| Condition (from audit) | What it activates |
|---|---|
| Has enum-like fields | Semantic value objects |
| Has sub-resources with own table | Sub-resource commands, repositories, handlers, behat scenarios |
| Has computed/image columns in grid | Grid data factory decorator |
| Has multistore | Multistore behat scenarios |
| Has file uploads | File uploader interface + implementation |
| Has i18n fields | i18n behat scenarios |
| Has `position` column | Position definition service, position playwright campaign |
| Has dynamic form fields (Vue needed) | Complex form subtypes, Vue components, form manager, webpack entry |
| Is a genuinely new feature | Showcase card template |
| Has upgrade path for existing installs | Upgrade SQL |

## Inter-skill communication contracts

Key artifacts that cross skill boundaries:

| From | To | Contract |
|---|---|---|
| Audit manifest | All downstream skills | Entity name, table, field definitions, action list |
| Domain layer | Adapter layer | Interface FQCNs, exception class names, DTO getter list |
| Domain exceptions | Behat tests | `const INVALID_*` codes for constraint violation scenarios |
| DTO getters | Form DataProvider | `getData()` array keys match DTO structure |
| Command signatures | Form DataHandler | `create()`/`update()` dispatch commands using form data |
| Grid factory + filters | Controller | Grid factory service ID, `{Domain}Filters` class FQCN |
| Form services | Controller | Form builder + handler service IDs |
| Route names | Twig templates | `path()` calls reference route names |
| Feature flag name | Playwright tests | `enableFeatureFlag()` in test setup |
| Form type block names | Twig form theme | Widget block rendering for complex fields |
| Webpack bundle name | Twig form template | `<script>` asset reference |

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
