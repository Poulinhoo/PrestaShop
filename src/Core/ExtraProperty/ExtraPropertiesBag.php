<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty;

use ArrayAccess;
use ArrayIterator;
use Closure;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Lazy-loading grouped value bag for extra properties on an ObjectModel instance.
 *
 * Keys are module names (e.g. 'demoextrafield', '_core'). Values are ModuleFieldsBag
 * instances keyed by field name. Data is loaded from the DB on first access.
 *
 * Usage:
 *   $product->extra_properties['demoextrafield']['date_last_seen']         // read
 *   $product->extra_properties['demoextrafield']['date_last_seen'] = $val  // write + mark dirty
 *   foreach ($product->extra_properties as $module => $fields) { ... }    // iterate (triggers load)
 *   json_encode($product->extra_properties)                                // serialize
 */
final class ExtraPropertiesBag implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    private bool $loaded = false;
    /** @var array<string, ModuleFieldsBag> */
    private array $values = [];

    public function __construct(private readonly Closure $loader)
    {
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = ($this->loader)();
        foreach ($grouped as $moduleKey => $fields) {
            $this->values[(string) $moduleKey] = new ModuleFieldsBag((string) $moduleKey, (array) $fields);
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->ensureLoaded();

        return isset($this->values[$offset]);
    }

    /**
     * Returns the ModuleFieldsBag for the given module key, auto-creating an empty one if unknown.
     * This allows chained writes: $bag['module']['field'] = value.
     */
    public function offsetGet(mixed $offset): ModuleFieldsBag
    {
        $this->ensureLoaded();
        if (!isset($this->values[$offset])) {
            $this->values[$offset] = new ModuleFieldsBag((string) $offset);
        }

        return $this->values[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Assigning a full ModuleFieldsBag replaces the module bag.
        // Normal usage is chained: $bag['module']['field'] = value (goes through offsetGet + ModuleFieldsBag::offsetSet).
        $this->ensureLoaded();
        if ($value instanceof ModuleFieldsBag) {
            $this->values[(string) $offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->ensureLoaded();
        unset($this->values[$offset]);
    }

    public function getIterator(): Traversable
    {
        $this->ensureLoaded();

        return new ArrayIterator($this->values);
    }

    public function jsonSerialize(): mixed
    {
        $this->ensureLoaded();

        return $this->values;
    }

    public function hasModifications(): bool
    {
        foreach ($this->values as $moduleBag) {
            if ($moduleBag->hasModifications()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> Flat [storageColumnName => value] map of all dirty fields across modules. */
    public function getModifiedValues(): array
    {
        $flat = [];
        foreach ($this->values as $moduleBag) {
            $flat += $moduleBag->getModifiedValues();
        }

        return $flat;
    }

    /** @return array<string, ModuleFieldsBag> All loaded module bags. Triggers lazy load. */
    public function toArray(): array
    {
        $this->ensureLoaded();

        return $this->values;
    }
}
