# Javascript Component

## Purpose

Back-office JavaScript/TypeScript infrastructure: page entry points, reusable global components, grid extensions, and Vue integration for complex UI sections. Front-office JS is separate (in themes).

## Layers

| Layer | Path |
|-------|------|
| Page entry points | `admin-dev/themes/new-theme/js/pages/{domain}/` |
| Global components | `admin-dev/themes/new-theme/js/components/` |
| Grid extensions | `admin-dev/themes/new-theme/js/components/grid/extension/` |
| App utilities | `admin-dev/themes/new-theme/js/app/` |
| Vue integration | `admin-dev/themes/new-theme/js/vue/`, `js/pages/{domain}/` (Vue SFCs) |
| Webpack config | `admin-dev/themes/new-theme/.webpack/common.js` |
| Output bundles | `admin-dev/themes/new-theme/public/*.bundle.js` |

## Page entry point structure

Each admin page has a TypeScript entry point:

```
admin-dev/themes/new-theme/js/pages/{domain}/
├── index.ts                    # Listing/grid page
├── form.ts OR form/index.ts    # Form page (create/edit)
├── {domain}-map.ts             # DOM element selector mappings (optional)
└── manager/                    # Complex feature managers (optional, not default)
```

- `index.ts` — grid listing: initializes `Grid` instance, adds extensions, initializes needed components
- `form.ts` or `form/index.ts` — form page: initializes components for form fields (translatable inputs, choice trees, etc.)
- For simple pages, the entry point directly enables components. For complex pages, dedicated manager classes can be created but this is not the default

**Webpack entries** are registered in `admin-dev/themes/new-theme/.webpack/common.js`:
```javascript
entry: {
  tax: './js/pages/tax',
  manufacturer: './js/pages/manufacturer',
  // form-specific entries:
  attribute_form: './js/pages/attribute/form',
}
```

Path aliases: `@app`, `@js`, `@pages`, `@components`, `@scss`, `@PSVue`, `@PSTypes`

## Global component system (`initComponents`)

Components are exposed on `window.prestashop.component` and initialized via:

```typescript
window.prestashop.component.initComponents([
  'TranslatableInput',
  'TinyMCEEditor',
  'ChoiceTree',
]);
```

Components activate automatically based on `data-*` attributes in the DOM. Only initialize the ones the page actually needs.

**Initialization pattern:** wrap in jQuery document ready — `$(() => { ... })` — to ensure DOM is available.

**Direct instantiation:** for manual control over specific instances, components can also be instantiated directly: `new window.prestashop.component.ChoiceTree(selector)`. Use `enableAutoCheckChildren()` on ChoiceTree for auto-selecting child nodes.

**Module registration:** modules can register custom components by listening to the `PSComponentsInitiated` event via `EventEmitter`.

**Available components:**

| Component | Purpose |
|---|---|
| `TranslatableField` / `TranslatableInput` | Multilingual input with language tabs |
| `TinyMCEEditor` | Rich text editor |
| `TaggableField` | Tag input with autocomplete |
| `ChoiceTable` / `MultipleChoiceTable` | Checkbox/radio table selection |
| `ChoiceTree` | Hierarchical tree selector (categories, groups) |
| `EntitySearchInput` | Autocomplete entity search |
| `GeneratableInput` | Auto-generate from another field (e.g. slug from name) |
| `ColorPicker` | Color selection input |
| `DateRange` | Date range picker |
| `DeltaQuantityInput` | Quantity change input (stock) |
| `DisablingSwitch` | Toggle that disables related fields |
| `FormFieldToggler` | Show/hide fields based on another field's value |
| `TextWithLengthCounter` / `TextWithRecommendedLengthCounter` | Character count display |
| `CountryStateSelectionToggler` / `CountryDniRequiredToggler` | Country-dependent field logic |
| `MultistoreConfigField` | Multistore-scoped config field |
| `ModifyAllShopsCheckbox` | "Apply to all shops" checkbox |
| `PreviewOpener` | Preview popup |
| `Grid` | Grid component (see grid extensions) |
| `Router` | FOS JS router integration |
| `EventEmitter` | Cross-component event communication |
| `IframeClient` | Iframe communication |

## Vue integration (exception, not default)

Vue is used for **individual page sections** with complex UX requirements (e.g. combination listing in Product page). It is NOT the default — most pages use `initComponents` with standard components.

Vue apps are mounted to specific DOM selectors and communicate with the rest of the page via `EventEmitter` (passed as a prop). They are initialized from TypeScript entry points, not as SPAs.

- **Composition API required:** use `<script setup>` in all Vue SFCs
- **i18n:** set up `vue-i18n` with locale and messages passed via `data-*` attributes on the mount element
- **Form sync:** sync Vue component state back to the Symfony form via hidden `<input>` fields for submission — the Vue component is a UI layer, not the form itself
- **Multiple Grid instances:** when a page has multiple grids, create separate `new Grid()` instances per grid with their own extension chains

## Canonical examples

- `admin-dev/themes/new-theme/js/pages/tax/index.ts` — simple listing entry point
- `admin-dev/themes/new-theme/js/pages/manufacturer/index.ts` — listing with multiple grids
- `admin-dev/themes/new-theme/js/pages/category/index.ts` — listing with position management
- `admin-dev/themes/new-theme/js/pages/attribute/form/index.ts` — simple form entry point
- `admin-dev/themes/new-theme/js/pages/product/edit/index.ts` — complex form with Vue components

