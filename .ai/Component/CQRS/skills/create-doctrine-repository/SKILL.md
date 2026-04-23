---
name: create-doctrine-repository
description: >
  Create the repository in the Adapter layer. This is the ONLY class that touches
  the database — all handlers delegate to it. Covers multistore patterns with
  ShopConstraint when the entity requires it. Trigger: "create repository for {Domain}".
needs: [create-cqrs-commands]
produces: "{Domain}Repository.php — the single persistence entry point for the domain"
---

# create-doctrine-repository

## 1. Choose the base class

Create `src/Adapter/{Domain}/{Domain}Repository.php`. Choose the base class based on the entity's multistore tier:

| Multistore tier | Base class | When to use |
|---|---|---|
| Tier 1 — no shop relation | `AbstractObjectModelRepository` | Entity has no shop association at all |
| Tier 2 — simple shop association | `AbstractObjectModelRepository` or `AbstractMultiShopObjectModelRepository` | Content is the same across shops, just linked to a list of shops. AbstractMultiShop provides useful helpers even if per-shop content isn't needed |
| Tier 3 — per-shop content | `AbstractMultiShopObjectModelRepository` | Fields change by shop (e.g. Product, Category) |

## 2. Core methods

Implement these methods (adapt naming to your domain):

- `get{Domain}({Domain}Id $id): {LegacyObjectModel}` — loads the ObjectModel, throws `{Domain}NotFoundException` if not found
- `create(...): {Domain}Id` — calls ObjectModel `save()` or `add()`, wraps in try/catch, returns new ID
- `update(...)` — calls ObjectModel `save()` or `update()`
- `delete({Domain}Id $id)` — deletes the entity, respects multistore if applicable

**Reference:** `src/Adapter/Tax/CommandHandler/` uses a simple repository pattern, `src/Adapter/Carrier/CommandHandler/` uses `AbstractMultiShopObjectModelRepository`

## 3. Multistore pattern (when using AbstractMultiShopObjectModelRepository)

- Receive `ShopConstraint` as a method parameter — never read it from Context
- Call `$shopIds = $this->getShopIdsByConstraint($shopConstraint)` at the start of every write
- Iterate `$shopIds` and apply the write for each shop context
- Modes: `ShopConstraint::allShops()`, `ShopConstraint::shop($shopId)`, `ShopConstraint::shopGroup($groupId)`
- Single-shop installs still go through this path (returns array with one ID)

## 4. Sub-resource methods (if applicable)

For entities with has-many sub-resources, two strategies:

### Atomic replace (simpler)
- `set{SubResource}s({Domain}Id, array $items)` — delete all existing rows, insert new set
- Wrap in transaction — partial replace corrupts data

### Incremental update (cleaner)
- Compare existing rows with new collection, apply only changes
- Preferred when sub-resources have their own identity

## Rules

- **Repositories must be stateless** — no instance state between calls
- **Never depend on Context services** — receive all contextual values (shop, language, etc.) as method parameters. The caller consults the Context and passes values to the repository
- Never use `Db::getInstance()` — use Doctrine DBAL or ObjectModel methods
- Throw typed domain exceptions (`{Domain}NotFoundException`, `CannotAdd{Domain}Exception`), not generic exceptions
- Never hard-code `Context::getContext()->shop->id` in repositories
