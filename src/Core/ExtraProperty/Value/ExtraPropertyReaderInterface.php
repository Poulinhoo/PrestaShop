<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Value;

use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;

/**
 * Reads extra property values for a given entity instance.
 *
 * Used by ObjectModel (via ServiceLocator) and front-office LazyArray / presenter contexts.
 * Values are grouped by module technical name then by field name.
 */
interface ExtraPropertyReaderInterface
{
    /**
     * Returns extra property values for one entity instance, grouped by module name.
     *
     * Format:
     * [
     *     'module_technical_name' => [
     *         'property_name' => 'value_or_array',
     *     ],
     * ]
     *
     * Lang-scope fields:
     *   - $langId given  → one scalar value per field
     *   - $langId null   → array keyed by id_lang (BO forms, Admin API)
     *
     * Shop-scope fields:
     *   - ShopConstraint::shop($id) → one scalar value for that shop
     *   - ShopConstraint::allShops() → array keyed by id_shop (Admin API)
     *
     * @param string $entityName Entity table name (e.g. "product")
     * @param string $primaryKeyName PK column name (e.g. "id_product")
     * @param int $entityId
     * @param int|null $langId Null fetches all languages
     * @param ShopConstraint $shopConstraint Specific shop or allShops() to fetch all
     * @param bool $isLangMultishop Whether lang scope is shop-aware
     *
     * @return array<string, array<string, mixed>>
     */
    public function getExtraProperties(
        string $entityName,
        string $primaryKeyName,
        int $entityId,
        ?int $langId,
        ShopConstraint $shopConstraint,
        bool $isLangMultishop = false
    ): array;
}
