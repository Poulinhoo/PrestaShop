<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Exception\ExtraPropertyConstraintException;
use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\ValueObject\ExtraPropertyDefinitionId;

/**
 * Toggles one of the display boolean flags on a core extra property definition.
 *
 * The $displayField value must be one of the ALLOWED_FIELDS constants.
 * Validation is performed in the constructor to prevent invalid commands from reaching the bus.
 * The handler reads the current value and inverts it.
 */
class ToggleExtraPropertyDefinitionDisplayCommand
{
    /**
     * Allowed field names that can be toggled.
     */
    public const DISPLAY_API = 'display_api';
    public const DISPLAY_FRONT = 'display_front';

    /**
     * The complete set of toggleable fields.
     */
    public const ALLOWED_FIELDS = [
        self::DISPLAY_API,
        self::DISPLAY_FRONT,
    ];

    /**
     * @var ExtraPropertyDefinitionId
     */
    protected ExtraPropertyDefinitionId $id;

    /**
     * @param int $id
     * @param string $displayField One of DISPLAY_API, DISPLAY_FRONT
     *
     * @throws ExtraPropertyConstraintException When $displayField is not in ALLOWED_FIELDS
     */
    public function __construct(int $id, protected readonly string $displayField)
    {
        $this->id = new ExtraPropertyDefinitionId($id);

        if (!in_array($displayField, self::ALLOWED_FIELDS, true)) {
            throw new ExtraPropertyConstraintException(
                sprintf(
                    'Invalid display field "%s". Allowed: %s.',
                    $displayField,
                    implode(', ', self::ALLOWED_FIELDS)
                )
            );
        }
    }

    /**
     * @return ExtraPropertyDefinitionId
     */
    public function getId(): ExtraPropertyDefinitionId
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getDisplayField(): string
    {
        return $this->displayField;
    }
}
