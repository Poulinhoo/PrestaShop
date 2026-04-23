---
name: create-cqrs-commands
description: >
  Set up the full domain layer for a CQRS entity: identity value object, additional
  value objects, exception hierarchy, commands (Add, Edit, Delete, Toggle, sub-resource,
  or any custom domain action), and handler interfaces. Covers everything in
  src/Core/Domain/{Domain}/. Trigger: "create CQRS commands for {Domain}",
  "set up domain layer for {Domain}".
needs: []
produces: "Complete src/Core/Domain/{Domain}/ layer: ValueObject/, Command/, CommandHandler/, Exception/"
---

# create-cqrs-commands

This skill creates the entire domain layer for an entity. It covers the Core side only
(interfaces and data objects) — handler implementations live in `implement-cqrs-handlers`.

## Critical rule: scalar inputs, VO getters

**Command and Query constructor parameters must always be scalar types** (`int`, `string`, `bool`, `array`)
to facilitate serialization. However, getters can (and should) return Value Objects built from those scalars.

Accepted exceptions to the scalar-only input rule:
- `ShopConstraint` — for multistore scoping
- `DecimalNumber` — ALWAYS use instead of native `float` (which carries imprecision)
- `DateTimeInterface` / `DateTimeImmutable` — for date/time values

```php
// Correct: scalar input, VO getter
public function __construct(private int $taxId) {}
public function getTaxId(): TaxId { return new TaxId($this->taxId); }

// Wrong: VO as input
public function __construct(private TaxId $taxId) {}
```

## 1. Identity Value Object

Create `src/Core/Domain/{Domain}/ValueObject/{Domain}Id.php`:

- Constructor takes `int $value`, validates `$value > 0`, throws `{Domain}Exception` if not
- Single getter: `getValue(): int`
- No Symfony/Doctrine dependencies — pure PHP

**Reference:** `src/Core/Domain/Tax/ValueObject/TaxId.php` (simple), `src/Core/Domain/Carrier/ValueObject/CarrierId.php` (complex domain)

## 2. Additional Value Objects (if needed)

For fields with non-trivial validation or domain meaning (not just `string`/`int`):

- Enum-like fields: class with constants + `fromInt()`/`fromString()` factory
- URL fields: validate format in constructor
- Constrained numbers: range checks in constructor

Not every field needs a VO — only those with invariants beyond primitive type checking.

For has-many relations, create typed collections implementing `\Countable` and `\IteratorAggregate`.

## 3. Exception Hierarchy

Create in `src/Core/Domain/{Domain}/Exception/`:

- **Base:** `{Domain}Exception extends \RuntimeException` with integer constants per error code
- **Not found:** `{Domain}NotFoundException extends {Domain}Exception`
- **Per-action:** `CannotAdd{Domain}Exception`, `CannotUpdate{Domain}Exception`, `CannotDelete{Domain}Exception`
- **Constraints:** `{Domain}ConstraintException` with `const INVALID_NAME = 1`, `const INVALID_ID = 2`, etc.

Error code constants must be unique integers within the class. Never throw generic `\Exception` from domain code.

**Reference:** `src/Core/Domain/Tax/Exception/` (simple), `src/Core/Domain/Carrier/Exception/` (many constraint codes)

## 4. Commands

All commands live in `src/Core/Domain/{Domain}/Command/`. They are data objects with `declare(strict_types=1)`, carrying no business logic and making no DB calls.

### 4.1 Add command

`Add{Domain}Command.php`:

- Constructor takes all **required** fields as scalar typed parameters
- Optional fields use nullable types with defaults
- Multilingual fields: `array $localizedValues` keyed by language ID
- Validate primitives in constructor (non-empty strings, positive ints)
- Getters can return VOs built from the scalar inputs
- Sub-resource fields are NOT included — they get their own command (see 4.5)

**Reference:** `src/Core/Domain/Tax/Command/AddTaxCommand.php` (simple), `src/Core/Domain/Carrier/Command/AddCarrierCommand.php` (many fields)

### 4.2 Edit command (partial-update pattern)

`Edit{Domain}Command.php`:

- Constructor takes **only** the entity ID (as `int`) — never field values
- **Favor nullable fields** for all editable properties to support partial update and PATCH API
- Every editable field: `private ?Type $field = null`
- Fluent setter: `public function setName(string $name): self { $this->name = $name; return $this; }`
- Nullable getter: `public function getName(): ?string { return $this->name; }`
- In the handler, `if ($command->getName() !== null)` before updating — this is the partial-update pattern
- Null means "not set in this request", not "set to null in DB"
- Do NOT include fields that are immutable after creation

**Reference:** `src/Core/Domain/Tax/Command/EditTaxCommand.php` (simple), `src/Core/Domain/Manufacturer/Command/EditManufacturerCommand.php` (with image)

### 4.3 Delete command

`Delete{Domain}Command.php`:

- Single constructor parameter: `int $id` (scalar)
- Getter returns VO: `getId(): {Domain}Id`
- No other properties — existence/constraint checks happen in the handler

### 4.4 Toggle status command

`Toggle{Domain}StatusCommand.php` (if entity has an active/enabled boolean):

- Constructor takes `int $id` and optionally `bool $expectedStatus`
- Handler reads current status and flips it, or sets to `$expectedStatus` if provided
- Used by the grid toggle switch (AJAX)

### 4.5 Sub-resource commands (if entity has has-many relations)

`Set{Domain}{SubResource}sCommand.php` — one command per sub-resource type:

- Constructor takes the entity `int $id` and the full replacement collection (as array of scalars)
- Getters return VOs as appropriate
- One command per sub-resource type

**Reference:** `src/Core/Domain/Carrier/Command/SetCarrierRangesCommand.php`

### 4.6 Custom domain actions

Any domain action that doesn't fit CRUD follows the same pattern: a command class with scalar constructor parameters and getters. The naming convention is `{Verb}{Domain}Command.php`.

## 5. Handler Interfaces

Create in `src/Core/Domain/{Domain}/CommandHandler/`:

- One interface per command: `Add{Domain}HandlerInterface`, `Edit{Domain}HandlerInterface`, etc.
- Single method: `public function handle({Action}{Domain}Command $command): void`
- Exception: `Add{Domain}HandlerInterface::handle()` returns `{Domain}Id` (the new entity's ID)
- All other handlers return `void`

The concrete implementations live in `src/Adapter/{Domain}/CommandHandler/` (see `implement-cqrs-handlers` skill).

## 6. Domain Interfaces (if needed)

For abstractions that cross the Core/Adapter boundary beyond the repository:

- File uploader: `{Domain}LogoFileUploaderInterface` in Core, implemented in Adapter
- Use only Core domain types in signatures — no Doctrine/ObjectModel types

## Rules

- **Scalar inputs, VO getters** — constructor params are always scalar (with exceptions noted above), getters return VOs
- Commands carry data only — no business logic, no DB calls
- All validation failures throw domain exceptions from the constructor
- Sub-resource fields get their own command, never merged into Edit
- One handler interface per command — never combine multiple commands
- Never call another handler from within a handler — compose at controller level
- `declare(strict_types=1)` on every class
