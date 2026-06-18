<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Api;

use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface;

/**
 * Write-side bridge: persists an already-prepared extraProperties payload for one entity row.
 *
 * The caller (PrestaShopBundle\EventListener\API\ExtraPropertyApiSubscriber) resolves the entity id, matches the
 * definitions targeting the operation and converts LANG values from locale strings to id_lang. This handler
 * therefore stays pure Core: it only routes the given values by scope (COMMON/LANG to the main write, SHOP per
 * shop id) and delegates to the writer.
 */
class ExtraPropertyApiPayloadHandler
{
    public function __construct(
        protected readonly ExtraPropertyWriterInterface $writer,
        protected readonly ShopContext $shopContext,
    ) {
    }

    public function persist(ExtraPropertyDefinitionCollection $definitions, int $entityId, array $valuesByModule): void
    {
        if ($entityId <= 0 || $definitions->isEmpty() || empty($valuesByModule)) {
            return;
        }

        $entityName = $definitions->first()->getEntityName();
        $primaryKeyName = $definitions->first()->getPrimaryKeyName();

        [$mainValuesByModule, $shopValuesByShopId] = $this->buildWritableValues($definitions, $valuesByModule);

        if (!empty($mainValuesByModule)) {
            $this->writer->writeAll($entityName, $primaryKeyName, $entityId, $mainValuesByModule, $this->shopContext->getShopConstraint());
        }

        foreach ($shopValuesByShopId as $shopId => $valuesByModuleForShop) {
            $this->writer->writeAll($entityName, $primaryKeyName, $entityId, $valuesByModuleForShop, ShopConstraint::shop((int) $shopId));
        }
    }

    /**
     * Routes the (already locale-converted) values into the main grouped write (COMMON scalars + LANG values keyed
     * by id_lang) and per-shop grouped writes (SHOP values keyed by shop id). No locale conversion happens here.
     *
     * @param array<string, array<string, mixed>> $valuesByModule
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, array<string, array<string, mixed>>>}
     */
    protected function buildWritableValues(ExtraPropertyDefinitionCollection $definitions, array $valuesByModule): array
    {
        $mainValuesByModule = [];
        $shopValuesByShopId = [];

        foreach ($definitions as $definition) {
            $moduleKey = $definition->getNormalizedModuleKey();
            $fieldName = $definition->getPropertyName();
            if (!isset($valuesByModule[$moduleKey]) || !array_key_exists($fieldName, $valuesByModule[$moduleKey])) {
                continue;
            }

            $value = $valuesByModule[$moduleKey][$fieldName];

            if (ExtraPropertyScope::LANG === $definition->getScope()) {
                if (!is_array($value)) {
                    continue;
                }
                // The subscriber already converted locale keys to id_lang; keep only the integer keys defensively.
                $byIdLang = array_filter($value, static fn ($key): bool => is_int($key), ARRAY_FILTER_USE_KEY);
                if ([] !== $byIdLang) {
                    $mainValuesByModule[$moduleKey][$fieldName] = $byIdLang;
                }
            } elseif (ExtraPropertyScope::SHOP === $definition->getScope()) {
                if (!is_array($value)) {
                    continue;
                }
                foreach ($value as $shopId => $shopValue) {
                    $shopValuesByShopId[(int) $shopId][$moduleKey][$fieldName] = $shopValue;
                }
            } else {
                $mainValuesByModule[$moduleKey][$fieldName] = $value;
            }
        }

        return [$mainValuesByModule, $shopValuesByShopId];
    }
}
