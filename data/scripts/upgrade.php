<?php declare(strict_types=1);

namespace Zip;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$config = $services->get('Config');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
// $translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.94')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.94'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare($oldVersion, '3.4.0.3', '<')) {
    $settings->delete('zip_job');

    $message = new PsrMessage(
        'It is now possible to store resources separately with files.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.3', '<')) {
    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    if (!$this->checkDestinationDir($basePath . '/zip_items')) {
        $message = new PsrMessage(
            'The directory "{directory}" is not writeable.', // @translate
            ['directory' => $basePath . '/zip_items']
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }

    $message = new PsrMessage(
        'It is now possible to store zip by item.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.6', '<')) {
    // The zip files contain private files, but the directory "files/zip" is
    // public, because it is shared with the derivatives of the module
    // DerivativeMedia. So move them into a protected directory.
    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    $dir = $basePath . '/' . $this::DIR;
    $dirItems = $basePath . '/' . $this::DIR_ITEMS;
    if (!$this->checkDestinationDir($dir, true) || !$this->checkDestinationDir($dirItems)) {
        $message = new PsrMessage(
            'The directory "{directory}" is not writeable.', // @translate
            ['directory' => $dir]
        );
        $messenger->addError($message);
        throw new ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }

    $failed = [];
    $move = function (string $file, string $destination) use (&$failed): void {
        if (!@rename($file, $destination)) {
            $failed[] = $file;
        }
    };

    // Only the zip files of this module are moved: the ones of the module
    // DerivativeMedia are named by item id.
    $oldDir = $basePath . '/zip';
    if (is_dir($oldDir)) {
        foreach (glob($oldDir . '/*') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('~^(?:original|large|medium|square|asset)_\d{4,}\.zip$~', $name)) {
                $move($file, $dir . '/' . $name);
            } elseif ($name === 'zipfiles.txt') {
                @unlink($file);
            }
        }
        // Temporary files of an interrupted job.
        foreach (glob($oldDir . '/tmp/*.zip') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($oldDir . '/tmp');
    }

    $oldDirItems = $basePath . '/zip_items';
    if (is_dir($oldDirItems)) {
        // Fix the name of the zip files created since version 3.4.4 too
        // ("1. original.zip").
        foreach (glob($oldDirItems . '/*.zip') ?: [] as $file) {
            $move($file, $dirItems . '/' . strtr(basename($file), ['. ' => '.']));
        }
    }

    // Remove the old directories when empty (rmdir fails else).
    foreach ([$oldDirItems, $oldDir] as $path) {
        if (is_dir($path) && !array_diff(scandir($path) ?: [], ['.', '..'])) {
            @rmdir($path);
        }
    }

    // Rebuild the list of zip files with the new paths.
    $zips = array_merge(glob($dir . '/*.zip') ?: [], glob($dirItems . '/*.zip') ?: []);
    if ($zips) {
        $length = mb_strlen($basePath) + 1;
        file_put_contents($dir . '/zipfiles.txt', implode("\n", array_map(fn ($v) => mb_substr($v, $length), $zips)) . "\n");
    }

    if ($failed) {
        $message = new PsrMessage(
            'Some zip files cannot be moved to the directory "{directory}": {list}', // @translate
            ['directory' => $dir, 'list' => implode(', ', $failed)]
        );
        $messenger->addWarning($message);
    }

    $message = new PsrMessage(
        'The zip files are now stored in the protected directory "{directory}", because they may contain private files.', // @translate
        ['directory' => basename($basePath) . '/' . $this::DIR]
    );
    $messenger->addSuccess($message);
}
