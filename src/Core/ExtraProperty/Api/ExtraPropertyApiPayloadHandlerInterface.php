<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Api;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Write-side bridge between the Admin API and the extra property system.
 *
 * Implemented in the PrestaShopBundle Admin API layer; consumed from the Core event subscriber and
 * the API validator through this interface so Core never depends on the bundle implementation.
 *
 * The payload is grouped exactly like the reader output / writer input:
 * ['moduleName' => ['propertyName' => scalar | localeArray | shopArray]].
 */
interface ExtraPropertyApiPayloadHandlerInterface
{
    /**
     * Validates an extraProperties payload against the definitions targeting the given operation
     * (URI template + HTTP method). Returns an empty list when nothing matches or the payload is empty.
     *
     * Violations use the property path "extraProperties.<module>.<field>[.<locale|shopId>]" so they can be
     * merged with the resource constraint violations into a single 422 response.
     *
     * @param array<string, array<string, mixed>> $extraPropertiesByModule
     */
    public function validate(array $extraPropertiesByModule, string $uriTemplate, string $method): ConstraintViolationListInterface;

    /**
     * Persists an extraProperties payload for the entity described by the given normalized response item.
     * The entity id is resolved from that item; locale → id_lang conversion (LANG scope) and per-shop routing
     * (SHOP scope) happen internally. Only definitions targeting the operation are written.
     *
     * @param array<string, array<string, mixed>> $extraPropertiesByModule
     * @param array<string, mixed> $normalizedItem The normalized written entity (carries the resolvable identifier)
     */
    public function persist(array $extraPropertiesByModule, array $normalizedItem, string $resourceClass, string $uriTemplate, string $method): void;
}
