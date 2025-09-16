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

namespace PrestaShopBundle\Command;

use Tools;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to generate the .htaccess file
 *
 * Usage:
 *   bin/console prestashop:htaccess:generate [--force]
 */

class GenerateHtaccessCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('prestashop:htaccess:generate')
            ->setDescription('Generate the .htaccess file')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite even if file exists');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = $input->getOption('force');
        $path = _PS_ROOT_DIR_ . '/.htaccess';

        if (file_exists($path) && !$force) {
            $output->writeln('<comment>.htaccess already exists. Use --force to overwrite.</comment>');
            return Command::FAILURE;
        }

        try {
            Tools::generateHtaccess(null, null, null, $force);
            $output->writeln('<info>.htaccess successfully generated at ' . $path . '</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to generate .htaccess: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

