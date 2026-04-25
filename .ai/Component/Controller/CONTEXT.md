# Controller Component

## Purpose

Symfony admin controllers for the back-office. Controllers are the thinnest layer — they delegate entirely to the command bus (writes) and query bus (reads). Zero business logic lives here.

## Layers

| Layer | Path |
|-------|------|
| Admin controllers | `src/PrestaShopBundle/Controller/Admin/{Section}/{Subsection}/` |
| Routing | `src/PrestaShopBundle/Resources/config/routing/admin/{section}/` |
| Feature flags | `install-dev/data/xml/feature_flag.xml` |

## Non-obvious patterns

- **Base class:** all admin controllers extend `PrestaShopAdminController` (not `FrameworkBundleAdminController` which no longer exists in v9)
- **DI preference:** prefer `getSubscribedServices()` over constructor injection — services are fetched and built just-in-time, avoiding unnecessary instantiation. DI in action method parameters is also acceptable for action-specific services
- **Security attributes:** every action must have `#[AdminSecurity]` and `#[DemoRestricted]` PHP attributes
- **Error mapping:** controllers implement a `getErrorMessages()` method that maps domain exception classes to translatable flash messages
- **Grid search/filter:** `CommonController::searchGridAction` is the generic controller that saves and applies grid filters, then redirects back to the domain's index. It is used as a base but still defined as a domain-specific route pointing to it
- **Grid filter reset:** uses the generic route `admin_common_reset_search_by_filter_id` — no domain-specific route or code needed. This is referenced from the `GridDefinition`
- **Index action argument:** use the dedicated `{Domain}Filters` class as the action argument — it is automatically resolved via the argument resolver (no manual `SearchCriteria` construction)
- **Form handling:** controllers never instantiate commands directly. They use `FormBuilder` to build forms and `FormHandler` to process submissions. `FormHandler` internally calls `FormDataHandler::create()` / `FormDataHandler::update()` which dispatch commands
- **Atomic commit:** controller, routing YAML, and feature flag XML must always be committed together. A route with `_legacy_feature_flag` referencing an unregistered flag causes a 500
- **Feature flag lifecycle:** flags start at `stability="beta"` (opt-in) and are promoted to `stability="stable"` (GA) in a dedicated PR. The `_legacy_feature_flag` value is **case-sensitive** and must match exactly between the routing YAML and `feature_flag.xml`
- **Toggle action:** toggle status actions return `JsonResponse` for AJAX grid toggle switches
- **Bulk actions:** are generic — implement only the ones the grid defines. Not all entities have enable/disable/delete; some have more. Always handle empty selection with an info flash. Catch `BulkCommandExceptionInterface` and flash individual failure messages with failed IDs

## Canonical examples

- `src/PrestaShopBundle/Controller/Admin/Improve/International/TaxController.php` — simple CRUD (few fields, one grid)
- `src/PrestaShopBundle/Controller/Admin/Sell/Catalog/ManufacturerController.php` — medium complexity (sub-resource addresses, two grids, export)
- `src/PrestaShopBundle/Controller/Admin/Sell/Catalog/CategoryController.php` — position management, tree hierarchy

