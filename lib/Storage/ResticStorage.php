<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Robin Windey <ro.windey@gmail.com>
 *
 * @author Robin Windey <ro.windey@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\ResticBrowser\Storage;

use Icewind\Streams\IteratorDirectory;
use OC\Files\Storage\Common;
use OCA\ResticBrowser\Service\IResticRepository;
use OCA\ResticBrowser\Service\ResticRepository;
use OCA\ResticBrowser\Util\CacheUtil;
use OCP\Files\StorageNotAvailableException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ITempManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

class ResticStorage extends Common {
    private const TYPE_FILE = 0;
    private const TYPE_DIR = 1;
    private const TYPE_UNKNOWN = 2;

    /** @var LoggerInterface */
    private $logger;
    /** @var ITempManager */
    private $tempManager;
    /** @var ICache */
    private $resultsCache;
    /** @var IResticRepository */
    private $resticRepository;

    public function __construct($params) {
        parent::__construct([
            'datadir' => 'non-existing-path',
        ]);
        $password = $params['password'];
        $path = $params['path'];
        $this->logger = Server::get(LoggerInterface::class);
        $this->tempManager = Server::get(ITempManager::class);
        $this->resultsCache = Server::get(ICacheFactory::class)->createLocal('restic_storage');
        $this->resticRepository = new ResticRepository($path, $password);
    }

    public function getId() {
        return 'restic::' . $this->resticRepository->getPath();
    }

    public function mkdir($path) {
        // Readonly
        return false;
    }

    public function rmdir($path) {
        // Readonly
        return false;
    }

    public function opendir($path) {
        $key = "opendir_$path";
        $files = CacheUtil::getCached($key, $this->resultsCache, function () use ($path) {
            if ($path === '' || $path === '/') {
                return $this->listSnapshots();
            }
            [$snapshotId, $snapshotSubPath] = $this->parsePath($path);
            $snapshotSubPathEscaped = str_replace('/', '\/', $snapshotSubPath);
            // Use a regex which matches the path as prefix and returns
            // the next path component as group-match
            $regexFilesInSubPath = "/$snapshotSubPathEscaped\/(.*?)(\/|$)/";
            $snapshotObjectInfos = $this->listFiles($snapshotId, $snapshotSubPath);
            foreach ($snapshotObjectInfos as $snapshotObjectInfo) {
                $snapshotFilePath = $snapshotObjectInfo['path'];
                $matchingFileOrDirectory = preg_match($regexFilesInSubPath, $snapshotFilePath, $subPathMatch) ? $subPathMatch[1] : null;
                if ($matchingFileOrDirectory === null) {
                    continue;
                }
                $files[] = $matchingFileOrDirectory;
            }
            return $files;
        });

        return IteratorDirectory::wrap($files);
    }

    public function test() {
        try {
            $this->resticRepository->snapshots();
        } catch (\Exception $e) {
            $this->logger->error("ResticStorage::test() failed: " . $e->getMessage(), ['exception' => $e]);
            return false;
        }
        return true;
    }

    public function is_dir($path) {
        $type = $this->getType($path);
        return $type === self::TYPE_DIR || $type === self::TYPE_UNKNOWN;
    }

    public function is_file($path) {
        return $this->getType($path) === self::TYPE_FILE;
    }

    public function stat($path) {
        // Root directory
        if ($path === '') {
            return $this->getDefaultStat();
        }

        [$snapshotId, $snapshotSubPath] = $this->parsePath($path);

        // Snapshot metadata
        if (empty($snapshotSubPath)) {
            $snapshots = $this->resticRepository->snapshots();
            if (!array_key_exists($snapshotId, $snapshots)) {
                return $this->getDefaultStat();
            }
            $snapshotMeta = $snapshots[$snapshotId];
            $snapshotTime = strtotime($snapshotMeta['time']);
            return [
                'size' => 0,
                'mtime' => $snapshotTime,
                'atime' => $snapshotTime
            ];
        }

        // Check if we cached the node info already
        $snapshotObjectInfo = $this->getCachedNodeInfo($snapshotId, $snapshotSubPath);

        // Read metadata of backed up file/folder
        if ($snapshotObjectInfo === null) {
            $snapshotObjectInfos = $this->listFiles($snapshotId, $snapshotSubPath);
            if (!array_key_exists($snapshotSubPath, $snapshotObjectInfos)) {
                return $this->getDefaultStat();
            }

            $snapshotObjectInfo = $snapshotObjectInfos[$snapshotSubPath];
        }

        $size = array_key_exists('size', $snapshotObjectInfo) ? $snapshotObjectInfo['size'] : 0;
        
        return [
            'size' => intval($size),
            'mtime' => strtotime($snapshotObjectInfo['mtime']),
            'atime' => strtotime($snapshotObjectInfo['atime'])
        ];
    }

    public function filetype($path) {
        $type = $this->getType($path);
        switch($type) {
            case self::TYPE_FILE:
                return 'file';
            case self::TYPE_DIR:
                return 'dir';
            case self::TYPE_UNKNOWN:
            default:
                return 'unknown';
        }
    }

    public function file_exists($path) {
        // TODO
        return true;
    }

    public function unlink($path) {
        // Readonly
        return false;
    }

