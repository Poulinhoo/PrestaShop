<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Api;

use ObjectModelCore;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShop\PrestaShop\Core\Util\Inflector;

/**
 * Read-side bridge: provides the extra-property values of one entity row for the Admin API.
 *
 * It stays pure Core: the caller (PrestaShopBundle\EventListener\API\ExtraPropertyApiSubscriber) resolves the entity
 * id and the matching definitions, and is responsible for the id_lang ↔ locale conversion of LANG values. This
 * service only reads:
 *  - loadExtraProperties(): the full set for a single-item endpoint (LANG values kept keyed by id_lang),
 *  - injectInlineListItem(): reuses the values a grid query already fetched (single context locale), merged at the
 *    item root under their grid field name.
 */
class ExtraPropertyApiResponseInjector
{
    public function __construct(
        protected readonly ExtraPropertyReaderInterface $reader,
        protected readonly ShopContext $shopContext,
        protected readonly ExtraPropertyApiListRecordCollector $listRecordCollector,
    ) {
    }

    public function loadExtraProperties(ExtraPropertyDefinitionCollection $definitions, int $entityId): array
    {
        if ($entityId <= 0 || $definitions->isEmpty()) {
            return [];
        }

        $entityName = $definitions->first()->getEntityName();

        return $this->reader->getExtraProperties(
            $entityName,
            $definitions->first()->getPrimaryKeyName(),
            $entityId,
            null,
            $this->shopContext->getShopConstraint(),
            $this->isLangMultishop($entityName),
            $definitions,
        );
    }

    public function injectInlineListItem(array $item, ExtraPropertyDefinitionCollection $definitions, int $entityId): array
    {
        if ($entityId <= 0 || $definitions->isEmpty()) {
            return $item;
        }

        $capturedRecord = $this->listRecordCollector->find($definitions->first()->getEntityName(), $entityId);
        if (null === $capturedRecord) {
            return $item;
        }

        // Reuse exactly what the grid fetched: each value inline at the item root, under its grid field name
        // (single context locale), with no further transformation.
        foreach ($definitions as $definition) {
            $alias = $definition->getFormFieldName();
            if (array_key_exists($alias, $capturedRecord)) {
                $item[$alias] = $capturedRecord[$alias];
            }
        }

        return $item;
    }

    /**
     * Whether the entity stores LANG values per shop (its ObjectModel definition is multilang_shop). The class name
     * is the StudlyCase of the (tableized) entity name; isClassLangMultishop safely returns false for any unknown
     * class, so a wrong guess never breaks the read.
     */
    protected function isLangMultishop(string $entityName): bool
    {
        return ObjectModelCore::isClassLangMultishop(Inflector::getInflector()->classify($entityName));
    }
}
