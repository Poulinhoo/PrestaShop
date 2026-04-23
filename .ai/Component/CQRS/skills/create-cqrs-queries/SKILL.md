---
name: create-cqrs-queries
description: >
  Create the read-side CQRS layer: queries, result DTOs, and query handler interfaces.
  Covers Get{Domain}ForEditing (single entity for edit form) and optionally
  Get{Domain}sForListing (grid data source). Trigger: "create queries for {Domain}",
  "set up read side for {Domain}".
needs: [create-cqrs-commands]
produces: "src/Core/Domain/{Domain}/Query/, QueryResult/, QueryHandler/ — read-side domain layer"
---

# create-cqrs-queries

Same scalar input rule as commands: query constructor parameters are always scalar types.
See `create-cqrs-commands` for the full rule and exceptions (ShopConstraint, DecimalNumber, DateTime).

## 1. Get-for-editing query

Create `src/Core/Domain/{Domain}/Query/Get{Domain}ForEditing.php`:

- Constructor takes `int $id` (scalar)
- Getter returns VO: `getId(): {Domain}Id`
- Queries are read-only data objects — no side effects

## 2. Result DTO

Create `src/Core/Domain/{Domain}/QueryResult/Editable{Domain}.php`:

- Constructor parameters: all fields the edit form needs to pre-fill
- **All types must be scalar** — `int`, `string`, `bool`, `array` (for multilingual, keyed by lang ID), `?int` for nullable FKs
- No Value Objects in QueryResult — the data is already validated, no need for VO wrappers
- Public getter for every field
- Immutable: no setters, all values set at construction
- No ObjectModel instances inside — only scalars and arrays

**Reference:** `src/Core/Domain/Tax/QueryResult/EditableTax.php` (simple), `src/Core/Domain/Manufacturer/QueryResult/EditableManufacturer.php` (with associations)

## 3. List query (assess first)

Most PS grids use `SearchCriteria` + grid `QueryBuilder` directly — no explicit CQRS query class needed.

Check the domain convention before creating a list query:
- If the domain uses `SearchCriteria` + `QueryBuilder` in the grid: skip this step
- If the domain uses an explicit query: create `Get{Domain}sForListing.php` with `SearchCriteria` parameter

**Reference:** Most domains (Tax, Manufacturer, Category) use the grid QueryBuilder pattern without an explicit list query.

## 4. Query handler interfaces

Create in `src/Core/Domain/{Domain}/QueryHandler/`:

- `Get{Domain}ForEditingHandlerInterface` with `handle(Get{Domain}ForEditing $query): Editable{Domain}`
- If list query exists, create corresponding interface
- Query handlers always return data (typed DTO or array) — never void

## Rules

- Queries never trigger side effects — read only
- **QueryResult DTOs use only scalar types** — no VOs, the data is already consistent
- Return types should be typed DTOs, not ObjectModel instances
- Map ALL editable fields in the DTO — missing fields cause empty form fields on edit
- Most list queries are handled by the grid QueryBuilder — don't create unnecessary query classes
