<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace PrestaShop\PrestaShop\Core\Form\IdentifiableObject\OptionProvider;

use PrestaShop\PrestaShop\Adapter\Discount\Repository\DiscountTypeRepository;
use PrestaShop\PrestaShop\Core\Context\LanguageContext;

class DiscountFormOptionsProvider implements FormOptionsProviderInterface
{
    public function __construct(
        private readonly DiscountTypeRepository $discountTypeRepository,
        private readonly LanguageContext $languageContext,
    ) {
    }

    public function getOptions(int $id, array $data): array
    {
        return [
            'discount_type' => $data['information']['discount_type'] ?? '',
            'available_discount_types' => $this->discountTypeRepository->getAllActiveTypes($this->languageContext->getId()),
        ];
    }

    public function getDefaultOptions(array $data): array
    {
        return [
            'available_discount_types' => $this->discountTypeRepository->getAllActiveTypes($this->languageContext->getId()),
        ];
    }
}
