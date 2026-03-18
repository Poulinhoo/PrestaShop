<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\Product\Provider;

use Doctrine\DBAL\Connection;
use PrestaShop\Decimal\DecimalNumber;

final class CatalogProductProvider implements ProductProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $dbPrefix,
    ) {
    }

    public function getBasePrice(int $productId): DecimalNumber
    {
        $sql = 'SELECT price FROM ' . $this->dbPrefix . 'product WHERE id_product = :productId';
        $result = $this->connection->fetchOne($sql, ['productId' => $productId]);

        if ($result === false) {
            return new DecimalNumber('0');
        }

        return new DecimalNumber((string) $result);
    }

    public function getCombinationPriceImpact(int $productId, int $combinationId): DecimalNumber
    {
        $sql = 'SELECT price FROM ' . $this->dbPrefix . 'product_attribute'
            . ' WHERE id_product = :productId AND id_product_attribute = :combinationId';
        $result = $this->connection->fetchOne($sql, [
            'productId' => $productId,
            'combinationId' => $combinationId,
        ]);

        if ($result === false) {
            return new DecimalNumber('0');
        }

        return new DecimalNumber((string) $result);
    }
}
