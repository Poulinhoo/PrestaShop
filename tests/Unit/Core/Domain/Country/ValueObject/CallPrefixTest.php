<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Core\Domain\Country\ValueObject;

use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\Domain\Country\Exception\CountryConstraintException;
use PrestaShop\PrestaShop\Core\Domain\Country\ValueObject\CallPrefix;

class CallPrefixTest extends TestCase
{
    /**
     * @dataProvider validCallPrefixProvider
     */
    public function testItAcceptsDigitsOnlyValues(int|string $callPrefix, int $expectedValue): void
    {
        $this->assertSame($expectedValue, (new CallPrefix($callPrefix))->getValue());
    }

    /**
     * @return iterable<array{int|string, int}>
     */
    public static function validCallPrefixProvider(): iterable
    {
        yield 'single digit string' => ['0', 0];
        yield 'multiple digits string' => ['33', 33];
        yield 'leading zero string' => ['099', 99];
        yield 'integer value (API contract)' => [33, 33];
        yield 'zero integer' => [0, 0];
    }

    /**
     * @dataProvider invalidCallPrefixProvider
     */
    public function testItRejectsNonDigitValues(string $callPrefix): void
    {
        $this->expectException(CountryConstraintException::class);
        $this->expectExceptionCode(CountryConstraintException::INVALID_CALL_PREFIX);

        new CallPrefix($callPrefix);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidCallPrefixProvider(): iterable
    {
        yield 'leading plus sign' => ['+99'];
        yield 'leading minus sign' => ['-5'];
        yield 'trailing letter' => ['9a'];
        yield 'empty string' => [''];
        yield 'surrounding spaces' => [' 9 '];
        yield 'decimal value' => ['9.5'];
    }
}
