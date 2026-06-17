<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\ApiPlatform\EndPoint;

use Module;
use Symfony\Component\HttpFoundation\Response;
use Tests\Resources\DatabaseDump;
use Tests\Resources\Resetter\LanguageResetter;
use Tests\Resources\Resetter\ProductResetter;
use Tools;

/**
 * Integration tests for the "Extra Properties on the Admin API" feature.
 *
 * A test module (extrapropertytest) registers a few extra properties scoped to specific API endpoints.
 * These tests assert the runtime contract of the dedicated extraProperties sub-object:
 *  - it is keyed by module technical name then snake_case property name,
 *  - COMMON fields are scalars, LANG fields are objects keyed by locale,
 *  - writes validate (422 on failure) and persist, unknown keys are tolerated,
 *  - properties never leak across entities they were not registered on.
 */
final class ExtraPropertyEndpointTest extends ApiTestCase
{
    private const MODULE_NAME = 'extrapropertytest';

    private const PRODUCT_READ = 'product_read';
    private const PRODUCT_WRITE = 'product_write';
    private const CUSTOMER_READ = 'customer_read';
    private const CUSTOMER_WRITE = 'customer_write';

    /**
     * Existing product fixture id from the standard test fixtures.
     */
    private const PRODUCT_ID = 1;

    private static bool $moduleInstalled = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The localized extra property (api_note) is written/read in several locales, so install a second
        // language (the default install only has en-US).
        LanguageResetter::resetLanguages();
        self::addLanguageByLocale('fr-FR');

        ProductResetter::resetProducts();
        DatabaseDump::restoreTables(['customer', 'customer_group']);

        // Copy the test module into the modules directory then install it (mirrors ModuleManagerBuilderTest).
        $sourceModuleDir = dirname(__DIR__, 3) . '/Resources/modules_tests/' . self::MODULE_NAME;
        if (is_dir($sourceModuleDir)) {
            Tools::recurseCopy($sourceModuleDir, _PS_MODULE_DIR_ . '/' . self::MODULE_NAME);
        }

