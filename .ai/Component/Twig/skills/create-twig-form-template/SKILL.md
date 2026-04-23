---
name: create-twig-form-template
description: >
  Create the Twig template for the add/edit form page. Renders the Symfony form,
  save buttons, and optional form theme overrides for custom field rendering. Read
  Component/Twig/CONTEXT.md for template conventions. Trigger: "create form template
  for {Domain}".
needs: [create-form-type, create-controller-form-actions, create-admin-routing]
produces: "form.html.twig — add/edit form page template"
---

# create-twig-form-template

Read `@.ai/Component/Twig/CONTEXT.md` for template conventions (layout, flash messages, routes, form themes).

## 1. Form template

Create `src/PrestaShopBundle/Resources/views/Admin/{Section}/{Domain}/form.html.twig`:

```twig
{% extends '@PrestaShop/Admin/layout.html.twig' %}

{% block content %}
  {{ form_start(form) }}
    {{ form_widget(form) }}
    <button type="submit" class="btn btn-primary">
      {{ 'Save'|trans({}, 'Admin.Actions') }}
    </button>
  {{ form_end(form) }}
{% endblock %}
```

- **Always use a single `form_widget(form)`** to render the entire form — never split into multiple `form_widget` calls. Modules hook into the form builder to add fields, and a single call ensures they are rendered automatically
- The same template typically serves both create and edit — the controller passes different form data
- For file uploads, add `enctype="multipart/form-data"` to the form start or use `form_start(form, {attr: {enctype: 'multipart/form-data'}})` 

**Reference:** `src/PrestaShopBundle/Resources/views/Admin/Improve/International/Tax/` (simple), `src/PrestaShopBundle/Resources/views/Admin/Sell/Catalog/Manufacturer/` (with image)

## 2. Form theme overrides (only if needed)

When specific fields need custom rendering beyond Symfony defaults:

- Scope the override: `{% form_theme form 'Admin/{Section}/{Domain}/_form_widgets.html.twig' %}`
- Override the field block: `{% block _{field_id}_widget %}...{% endblock %}`
- Common case: image preview next to upload field with a "Remove" checkbox

Form themes are scoped to this form only — no global side effects.

## 3. JS asset inclusion

If the form needs JavaScript (for `initComponents` or Vue):

```twig
{% block javascripts %}
  {{ parent() }}
  <script src="{{ asset('themes/new-theme/public/{domain}.bundle.js') }}"></script>
{% endblock %}
```

The asset path must match the webpack entry name.

## Rules

- Always extend `@PrestaShop/Admin/layout.html.twig`
- Use `path()` for all route references
- `form_widget(form)` handles tabs automatically when `NavigationTabType` is used — no manual tab HTML needed
- **Never split form rendering** into multiple `form_widget` calls — module-added fields would be invisible
- Form theme overrides should be minimal — Symfony's default rendering handles most cases
- CSRF token is included automatically by `form_end(form)`
