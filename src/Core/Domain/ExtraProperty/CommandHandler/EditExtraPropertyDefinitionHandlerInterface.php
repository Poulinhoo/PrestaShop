<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core\Domain\ExtraProperty\CommandHandler;

use PrestaShop\PrestaShop\Core\Domain\ExtraProperty\Command\EditExtraPropertyDefinitionCommand;

/**
 * Handler contract for EditExtraPropertyDefinitionCommand.
 */
interface EditExtraPropertyDefinitionHandlerInterface
{
    /**
     * @param EditExtraPropertyDefinitionCommand $command
     */
    public function handle(EditExtraPropertyDefinitionCommand $command): void;
}
