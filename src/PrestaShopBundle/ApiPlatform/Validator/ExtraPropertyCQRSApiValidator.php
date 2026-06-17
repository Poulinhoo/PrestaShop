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
use PrestaShop\PrestaShop\Core\ExtraProperty\Api\ExtraPropertyApiPayloadHandlerInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolationList;
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
        protected readonly ExtraPropertyApiPayloadHandlerInterface $payloadHandler,
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
        protected readonly ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory,
        protected readonly RequestStack $requestStack,
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
            $extraViolations = $this->payloadHandler->validate(
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
