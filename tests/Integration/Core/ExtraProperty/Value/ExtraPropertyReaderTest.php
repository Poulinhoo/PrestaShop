<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Core\ExtraProperty\Value;

use Db;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyRegistryInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope;
use PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyType;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyReaderInterface;
use PrestaShop\PrestaShop\Core\ExtraProperty\Value\ExtraPropertyWriterInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for ExtraPropertyReader against the *_extra / *_extra_lang tables. It covers BOTH methods of
 * ExtraPropertyReaderInterface — getExtraProperties() (one entity) and getMultipleExtraProperties() (several
 * entities in a single query) — across COMMON and LANG scopes.
 *
 * It registers its own definitions on the product entity (independent of any module or the Admin API), so it lives
 * here rather than in the API endpoint test where the previous version sat.
 */
class ExtraPropertyReaderTest extends KernelTestCase
{
    private const MODULE = 'extrapropertyreadertest';
    private const ENTITY = 'product';
    private const PRIMARY_KEY = 'id_product';

    private static ExtraPropertyReaderInterface $reader;
    private static ExtraPropertyWriterInterface $writer;
    private static ExtraPropertyRegistryInterface $registry;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::bootKernel();
        // Global var read by legacy code resolving the container (SymfonyContainer::getInstance).
        global $kernel;
        $kernel = self::$kernel;

        $container = self::getContainer();
        self::$reader = $container->get(ExtraPropertyReaderInterface::class);
        self::$writer = $container->get(ExtraPropertyWriterInterface::class);
        self::$registry = $container->get(ExtraPropertyRegistryInterface::class);

        self::$registry->register(self::commonDefinition());
        self::$registry->register(self::langDefinition());
    }

    public static function tearDownAfterClass(): void
    {
        self::$registry->unregister(self::commonDefinition(), true);
        self::$registry->unregister(self::langDefinition(), true);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Start from an empty state so each test controls exactly the rows it reads.
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'product_extra`');
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'product_extra_lang`');
    }

    public function testGetExtraPropertiesReturnsTheValuesOfOneEntity(): void
    {
        $shopConstraint = ShopConstraint::shop(1);
        self::$writer->writeAll(
            self::ENTITY,
            self::PRIMARY_KEY,
            101,
            [self::MODULE => ['reader_common' => 'hello', 'reader_lang' => [1 => 'english']]],
            $shopConstraint
        );

        // langId null → COMMON scalar + LANG value keyed by id_lang.
        $allLangs = self::$reader->getExtraProperties(self::ENTITY, self::PRIMARY_KEY, 101, null, $shopConstraint);
        $this->assertSame('hello', $allLangs[self::MODULE]['reader_common']);
        $this->assertSame([1 => 'english'], $allLangs[self::MODULE]['reader_lang']);

        // langId given → LANG value collapsed to a single scalar for that language.
        $oneLang = self::$reader->getExtraProperties(self::ENTITY, self::PRIMARY_KEY, 101, 1, $shopConstraint);
        $this->assertSame('english', $oneLang[self::MODULE]['reader_lang']);

        // Non-positive id → empty.
        $this->assertSame([], self::$reader->getExtraProperties(self::ENTITY, self::PRIMARY_KEY, 0, null, $shopConstraint));
    }

    public function testGetMultipleExtraPropertiesGroupsValuesPerEntityId(): void
    {
        $shopConstraint = ShopConstraint::shop(1);
        self::$writer->writeAll(self::ENTITY, self::PRIMARY_KEY, 101, [self::MODULE => ['reader_common' => 'A']], $shopConstraint);
        self::$writer->writeAll(self::ENTITY, self::PRIMARY_KEY, 102, [self::MODULE => ['reader_common' => 'B']], $shopConstraint);

        $values = self::$reader->getMultipleExtraProperties(self::ENTITY, self::PRIMARY_KEY, [101, 102, 103], null, $shopConstraint);

        $this->assertSame('A', $values[101][self::MODULE]['reader_common']);
        $this->assertSame('B', $values[102][self::MODULE]['reader_common']);
        // The requested id with no row still appears, seeded with the (nullable) default.
        $this->assertArrayHasKey(103, $values);
        $this->assertNull($values[103][self::MODULE]['reader_common']);
    }

    public function testGetMultipleExtraPropertiesReturnsEmptyWhenNoIds(): void
    {
        $this->assertSame([], self::$reader->getMultipleExtraProperties(self::ENTITY, self::PRIMARY_KEY, [], null, ShopConstraint::shop(1)));
    }

    private static function commonDefinition(): ExtraPropertyDefinition
    {
        return new ExtraPropertyDefinition(
            entityName: self::ENTITY,
            propertyName: 'reader_common',
            type: ExtraPropertyType::STRING,
            scope: ExtraPropertyScope::COMMON,
            moduleName: self::MODULE,
        );
    }

    private static function langDefinition(): ExtraPropertyDefinition
    {
        return new ExtraPropertyDefinition(
            entityName: self::ENTITY,
            propertyName: 'reader_lang',
            type: ExtraPropertyType::STRING,
            scope: ExtraPropertyScope::LANG,
            moduleName: self::MODULE,
        );
    }
}