        $module = Module::getInstanceByName(self::MODULE_NAME);
        self::assertInstanceOf(Module::class, $module);
        self::$moduleInstalled = (bool) $module->install();
        self::assertTrue(self::$moduleInstalled, 'The extrapropertytest module could not be installed');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$moduleInstalled && Module::isInstalled(self::MODULE_NAME)) {
            Module::getInstanceByName(self::MODULE_NAME)->uninstall();
        }
        if (is_dir(_PS_MODULE_DIR_ . '/' . self::MODULE_NAME)) {
            Tools::deleteDirectory(_PS_MODULE_DIR_ . '/' . self::MODULE_NAME);
        }

        ProductResetter::resetProducts();
        DatabaseDump::restoreTables(['customer', 'customer_group']);
        LanguageResetter::resetLanguages();

        self::$moduleInstalled = false;

        parent::tearDownAfterClass();
    }

    public function getProtectedEndpoints(): iterable
    {
        yield 'get product endpoint' => [
            'GET',
            '/products/' . self::PRODUCT_ID,
        ];

        yield 'update product endpoint' => [
            'PATCH',
            '/products/' . self::PRODUCT_ID,
        ];

        yield 'get customer endpoint' => [
            'GET',
            '/customers/1',
        ];

        yield 'update customer endpoint' => [
            'PATCH',
            '/customers/1',
        ];
    }

    /**
     * Round-trip: PATCH writes extra properties, the PATCH response and a subsequent GET both expose them.
     */
    public function testWriteAndReadProductExtraProperties(): void
    {
        $patchedProduct = $this->partialUpdateItem(
            '/products/' . self::PRODUCT_ID,
            [
                'extraProperties' => [
                    self::MODULE_NAME => [
                        'api_flag' => true,
                        'api_note' => [
                            'en-US' => 'hello',
                            'fr-FR' => 'bonjour',
                        ],
                    ],
                ],
            ],
            [self::PRODUCT_WRITE]
        );

        $this->assertModuleExtraProperties($patchedProduct);
        $patchedExtra = $patchedProduct['extraProperties'][self::MODULE_NAME];
        $this->assertSame(true, $patchedExtra['api_flag']);
        $this->assertSame(['en-US' => 'hello', 'fr-FR' => 'bonjour'], $patchedExtra['api_note']);

        // The same values must be persisted and returned on a plain GET.
        $fetchedProduct = $this->getItem('/products/' . self::PRODUCT_ID, [self::PRODUCT_READ]);
        $this->assertModuleExtraProperties($fetchedProduct);
        $fetchedExtra = $fetchedProduct['extraProperties'][self::MODULE_NAME];
        $this->assertSame(true, $fetchedExtra['api_flag']);
        $this->assertSame(['en-US' => 'hello', 'fr-FR' => 'bonjour'], $fetchedExtra['api_note']);
    }

    /**
     * The list (GET collection) reuses the values the grid query already fetched and exposes them INLINE at the
     * item root, under their grid field name (extra_<scope>_<module>_<field>) — NOT inside a nested
     * extraProperties sub-object, and as the single current-locale value (mirroring the back-office grid). Only
     * properties associated with both the grid and the API appear, so we assert the product we wrote to carries
     * its api_flag (bool) and api_note (single-locale string) at the root and that there is no sub-object.
     *
     * @depends testWriteAndReadProductExtraProperties
     */
    public function testListInjectsExtraProperties(): void
    {
        $list = $this->listItems('/products', [self::PRODUCT_READ]);
        $this->assertNotEmpty($list['items']);

        $writtenItem = null;
        foreach ($list['items'] as $item) {
            if ((int) $item['productId'] === self::PRODUCT_ID) {
                $writtenItem = $item;
                break;
            }
        }

        $this->assertNotNull($writtenItem, 'The product written to was not present in the list');

        // List items must NOT carry the nested extraProperties object used by single-item endpoints.
        $this->assertArrayNotHasKey('extraProperties', $writtenItem);

        // COMMON value, inline at root, already cast to bool by the grid query.
        $flagKey = 'extra_common_' . self::MODULE_NAME . '_api_flag';
        $this->assertArrayHasKey($flagKey, $writtenItem);
        $this->assertTrue($writtenItem[$flagKey]);

        // LANG value, inline at root, as the single current-locale scalar (not a {locale: value} object). The
        // value is whichever locale the API context resolves to among the two we wrote.
        $noteKey = 'extra_lang_' . self::MODULE_NAME . '_api_note';
        $this->assertArrayHasKey($noteKey, $writtenItem);
        $this->assertIsString($writtenItem[$noteKey]);
        $this->assertContains($writtenItem[$noteKey], ['hello', 'bonjour']);
    }

    /**
     * A value that fails the registered validator (isBool) must produce a 422 with a violation
     * whose propertyPath points at the merged extraProperties.<module>.<field> path.
     */
    public function testValidationErrorReturns422(): void
    {
        $response = $this->partialUpdateItem(
            '/products/' . self::PRODUCT_ID,
            [
                'extraProperties' => [
                    self::MODULE_NAME => [
                        'api_flag' => 'not-a-bool',
                    ],
                ],
            ],
            [self::PRODUCT_WRITE],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );

        $this->assertIsArray($response);
        $this->assertValidationErrors(
            [
                ['propertyPath' => 'extraProperties.' . self::MODULE_NAME . '.api_flag'],
            ],
            $response
        );
    }

    /**
     * Properties registered on one entity must never appear on another entity, and vice versa.
     */
    public function testCrossEntityIsolation(): void
    {
        $customerId = $this->getExistingCustomerId();

        // Make the test self-contained: ensure the product exposes its own (product-scoped) property so we can
        // meaningfully assert the customer-only property never leaks into the product's module sub-object.
        $this->partialUpdateItem(
            '/products/' . self::PRODUCT_ID,
            [
                'extraProperties' => [
                    self::MODULE_NAME => [
                        'api_flag' => true,
                    ],
                ],
            ],
            [self::PRODUCT_WRITE]
        );

        // Set the customer-only extra property.
        $patchedCustomer = $this->partialUpdateItem(
            '/customers/' . $customerId,
            [
                'extraProperties' => [
                    self::MODULE_NAME => [
                        'api_score' => 42,
                    ],
                ],
            ],
            [self::CUSTOMER_WRITE]
        );
        $this->assertArrayHasKey('extraProperties', $patchedCustomer);
        $this->assertArrayHasKey(self::MODULE_NAME, $patchedCustomer['extraProperties']);
        $this->assertSame(42, $patchedCustomer['extraProperties'][self::MODULE_NAME]['api_score']);

        // The product must not expose the customer-only property.
        $product = $this->getItem('/products/' . self::PRODUCT_ID, [self::PRODUCT_READ]);
        $this->assertArrayHasKey('extraProperties', $product);
        $this->assertArrayHasKey(self::MODULE_NAME, $product['extraProperties']);
        $this->assertArrayNotHasKey('api_score', $product['extraProperties'][self::MODULE_NAME]);

        // The customer must expose its own property but none of the product-only ones.
        $customer = $this->getItem('/customers/' . $customerId, [self::CUSTOMER_READ]);
        $this->assertArrayHasKey('extraProperties', $customer);
        $this->assertArrayHasKey(self::MODULE_NAME, $customer['extraProperties']);
        $customerExtra = $customer['extraProperties'][self::MODULE_NAME];
        $this->assertArrayHasKey('api_score', $customerExtra);
        $this->assertSame(42, $customerExtra['api_score']);
        $this->assertArrayNotHasKey('api_flag', $customerExtra);
        $this->assertArrayNotHasKey('api_note', $customerExtra);
    }

    /**
     * An unknown extra property key (no matching definition) must be silently ignored:
     * the write succeeds (no 4xx) and the unknown field is absent from the response.
     */
    public function testUnknownExtraPropertyKeyIsTolerated(): void
    {
        $patchedProduct = $this->partialUpdateItem(
            '/products/' . self::PRODUCT_ID,
            [
                'extraProperties' => [
                    self::MODULE_NAME => [
                        'does_not_exist' => 'x',
                    ],
                ],
            ],
            [self::PRODUCT_WRITE]
        );

        $this->assertArrayHasKey('extraProperties', $patchedProduct);
        if (array_key_exists(self::MODULE_NAME, $patchedProduct['extraProperties'])) {
            $this->assertArrayNotHasKey('does_not_exist', $patchedProduct['extraProperties'][self::MODULE_NAME]);
        }
    }

    /**
     * Asserts the response carries the dedicated extraProperties sub-object keyed by the module name.
     *
     * @param array<string, mixed> $response
     */
    private function assertModuleExtraProperties(array $response): void
    {
        $this->assertArrayHasKey('extraProperties', $response);
        $this->assertIsArray($response['extraProperties']);
        $this->assertArrayHasKey(self::MODULE_NAME, $response['extraProperties']);
        $this->assertIsArray($response['extraProperties'][self::MODULE_NAME]);
    }

    /**
     * Returns a real, existing customer id.
     *
     * The customer API does not expose a GET collection, so rather than assume a fixture id we create a
     * customer through the API and reuse its id. defaultGroupId/groupIds 3 (registered customers) and
     * genderId 1 match the standard fixtures and are the same values used by the customer endpoint tests.
     */
    private function getExistingCustomerId(): int
    {
        $customer = $this->createItem(
            '/customers',
            [
                'firstName' => 'Extra',
                'lastName' => 'Property',
                'email' => 'extra.property.api@example.com',
                'password' => 'TestPassword123!',
                'defaultGroupId' => 3,
                'groupIds' => [3],
                'genderId' => 1,
                'enabled' => true,
                'partnerOffersSubscribed' => false,
                'birthday' => '1990-01-15',
                'guest' => false,
            ],
            [self::CUSTOMER_WRITE]
        );
        $this->assertArrayHasKey('customerId', $customer);

        return (int) $customer['customerId'];
    }
}
