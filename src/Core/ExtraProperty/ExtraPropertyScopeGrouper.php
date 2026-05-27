<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionInfo;

/**
 * Groups extra property definitions by scope using enum values as source of truth.
 */
final class ExtraPropertyScopeGrouper
{
    /**
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     *
     * @return array<string, list<ExtraPropertyDefinitionInfo>>
     */
    public static function groupDefinitionsByScope(array $definitions): array
    {
        $definitionsByScope = [];
        foreach (ExtraPropertyScope::values() as $scope) {
            $definitionsByScope[$scope] = [];
        }

        foreach ($definitions as $definition) {
            $fieldScope = $definition->getFieldScope();
            if (!array_key_exists($fieldScope, $definitionsByScope)) {
                continue;
            }
            $definitionsByScope[$fieldScope][] = $definition;
        }

        return $definitionsByScope;
    }
}
