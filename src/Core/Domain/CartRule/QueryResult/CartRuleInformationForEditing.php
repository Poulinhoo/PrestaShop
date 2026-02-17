<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\CartRule\QueryResult;

class CartRuleInformationForEditing
{
    /**
     * @var array
     */
    private $localizedNames;

    /**
     * @var string
     */
    private $description;

    /**
     * @var string
     */
    private $code;

    /**
     * @var bool
     */
    private $highlight;

    /**
     * @var bool
     */
    private $partialUse;

    /**
     * @var int
     */
    private $priority;

    /**
     * @var bool
     */
    private $enabled;

    public function __construct(
        array $localizedNames,
        string $description,
        string $code,
        bool $highlight,
        bool $partialUse,
        int $priority,
        bool $enabled
    ) {
        $this->localizedNames = $localizedNames;
        $this->description = $description;
        $this->code = $code;
        $this->highlight = $highlight;
        $this->partialUse = $partialUse;
        $this->priority = $priority;
        $this->enabled = $enabled;
    }

    /**
     * @return array
     */
    public function getLocalizedNames(): array
    {
        return $this->localizedNames;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return bool
     */
    public function isHighlight(): bool
    {
        return $this->highlight;
    }

    /**
     * @return bool
     */
    public function isPartialUse(): bool
    {
        return $this->partialUse;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
