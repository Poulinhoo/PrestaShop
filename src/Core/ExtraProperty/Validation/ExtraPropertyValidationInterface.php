<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Validation;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionCollection;

/**
 * Validation contract for the ExtraProperty feature.
 *
 * Structural checks (isTableOrIdentifier, isModuleName) are used at registration time.
 * Value validation (validateValue / validate) is used by forms, API, and ObjectModel.
 *
 * This interface is kept as a DI alias contract (→ ExtraPropertyValueValidator) so that
 * callers can depend on the interface rather than the concrete class. Merging it into
 * ExtraPropertyValueValidator would remove the indirection without meaningful gain.
 */
interface ExtraPropertyValidationInterface
{
    /**
     * Checks if a value is a valid SQL table/identifier token.
     */
    public function isTableOrIdentifier(string $value): bool;

    /**
     * Checks if a value is a valid module technical name.
     */
    public function isModuleName(string $value): bool;

    /**
     * Validates one extra property value against its definition's validator.
     *
     * For lang-scoped fields the value may be an array keyed by id_lang;
     * each language value is validated individually.
     * Returns true on success, or an error message string on failure.
     *
     * @param mixed $value
     *
     * @return true|string
     */
    public function validateValue(ExtraPropertyDefinition $definition, mixed $value): bool|string;

    /**
     * Validates a batch of extra property values against their definitions.
     *
     * $flatValues uses the storage-column name format: ['module__field' => value].
     * Definitions not present in $flatValues are skipped.
     * Returns true on success, or the first error message string encountered.
     *
     * @param array<string, mixed> $flatValues column_name => value
     * @param ExtraPropertyDefinitionCollection $definitions
     *
     * @return true|string
     */
    public function validate(array $flatValues, ExtraPropertyDefinitionCollection $definitions): bool|string;
}
