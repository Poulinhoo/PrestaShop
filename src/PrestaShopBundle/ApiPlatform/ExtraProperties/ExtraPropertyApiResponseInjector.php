<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\ApiPlatform\ExtraProperties;

use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\ExtraProperty\Api\ExtraPropertyApiResponseInjectorInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShopBundle\ApiPlatform\LocalizedValueUpdater;
use PrestaShopBundle\ApiPlatform\Metadata\LocalizedValue;
use Throwable;

/**
 * Read-side bridge: enriches a normalized Admin API item with its extra properties.
 *
 * The set of fields exposed is decided purely by each definition's associatedApis matching the current
 * operation (URI template + HTTP method) — there is no class→entity inference, so a field never leaks onto
 * a resource it does not explicitly target. LANG-scope values are converted from id_lang keys to locale
 * strings via LocalizedValueUpdater; SHOP-scope values are flattened in a single-shop context.
 */
class ExtraPropertyApiResponseInjector implements ExtraPropertyApiResponseInjectorInterface
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ExtraPropertyReaderInterface $reader,
        protected readonly ShopContext $shopContext,
        protected readonly LocalizedValueUpdater $localizedValueUpdater,
        protected readonly ApiResourceIdResolver $idResolver,
        protected readonly ExtraPropertyApiListRecordCollector $listRecordCollector,
    ) {
    }

    public function injectIntoItem(array $item, string $resourceClass, string $uriTemplate, string $method): array
    {
        $definitions = $this->repository->getAllDefinitions()->filterByApi($uriTemplate, $method);
        if ($definitions->isEmpty()) {
            return $item;
        }

        // All definitions matching one operation normally share the same entity; group defensively.
        foreach ($this->groupByEntity($definitions) as $entityName => $entityDefinitions) {
            $entityId = $this->idResolver->resolveId($item, $entityName, $resourceClass);
            if ($entityId <= 0) {
                continue;
            }

            $extraProperties = $this->loadExtraProperties($entityName, $entityId, $entityDefinitions);
            if (!empty($extraProperties)) {
                $item['extraProperties'] = array_merge(
                    is_array($item['extraProperties'] ?? null) ? $item['extraProperties'] : [],
                    $extraProperties
                );
            }
        }

        return $item;
    }

    public function injectInlineListItem(array $item, string $resourceClass, string $uriTemplate, string $method): array
    {
        $definitions = $this->repository->getAllDefinitions()->filterByApi($uriTemplate, $method);
        if ($definitions->isEmpty()) {
            return $item;
        }

        foreach ($this->groupByEntity($definitions) as $entityName => $entityDefinitions) {
            $entityId = $this->idResolver->resolveId($item, $entityName, $resourceClass);
            if ($entityId <= 0) {
                continue;
            }

            $capturedRecord = $this->listRecordCollector->find($entityName, $entityId);
            if (null === $capturedRecord) {
                continue;
            }

            // Reuse exactly what the grid fetched: each value inline at the item root, under its grid field name
            // (single context locale), with no further transformation.
            foreach ($entityDefinitions as $definition) {
                $alias = $definition->getFormFieldName();
                if (array_key_exists($alias, $capturedRecord)) {
                    $item[$alias] = $capturedRecord[$alias];
                }
            }
        }

        return $item;
    }

    /**
     * @return array<string, ExtraPropertyDefinitionCollection>
     */
    protected function groupByEntity(ExtraPropertyDefinitionCollection $definitions): array
    {
        $byEntity = [];
        foreach ($definitions as $definition) {
            $byEntity[$definition->getEntityName()][] = $definition;
        }

        return array_map(
            static fn (array $defs): ExtraPropertyDefinitionCollection => new ExtraPropertyDefinitionCollection($defs),
            $byEntity
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function loadExtraProperties(string $entityName, int $entityId, ExtraPropertyDefinitionCollection $definitions): array
    {
        $shopConstraint = $this->shopContext->getShopConstraint();
        $values = $this->reader->getExtraProperties(
            $entityName,
            $definitions->first()->getPrimaryKeyName(),
            $entityId,
            null,
            $shopConstraint,
            true,
            $definitions,
        );
        if (empty($values)) {
            return [];
        }

        $langScopedFields = $this->scopedFieldsByModule($definitions, ExtraPropertyScope::LANG);
        $shopScopedFields = $this->scopedFieldsByModule($definitions, ExtraPropertyScope::SHOP);

        $result = $this->convertLangKeysToLocale($values, $langScopedFields);
        if ($shopConstraint->isSingleShopContext()) {
            $result = $this->flattenShopScopedValues($result, $shopScopedFields);
        }

        // Extra properties are dynamic and unknown, so — unlike standard core fields — null values are kept:
        // null is a valid value for a nullable field and the consumer should always see the declared property.
        return $result;
    }

    /**
     * @return array<string, array<string, true>>
     */
    protected function scopedFieldsByModule(ExtraPropertyDefinitionCollection $definitions, ExtraPropertyScope $scope): array
    {
        $fields = [];
        foreach ($definitions as $definition) {
            if ($definition->getScope() !== $scope) {
                continue;
            }
            $fields[$definition->getNormalizedModuleKey()][$definition->getPropertyName()] = true;
        }

        return $fields;
    }

    /**
     * Converts id_lang-keyed values of LANG-scoped fields to locale-string keys via LocalizedValueUpdater.
     * Common-scope scalars and shop-scope arrays are passed through unchanged.
     *
     * @param array<string, array<string, mixed>> $valuesByModule
     * @param array<string, array<string, true>> $langScopedFields
     *
     * @return array<string, array<string, mixed>>
     */
    protected function convertLangKeysToLocale(array $valuesByModule, array $langScopedFields): array
    {
        $result = [];
        foreach ($valuesByModule as $moduleKey => $fields) {
            foreach ($fields as $fieldName => $value) {
                if (!is_array($value) || empty($langScopedFields[$moduleKey][$fieldName])) {
                    $result[$moduleKey][$fieldName] = $value;
                    continue;
                }

                try {
                    $result[$moduleKey][$fieldName] = $this->localizedValueUpdater->normalizeLocalizedValue(
                        $value,
                        $fieldName,
                        [LocalizedValue::IS_LOCALIZED_VALUE => true],
                    );
                } catch (Throwable) {
                    // Unknown id_lang in stored data — keep the raw value rather than failing the response.
                    $result[$moduleKey][$fieldName] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * In a single-shop context a shop-scope field value is stored as [id_shop => value]; the API exposes the
     * scalar value directly.
     *
     * @param array<string, array<string, mixed>> $valuesByModule
     * @param array<string, array<string, true>> $shopScopedFields
     *
     * @return array<string, array<string, mixed>>
     */
    protected function flattenShopScopedValues(array $valuesByModule, array $shopScopedFields): array
    {
        if (empty($shopScopedFields)) {
            return $valuesByModule;
        }

        foreach ($valuesByModule as $moduleKey => &$fields) {
            foreach ($fields as $fieldName => &$value) {
                if (!empty($shopScopedFields[$moduleKey][$fieldName]) && is_array($value)) {
                    $value = [] !== $value ? reset($value) : null;
                }
            }
            unset($value);
        }
        unset($fields);

        return $valuesByModule;
    }
}
