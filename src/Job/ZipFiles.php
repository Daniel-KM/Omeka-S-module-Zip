<?php declare(strict_types=1);

namespace Zip\Job;

use Doctrine\Common\Collections\Criteria;
use Omeka\Entity\Job;
use Omeka\Job\AbstractJob;
use ZipArchive;

class ZipFiles extends AbstractJob
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

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

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $this->api = $services->get('Omeka\ApiManager');
        $this->connection = $services->get('Omeka\Connection');
        $this->entityManager = $services->get('Omeka\EntityManager');

        $zipItems = $this->getArg('zip_items') ?: null;
        $zipBy = $this->getArg('zip_by', []);
        $zipBy = array_filter(array_map('intval', $zipBy));

        if (!$zipItems && !count($zipBy)) {
            $this->logger->warn(
                'No zip to create.' // @translate
            );
            return;
        }

        if ($zipItems) {
            $this->logger->notice(
                'Zipping files by items ({type}).', // @translate
                ['type' => $zipItems]
            );
            $total = $this->zipFilesByItemForType($zipItems);
            $this->logger->notice(
                'Zipping "{type}" files by item ended: {total} files created in folder {directory}.', // @translate
                ['type' => $zipItems, 'total' => $total, 'directory' => basename($this->basePath) . '/zip_items']
            );
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

    protected function zipFilesByItemForType(string $type): int
    {
        // Without advanced search, api cannot search items with files.
        // $itemIds = $this->api->search('items', [], ['returnScalar' => 'id'])->getContent();
        // $itemIds = array_keys($itemIds);

        // TODO Fetch media ids and filenames directly one time?
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('item.id')
            ->distinct()
            ->from('item')
            ->innerJoin('item', 'media', 'media', 'media.item_id = item.id')
            ->where($qb->expr()->eq($type === 'original' ? 'has_original' : 'has_thumbnails', 1))
            ->orderBy('item.id', 'ASC');
        $itemIds = $this->connection->executeQuery($qb)->fetchFirstColumn();
         if (!$itemIds) {
            return 0;
        }

        $path = $this->basePath . '/zip_items';
        if (file_exists($path) && !is_dir($path)) {
            $this->job->setStatus(Job::STATUS_ERROR);
            $this->logger->err(
                'The file path {directory} is not a directory.', // @translate
                ['directory' => basename($this->basePath) . '/zip_items']
            );
            ùf();
            return 0;
        } elseif (!file_exists($path)) {
            $result = @mkdir($path, 0775, true);
            if (!$result) {
                $this->job->setStatus(Job::STATUS_ERROR);
                $this->logger->err(
                    'The directory {directory} for zip items cannot be created.', // @translate
                    ['directory' => basename($this->basePath) . '/zip_items']
                );
                return 0;
            }
        } elseif (!is_writeable($path)) {
            ùf();
            $this->job->setStatus(Job::STATUS_ERROR);
            $this->logger->err(
                'The directory {directory} for zip items is not writeable.', // @translate
                ['directory' => basename($this->basePath) . '/zip_items']
            );
            return 0;
        }

        // $basePathFileLength = mb_strlen($this->basePath . '/' . $type . '/');

        $filter = $type === 'original' ? 'hasOriginal' : 'hasThumbnails';
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq($filter, 1))
            ->orderBy(['position' => Criteria::ASC]);

        /**
         * @var \Omeka\Entity\Item $item
         * @var \Doctrine\Common\Collections\Collection $medias
         * @var \Omeka\Entity\Media $media
         */
        $index = 0;
        foreach ($itemIds as $itemId) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Zipping "{type}" files by item stopped at {count]/{total}.', // @translate
                    ['type' => $type, 'count' => $index, 'total' => count($itemIds)]
                );
                return 0;
            }
            $item = $this->api->read('items', $itemId, [], ['responseContent' => 'resource'])->getContent();
            $medias = $item->getMedia()->matching($criteria);
            if ($medias->isEmpty()) {
                continue;
            }
            $filepathZip = $this->basePath .'/zip_items/' . $itemId . '.' . $type . '.zip';
            if (file_exists($filepathZip)) {
                @unlink($filepathZip);
            }
            $zip = new ZipArchive();
            $zip->open($filepathZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $comment = <<<INI
                item = {$item->getId()}
                type = $type
                INI;
            $zip->setArchiveComment($comment);

            $countFiles = 0;
            $unreadables = [];
            foreach ($medias as $media) {
                $storageId = $media->getStorageId();
                $extension = $media->getExtension();
                $dotExtension = $type === 'original' ? ($extension ? ".$extension" : '') : '.jpg';
                $realFilename = $storageId . $dotExtension;
                $filepath = $this->basePath . '/' . $type . '/' . $realFilename;
                if (!file_exists($filepath) || !is_readable($filepath)) {
                    $unreadables[] = $media->getId();
                    continue;
                }
                // TODO Add an option to set the source name instead of the index (that is better than the hashed filename anyway) or the ArchiveRepertory name.
                // Warning: if two files have the same position, it will be overridden, but it should not be possible.
                $relativePath = sprintf('%1$d/%2$s/%3$04d%4$s', $itemId, $type, $media->getPosition(), $dotExtension);
                $zip->addFile($filepath, $relativePath);
                ++$countFiles;
            }
            if ($countFiles) {
                $zip->close();
            } elseif (file_exists($filepathZip)) {
                @unlink($filepathZip);
            }
            if ($unreadables) {
                $this->logger->warn(
                    'For item #{item_id}, {count} "{type}" files are missing: #{media_ids}', // @translate
                    ['item_id' => $itemId, 'count' => count($unreadables), 'type' => $type, 'media_ids' => implode(', #', $unreadables)]
                );
            }
            ++$index;
        }

        return $index;
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
