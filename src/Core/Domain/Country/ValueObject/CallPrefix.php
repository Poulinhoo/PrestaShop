<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\Country\ValueObject;

use PrestaShop\PrestaShop\Core\Domain\Country\Exception\CountryConstraintException;

/**
 * Contains a valid international call prefix for a country.
 *
 * The raw value is validated as a digits-only string before being stored as an
 * integer. Validating the string (instead of casting first) catches malformed
 * values such as "+99" or "-5" that PHP's int cast would silently coerce into a
 * valid integer — this guarantees the same rejection the legacy
 * AdminCountriesController enforced via Validate::isInt, regardless of the entry
 * point (form, API, import, programmatic command).
 */
class CallPrefix
{
    /**
     * Call prefix validation pattern: digits only.
     */
    public const CALL_PREFIX_PATTERN = '/^\d+$/';

    /**
     * @var int
     */
    protected $callPrefix;

    /**
     * @param int|string $callPrefix raw call prefix value, before any integer cast
     *
     * @throws CountryConstraintException
     */
    public function __construct(int|string $callPrefix)
    {
        $callPrefix = (string) $callPrefix;
        $this->assertIsValidCallPrefix($callPrefix);
        $this->callPrefix = (int) $callPrefix;
    }

    public function getValue(): int
    {
        return $this->callPrefix;
    }

    /**
     * @param string $callPrefix
     *
     * @throws CountryConstraintException
     */
    protected function assertIsValidCallPrefix(string $callPrefix): void
    {
        if (!preg_match(self::CALL_PREFIX_PATTERN, $callPrefix)) {
            throw new CountryConstraintException(
                sprintf('Invalid country call prefix: %s', $callPrefix),
                CountryConstraintException::INVALID_CALL_PREFIX
            );
        }
    }
}
