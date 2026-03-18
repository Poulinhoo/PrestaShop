<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Pricing\ValueObject;

final class PriceModification
{
    public function __construct(
        private readonly string $callerClass,
        private readonly int $callerLine,
        private readonly string $property,
        private readonly string $previousValue,
        private readonly string $newValue,
    ) {
    }

    public function getCallerClass(): string
    {
        return $this->callerClass;
    }

    public function getCallerLine(): int
    {
        return $this->callerLine;
    }

    public function getProperty(): string
    {
        return $this->property;
    }

    public function getPreviousValue(): string
    {
        return $this->previousValue;
    }

    public function getNewValue(): string
    {
        return $this->newValue;
    }
}
