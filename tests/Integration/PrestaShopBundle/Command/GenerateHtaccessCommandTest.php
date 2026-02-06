<?php

namespace Tests\Integration\PrestaShopBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateHtaccessCommandTest extends KernelTestCase
{
    public function setUp(): void
    {
        // Load PrestaShop environment constants (defines like _PS_IMG_)
        require_once dirname(__DIR__, 4) . '/config/config.inc.php';

        parent::setUp();
        self::bootKernel();
    }

    public function testGenerateHtaccessFile()
    {
        $application = new Application(static::$kernel);
        $command = $application->find('prestashop:htaccess:generate');

        $this->assertNotNull($command, 'Command prestashop:htaccess:generate not found');

        $tester = new CommandTester($command);
        $tester->execute([
            'command' => $command->getName(),
        ]);
        $display = $tester->getDisplay();
        fwrite(STDOUT, $display);

        // Check exit code
        $this->assertEquals(0, $tester->getStatusCode(), 'Command did not exit successfully');

        // Check that the command output indicates success
        $this->assertStringContainsString('.htaccess successfully generated', $display);
        $this->assertStringContainsString(_PS_ROOT_DIR_ . '/.htaccess', $display);
    }


}
