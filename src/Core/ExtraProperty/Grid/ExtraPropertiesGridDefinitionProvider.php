<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */
declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Grid;

use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyDefinitionCollection;
use PrestaShop\PrestaShop\Core\ExtraProperty\Repository\ExtraPropertyDefinitionRepositoryInterface;

/**
 * Provides extra field definitions for Back Office Symfony grids.
 *
 * Filters on display_grid=1 and returns a typed ExtraPropertyDefinitionCollection.
 */
class ExtraPropertiesGridDefinitionProvider
{
    public function __construct(
        protected readonly ExtraPropertyDefinitionRepositoryInterface $repository,
    ) {
    }

    public function getDefinitionsForGrid(string $gridId): ExtraPropertyDefinitionCollection
    {
        $collection = $this->findCollectionWithGridFallbacks($gridId);

        return $collection->withDisplayGrid();
    }

    protected function findCollectionWithGridFallbacks(string $gridId): ExtraPropertyDefinitionCollection
    {
        foreach ($this->buildGridIdCandidates($gridId) as $candidate) {
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
    protected function buildGridIdCandidates(string $gridId): array
    {
        $name = trim($gridId);
        if ('' === $name) {
            return [];
        }

        $candidates = [$name];

        if (!str_ends_with($name, 's')) {
            $candidates[] = $name . 's';
        } else {
            $candidates[] = rtrim($name, 's');
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $v): bool => '' !== $v)));
    }
}
