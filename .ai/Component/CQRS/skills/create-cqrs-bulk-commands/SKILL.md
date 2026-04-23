---
name: create-cqrs-bulk-commands
description: >
  Create bulk action commands and their handlers. All bulk handlers extend
  AbstractBulkCommandHandler, catch errors per item, and report failures via
  BulkCommandExceptionInterface. Trigger: "create bulk commands for {Domain}".
needs: [create-cqrs-commands, create-doctrine-repository]
produces: "BulkDelete{Domain}sCommand + BulkToggle{Domain}StatusCommand + their handlers"
conditional: "only if the grid has bulk actions (most entities do)"
---

# create-cqrs-bulk-commands

All bulk handlers follow the same pattern: extend `AbstractBulkCommandHandler`, iterate over
IDs, catch errors per item, continue processing, and report all failures together via
`BulkCommandExceptionInterface`. This applies to bulk delete, bulk status toggle, and any
other bulk action.

## 1. Bulk delete command

Create `src/Core/Domain/{Domain}/Command/BulkDelete{Domain}sCommand.php`:

- Constructor takes `array $ids` (scalar `int[]`)
- Single getter: `getIds(): array`

## 2. Bulk status command

Create `src/Core/Domain/{Domain}/Command/BulkToggle{Domain}StatusCommand.php`:

- Constructor takes `array $ids` (`int[]`) and `bool $expectedStatus`
- `$expectedStatus = true` means enable, `false` means disable
- Getters: `getIds(): array` and `getExpectedStatus(): bool`

## 3. Bulk handlers

All bulk handlers follow the same structure. Create in `src/Adapter/{Domain}/CommandHandler/`:

### Common pattern (applies to ALL bulk handlers):

- Extend `AbstractBulkCommandHandler`
- Implement `BulkCommandExceptionInterface` for error reporting
- Iterate `$command->getIds()`
- For each ID, perform the action in a try/catch
- **Continue after individual failure** — never abort mid-batch
- Collect all exceptions
- If any failures, throw a bulk exception listing all failed IDs with their individual errors

### Bulk delete handler

`BulkDelete{Domain}sHandler.php`:

- For each ID, call the repository delete
- Collect failures, continue with remaining IDs

### Bulk status handler

`BulkToggle{Domain}StatusHandler.php`:

- For each ID, load entity, set `active = $command->getExpectedStatus()`, update
- Use the target status from the command — do not flip the current value

## 4. Handler interfaces

Create in `src/Core/Domain/{Domain}/CommandHandler/`:
- `BulkDelete{Domain}sHandlerInterface`
- `BulkToggle{Domain}StatusHandlerInterface`

Both return `void`. The bulk exception is thrown, not returned.

## Rules

- **All bulk handlers extend `AbstractBulkCommandHandler`** — this is not optional
- Always continue after individual failure — never abort mid-batch
- Report ALL failed IDs in the bulk exception, not just the first one
- `BulkCommandExceptionInterface` encapsulates individual errors per item
- Be consistent with the single-entity commands on ID typing
- Skip this skill entirely if the entity has no bulk grid actions
