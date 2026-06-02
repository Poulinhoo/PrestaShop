<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\ApiPlatform\ExtraProperties;

use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use PrestaShop\PrestaShop\Core\Context\ShopContext;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidationInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface;
use PrestaShopBundle\Entity\Repository\LangRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Throwable;

/**
 * Handles extra properties injection and extraction in the Admin API layer.
 *
 * Responsibilities:
 *  - Resolve the entity storage table from an ApiResource class name (convention-based)
 *  - Extract `extraProperties` from incoming JSON payloads before denormalization
 *  - Store pending extra properties in the Request for persistence after entity save
 *  - Inject extra properties (filtered by display_api=1) into serialized JSON responses
 *  - Delegate read/write operations to ExtraPropertyReaderInterface / ExtraPropertyWriterInterface
 *
 * Three field scopes are supported:
 *  - entity : stored in `{prefix}{entity}_extra`,      keyed directly by field name
 *  - lang   : stored in `{prefix}{entity}_extra_lang`, keyed by locale string (e.g. "fr-FR")
 *  - shop   : stored in `{prefix}{entity}_extra_shop`, keyed by shop ID (integer)
 */
class ExtraPropertiesApiService
{
    /**
     * Request attribute key used to store pending extra properties during a write operation.
     * Structure: ['entity_table' => ['module_name' => ['property_name' => value|locale-array|shop-array]]]
     */
    public const PENDING_REQUEST_ATTRIBUTE = '_ps_extra_properties_pending';

    /**
     * Class name prefixes to strip when resolving the entity name from an ApiResource class.
     *
     * @var string[]
     */
    protected const CLASS_PREFIXES_TO_STRIP = [
        'AddOrEdit',
        'BulkDelete',
        'BulkUpdate',
        'BulkCreate',
        'Bulk',
        'Create',
        'Delete',
        'Update',
        'List',
    ];

    /**
     * Request-lifetime cache for the list of API-exposed lang-scoped fields, grouped by module.
     *
     * Keyed by entity table name (e.g. 'product') to avoid recomputing it for each normalized item
     * in collection/bulk operations.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    private array $langScopedFieldsByEntityTableCache = [];

    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly RequestStack $requestStack,
        protected readonly ExtraPropertyReaderInterface $reader,
        protected readonly ExtraPropertyWriterInterface $writer,
        protected readonly ExtraPropertyValidationInterface $validatorAdapter,
        protected readonly ShopContext $shopContext,
        protected readonly LangRepository $langRepository,
        protected readonly ?PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory = null,
        protected readonly ?PropertyMetadataFactoryInterface $propertyMetadataFactory = null,
    ) {
    }

    /**
     * Resolves the entity storage table name from an ApiResource FQCN.
     *
     * Uses naming convention: short class name in PascalCase → snake_case entity table.
     * Example: "PrestaShop\...\Product\Product" → "product"
     *          "PrestaShop\...\Customer\AddOrEditCustomer" → "customer"
     *
     * Returns null when no extra field definitions are registered for the resolved entity,
     * so callers can skip processing without any extra cost.
     *
     * @param string $resourceClass Fully-qualified ApiResource class name
     *
     * @return string|null Entity table name (e.g. 'product'), or null if not resolvable
     */
    public function resolveEntityTableFromClass(string $resourceClass): ?string
    {
        // Extract the short class name from the FQCN
        $lastBackslash = strrpos($resourceClass, '\\');
        $shortName = false === $lastBackslash ? $resourceClass : substr($resourceClass, $lastBackslash + 1);

        // Strip known operation prefixes to get the bare entity name
        foreach (static::CLASS_PREFIXES_TO_STRIP as $prefix) {
            if (str_starts_with($shortName, $prefix)) {
                $shortName = substr($shortName, strlen($prefix));
                break;
            }
        }

        if ('' === $shortName) {
            return null;
        }

        // PascalCase → snake_case: "CustomerAddress" → "customer_address"
        $entityTable = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        // Validate: the repository must have at least one definition for this entity
        if ($this->repository->getDefinitionCollection($entityTable)->isEmpty()) {
            return null;
        }

        return $entityTable;
    }