    public function fopen($path, $mode) {
        if ($mode !== 'r') {
            return false;
        }
        [$snapshotId, $snapshotSubPath] = $this->parsePath($path);
        $tmpFile = $this->tempManager->getTemporaryFile();
        $fp = fopen($tmpFile, 'w+');
        $this->resticRepository->dump($snapshotId, $snapshotSubPath, $tmpFile);
        fseek($fp, 0);
        return $fp;
    }

    public function touch($path, $mtime = null) {
        // Readonly
        return false;
    }

    public function getPropagator($storage = null) {
        // Disable full scans via 'occ files:scan' or cron.php
        // because this could bloat oc_filecache table.
        throw new StorageNotAvailableException();
    }

    /**
     * @return resource|boolean
     */
    private function listSnapshots() {
        $snapshots = $this->resticRepository->snapshots();
        return array_map(function($snapshot) {
            // for example 2023-04-15T23:39:39.095734241+02:00 (52f058e0)
            return $snapshot['time'] . ' (' . $snapshot['short_id'] .')';
        }, $snapshots);
    }

    /**
     * Executes a restic ls command and caches the individual result rows by path.
     * @param string $snapshotId
     * @param string $snapshotSubPath
     * @return array The result of ResticRepository::ls()
     */
    private function listFiles(string $snapshotId, string $snapshotSubPath): array {
        $snapshotObjectInfos = $this->resticRepository->ls($snapshotId, $snapshotSubPath);
        foreach($snapshotObjectInfos as $path => $snapshotObjectInfo) {
            $this->setCachedNodeInfo($snapshotId, $path, $snapshotObjectInfo);
        }
        return $snapshotObjectInfos;
    }

    private function parsePath(string $path) : array {
        // We built our path like this:
        // 2023-04-15T23:39:39.095734241+02:00 (52f058e0)/path/to/file.png
        // but path can also be empty or just the snapshot id part.
        $path = $this->normalizePath($path);
        $splitted = explode('/', $path, 2);
        $snapshotId = preg_match('/.*\s\((.*)\)/', $splitted[0], $snapshotMatch) ? $snapshotMatch[1] : null;
        $snapshotSubPath = '';
        if (count($splitted) > 1) {
            $snapshotSubPath = '/' . trim($splitted[1], '/');
        }
        return [$snapshotId, $snapshotSubPath];
    }

    private function getType(string $path) {
        if ($path === '') {
            return self::TYPE_DIR;
        }

        [$snapshotId, $snapshotSubPath] = $this->parsePath($path);
        // A snapshot root is always a directory
        if (empty($snapshotSubPath)) {
            return self::TYPE_DIR;
        }

        // Check cached node info
        $snapshotFileOrDir = $this->getCachedNodeInfo($snapshotId, $snapshotSubPath);
        
        // Check type via restic ls
        if ($snapshotFileOrDir === null) {
            $snapshotObjectInfos = $this->listFiles($snapshotId, $snapshotSubPath);
            $snapshotFileOrDir = array_key_exists($snapshotObjectInfos, $snapshotSubPath) ? $snapshotObjectInfos[$snapshotSubPath] : false;
        }

        // Get type from restic metadata
        if ($snapshotFileOrDir !== false) {
            if ($snapshotFileOrDir['type'] === 'dir') {
                return self::TYPE_DIR;
            }
            if ($snapshotFileOrDir['type'] === 'file') {
                return self::TYPE_FILE;
            }
        }
        return self::TYPE_UNKNOWN;
    }

    private function normalizePath(string $path) : string {
        $path = trim($path, '/');
        return str_replace('\\', '/', $path);
    }

    private function getDefaultStat() : array {
        return [
            'size' => 0,
            'mtime' => time(),
            'atime' => time()
        ];
    }

    /**
     * Returns the entry of ResticRepository->ls() for the given node if it exists.
     * Array will have this form:
     * [
     *      "name":"file.txt",
     *      "type":"file",
     *      "path":"/my/path/file.txt",
     *      "uid":1000,
     *      "gid":1000,
     *      "size":80,
     *      "mode":436,
     *      "permissions":"-rw-rw-r--",
     *      "mtime":"2018-02-22T19:35:46+01:00",
     *      "atime":"2018-02-22T19:35:46+01:00",
     *      "ctime":"2022-09-21T17:06:45.689362911+02:00",
     *      "struct_type":"node"
     * ]
     * @param string $snapshotId
     * @param string $snapshotSubPath
     */
    private function getCachedNodeInfo(string $snapshotId, string $snapshotSubPath) : ?array {
        return $this->resultsCache->get(self::getNodeInfoCacheKey($snapshotId, $snapshotSubPath));
    }

    /**
     * @param string $snapshotId
     * @param string $snapshotSubPath
     */
    private function setCachedNodeInfo(string $snapshotId, string $snapshotSubPath, array $nodeInfo) : void {
        $this->resultsCache->set(self::getNodeInfoCacheKey($snapshotId, $snapshotSubPath), $nodeInfo);
    }

    private static function getNodeInfoCacheKey(string $snapshotId, string $snapshotSubPath) : string {
        return "nodeinfo_$snapshotId" . "_$snapshotSubPath";
    }
}