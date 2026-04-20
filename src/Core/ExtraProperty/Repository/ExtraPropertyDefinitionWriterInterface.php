<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Repository;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyOptions;

/**
 * Write-side repository contract for extra property definitions.
 *
 * Used exclusively by ExtraPropertyRegistry (Core) to persist and remove
 * definitions without depending on the concrete Adapter implementation.
 *
 * Separating this from ExtraPropertyDefinitionRepositoryInterface (read-only)
 * keeps the read path cacheable independently of the write path.
 */
interface ExtraPropertyDefinitionWriterInterface
{
    /**
     * Normalizes a legacy entity name suffix (product_lang → entity=product, scope=lang).
     * Also validates the entity name and scope against known values.
     *
     * Returns [entityName, scope] on success, [null, null] when validation fails.
     *
     * @param string $entityName Raw entity name (may have _lang / _shop suffix)
     * @param string $fieldScope Raw scope string ('common', 'lang', 'shop')
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function normalizeEntityNameAndFieldScope(string $entityName, string $fieldScope): array;

    /**
     * Saves (insert or update) one definition row from typed parameters.
     *
     * When $existingId is provided, performs an UPDATE; otherwise INSERT.
     * Returns the definition id on success, false on failure.
     *
     * @param ExtraPropertyOptions $options Typed options as declared by the module
     * @param string $entityName Normalized entity name (e.g. 'product')
     * @param string $propertyName Property name as declared (e.g. 'is_dangerous')
     * @param string|null $normalizedModuleName Module name (null for core fields)
     * @param string $normalizedScope Normalized scope value ('common', 'lang', 'shop')
     * @param int|null $existingId When provided, performs an UPDATE; otherwise INSERT
     *
     * @return int|false
     */
    public function save(
        ExtraPropertyOptions $options,
        string $entityName,
        string $propertyName,
        ?string $normalizedModuleName,
        string $normalizedScope,
        ?int $existingId = null
    ): int|false;

    /**
     * Deletes one definition row by primary key.
     *
     * @param int $id
     *
     * @return bool
     */
    public function delete(int $id): bool;
}
