<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\Domain\CartRule\ValueObject;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\CartRuleConstraintException;
use PrestaShop\PrestaShop\Core\Domain\CartRule\ValueObject\DiscountApplicationType;

class DiscountApplicationTypeTest extends TestCase
{
    /**
     * @dataProvider getDataToBuildDiscountApplicationType
     *
     * @param string $type
     * @param int|null $productId
     *
     * @return void
     */
    public function testItBuildsDiscountApplicationType(string $type, ?int $productId = null): void
    {
        $discountApplicationType = new DiscountApplicationType($type, $productId);
        Assert::assertSame($type, $discountApplicationType->getType());
        if (null === $productId) {
            Assert::assertNull($discountApplicationType->getProductId());
        } else {
            Assert::assertSame($productId, $discountApplicationType->getProductId()->getValue());
        }
    }

    /**
     * @dataProvider getInvalidData
     *
     * @param string $type
     * @param int|null $productId
     * @param string $expectedException
     * @param int $expectedCode
     *
     * @return void
     */
    public function testItThrowsExceptionWhenInvalidDataIsProvided(
        string $type,
        ?int $productId,
        string $expectedException,
        int $expectedCode
    ): void {
        $this->expectException($expectedException);
        $this->expectExceptionCode($expectedCode);

        new DiscountApplicationType($type, $productId);
    }

    public function getDataToBuildDiscountApplicationType(): iterable
    {
        yield [
            DiscountApplicationType::ORDER_WITHOUT_SHIPPING,
        ];

        yield [
            DiscountApplicationType::SELECTED_PRODUCTS,
        ];

        yield [
            DiscountApplicationType::CHEAPEST_PRODUCT,
        ];

        yield [
            DiscountApplicationType::SPECIFIC_PRODUCT,
            12,
        ];
    }

    public function getInvalidData(): iterable
    {
        yield [
            'random',
            null,
            CartRuleConstraintException::class,
            CartRuleConstraintException::INVALID_DISCOUNT_APPLICATION_TYPE,
        ];

        yield [
            DiscountApplicationType::SPECIFIC_PRODUCT,
            null,
            CartRuleConstraintException::class,
            CartRuleConstraintException::MISSING_DISCOUNT_APPLICATION_PRODUCT,
        ];
    }
}