    /**
     * Extracts the `extraProperties` key from a payload array and removes it from $data.
     *
     * Called before denormalization to prevent ApiPlatform from complaining about
     * unknown fields. Returns null when the key is absent or not an array.
     *
     * @param array<string, mixed> $data Payload array, modified in-place to remove 'extraProperties'
     *
     * @return array<string, array<string, mixed>>|null Grouped extra properties or null
     */
    public function extractExtraPropertiesFromPayload(array &$data): ?array
    {
        if (!isset($data['extraProperties']) || !is_array($data['extraProperties'])) {
            return null;
        }

        $extraProperties = $data['extraProperties'];
        unset($data['extraProperties']);

        return $extraProperties;
    }

    /**
     * Stores pending extra properties in the current Request attributes.
     *
     * The data is keyed by entity table name so that the normalize() step can later match
     * the persisted entity to the correct pending payload.
     *
     * @param string $entityTable Entity storage table (e.g. 'product')
     * @param array<string, array<string, mixed>> $extraPropertiesByModule Grouped by module name
     */
    public function storePendingExtraProperties(string $entityTable, array $extraPropertiesByModule): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $pending = $request->attributes->get(self::PENDING_REQUEST_ATTRIBUTE, []);
        $pending[$entityTable] = $extraPropertiesByModule;
        $request->attributes->set(self::PENDING_REQUEST_ATTRIBUTE, $pending);
    }

    /**
     * Validates an extraProperties payload using registry validator methods.
     *
     * Returned violations are compatible with ApiPlatform error handling (HTTP 422).
     *
     * @param string $entityTable Entity storage table (e.g. 'product')
     * @param array<string, array<string, mixed>> $extraPropertiesByModule Payload grouped by module
     *
     * @return ConstraintViolationListInterface
     */
    public function validateExtraPropertiesPayload(string $entityTable, array $extraPropertiesByModule): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();

        $allDefinitions = $this->repository->getDefinitionCollection($entityTable);
        if ($allDefinitions->isEmpty() || empty($extraPropertiesByModule)) {
            return $violations;
        }

        foreach ($allDefinitions as $def) {
            if (!$def->isDisplayApi() || null === $def->getValidator()) {
                continue;
            }

            $moduleName = $def->getDisplayModuleKey();
            $fieldName = $def->getPropertyName();
            $scope = $def->getScope()->value;

            if ('' === $fieldName || !isset($extraPropertiesByModule[$moduleName]) || !array_key_exists($fieldName, $extraPropertiesByModule[$moduleName])) {
                continue;
            }

            $value = $extraPropertiesByModule[$moduleName][$fieldName];
            $basePath = sprintf('extraProperties.%s.%s', $moduleName, $fieldName);

            if ('lang' === $scope && is_array($value)) {
                foreach ($value as $locale => $localeValue) {
                    $this->validateOneValue($violations, $def, $localeValue, $basePath . '.' . (string) $locale);
                }
                continue;
            }

            if ('shop' === $scope && is_array($value)) {
                foreach ($value as $shopId => $shopValue) {
                    $this->validateOneValue($violations, $def, $shopValue, $basePath . '.' . (string) $shopId);
                }
                continue;
            }

            $this->validateOneValue($violations, $def, $value, $basePath);
        }

        return $violations;
    }

    /**
     * @param mixed $value
     */
    protected function validateOneValue(
        ConstraintViolationListInterface $violations,
        ExtraPropertyDefinition $def,
        $value,
        string $propertyPath,
    ): void {
        $result = $this->validatorAdapter->validateValue($def, $value);
        if (true !== $result) {
            $violations->add(new ConstraintViolation(
                'This value is not valid.',
                'This value is not valid.',
                [],
                null,
                $propertyPath,
                $value
            ));
        }
    }

    /**
     * Injects extra properties (filtered by display_api=1) into the normalized response array.
     *
     * When a pending write payload is found in the Request (POST/PUT/PATCH flow), it is
     * persisted first so the response reflects the just-written values. The pending entry
     * is then cleared to avoid double persistence.
     *
     * @param array<string, mixed> $normalizedData Data as produced by the upstream normalizer
     * @param string $resourceClass Fully-qualified ApiResource class name
     *
     * @return array<string, mixed> Modified normalized data with 'extraProperties' injected
     */
    public function injectExtraPropertiesIntoResponse(array $normalizedData, string $resourceClass): array
    {
        $entityTable = $this->resolveEntityTableFromClass($resourceClass);
        if (null === $entityTable) {
            return $normalizedData;
        }

        $entityId = $this->resolveEntityIdFromData($normalizedData, $entityTable, $resourceClass);
        if ($entityId <= 0) {
            return $normalizedData;
        }

        // Persist pending extra properties from the current write operation before reading
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request) {
            $pending = $request->attributes->get(self::PENDING_REQUEST_ATTRIBUTE, []);
            if (!empty($pending[$entityTable])) {
                $this->persistExtraProperties($entityTable, $entityId, $pending[$entityTable]);
                unset($pending[$entityTable]);
                $request->attributes->set(self::PENDING_REQUEST_ATTRIBUTE, $pending);
            }
        }

        $extraProperties = $this->loadExtraProperties($entityTable, $entityId);
        if (!empty($extraProperties)) {
            $normalizedData['extraProperties'] = $extraProperties;
        }

        return $normalizedData;
    }

    /**
     * Loads all extra properties for the three supported scopes via the reader.
     *
     * Lang-scope values are id_lang-keyed (int).
     * This method converts them to locale-string-keyed (e.g. "fr-FR") for the API response.
     *
     * - entity scope : ['module' => ['field' => scalar_value]]
     * - lang scope   : ['module' => ['field' => ['fr-FR' => value, 'en-GB' => value]]]
     * - shop scope   : ['module' => ['field' => scalar_value]]   (single-shop context, R8)
     * - shop scope   : ['module' => ['field' => [1 => value, 2 => value]]]  (all-shops context)
     *
     * @param string $entityTable Entity storage table (e.g. 'product')
     * @param int $entityId Entity primary key value
     *
     * @return array<string, array<string, mixed>> Grouped by module name
     */
    protected function loadExtraProperties(string $entityTable, int $entityId): array
    {
        $shopConstraint = $this->shopContext->getShopConstraint();
        $values = $this->reader->getExtraProperties($entityTable, 'id_' . $entityTable, $entityId, null, $shopConstraint, true);

        if (empty($values)) {
            return [];
        }

        // Build the list of API-exposed lang-scope fields so that locale conversion is not ambiguous.
        // Without this, shop-scope fields keyed by id_shop (e.g. 1) can be mistaken for id_lang keys
        // and wrongly converted to locales (e.g. "fr-FR") when id_lang=1 exists.
        $langScopedFieldsByModule = $this->buildLangScopedFieldsByModule($entityTable);
        $shopScopedFieldsByModule = $this->buildShopScopedFieldsByModule($entityTable);

        // Convert id_lang → locale string for API presentation
        $langLocaleMap = $this->fetchLangIdLocaleMap();

        $result = $this->convertLangKeysToLocale($values, $langLocaleMap, $langScopedFieldsByModule);

        // R8: flatten [id_shop => value] → scalar when the request is scoped to a single shop.
        if ($shopConstraint->isSingleShopContext()) {
            $result = $this->flattenShopScopedValues($result, $shopScopedFieldsByModule);
        }

        // Keep only fields flagged display_api = 1.
        $whitelist = [];
        foreach ($this->repository->getDefinitionCollection($entityTable)->filterByApi() as $def) {
            $whitelist[$def->getDisplayModuleKey()][$def->getPropertyName()] = true;
        }
        foreach ($result as $moduleKey => $fields) {
            foreach (array_keys($fields) as $fieldName) {
                if (!isset($whitelist[$moduleKey][$fieldName])) {
                    unset($result[$moduleKey][$fieldName]);
                }
            }
            if (empty($result[$moduleKey])) {
                unset($result[$moduleKey]);
            }
        }

        return $result;
    }

    /**
     * Persists extra properties for all three scopes via the writer.
     *
     * @param string $entityTable Entity storage table (e.g. 'product')
     * @param int $entityId Entity primary key value
     * @param array<string, array<string, mixed>> $extraPropertiesByModule Grouped by module name
     */
    protected function persistExtraProperties(string $entityTable, int $entityId, array $extraPropertiesByModule): void
    {
        $allDefinitions = $this->repository->getDefinitionCollection($entityTable);
        if ($allDefinitions->isEmpty()) {
            return;
        }

        $entityValues = $this->buildEntityScopeValues($allDefinitions, $extraPropertiesByModule);
        $langValuesByIdLang = $this->buildLangScopeValues($allDefinitions, $extraPropertiesByModule);
        $shopValuesByShopId = $this->buildShopScopeValues($allDefinitions, $extraPropertiesByModule);

        if (empty($entityValues) && empty($langValuesByIdLang) && empty($shopValuesByShopId)) {
            return;
        }

        $shopConstraint = $this->shopContext->getShopConstraint();

        if (!empty($entityValues) || !empty($langValuesByIdLang)) {
            $this->writer->writeAll(
                $entityTable,
                'id_' . $entityTable,
                $entityId,
                $entityValues,
                $langValuesByIdLang,
                [],
                $shopConstraint
            );
        }

        foreach ($shopValuesByShopId as $shopId => $shopValues) {
            if (empty($shopValues)) {
                continue;
            }
            $this->writer->writeAll(
                $entityTable,
                'id_' . $entityTable,
                $entityId,
                [],
                [],
                $shopValues,
                ShopConstraint::shop((int) $shopId)
            );
        }
    }

    /**
     * Builds the common-scope column values from the module-keyed payload.
     *
     * Only definitions flagged display_api = 1 and scope 'common' are included.
     *
     * @param ExtraPropertyDefinitionCollection $allDefinitions
     * @param array<string, array<string, mixed>> $extraPropertiesByModule
     *
     * @return array<string, mixed> [storage_column => value]
     */
    protected function buildEntityScopeValues(ExtraPropertyDefinitionCollection $allDefinitions, array $extraPropertiesByModule): array
    {
        $columnValues = [];
        foreach ($allDefinitions as $def) {
            if ('common' !== $def->getScope()->value || !$def->isDisplayApi()) {
                continue;
            }

            $moduleName = $def->getDisplayModuleKey();
            $fieldName = $def->getPropertyName();

            if ('' === $fieldName) {
                continue;
            }

            if (!isset($extraPropertiesByModule[$moduleName][$fieldName])) {
                continue;
            }

            $storageColumn = $def->getStorageColumnName();
            $columnValues[$storageColumn] = $extraPropertiesByModule[$moduleName][$fieldName];
        }

        return $columnValues;
    }

    /**
     * Builds the lang-scope column values from the module-keyed payload.
     *
     * Locale strings (e.g. "fr-FR") in the payload are resolved to id_lang (int).
     * Only definitions flagged display_api = 1 and scope 'lang' are included.
     *
     * @param ExtraPropertyDefinitionCollection $allDefinitions
     * @param array<string, array<string, mixed>> $extraPropertiesByModule
     *
     * @return array<int, array<string, mixed>> [id_lang => [storage_column => value]]
     */
    protected function buildLangScopeValues(ExtraPropertyDefinitionCollection $allDefinitions, array $extraPropertiesByModule): array
    {
        $columnToPropertyMap = $this->buildColumnPropertyMap($allDefinitions, 'lang');
        if (empty($columnToPropertyMap)) {
            return [];
        }

        // Collect all (locale → column → value) entries from the payload
        $localeColumnValues = [];
        foreach ($columnToPropertyMap as $columnName => $propertyPath) {
            $moduleName = $propertyPath['module_name'];
            $fieldName = $propertyPath['property_name'];

            $fieldValue = $extraPropertiesByModule[$moduleName][$fieldName] ?? null;
            if (!is_array($fieldValue)) {
                continue;
            }

            foreach ($fieldValue as $locale => $value) {
                $localeColumnValues[(string) $locale][$columnName] = $value;
            }
        }

        if (empty($localeColumnValues)) {
            return [];
        }

        $localeToIdLangMap = array_flip($this->fetchLangIdLocaleMap());

        $langValuesByIdLang = [];
        foreach ($localeColumnValues as $locale => $columnValues) {
            $idLang = $localeToIdLangMap[$locale] ?? null;
            if (null === $idLang) {
                continue;
            }
            $langValuesByIdLang[(int) $idLang] = $columnValues;
        }

        return $langValuesByIdLang;
    }

    /**
     * Builds the shop-scope column values from the module-keyed payload.
     *
     * Shop IDs (integer keys) in the payload are preserved as-is.
     * Only definitions flagged display_api = 1 and scope 'shop' are included.
     *
     * @param ExtraPropertyDefinitionCollection $allDefinitions
     * @param array<string, array<string, mixed>> $extraPropertiesByModule
     *
     * @return array<int, array<string, mixed>> [id_shop => [storage_column => value]]
     */
    protected function buildShopScopeValues(ExtraPropertyDefinitionCollection $allDefinitions, array $extraPropertiesByModule): array
    {
        $columnToPropertyMap = $this->buildColumnPropertyMap($allDefinitions, 'shop');
        if (empty($columnToPropertyMap)) {
            return [];
        }

        $shopColumnValues = [];
        foreach ($columnToPropertyMap as $columnName => $propertyPath) {
            $moduleName = $propertyPath['module_name'];
            $fieldName = $propertyPath['property_name'];

            $fieldValue = $extraPropertiesByModule[$moduleName][$fieldName] ?? null;
            if (!is_array($fieldValue)) {
                continue;
            }

            foreach ($fieldValue as $shopId => $value) {
                $shopColumnValues[(int) $shopId][$columnName] = $value;
            }
        }

        return $shopColumnValues;
    }

    /**
     * Resolves the entity primary key value from the normalized response array.
     *
     * When ApiPlatform property metadata factories are available, inspects #[ApiProperty(identifier: true)]
     * on the resource class to find the identifier property name. Falls back to the 'id' field and then
     * to the camelCase pattern derived from the entity table name (e.g. 'productId', 'customerId').
     *
     * @param array<string, mixed> $normalizedData Normalized response data
     * @param string $entityTable Entity storage table (e.g. 'product', 'customer_address')
     * @param string|null $resourceClass Fully-qualified ApiResource class name (used for metadata lookup)
     *
     * @return int Entity ID, or 0 when not found
     */
    protected function resolveEntityIdFromData(array $normalizedData, string $entityTable, ?string $resourceClass = null): int
    {
        // R5: use #[ApiProperty(identifier: true)] metadata when available.
        if (null !== $resourceClass && null !== $this->propertyNameCollectionFactory && null !== $this->propertyMetadataFactory) {
            try {
                foreach ($this->propertyNameCollectionFactory->create($resourceClass) as $propertyName) {
                    $metadata = $this->propertyMetadataFactory->create($resourceClass, $propertyName);
                    if ($metadata->isIdentifier() && isset($normalizedData[$propertyName]) && is_int($normalizedData[$propertyName])) {
                        return $normalizedData[$propertyName];
                    }
                }
            } catch (Throwable) {
                // Fall through to heuristic resolution below.
            }
        }

        // Try the generic 'id' field first (most common in PS API resources)
        if (isset($normalizedData['id']) && is_int($normalizedData['id'])) {
            return $normalizedData['id'];
        }

        // Fall back to camelCase pattern: "product" → "productId", "customer_address" → "customerAddressId"
        $camelCase = lcfirst(str_replace('_', '', ucwords($entityTable, '_')));
        $idPropertyName = $camelCase . 'Id';

        if (isset($normalizedData[$idPropertyName]) && is_int($normalizedData[$idPropertyName])) {
            return $normalizedData[$idPropertyName];
        }

        return 0;
    }

    /**
     * Builds a storage-column → property-path map for a given scope, filtered to display_api=1.
     *
     * @param ExtraPropertyDefinitionCollection $allDefinitions All repository definitions for the entity
     * @param string $scope 'common', 'lang' or 'shop'
     *
     * @return array<string, array{module_name: string, property_name: string}>
     */
    private function buildColumnPropertyMap(ExtraPropertyDefinitionCollection $allDefinitions, string $scope): array
    {
        $map = [];
        foreach ($allDefinitions as $def) {
            if ($def->getScope()->value !== $scope || !$def->isDisplayApi()) {
                continue;
            }

            $fieldName = $def->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $storageColumn = $def->getStorageColumnName();
            $map[$storageColumn] = [
                'module_name' => $def->getDisplayModuleKey(),
                'property_name' => $fieldName,
            ];
        }

        return $map;
    }

    /**
     * Returns a module-keyed set of field names that are shop-scoped and API-exposed.
     *
     * Used by R8 (flatten shop-scoped values in single-shop context).
     *
     * @param string $entityTable Entity storage table (e.g. 'product')
     *
     * @return array<string, array<string, true>> ['moduleKey' => ['fieldName' => true]]
     */
    private function buildShopScopedFieldsByModule(string $entityTable): array
    {
        $shopFields = [];
        foreach ($this->repository->getDefinitionCollection($entityTable) as $def) {
            if ('shop' !== $def->getScope()->value || !$def->isDisplayApi()) {
                continue;
            }

            $fieldName = $def->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $moduleKey = $def->getDisplayModuleKey();
            $shopFields[$moduleKey][$fieldName] = true;
        }

        return $shopFields;
    }

    /**
     * Returns a module-keyed set of field names that are lang-scoped and API-exposed.
     *
     * @param string $entityTable Entity storage table (e.g. 'product')
     *
     * @return array<string, array<string, true>> ['moduleKey' => ['fieldName' => true]]
     */
    private function buildLangScopedFieldsByModule(string $entityTable): array
    {
        if (isset($this->langScopedFieldsByEntityTableCache[$entityTable])) {
            return $this->langScopedFieldsByEntityTableCache[$entityTable];
        }

        $langFields = [];
        foreach ($this->repository->getDefinitionCollection($entityTable) as $def) {
            if ('lang' !== $def->getScope()->value || !$def->isDisplayApi()) {
                continue;
            }

            $fieldName = $def->getPropertyName();
            if ('' === $fieldName) {
                continue;
            }

            $moduleKey = $def->getDisplayModuleKey();
            $langFields[$moduleKey][$fieldName] = true;
        }

        $this->langScopedFieldsByEntityTableCache[$entityTable] = $langFields;

        return $langFields;
    }

    /**
     * Flattens shop-scoped array values to scalars when in single-shop context.
     *
     * In single-shop context, a shop-scope field value is stored as [id_shop => value].
     * The API response should expose the scalar value directly, not the shop-keyed array.
     *
     * @param array<string, array<string, mixed>> $valuesByModule
     * @param array<string, array<string, true>> $shopScopedFieldsByModule ['moduleKey' => ['fieldName' => true]]
     *
     * @return array<string, array<string, mixed>>
     */
    private function flattenShopScopedValues(array $valuesByModule, array $shopScopedFieldsByModule): array
    {
        if (empty($shopScopedFieldsByModule)) {
            return $valuesByModule;
        }

        foreach ($valuesByModule as $moduleName => &$fields) {
            foreach ($fields as $fieldName => &$value) {
                if (!empty($shopScopedFieldsByModule[$moduleName][$fieldName]) && is_array($value)) {
                    // Single-shop context: unwrap the single-element shop array.
                    $value = !empty($value) ? reset($value) : null;
                }
            }
            unset($value);
        }
        unset($fields);

        return $valuesByModule;
    }

    /**
     * Converts id_lang-keyed values in a module-grouped array to locale-string keys.
     *
     * Only fields that are declared as lang-scoped in the registry are converted.
     * Common and shop scope values are passed through unchanged.
     *
     * @param array<string, array<string, mixed>> $valuesByModule
     * @param array<int, string> $langLocaleMap [id_lang => locale]
     * @param array<string, array<string, true>> $langScopedFieldsByModule ['moduleKey' => ['fieldName' => true]]
     *
     * @return array<string, array<string, mixed>>
     */
    private function convertLangKeysToLocale(array $valuesByModule, array $langLocaleMap, array $langScopedFieldsByModule): array
    {
        if (empty($langLocaleMap)) {
            return $valuesByModule;
        }

        $result = [];
        foreach ($valuesByModule as $moduleName => $fields) {
            foreach ($fields as $fieldName => $value) {
                if (!is_array($value)) {
                    // Common-scope scalar — pass through
                    $result[$moduleName][$fieldName] = $value;
                    continue;
                }

                $isLangScoped = !empty($langScopedFieldsByModule[$moduleName][$fieldName]);
                if ($isLangScoped) {
                    // Lang-scope: convert id_lang → locale string
                    $converted = [];
                    foreach ($value as $idLang => $langValue) {
                        $locale = $langLocaleMap[(int) $idLang] ?? null;
                        if (null !== $locale) {
                            $converted[$locale] = $langValue;
                        }
                    }
                    $result[$moduleName][$fieldName] = $converted;
                    continue;
                }

                // Shop-scope (or any other array-shaped value): keep keys as-is.
                $result[$moduleName][$fieldName] = $value;
            }
        }

        return $result;
    }

    /**
     * Returns the id_lang → locale mapping via LangRepository.
     *
     * @return array<int, string>
     */
    private function fetchLangIdLocaleMap(): array
    {
        try {
            $mapping = $this->langRepository->getMapping();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($mapping as $idLang => $row) {
            $result[(int) $idLang] = (string) $row['locale'];
        }

        return $result;
    }
}
