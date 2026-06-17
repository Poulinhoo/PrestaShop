<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Api;

/**
 * Read-side bridge between the Admin API and the extra property system.
 *
 * Implemented in the PrestaShopBundle Admin API layer; consumed from the Core event subscriber through
 * this interface so Core never depends on the bundle implementation.
 */
interface ExtraPropertyApiResponseInjectorInterface
{
    /**
     * Returns the given normalized item enriched with an `extraProperties` sub-object when the operation
     * (URI template + HTTP method) has matching definitions and the item carries a resolvable identifier.
     * Returns the item unchanged otherwise.
     *
     * LANG-scope values are exposed as locale-indexed objects (e.g. {"en-US": ...}); SHOP-scope values are
     * flattened to a scalar in a single-shop context. Field names keep their declared (snake_case) naming.
     *
     * @param array<string, mixed> $item Normalized resource item (single entity, or one element of a list)
     * @param string $resourceClass Fully-qualified ApiResource class (used to resolve the identifier property)
     *
     * @return array<string, mixed>
     */
    public function injectIntoItem(array $item, string $resourceClass, string $uriTemplate, string $method): array;

    /**
     * Returns the given list item enriched IN PLACE at its root with the extra-property values the grid query
     * already fetched for this operation (reused without any database read). Returns the item unchanged when
     * nothing was captured for it.
     *
     * Unlike injectIntoItem(), list items expose each value inline under its grid field name (e.g.
     * "extra_common_<module>_<field>") as the single context-locale value — matching the back-office grid display
     * rather than the nested, all-locales `extraProperties` object used on single-item endpoints.
     *
     * @param array<string, mixed> $item One element of a paginated list
     * @param string $resourceClass Fully-qualified ApiResource class (used to resolve the identifier property)
     *
     * @return array<string, mixed>
     */
    public function injectInlineListItem(array $item, string $resourceClass, string $uriTemplate, string $method): array;
}
