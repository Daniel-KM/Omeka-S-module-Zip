<?php declare(strict_types=1);

namespace Zip;

// Common may be installed but not registered in autoloader, in particular
// during upgrade. So dynamically register all classes of the module.
if (!defined('COMMON_PSR4_FALLBACK')) {
    foreach ([
        OMEKA_PATH . '/modules/Common/src',
        OMEKA_PATH . '/composer-addons/modules/Common/src',
        dirname(__DIR__) . '/Common/src',
    ] as $commonSrc) {
        if (file_exists($commonSrc . '/TraitModule.php')) {
            define('COMMON_PSR4_FALLBACK', $commonSrc);
            spl_autoload_register(static function ($class): void {
                if (str_starts_with($class, 'Common\\')) {
                    $file = COMMON_PSR4_FALLBACK . '/' . strtr(substr($class, 7), '\\', '/') . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            break;
        }
    }
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * Zip.
 *
 * Create a zip of specified files.
 *
 * @copyright Daniel Berthereau, 2021-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    /**
     * Directory of the zip files, relative to the files directory.
     *
     * The zip files contain private files too, so the directory is protected
     * from a direct web access.
     */
    const DIR = 'zip_backup';

    const DIR_ITEMS = 'zip_backup/items';

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.94')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.94'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $errors = [];

        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // The zip files contain private files, so protect their directory.
        if (!$this->checkDestinationDir($basePath . '/' . self::DIR, true)) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/' . self::DIR]
            );
            $errors[] = (string) $message;
        }

        if (!$this->checkDestinationDir($basePath . '/' . self::DIR_ITEMS)) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/' . self::DIR_ITEMS]
            );
            $errors[] = (string) $message;
        }

        if ($errors) {
            throw new ModuleCannotInstallException(implode("\n", $errors));
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        // Add a job to upgrade structures once from v3.
        $sharedEventManager->attach(
            \EasyAdmin\Form\CheckAndFixForm::class,
            'form.add_elements',
            [$this, 'handleEasyAdminJobsForm']
        );
        $sharedEventManager->attach(
            \EasyAdmin\Controller\Admin\CheckAndFixController::class,
            'easyadmin.job',
            [$this, 'handleEasyAdminJobs']
        );
    }

    public function handleMainSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'settings');

        // Params are already checked.
        $services = $this->getServiceLocator();
        $params = $services->get('ViewHelperManager')->get('params')->fromPost();
        $zipJob = !empty($params['zip_job']);
        if ($zipJob) {
            $this->prepareZip();
        }
    }

    protected function prepareZip(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        // Check if a zip job is already running before running a new one.
        $jobId = $this->checkJob(\Zip\Job\ZipFiles::class);
        if ($jobId) {
            $message = new PsrMessage(
                'Another job is running for the same process (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId]))),
                    'job_id' => $jobId,
                    'link_end' => '</a>',
                    'link_log' => class_exists('Log\Module', false)
                        ? sprintf('<a href="%1$s">', htmlspecialchars($urlHelper('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $jobId]])))
                        : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $jobId]))),
                ]
            );
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
            return;
        }

        $zipItems = $settings->get('zip_items') ?: null;

        $zipBy = [
            'original' => 0,
            'large' => 0,
            'medium' => 0,
            'square' => 0,
            'asset' => 0,
        ];
        foreach (array_keys($zipBy) as $type) {
            $zipBy[$type] = (int) $settings->get('zip_' . $type, 0);
        }
        $zipBy = array_filter($zipBy);

        if (!$zipItems && !count($zipBy)) {
            $message = new PsrMessage('No zip to create.'); // @translate
            $messenger->addWarning($message);
            return;
        }

        $zipList = (bool) $settings->get('zip_list_zip');

        $args = [
            'zip_items' => $zipItems,
            'zip_by' => $zipBy,
            'zip_list' => $zipList,
            'zip_items_include_digital_objects' => (bool) $settings->get('zip_items_include_digital_objects'),
        ];

        // Run the job.
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\Zip\Job\ZipFiles::class, $args);
        $jobId = $job->getId();

        $message = new PsrMessage(
            'Creating zips in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId]))),
                'job_id' => $jobId,
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', htmlspecialchars($urlHelper('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                    : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    /**
     * Check if a job is running for a class and return the first running job id.
     */
    protected function checkJob(string $class): int
    {
        $sql = <<<SQL
            SELECT id, pid, status
            FROM job
            WHERE status IN ("starting", "stopping", "in_progress")
                AND class = :class
            ORDER BY id ASC;
            SQL;

        $connection = $this->getServiceLocator()->get('Omeka\EntityManager')->getConnection();
        $result = $connection->executeQuery($sql, ['class' => $class])->fetchAllAssociative();

        // Unselect processes without pid.
        foreach ($result as $id => $row) {
            // TODO The check of the pid works only with Linux.
            if (!$row['pid'] || !file_exists('/proc/' . $row['pid'])) {
                unset($result[$id]);
            }
        }

        if (count($result)) {
            reset($result);
            return key($result);
        }

        return 0;
    }

    public function handleEasyAdminJobsForm(Event $event): void
    {
        /**
         * @var \EasyAdmin\Form\CheckAndFixForm $form
         * @var \Laminas\Form\Element\Radio $process
         * @var \Laminas\Form\Fieldset $fieldset
         * @var \Zip\Form\SettingsFieldset $settingsFieldset
         */
        $form = $event->getTarget();
        $fieldset = $form->get('module_tasks');
        $process = $fieldset->get('process');
        $valueOptions = $process->getValueOptions();
        $valueOptions['zip_zip'] = 'Zip: Store items and files as zip'; // @translate
        $process->setValueOptions($valueOptions);

        if (method_exists($form, 'addTaskSubjects')) {
            $form->addTaskSubjects([
                'zip_zip' => [
                    'name' => 'Zip: Prepare zips of items and files', // @translate
                    'description' => 'Store items and their files as zip archives.', // @translate
                    'actions' => [
                        'zip_zip' => 'Store', // @translate
                    ],
                ],
            ]);
        }

        $settingsFieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(\Zip\Form\SettingsFieldset::class)
            ->setAttribute('id', 'zip_zip')
            ->setAttribute('class', 'zip_zip')
            ->setName('zip_zip')
            ->remove('zip_job');

        $settingsFieldset->get('zip_original')->setValue('0');
        $settingsFieldset->get('zip_large')->setValue('0');
        $settingsFieldset->get('zip_medium')->setValue('0');
        $settingsFieldset->get('zip_square')->setValue('0');
        $settingsFieldset->get('zip_asset')->setValue('0');

        $fieldset
            ->add($settingsFieldset);
    }

    public function handleEasyAdminJobs(Event $event): void
    {
        $process = $event->getParam('process');
        if ($process === 'zip_zip') {
            $params = $event->getParam('params');
            $args = $params['module_tasks']['zip_zip'] ?? [];
            $sourceArgs = $args;
            $zipItems = $args['zip_items'] ?? null;
            $zipBy = [
                'original' => 0,
                'large' => 0,
                'medium' => 0,
                'square' => 0,
                'asset' => 0,
            ];
            foreach (array_keys($zipBy) as $type) {
                $zipBy[$type] = (int) ($args['zip_' . $type] ?? 0);
            }
            $zipBy = array_filter($zipBy);
            $zipList = !empty($args['zip_list_zip']);
            $args = [
                'zip_items' => $zipItems,
                'zip_by' => $zipBy,
                'zip_list' => $zipList,
                'zip_items_include_digital_objects' => !empty($sourceArgs['zip_items_include_digital_objects']),
            ];
            $event->setParam('job', \Zip\Job\ZipFiles::class);
            $event->setParam('args', $args);
        }
    }
}
