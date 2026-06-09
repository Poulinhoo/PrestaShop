<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\QuickAccess;

use PrestaShop\PrestaShop\Adapter\Language\LanguageDataProvider;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;

/**
 * Fills empty/missing quick access names with the default language value, for every active language.
 *
 * This reproduces the legacy back office behavior (and the form-level auto-fill) at the CQRS level,
 * so it applies whatever builds the command: the form, the ajax quick-link call (which only provides
 * the current language) and the Admin API.
 */
class LocalizedNamesFiller
{
    public function __construct(
        private readonly LanguageDataProvider $languageDataProvider,
        private readonly ConfigurationInterface $configuration,
    ) {
    }

    /**
     * @param array<int, string> $localizedNames Lang-ID-keyed name translations
     *
     * @return array<int, string>
     */
    public function fill(array $localizedNames): array
    {
        $defaultValue = $this->resolveDefaultValue($localizedNames);
        if (null === $defaultValue) {
            return $localizedNames;
        }

        foreach ($this->languageDataProvider->getLanguages(true, false, true) as $languageId) {
            if (empty($localizedNames[(int) $languageId])) {
                $localizedNames[(int) $languageId] = $defaultValue;
            }
        }

        return $localizedNames;
    }

    /**
     * Returns the value used to fill the empty languages: the default language name when set,
     * otherwise the first non-empty name provided (e.g. the ajax call only sends the current language).
     *
     * @param array<int, string> $localizedNames
     */
    private function resolveDefaultValue(array $localizedNames): ?string
    {
        $defaultLanguageId = (int) $this->configuration->get('PS_LANG_DEFAULT');
        if (!empty($localizedNames[$defaultLanguageId])) {
            return $localizedNames[$defaultLanguageId];
        }

        foreach ($localizedNames as $name) {
            if (!empty($name)) {
                return $name;
            }
        }

        return null;
    }
}
