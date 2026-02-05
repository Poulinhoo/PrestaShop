<?php

namespace Tests\Integration\PrestaShopBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

class GenerateHtaccessCommandTest extends KernelTestCase
{
    /** @var Filesystem */
    private Filesystem $fileSystem;

    private $outputFile;

    public function setUp(): void
    {
        // Load PrestaShop environment constants (defines like _PS_IMG_)
        require_once dirname(__DIR__, 4) . '/config/config.inc.php';

        parent::setUp();
        $this->fileSystem = new Filesystem();
        self::bootKernel();

        // Temporary folder for output
        $this->outputFile = _PS_ROOT_DIR_ . '/.htaccess';
        if ($this->fileSystem->exists($this->outputFile)) {
            $this->fileSystem->remove($this->outputFile);
        }
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

        echo "\n=== Debug: locating generated .htaccess ===\n";
        system('find /var/www/html -type f -name ".htaccess" 2>/dev/null');
        system('find /tmp -type f -name ".htaccess" 2>/dev/null');
        system('find /var/www/html/var -type f -name ".htaccess" 2>/dev/null');
        echo "=== End ===\n";

        // Check exit code
        $this->assertEquals(0, $tester->getStatusCode(), 'Command did not exit successfully');

        // Check .htaccess file exists
        $this->assertTrue(file_exists($this->outputFile), '.htaccess file not generated');

        // Optionally check for expected content
        $content = file_get_contents($this->outputFile);
        $this->assertStringContainsStringIgnoringCase('RewriteEngine On', $content, 'Missing basic htaccess directives');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->fileSystem->exists($this->outputFile)) {
            $this->fileSystem->remove($this->outputFile);
        }
    }
}
