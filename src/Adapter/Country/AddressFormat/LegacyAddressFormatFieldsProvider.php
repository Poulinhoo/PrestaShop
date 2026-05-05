<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\Country\AddressFormat;

use AddressFormat;
use PrestaShop\PrestaShop\Core\Domain\Country\AddressFormat\AddressFormatFieldsProviderInterface;

/**
 * Adapter that bridges AddressFormatFieldsProviderInterface to AddressFormat::getValidateFields(),
 * which uses reflection on the legacy ObjectModel classes to discover their public properties.
 */
final class LegacyAddressFormatFieldsProvider implements AddressFormatFieldsProviderInterface
{
    public function getFieldsForClass(string $className): array
    {
        return AddressFormat::getValidateFields($className);
    }

    public function getRequiredFields(): array
    {
        return array_values(AddressFormat::getFieldsRequired());
    }
}
