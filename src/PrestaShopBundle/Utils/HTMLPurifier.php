<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShopBundle\Utils;

use HTMLPurifier_Config;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

class HTMLPurifier
{
    /**
     * @var \HTMLPurifier
     */
    private $instance;

    public function __construct(
        private readonly Filesystem $filesystem,
        // kernel.cache_dir (var/cache/dev/admin) is excluded by LegacyCacheClearer; using it avoids a race condition where var/cache/dev/purifier is deleted between __construct and purify().
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
    ) {
        $config = HTMLPurifier_Config::createDefault();
        // We must keep IDs that are by JS used to target element
        $config->set('Attr.EnableID', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        $serializerPath = $this->cacheDir . DIRECTORY_SEPARATOR . 'purifier';
        $this->filesystem->mkdir($serializerPath);

        $config->set('Cache.SerializerPath', $serializerPath);

        $purifier = new \HTMLPurifier($config);
        $this->instance = $purifier;
    }

    /**
     * Filters an HTML snippet/document to be XSS-free and standards-compliant.
     *
     * @param string $html String of HTML to purify
     *
     * @return string Purified HTML
     */
    public function purify($html)
    {
        return $this->instance->purify($html);
    }
}
