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

namespace OCA\ResticBrowser\Service;

use OCA\ResticBrowser\Exception\ResticException;
use OCA\ResticBrowser\Util\CacheUtil;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

class ResticRepository implements IResticRepository {
    /** @var string */
    private $path;

    /** @var string */
    private $password;

    /** @var ICache */
    private $cache;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(string $path, string $password) {
        $this->path = $path;
        $this->password = $password;
        $this->cache = Server::get(ICacheFactory::class)->createLocal('restic');
        $this->logger = Server::get(LoggerInterface::class);
    }

    public function getPath(): string {
        return $this->path;
    }

    public function snapshots(): array {
        // Snapshot numbers can change so only cache for a short period of time
        return CacheUtil::getCached("snapshots", $this->cache, function() {
            [$stdOut, $stdErr, $returnCode] = $this->executeResticCommand("snapshots --json");
            $snapshots = json_decode($stdOut, true);
            $result = [];
            foreach ($snapshots as $snapshot) {
                $result[$snapshot['short_id']] = $snapshot;
            }

            return $result;
        }, 10);
    }

    public function ls(string $snapshotId, string $snapshotPath): array {
        // Snapshots are immutable so we can cache them for quite a long time
        return CacheUtil::getCached("ls_$snapshotId"."_$snapshotPath", $this->cache, function() use ($snapshotId, $snapshotPath) {
            if (strlen($snapshotPath) === 0) {
                $snapshotPath = '/';
            }
            if ($snapshotPath[0] !== '/') {
                $snapshotPath = '/' . $snapshotPath;
            }
            $cmd = 'ls ' . escapeshellarg($snapshotId) . ' ' . escapeshellarg($snapshotPath) . ' --json';
            [$stdOut, $stdErr, $returnCode] = $this->executeResticCommand($cmd);
            $snapshotObjects = [];
            $lineCount = 0;
            foreach (explode("\n", $stdOut) as $line) {
                // Skip invalid lines
                if ($lineCount++ === 0 || strlen($line) === 0 || $line[0] !== '{') {
                    continue;
                }
                $info = json_decode($line, true);
                $path = $info['path'];
                $snapshotObjects[$path] = $info;
            }
            
            return $snapshotObjects;
        });

    }

    public function dump(string $snapshotId, string $snapshotPath, string $target): void {
        $cmd = 'dump ' . escapeshellarg($snapshotId). ' ' . escapeshellarg($snapshotPath) . ' > ' . escapeshellarg($target);
        [$stdOut, $stdErr, $returnCode] = $this->executeResticCommand($cmd);
    }

    /**
     * Returns [stdout, stderr, returncode]
     */
    private function executeResticCommand(string $commandSuffix): array {
        $descriptorspec = array(
            0 => array("pipe", "r"),    // stdin 
            1 => array("pipe", "w"),    // stdout 
            2 => array("pipe", "w")     // stderr 
        );
        
        $command = 'restic -q -r ' . escapeshellarg($this->path) . ' ' . $commandSuffix;

        $this->logger->debug('Executing command: ' . $command);

        try {
            $process = proc_open($command, $descriptorspec, $pipes);
            
            if (!is_resource($process)) {
                throw new \Exception('Could not execute command: ' . $command);
            }

            fwrite($pipes[0], $this->password);
            fclose($pipes[0]);
            
            $stdOut = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            
            $stdErr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            
            $returnCode = proc_close($process);
            
            if ($returnCode !== 0) {
                throw new ResticException('Command failed: ' . $command . '. Stderr was: ' . $stdErr . ' (return code: ' . $returnCode . ')');
            }

            return [$stdOut, $stdErr, $returnCode];
        }
        catch (\Throwable $e) {
            $this->logger->error('Error executing command: ' . $command . '. Error was: ' . $e->getMessage());
            throw $e;
        }
        finally {
            if (is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
        }
    }
}