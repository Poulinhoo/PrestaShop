<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Form;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;

/**
 * Provides extra field definitions for Back Office Symfony forms.
 *
 * Filters on display_form=1 and returns a typed ExtraPropertyDefinitionCollection.
 */
class ExtraPropertiesFormDefinitionProvider
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
    ) {
    }

    public function getDefinitionsForEntity(string $entityName): ExtraPropertyDefinitionCollection
    {
        $collection = $this->findCollectionWithEntityFallbacks($entityName);

        return $collection->withDisplayForm();
    }

    protected function findCollectionWithEntityFallbacks(string $entityName): ExtraPropertyDefinitionCollection
    {
        foreach ($this->buildEntityNameCandidates($entityName) as $candidate) {
            $collection = $this->repository->getDefinitionCollection($candidate);
            if (!$collection->isEmpty()) {
                return $collection;
            }
        }

        return ExtraPropertyDefinitionCollection::empty();
    }

    /**
     * @return string[]
     */
    protected function buildEntityNameCandidates(string $entityName): array
    {
        $name = trim($entityName);
        if ('' === $name) {
            return [];
        }

        $candidates = [$name];

        $lastUnderscore = strrpos($name, '_');
        if (false !== $lastUnderscore && $lastUnderscore < strlen($name) - 1) {
            $suffix = substr($name, $lastUnderscore + 1);
            $candidates[] = $suffix;
            if (!str_ends_with($suffix, 's')) {
                $candidates[] = $suffix . 's';
            }
        }

        if (!str_ends_with($name, 's')) {
            $candidates[] = $name . 's';
        } else {
            $candidates[] = rtrim($name, 's');
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $v): bool => '' !== $v)));
    }
}
