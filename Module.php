<?php declare(strict_types=1);

namespace Zip;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Module\AbstractModule;

/**
 * AnalyticsSnippet
 *
 * Add a snippet, generally a javascript tracker, in public or admin pages, and
 * allows to track json and xml requests.
 *
 * @copyright Daniel Berthereau, 2021-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.65'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        if (!$this->checkDestinationDir($basePath . '/zip')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/zip']
            );
            throw new ModuleCannotInstallException((string) $message);
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

    protected function prepareZip()
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
                    'link_job' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId]))
                    ),
                    'job_id' => $jobId,
                    'link_end' => '</a>',
                    'link_log' => sprintf(
                        '<a href="%s">',
                        htmlspecialchars(class_exists(\Log\Module::class)
                            ? $urlHelper('admin/log/default', [], ['query' => ['job_id' => $jobId]])
                            : $urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId, 'action' => 'log']))
                        ),
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
        ];

        // Run the job.
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\Zip\Job\ZipFiles::class, $args);
        $jobId = $job->getId();

        $message = new PsrMessage(
            'Creating zips in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId]))
                    ),
                'job_id' => $jobId,
                'link_end' => '</a>',
                'link_log' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars(class_exists(\Log\Module::class)
                        ? $urlHelper('admin/log/default', [], ['query' => ['job_id' => $jobId]])
                        : $urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId, 'action' => 'log']))
                    ),
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
            ];
            $event->setParam('job', \Zip\Job\ZipFiles::class);
            $event->setParam('args', $args);
        }
    }
}
