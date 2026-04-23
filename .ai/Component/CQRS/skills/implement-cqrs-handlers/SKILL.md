---
name: implement-cqrs-handlers
description: >
  Implement all command and query handlers in the Adapter layer. Each handler
  delegates to the repository for persistence — no direct SQL or ObjectModel
  access. Handlers use #[AsCommandHandler] / #[AsQueryHandler] attributes for
  auto-registration. Trigger: "implement handlers for {Domain}".
needs: [create-cqrs-commands, create-cqrs-queries, create-doctrine-repository]
produces: "src/Adapter/{Domain}/CommandHandler/ and QueryHandler/ — all handler implementations"
---

# implement-cqrs-handlers

Handlers live in `src/Adapter/{Domain}/CommandHandler/` and `QueryHandler/`. They implement
the interfaces defined in `src/Core/Domain/{Domain}/`. Each handler uses the
`#[AsCommandHandler]` or `#[AsQueryHandler]` attribute for automatic registration via
Symfony Messenger — no manual service tagging needed.

```php
#[AsCommandHandler]
class Add{Domain}Handler implements Add{Domain}HandlerInterface
{
    // ...
}
```

## 1. Add handler

`Add{Domain}Handler.php` implementing `Add{Domain}HandlerInterface`:

- Inject `{Domain}Repository`
- `handle(Add{Domain}Command $command): {Domain}Id`:
  - Construct the ObjectModel entity from command data
  - For multilingual fields, map `$command->getLocalizedNames()` to the lang array
  - Call `$this->repository->create(...)`
  - Return the new `{Domain}Id`
- Catch persistence exceptions, rethrow as domain exceptions

**Reference:** `src/Adapter/Tax/CommandHandler/AddTaxHandler.php` (simple), `src/Adapter/Manufacturer/CommandHandler/AddManufacturerHandler.php` (with image)

## 2. Edit handler (partial-update)

`Edit{Domain}Handler.php` implementing `Edit{Domain}HandlerInterface`:

- Load entity: `$entity = $this->repository->get{Domain}($command->getId())`
- For each field: `if ($command->getName() !== null) { $entity->name = $command->getName(); }`
- Apply only non-null fields — never overwrite with null
- Call `$this->repository->update($entity)`
- Sub-resource commands are dispatched independently, not composed here

**Reference:** `src/Adapter/Tax/CommandHandler/EditTaxHandler.php` (simple), `src/Adapter/Carrier/CommandHandler/EditCarrierHandler.php` (many fields)

## 3. Delete handler

`Delete{Domain}Handler.php` implementing `Delete{Domain}HandlerInterface`:

- Load entity to verify existence (throws `{Domain}NotFoundException`)
- Check business constraints: if entity is referenced by active orders/other entities, throw `CannotDelete{Domain}Exception`
- Call `$this->repository->delete($command->getId())`

## 4. Toggle status handler

`Toggle{Domain}StatusHandler.php`:

- Load entity by ID
- Flip: `$entity->active = !$entity->active`
- Call repository update
- Return void — the controller reads back state from the grid

## 5. Sub-resource handler (if sub-resources exist)

`Set{Domain}{SubResource}sHandler.php`:

Two strategies depending on complexity:

### Strategy A: Atomic replace (simpler, quicker)

Used when sub-resources have no identity of their own or when maintaining existing rows isn't important:

- Begin a DB transaction
- Delete all existing sub-resource rows for the entity
- Insert new rows from `$command->getItems()`
- Commit; on failure, rollback and throw domain exception
- Empty array = delete all (valid use case)

**Reference:** `src/Adapter/Carrier/CommandHandler/SetCarrierRangesHandler.php`

### Strategy B: Incremental update (cleaner, preserves existing sub-resources)

Preferred when sub-resources have their own identity or when preserving existing rows matters (e.g., to keep audit trails, auto-increment IDs, or related data):

- Load existing sub-resources for the entity
- Compare with the new collection: identify additions, updates, and removals
- Apply only the necessary changes (insert new, update changed, delete removed)
- Wrap in a transaction

Choose Strategy B when possible for cleaner data management. Use Strategy A when sub-resources are simple value-like collections without individual identity.

## 6. Get-for-editing handler

`Get{Domain}ForEditingHandler.php` in `QueryHandler/`:

- Load entity via repository
- Map ALL fields to the return DTO: scalars, multilingual (array keyed by langId), related IDs
- Return typed `Editable{Domain}` DTO (with scalar types only — no VOs in the DTO)
- No write side effects

**Reference:** `src/Adapter/Tax/QueryHandler/GetTaxForEditingHandler.php` (simple)

## 7. List query handler (if explicit query exists)

Most domains use the grid `QueryBuilder` pattern — no handler needed. Only create if the domain
uses an explicit `Get{Domain}sForListing` query class.

## Rules

- Use `#[AsCommandHandler]` / `#[AsQueryHandler]` attributes — no manual service tags
- Handlers contain business orchestration only — no direct SQL, no raw `Db::getInstance()`
- All DB access goes through the repository
- Never call another handler from within a handler — compose at controller level
- Add handler returns `{Domain}Id`; all others return `void`
- Check null before every field update in Edit handler (partial-update pattern)
- Always verify existence before deletion — never delete blindly
