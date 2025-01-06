<?php declare(strict_types=1);

namespace Zip\Job;

use Omeka\Job\AbstractJob;
use ZipArchive;

class ZipFiles extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $basePath;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('zip/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->basePath = $this->config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $zipBy = $this->getArg('zipBy', []);
        $zipBy = array_filter(array_map('intval', $zipBy));
        if (!count($zipBy)) {
            $this->logger->warn(
                'No zip to create.' // @translate
            );
            return;
        }

        foreach ($zipBy as $type => $by) {
            $this->logger->notice(
                'Zipping "{type}" files by {count}.', // @translate
                ['type' => $type, 'count' => $by]
            );
            $total = $this->zipFilesForType($type, (int) $by);
            $this->logger->notice(
                'Zipping "{type}" files by {count} ended: {total} files created in folder files/zip.', // @translate
                ['type' => $type, 'count' => $by, 'total' => $total]
            );
        }

        if ($this->getArg('zipList')) {
            $this->addZipList();
            $this->logger->info(
                'Added zip list in files/zip/ziplist.txt.' // @translate
            );
        }

        $this->logger->notice(
            'Zipping ended.' // @translate
        );
    }

    protected function zipFilesForType(string $type, int $by): int
    {
        // Get the full list of files inside the specified directory.
        $path = $this->basePath . '/' . $type;
        $filesList= $this->listFilesInFolder($path, true);
        $totalFiles = count($filesList);

        // Batch zip the resources in chunks.
        $filesZip = [];
        $index = 0;
        $indexFile = 1;
        $baseFilename = $this->basePath .'/zip/tmp/' . $type . '_';
        $finalBaseFilename = $this->basePath .'/zip/' . $type . '_';
        $totalChunks = (int) ceil(count($filesList) / $by);

        @mkdir(dirname($baseFilename), 0775, true);

        foreach (array_chunk($filesList, $by) as $files) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Zipping "{type}" files stopped.', // @translate
                    ['type' => $type]
                );
                foreach ($filesZip as $file) {
                    @unlink($file);
                }
                return 0;
            }

            $filepath = $baseFilename . sprintf('%04d', ++$index) . '.zip';
            $filesZip[] = $filepath;

            @unlink($filepath);
            $zip = new ZipArchive();
            $zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $comment = <<<INI
                chunk = $index
                total_chunks = $totalChunks
                total_files = $totalFiles
                INI;
            $zip->setArchiveComment($comment);

            foreach ($files as $file) {
                $relativePath = $type . '/' . mb_substr($file, mb_strlen($path) + 1);
                $zip->addFile($file, $relativePath);
                ++$indexFile;
            }

            $zip->close();
        }

        // Remove all old zip files for this type.
        $removeList = glob($finalBaseFilename . '*.zip');
        $removeList[] = $this->basePath .'/zip/zipfiles.txt';
        foreach ($removeList as $file) {
            @unlink($file);
        }

        // Move temp zip files to final destination.
        foreach ($filesZip as $file) {
            rename($file, str_replace($baseFilename, $finalBaseFilename, $file));
        }

        return count($filesZip);
    }

    protected function addZipList(): void
    {
        $length = mb_strlen($this->basePath) + 1;
        $list = implode("\n", array_map(function($v) use ($length) {
            return mb_substr($v, $length);
        }, glob($this->basePath .'/zip/*.zip')));
        file_put_contents($this->basePath .'/zip/zipfiles.txt', $list);
    }

    /**
     * Get a relative or full path of files filtered by extensions recursively
     * in a directory.
     */
    protected function listFilesInFolder(string $dir, bool $absolute = false, array $extensions = []): array
    {
        if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $regex = empty($extensions)
            ? '/^.+$/i'
            : '/^.+\.(' . implode('|', $extensions) . ')$/i';
        $directory = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, $regex, \RecursiveRegexIterator::GET_MATCH);
        $files = [];
        if ($absolute) {
            foreach ($regex as $file) {
                $files[] = reset($file);
            }
        } else {
            $dirLength = mb_strlen($dir) + 1;
            foreach ($regex as $file) {
                $files[] = mb_substr(reset($file), $dirLength);
            }
        }
        sort($files);
        return $files;
    }
}
