---
name: create-behat-context
description: >
  Create the PHP feature context class that implements step definitions for a domain,
  and register it in behat.yml. Covers the PHP implementation side of Behat tests.
  Read Component/Behat/CONTEXT.md for conventions. Trigger: "create behat context
  for {Domain}".
needs: [create-cqrs-commands, create-cqrs-queries]
produces: "{Domain}FeatureContext.php + behat.yml registration"
---

# create-behat-context

Read `@.ai/Component/Behat/CONTEXT.md` for conventions (base class, entity references, stateless steps, bus access).

## 1. Context class

Create `tests/Integration/Behaviour/Features/Context/Domain/{Domain}/{Domain}FeatureContext.php`:

- Extend `AbstractDomainFeatureContext`
- Implement step definitions as methods with `@Given`, `@When`, `@Then` annotations
- Use `$this->getCommandBus()->handle(...)` for write operations
- Use `$this->getQueryBus()->handle(...)` for read/verification
- Use `$this->referenceToId($reference)` to resolve string references to integer IDs
- After creating an entity, store reference: `$this->getSharedStorage()->set($reference, $newId)`

**Reference:** `tests/Integration/Behaviour/Features/Context/Domain/Tax/TaxFeatureContext.php` (simple)

## 2. Step implementation patterns

### Action steps (@When)

```php
/**
 * @When I add a {domain} :reference with following properties:
 */
public function iAddDomainWithProperties(string $reference, TableNode $table): void
{
    $data = $this->localizeByRows($table);
    $command = new Add{Domain}Command(
        $data['name'],
        (bool) $data['active'],
    );
    $id = $this->getCommandBus()->handle($command);
    $this->getSharedStorage()->set($reference, $id->getValue());
}
```

### Assertion steps (@Then) — must be stateless

```php
/**
 * @Then {domain} :reference should have the following properties:
 */
public function domainShouldHaveProperties(string $reference, TableNode $table): void
{
    $id = $this->referenceToId($reference);
    $result = $this->getQueryBus()->handle(new Get{Domain}ForEditing($id));
    // Assert each field independently
}
```

The assertion loads the entity fresh from the database — it does NOT rely on state from a previous step.

### Error steps

```php
/**
 * @Then I should get an error :errorType
 */
```

Use exception handling to catch and assert specific domain exception types.

## 3. Registration in behat.yml

Open `tests/Integration/Behaviour/behat.yml` and add the context to the `domain` suite:

```yaml
domain:
    contexts:
        - PrestaShop\Tests\Integration\Behaviour\Features\Context\Domain\{Domain}\{Domain}FeatureContext
```

Verify: `php vendor/bin/behat --dry-run` to confirm all steps are matched.

## Rules

Conventions (stateless steps, referenceToId, deterministic steps, typed exceptions, error scenarios) are in [Behat/CONTEXT.md](../../CONTEXT.md). Skill-specific reminders:

- Use `referenceToId` / `referencesToIds` — not `getSharedStorage()->get()` directly
- Check existing contexts for reusable steps before creating new ones
