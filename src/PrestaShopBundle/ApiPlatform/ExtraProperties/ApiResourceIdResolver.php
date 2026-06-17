<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\ApiPlatform\ExtraProperties;

use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use Symfony\Component\DependencyInjection\Container;
use Throwable;

/**
 * Resolves the integer entity identifier from a normalized Admin API item, shared by the read injector and the
 * write payload handler.
 *
 * Resolution order: the #[ApiProperty(identifier: true)] property (via metadata) → the generic 'id' field →
 * the camelCase "{entity}Id" pattern (e.g. product → productId).
 */
class ApiResourceIdResolver
{
    public function __construct(
        protected readonly ?PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory = null,
        protected readonly ?PropertyMetadataFactoryInterface $propertyMetadataFactory = null,
    ) {
    }

    /**
     * @param array<string, mixed> $normalizedData
     */
    public function resolveId(array $normalizedData, string $entityName, string $resourceClass): int
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
}
