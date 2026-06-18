<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\EventListener\API;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Api\ExtraPropertyApiPayloadHandler;
use PrestaShop\PrestaShop\Core\ExtraProperty\Api\ExtraPropertyApiResponseInjector;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShopBundle\ApiPlatform\Exception\LocaleNotFoundException;
use PrestaShopBundle\ApiPlatform\LocalizedValueUpdater;
use PrestaShopBundle\ApiPlatform\Metadata\LocalizedValue;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Bridges Admin API responses with the extra property system, and is the single API-Platform-coupled entry point of
 * the feature: it resolves the entity id and converts LANG values between locale and id_lang (LocalizedValueUpdater),
 * so the read/write services it delegates to stay pure Core. Validation lives in the dedicated CQRSApiValidator
 * subclass.
 *
 * On kernel.response (after API Platform produced the JSON body) it:
 *  - persists the submitted extraProperties payload of a write, once the entity id is in the response body,
 *  - enriches the response: a single item gets the nested `extraProperties` object (all locales), each item of a
 *    paginated list gets the grid-fetched values inline at its root (single context locale).
 *
 * Registered only in the Admin API kernel.
 */
class ExtraPropertyApiSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ExtraPropertyApiResponseInjector $responseInjector,
        protected readonly ExtraPropertyApiPayloadHandler $payloadHandler,
        protected readonly LocalizedValueUpdater $localizedValueUpdater,
        protected readonly ?PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory = null,
        protected readonly ?PropertyMetadataFactoryInterface $propertyMetadataFactory = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Negative priority so it runs after API Platform has produced the final JSON response body.
            KernelEvents::RESPONSE => [['onKernelResponse', -10]],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $operation = $request->attributes->get('_api_operation');
        if (!$operation instanceof HttpOperation) {
            return;
        }

        $response = $event->getResponse();
        if (!$this->isJsonEntityResponse($response)) {
            return;
        }

        $content = $response->getContent();
        if (false === $content || '' === $content) {
            return;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return;
        }

        $uriTemplate = (string) $operation->getUriTemplate();
        $method = (string) $operation->getMethod();

        // Single match: the definitions targeting this operation. When none match there is nothing to do.
        $definitions = $this->repository->getAllDefinitions()->filterByApi($uriTemplate, $method);
        if ($definitions->isEmpty()) {
            return;
        }

        $resourceClass = (string) $operation->getClass();
        $entityName = $definitions->first()->getEntityName();
        $langScopedFields = $this->langScopedFields($definitions);

        if ($this->isCollection($operation, $decoded)) {
            if (isset($decoded['items']) && is_array($decoded['items'])) {
                foreach ($decoded['items'] as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $entityId = $this->resolveId($item, $entityName, $resourceClass);
                    if ($entityId > 0) {
                        $decoded['items'][$index] = $this->responseInjector->injectInlineListItem($item, $definitions, $entityId);
                    }
                }
            }
        } else {
            $entityId = $this->resolveId($decoded, $entityName, $resourceClass);
            if ($entityId > 0) {
                // Persist the submitted payload first (write operations), so the read-back below reflects it. A 2xx
                // response means the merging validator already accepted the payload.
                if ($this->isWriteMethod($method)) {
                    $payload = $this->extractRequestPayload($request);
                    if ([] !== $payload) {
                        $this->payloadHandler->persist($definitions, $entityId, $this->localesToIds($payload, $langScopedFields));
                    }
                }

                $values = $this->idsToLocales(
                    $this->responseInjector->loadExtraProperties($definitions, $entityId),
                    $langScopedFields
                );
                if ([] !== $values) {
                    $decoded['extraProperties'] = array_merge(
                        is_array($decoded['extraProperties'] ?? null) ? $decoded['extraProperties'] : [],
                        $values
                    );
                }
            }
        }

        $response->setContent((string) json_encode($decoded));
        // Content length is recomputed when the response is sent; drop any stale value.
        $response->headers->remove('Content-Length');
    }

    protected function isJsonEntityResponse(Response $response): bool
    {
        if (Response::HTTP_OK !== $response->getStatusCode() && Response::HTTP_CREATED !== $response->getStatusCode()) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type', ''), 'json');
    }

    /**
     * @param array<string, mixed> $decoded
     */
    protected function isCollection(HttpOperation $operation, array $decoded): bool
    {
        return $operation instanceof CollectionOperationInterface || array_key_exists('items', $decoded);
    }

    protected function isWriteMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * Resolves the integer entity identifier from a normalized API item. Resolution order: the
     * #[ApiProperty(identifier: true)] property (via metadata) → the generic 'id' field → the camelCase
     * "{entity}Id" pattern (e.g. product → productId). Returns 0 when none is found.
     *
     * @param array<string, mixed> $normalizedData
     */
    protected function resolveId(array $normalizedData, string $entityName, string $resourceClass): int
    {
        if (null !== $this->propertyNameCollectionFactory && null !== $this->propertyMetadataFactory) {
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

        if (isset($normalizedData['id']) && is_int($normalizedData['id'])) {
            return $normalizedData['id'];
        }

        $idPropertyName = lcfirst(Container::camelize($entityName)) . 'Id';
        if (isset($normalizedData[$idPropertyName]) && is_int($normalizedData[$idPropertyName])) {
            return $normalizedData[$idPropertyName];
        }

        return 0;
    }

    /**
     * Reads the submitted extraProperties sub-object from the initial request body. Request::getContent() is
     * cached, so this does not consume the input stream a second time.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function extractRequestPayload(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['extraProperties']) || !is_array($decoded['extraProperties'])) {
            return [];
        }

        return $decoded['extraProperties'];
    }

    /**
     * @return array<string, array<string, true>> [moduleKey][propertyName] => true for LANG-scoped definitions
     */
    protected function langScopedFields(ExtraPropertyDefinitionCollection $definitions): array
    {
        $fields = [];
        foreach ($definitions as $definition) {
            if (ExtraPropertyScope::LANG === $definition->getScope()) {
                $fields[$definition->getNormalizedModuleKey()][$definition->getPropertyName()] = true;
            }
        }

        return $fields;
    }

    /**
     * Converts the LANG fields of a submitted payload from locale keys to id_lang keys; other scopes pass through.
     *
     * @param array<string, array<string, mixed>> $payload
     * @param array<string, array<string, true>> $langScopedFields
     *
     * @return array<string, array<string, mixed>>
     */
    protected function localesToIds(array $payload, array $langScopedFields): array
    {
        foreach ($payload as $moduleKey => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $fieldName => $value) {
                if (!is_array($value) || empty($langScopedFields[$moduleKey][$fieldName])) {
                    continue;
                }
                try {
                    $payload[$moduleKey][$fieldName] = $this->localizedValueUpdater->denormalizeLocalizedValue(
                        $value,
                        $fieldName,
                        [LocalizedValue::IS_LOCALIZED_VALUE => true, LocalizedValue::DENORMALIZED_KEY => LocalizedValue::ID_KEY],
                    );
                } catch (LocaleNotFoundException) {
                    // Unknown locale (already reported by validation) — drop the field rather than fail the write.
                    unset($payload[$moduleKey][$fieldName]);
                }
            }
        }

        return $payload;
    }

    /**
     * Converts the LANG fields of loaded values from id_lang keys to locale keys; other scopes pass through.
     *
     * @param array<string, array<string, mixed>> $valuesByModule
     * @param array<string, array<string, true>> $langScopedFields
     *
     * @return array<string, array<string, mixed>>
     */
    protected function idsToLocales(array $valuesByModule, array $langScopedFields): array
    {
        foreach ($valuesByModule as $moduleKey => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $fieldName => $value) {
                if (!is_array($value) || empty($langScopedFields[$moduleKey][$fieldName])) {
                    continue;
                }
                try {
                    $valuesByModule[$moduleKey][$fieldName] = $this->localizedValueUpdater->normalizeLocalizedValue(
                        $value,
                        $fieldName,
                        [LocalizedValue::IS_LOCALIZED_VALUE => true],
                    );
                } catch (Throwable) {
                    // Unknown id_lang in stored data — keep the raw value rather than fail the response.
                }
            }
        }

        return $valuesByModule;
    }
}
