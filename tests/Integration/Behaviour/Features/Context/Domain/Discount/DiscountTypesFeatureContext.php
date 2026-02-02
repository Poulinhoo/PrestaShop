<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Tests\Integration\Behaviour\Features\Context\Domain\Discount;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;
use PrestaShop\PrestaShop\Core\Domain\Discount\Query\GetDiscountTypes;
use RuntimeException;
use Tests\Integration\Behaviour\Features\Context\Domain\AbstractDomainFeatureContext;

class DiscountTypesFeatureContext extends AbstractDomainFeatureContext
{
    /**
     * @Then I should receive the following discount types:
     *
     * @param TableNode $table
     */
    public function assertDiscountTypes(TableNode $table): void
    {
        $queryBus = $this->getQueryBus();
        $query = new GetDiscountTypes();
        $discountTypes = $queryBus->handle($query);

        Assert::assertNotNull($discountTypes, 'No discount types received');
        Assert::assertIsArray($discountTypes, 'Discount types should be an array');

        $expectedDiscountTypes = $this->localizeByColumns($table);

        Assert::assertGreaterThanOrEqual(
            count($expectedDiscountTypes),
            count($discountTypes),
            sprintf('Expected %d discount types but got %d', count($expectedDiscountTypes), count($discountTypes))
        );

        foreach ($expectedDiscountTypes as $expectedDiscountType) {
            $foundDiscountType = null;
            foreach ($discountTypes as $discountType) {
                if ($discountType->getType() === $expectedDiscountType['type']) {
                    $foundDiscountType = $discountType;
                    break;
                }
            }

            if (null === $foundDiscountType) {
                throw new RuntimeException(sprintf('Couldnt find discount type "%s"', $expectedDiscountType['type']));
            }

            if (isset($expectedDiscountType['discountTypeId'])) {
                Assert::assertEquals(
                    (int) $expectedDiscountType['discountTypeId'],
                    $foundDiscountType->getDiscountTypeId(),
                    sprintf('Unexpected discountTypeId for type "%s"', $expectedDiscountType['type'])
                );
            }

            if (isset($expectedDiscountType['core'])) {
                $expectedCore = filter_var($expectedDiscountType['core'], FILTER_VALIDATE_BOOLEAN);
                Assert::assertEquals(
                    $expectedCore,
                    $foundDiscountType->isCore(),
                    sprintf('Unexpected core value for type "%s"', $expectedDiscountType['type'])
                );
            }

            if (isset($expectedDiscountType['enabled'])) {
                $expectedEnabled = filter_var($expectedDiscountType['enabled'], FILTER_VALIDATE_BOOLEAN);
                Assert::assertEquals(
                    $expectedEnabled,
                    $foundDiscountType->isEnabled(),
                    sprintf('Unexpected enabled value for type "%s"', $expectedDiscountType['type'])
                );
            }

            if (isset($expectedDiscountType['names'])) {
                $actualNames = $foundDiscountType->getLocalizedNames();
                foreach ($expectedDiscountType['names'] as $langId => $expectedName) {
                    Assert::assertEquals(
                        $expectedName,
                        $actualNames[$langId],
                        sprintf('Unexpected name for language ID %d in type "%s"', $langId, $expectedDiscountType['type'])
                    );
                }
            }

            if (isset($expectedDiscountType['descriptions'])) {
                $actualDescriptions = $foundDiscountType->getLocalizedDescriptions();
                foreach ($expectedDiscountType['descriptions'] as $langId => $expectedDescription) {
                    Assert::assertEquals(
                        $expectedDescription,
                        $actualDescriptions[$langId],
                        sprintf('Unexpected description for language ID %d in type "%s"', $langId, $expectedDiscountType['type'])
                    );
                }
            }
        }
    }
}
