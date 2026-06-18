<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\ApiPlatform\Validator;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use ApiPlatform\Validator\ValidatorInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Validation\ExtraPropertyValidatorInterface;
use PrestaShopBundle\ApiPlatform\Exception\LocaleNotFoundException;
use PrestaShopBundle\ApiPlatform\LocalizedValueUpdater;
use PrestaShopBundle\ApiPlatform\Metadata\LocalizedValue;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Throwable;

/**
 * Admin-API-only CQRSApiValidator that also validates the incoming `extraProperties` payload and MERGES its
 * violations with the resource constraint violations into a single 422 — instead of one preempting the other.
 *
 * Aliased over CQRSApiValidator in the Admin API kernel so CQRSApiNormalizer uses it transparently. Core
 * resource validation in PrestaShop runs during denormalization, so this is the only seam where the two
 * violation lists can be combined.
 */
class ExtraPropertyCQRSApiValidator extends CQRSApiValidator
{
    public function __construct(
        MetadataFactoryInterface $validatorMetadataFactory,
        ValidatorInterface $validator,
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        protected readonly RequestStack $requestStack,
        protected readonly ExtraPropertyValidatorInterface $validatorAdapter,
        protected readonly LocalizedValueUpdater $localizedValueUpdater,
    ) {
        parent::__construct($validatorMetadataFactory, $validator);
    }

    /**
     * Returns true when the resource carries Symfony constraints OR when an extra property definition targets
     * one of its operations — so extra-property validation runs even for resources with no core constraints.
     */
    public function hasConstraints(string $resourceClass): bool
    {
        return parent::hasConstraints($resourceClass) || $this->resourceHasExtraProperties($resourceClass);
    }

    public function validate(mixed $apiResource, Operation $operation): void
    {
        $violations = new ConstraintViolationList();

        try {
            parent::validate($apiResource, $operation);
        } catch (ValidationException $e) {
            $violations->addAll($e->getConstraintViolationList());
        }

        $payload = $this->extractExtraPropertiesPayload();
        if (null !== $payload && $operation instanceof HttpOperation) {
            $extraViolations = $this->validateExtraProperties(
                $payload,
                (string) $operation->getUriTemplate(),
                (string) $operation->getMethod(),
            );
            $violations->addAll($extraViolations);
        }

        if ($violations->count() > 0) {
            throw new ValidationException($violations);
        }
    }

    protected function resourceHasExtraProperties(string $resourceClass): bool
    {
        $definitions = $this->repository->getAllDefinitions();
        if ($definitions->isEmpty()) {
            return false;
        }

        try {
            $metadataCollection = $this->resourceMetadataFactory->create($resourceClass);
        } catch (Throwable) {
            return false;
        }

        foreach ($metadataCollection as $resourceMetadata) {
            foreach ($resourceMetadata->getOperations() ?? [] as $operation) {
                if (!$operation instanceof HttpOperation) {
                    continue;
                }
                $uriTemplate = (string) $operation->getUriTemplate();
                if ('' === $uriTemplate) {
                    continue;
                }
                if (!$definitions->filterByApi($uriTemplate, (string) $operation->getMethod())->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validates an extraProperties payload against the definitions targeting the given operation (URI template +
     * HTTP method). Returns an empty list when nothing matches or the payload is empty. Violations use the path
     * "extraProperties.<module>.<field>[.<locale|shopId>]" so they merge with the resource constraint violations.
     *
     * @param array<string, array<string, mixed>> $extraPropertiesByModule
     */
    protected function validateExtraProperties(array $extraPropertiesByModule, string $uriTemplate, string $method): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();
        if (empty($extraPropertiesByModule)) {
            return $violations;
        }

        $definitions = $this->repository->getAllDefinitions()->filterByApi($uriTemplate, $method);
        foreach ($definitions as $definition) {
            $moduleKey = $definition->getNormalizedModuleKey();
            $fieldName = $definition->getPropertyName();
            if (!isset($extraPropertiesByModule[$moduleKey]) || !array_key_exists($fieldName, $extraPropertiesByModule[$moduleKey])) {
                continue;
            }

            $value = $extraPropertiesByModule[$moduleKey][$fieldName];
            $basePath = sprintf('extraProperties.%s.%s', $moduleKey, $fieldName);

            if (ExtraPropertyScope::LANG === $definition->getScope() && is_array($value)) {
                foreach ($value as $locale => $localeValue) {
                    $this->validateOneValue($violations, $definition, $localeValue, $basePath . '.' . (string) $locale);
                }
                $this->assertKnownLocales($violations, $value, $fieldName, $basePath);
                continue;
            }

            if (ExtraPropertyScope::SHOP === $definition->getScope() && is_array($value)) {
                foreach ($value as $shopId => $shopValue) {
                    $this->validateOneValue($violations, $definition, $shopValue, $basePath . '.' . (string) $shopId);
                }
                continue;
            }

            $this->validateOneValue($violations, $definition, $value, $basePath);
        }

        return $violations;
    }

    protected function validateOneValue(ConstraintViolationListInterface $violations, ExtraPropertyDefinition $definition, mixed $value, string $propertyPath): void
    {
        if (null === $definition->getValidator()) {
            return;
        }

        $result = $this->validatorAdapter->validateValue($definition, $value);
        if (true !== $result) {
            $message = is_string($result) && '' !== $result ? $result : 'This value is not valid.';
            $violations->add(new ConstraintViolation($message, $message, [], null, $propertyPath, $value));
        }
    }

    /**
     * Adds a violation when a LANG-scope payload uses a locale that does not exist in the shop.
     *
     * @param array<int|string, mixed> $localizedValue
     */
    protected function assertKnownLocales(ConstraintViolationListInterface $violations, array $localizedValue, string $fieldName, string $basePath): void
    {
        try {
            $this->localizedValueUpdater->denormalizeLocalizedValue(
                $localizedValue,
                $fieldName,
                [LocalizedValue::IS_LOCALIZED_VALUE => true, LocalizedValue::DENORMALIZED_KEY => LocalizedValue::ID_KEY],
            );
        } catch (LocaleNotFoundException $e) {
            $violations->add(new ConstraintViolation($e->getMessage(), $e->getMessage(), [], null, $basePath, $localizedValue));
        }
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    protected function extractExtraPropertiesPayload(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $content = $request->getContent();
        if ('' === $content) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['extraProperties']) || !is_array($decoded['extraProperties'])) {
            return null;
        }

        return $decoded['extraProperties'];
    }
}
