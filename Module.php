<?php declare(strict_types=1);

/*
 * Copyright Daniel Berthereau, 2021
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Zip;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    public const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        if (!$this->checkDestinationDir($basePath . '/zip')) {
            $message = new Message(
                'The directory "%s" is not writeable.', // @translate
                $basePath . '/zip'
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
    }

    public function handleMainSettings(Event $event): void
    {
        parent::handleMainSettings($event);

        // Params are already checked.
        $services = $this->getServiceLocator();
        $params = $services->get('ViewHelperManager')->get('params')->fromPost();
        $zipJob = !empty($params['zip']['zip_job']);
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
            $message = new Message(
                'Another job is running for the same process (job %1$s#%2$d%3$s, %4$slogs%3$s).', // @translate
                sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId]))
                ),
                $jobId,
                '</a>',
                sprintf(
                    '<a href="%s">',
                    // Check if module Log is enabled (avoid issue when disabled).
                    htmlspecialchars(class_exists(\Log\Stdlib\PsrMessage::class)
                        ? $urlHelper('admin/log/default', [], ['query' => ['job_id' => $jobId]])
                        : $urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId, 'action' => 'log'])
                ))
            );
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
            return;
        }

        $zipBy = [
            'original' => 0,
            'large' => 0,
            'medium' => 0,
            'square' => 0,
            'asset' => 0,
        ];
        foreach (array_keys($zipBy) as $type) {
            $zipBy[$type] = $settings->get('zip_' . $type, 0);
        }
        $zipBy = array_filter(array_map('intval', $zipBy));
        if (!count($zipBy)) {
            $message = new Message('No zip to create.'); // @translate
            $message->setEscapeHtml(false);
            $messenger->addWarning($message);
            return;
        }

        $zipList = (bool) $settings->get('zip_list_zip');

        // Run the job.
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\Zip\Job\ZipFiles::class, [
            'zipBy' => $zipBy,
            'zipList' => $zipList,
        ]);
        $jobId = $job->getId();

        $message = new Message(
            'Creating zips in background (job %1$s#%2$d%3$s, %4$slogs%3$s).', // @translate
            sprintf(
                '<a href="%s">',
                htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId]))
            ),
            $jobId,
            '</a>',
            sprintf(
                '<a href="%s">',
                // Check if module Log is enabled (avoid issue when disabled).
                htmlspecialchars(class_exists(\Log\Stdlib\PsrMessage::class)
                    ? $urlHelper('admin/log/default', [], ['query' => ['job_id' => $jobId]])
                    : $urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId, 'action' => 'log'])
            ))
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

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(new Message(
                    'The directory "%s" is not writeable.', // @translate
                    $dirPath
                ));
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(new Message(
                'The directory "%s" is not writeable: %s.', // @translate
                $dirPath, error_get_last()['message']
            ));
            return null;
        }
        return $dirPath;
    }
}
