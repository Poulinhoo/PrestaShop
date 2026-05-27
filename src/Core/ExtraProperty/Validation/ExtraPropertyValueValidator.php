<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\ExtraProperty\Validation;

use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinitionInfo;
use PrestaShop\PrestaShop\Core\ExtraProperty\ExtraPropertyNaming;
use Symfony\Contracts\Translation\TranslatorInterface;
use Validate;

/**
 * Validates extra property values against their registered definitions.
 *
 * Centralizes validation so that ObjectModel, BO form handlers and API integrations
 * all use the same rules. Structural checks (isTableOrIdentifier, isModuleName) use
 * pure regex. Value validation dispatches dynamically to Validate::xxx methods.
 *
 * Note: isRequiredWhenActive and defaultLanguageRequiredWhenActive require access to
 * the ObjectModel instance and are therefore intentionally skipped by validateValue().
 * ObjectModel-level validation handles those two cases directly.
 */
class ExtraPropertyValueValidator implements ExtraPropertyValidationInterface
{
    public function __construct(
        protected readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isTableOrIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $value);
    }

    /**
     * {@inheritdoc}
     */
    public function isModuleName(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $value);
    }

    /**
     * {@inheritdoc}
     */
    public function validateValue(ExtraPropertyDefinitionInfo $definition, mixed $value): bool|string
    {
        $validator = $definition->getValidator() ?? '';
        if ('' === $validator || !$this->hasValidatorMethod($validator)) {
            return true;
        }

        // isRequiredWhenActive / defaultLanguageRequiredWhenActive require the ObjectModel instance; skip here.
        $isEmptyValidationMethod = 'isrequiredwhenactive' === strtolower($validator)
            || 'defaultlanguagerequiredwhenactive' === strtolower($validator);

        $label = $definition->getPropertyName();
        $errorMessage = $this->translator->trans('The %s field is invalid.', [$label], 'Admin.Notifications.Error');

        if (is_array($value)) {
            foreach ($value as $langValue) {
                if (('' === (string) $langValue || null === $langValue) && !$isEmptyValidationMethod) {
                    continue;
                }
                if (!(bool) call_user_func([Validate::class, $validator], $langValue)) {
                    return $errorMessage;
                }
            }

            return true;
        }

        if (('' === (string) $value || null === $value) && !$isEmptyValidationMethod) {
            return true;
        }

        return (bool) call_user_func([Validate::class, $validator], $value) ? true : $errorMessage;
    }

    /**
     * Validates a set of extra property values against a list of definitions.
     *
     * Values use the flat storage-column format: ['module_field' => value_or_lang_array].
     * Returns true on success, or the first error message string on failure.
     *
     * @param array<string, mixed> $flatValues column_name => value
     * @param list<ExtraPropertyDefinitionInfo> $definitions
     *
     * @return true|string
     */
    public function validate(array $flatValues, array $definitions): bool|string
    {
        foreach ($definitions as $definition) {
            $columnName = ExtraPropertyNaming::storageColumnName(
                $definition->getModuleName(),
                $definition->getPropertyName()
            );
            if (!array_key_exists($columnName, $flatValues)) {
                continue;
            }

            $result = $this->validateValue($definition, $flatValues[$columnName]);
            if (true !== $result) {
                return $result;
            }
        }

        return true;
    }

    protected function hasValidatorMethod(string $validator): bool
    {
        return '' !== $validator && method_exists(Validate::class, $validator);
    }
}
