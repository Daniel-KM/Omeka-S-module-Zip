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

        // The extension zip is required by composer.json, but a module
        // installed from a release zip does not run composer, so check it here
        // to fail with a message instead of a fatal error.
        if (!class_exists('ZipArchive')) {
            $this->logger->err(
                'The php extension "zip" is required to create the archives.' // @translate
            );
            return;
        }

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
                'Zipping files by items ({format}).', // @translate
                ['format' => $zipItems]
            );
            $total = $this->zipFilesByItemForType($zipItems);
            $this->logger->notice(
                'Zipping "{format}" files by item ended: {total} files created in folder {directory}.', // @translate
                ['format' => $zipItems, 'total' => $total, 'directory' => basename($this->basePath) . '/zip_items']
            );
        }

        foreach ($zipBy as $format => $by) {
            $this->logger->notice(
                'Zipping "{format}" files by {count}.', // @translate
                ['format' => $format, 'count' => $by]
            );
            $total = $this->zipFilesForType($format, (int) $by);
            $this->logger->notice(
                'Zipping "{format}" files by {count} ended: {total} files created in folder files/zip.', // @translate
                ['format' => $format, 'count' => $by, 'total' => $total]
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

    protected function zipFilesByItemForType(string $format): int
    {
        // Without advanced search, api cannot search items with files.
        // $itemIds = $this->api->search('items', [], ['returnScalar' => 'id'])->getContent();
        // $itemIds = array_keys($itemIds);

        // TODO Fetch media ids and filenames directly one time?
        $column = $format === 'original' ? 'has_original' : 'has_thumbnails';
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('item.id')
            ->distinct()
            ->from('item')
            ->innerJoin('item', 'media', 'media', 'media.item_id = item.id')
            ->where($qb->expr()->eq('media.' . $column, 1))
            ->orderBy('item.id', 'ASC');
        $itemIds = $this->connection->executeQuery($qb->getSQL())->fetchFirstColumn();

        if ($this->getArg('zip_items_include_digital_objects') && $this->hasDigitalObjectEntity()) {
            $sql = <<<SQL
                SELECT DISTINCT v.resource_id
                FROM value v
                INNER JOIN digital_object do ON do.id = v.value_resource_id
                INNER JOIN item i ON i.id = v.resource_id
                WHERE do.{$column} = 1
                    AND do.storage_id IS NOT NULL
                SQL;
            try {
                $itemsWithDo = $this->connection->executeQuery($sql)->fetchFirstColumn();
                $itemIds = array_values(array_unique(array_merge($itemIds, $itemsWithDo)));
                sort($itemIds);
            } catch (\Throwable $e) {
                // Ignore: table structure may differ; log and continue.
                $this->logger->warn(
                    'Unable to collect items with digital objects: {message}', // @translate
                    ['message' => $e->getMessage()]
                );
            }
        }

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
            $this->job->setStatus(Job::STATUS_ERROR);
            $this->logger->err(
                'The directory {directory} for zip items is not writeable.', // @translate
                ['directory' => basename($this->basePath) . '/zip_items']
            );
            return 0;
        }

        // $basePathFileLength = mb_strlen($this->basePath . '/' . $format . '/');

        $filter = $format === 'original' ? 'hasOriginal' : 'hasThumbnails';
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq($filter, 1))
            ->orderBy(['position' => Criteria::ASC]);

        $includeDigitalObjects = (bool) $this->getArg('zip_items_include_digital_objects')
            && $this->hasDigitalObjectEntity();

        /**
         * @var \Omeka\Entity\Item $item
         * @var \Doctrine\Common\Collections\Collection $medias
         * @var \Omeka\Entity\Media $media
         */
        $index = 0;
        foreach ($itemIds as $itemId) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Zipping "{format}" files by item stopped at {count]/{total}.', // @translate
                    ['format' => $format, 'count' => $index, 'total' => count($itemIds)]
                );
                return 0;
            }
            $item = $this->api->read('items', $itemId, [], ['responseContent' => 'resource'])->getContent();
            $medias = $item->getMedia()->matching($criteria);
            if ($medias->isEmpty() && !$includeDigitalObjects) {
                continue;
            }
            $filepathZip = $this->basePath . '/zip_items/' . $itemId . '. ' . $format . '.zip';
            if (file_exists($filepathZip)) {
                @unlink($filepathZip);
            }
            $zip = new ZipArchive();
            $zip->open($filepathZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $comment = <<<INI
                item = {$item->getId()}
                type = $format
                INI;
            $zip->setArchiveComment($comment);

            $countFiles = 0;
            $unreadables = [];
            foreach ($medias as $media) {
                $storageId = $media->getStorageId();
                $extension = $media->getExtension();
                $dotExtension = $format === 'original' ? ($extension ? ".$extension" : '') : '.jpg';
                $realFilename = $storageId . $dotExtension;
                $filepath = $this->basePath . '/' . $format . '/' . $realFilename;
                if (!file_exists($filepath) || !is_readable($filepath)) {
                    $unreadables[] = $media->getId();
                    continue;
                }
                // TODO Add an option to set the source name instead of the index (that is better than the hashed filename anyway) or the ArchiveRepertory name.
                // Warning: if two files have the same position, it will be overridden, but it should not be possible.
                $relativePath = sprintf('%1$d/%2$s/%3$04d%4$s', $itemId, $format, $media->getPosition(), $dotExtension);
                $zip->addFile($filepath, $relativePath);
                ++$countFiles;
            }
            if ($includeDigitalObjects) {
                $countFiles += $this->addDigitalObjectsToZip($zip, $itemId, $format, $unreadables);
            }

            if ($countFiles) {
                $zip->close();
            } elseif (file_exists($filepathZip)) {
                @unlink($filepathZip);
            }
            if ($unreadables) {
                $this->logger->warn(
                    'For item #{item_id}, {count} "{format}" files are missing: #{media_ids}', // @translate
                    ['item_id' => $itemId, 'count' => count($unreadables), 'format' => $format, 'media_ids' => implode(', #', $unreadables)]
                );
            }
            ++$index;
        }

        return $index;
    }

    /**
     * Check that the DigitalObject entity/table is available.
     */
    protected function hasDigitalObjectEntity(): bool
    {
        try {
            return (bool) $this->connection->executeQuery(
                'SHOW TABLES LIKE "digital_object"'
            )->fetchOne();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Add digital objects referenced by an item (via property values) to the
     * per-item zip. Returns the number of files added.
     */
    protected function addDigitalObjectsToZip(
        ZipArchive $zip,
        int $itemId,
        string $format,
        array &$unreadables
    ): int {
        $column = $format === 'original' ? 'has_original' : 'has_thumbnails';
        $sql = <<<SQL
            SELECT DISTINCT do.id, do.storage_id, do.extension
            FROM digital_object do
            INNER JOIN value v ON v.value_resource_id = do.id
            WHERE v.resource_id = :item_id
                AND do.{$column} = 1
                AND do.storage_id IS NOT NULL
            ORDER BY do.id ASC
            SQL;
        try {
            $rows = $this->connection->executeQuery($sql, ['item_id' => $itemId])->fetchAllAssociative();
        } catch (\Throwable $e) {
            return 0;
        }
        $count = 0;
        $index = 0;
        foreach ($rows as $row) {
            $dotExtension = $format === 'original'
                ? ($row['extension'] ? '.' . $row['extension'] : '')
                : '.jpg';
            $filepath = $this->basePath . '/' . $format . '/' . $row['storage_id'] . $dotExtension;
            if (!file_exists($filepath) || !is_readable($filepath)) {
                $unreadables[] = 'do#' . $row['id'];
                continue;
            }
            $relativePath = sprintf(
                '%1$d/%2$s/do_%3$04d_%4$d%5$s',
                $itemId,
                $format,
                ++$index,
                $row['id'],
                $dotExtension
            );
            $zip->addFile($filepath, $relativePath);
            ++$count;
        }
        return $count;
    }

    protected function zipFilesForType(string $format, int $by): int
    {
        // Get the full list of files inside the specified directory.
        $path = $this->basePath . '/' . $format;
        $filesList = $this->listFilesInFolder($path, true);
        $totalFiles = count($filesList);

        // Batch zip the resources in chunks.
        $filesZip = [];
        $index = 0;
        $indexFile = 1;
        $baseFilename = $this->basePath . '/zip/tmp/' . $format . '_';
        $finalBaseFilename = $this->basePath . '/zip/' . $format . '_';
        $totalChunks = (int) ceil(count($filesList) / $by);

        @mkdir(dirname($baseFilename), 0775, true);

        foreach (array_chunk($filesList, $by) as $files) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Zipping "{format}" files stopped.', // @translate
                    ['format' => $format]
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
                $relativePath = $format . '/' . mb_substr($file, mb_strlen($path) + 1);
                $zip->addFile($file, $relativePath);
                ++$indexFile;
            }

            $zip->close();
        }

        // Remove all old zip files for this type.
        $removeList = glob($finalBaseFilename . '*.zip');
        $removeList[] = $this->basePath . '/zip/zipfiles.txt';
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
        $list = implode("\n", array_map(fn ($v) => mb_substr($v, $length), glob($this->basePath . '/zip/*.zip')));
        file_put_contents($this->basePath . '/zip/zipfiles.txt', $list);
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
