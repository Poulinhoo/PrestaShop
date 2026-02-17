<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

namespace Tests\Integration\Behaviour\Features\Context;

use Behat\Behat\Context\Context as BehatContext;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use RuntimeException;

/**
 * PrestaShopFeatureContext provides behat hooks to perform necessary operations for testing:
 * - shop setup
 * - database reset between scenario
 * - cache clear between steps
 * - ...
 */
abstract class AbstractPrestaShopFeatureContext implements BehatContext
{
    use SharedStorageTrait;

    protected function checkFixtureExists(array $fixtures, $fixtureName, $fixtureIndex)
    {
        $searchLength = 10;

        if (!isset($fixtures[$fixtureIndex])) {
            $fixtureNames = array_keys($fixtures);
            $firstFixtureNames = array_splice($fixtureNames, 0, $searchLength);
            $firstFixtureNamesStr = implode(',', $firstFixtureNames);
            throw new RuntimeException(sprintf(
                '%s named "%s" was not added in fixtures. First %d added are: %s',
                $fixtureName,
                $fixtureIndex,
                $searchLength,
                $firstFixtureNamesStr
            ));
        }
    }

    /**
     * @return int
     */
    protected function getDefaultLangId(): int
    {
        return (int) $this->getConfiguration()->get('PS_LANG_DEFAULT');
    }

    /**
     * @return int
     */
    protected function getDefaultShopId(): int
    {
        return (int) $this->getConfiguration()->get('PS_SHOP_DEFAULT');
    }

    protected function getConfiguration(): ConfigurationInterface
    {
        return CommonFeatureContext::getContainer()->get(ConfigurationInterface::class);
    }
}
