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

interface IResticRepository {
    /**
     * Returns the path on disk of this repository
     * @return string
     */
    public function getPath(): string;

    /**
     * Returns a associative array of snapshots metadata. 
     * The keys are the snapshot IDs and the values are the snapshot dates.
     * @return array
     */
    public function snapshots(): array;

    /**
     * Returns an array of snapshot info objects. 
     * The array is structured as follows:
     * [
     *  "/my/path/file.txt" => [ 
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
     *  ],
     *  "/my/path/folder" =>  ...
     * ]
     * @param string $snapshotId Restic snapshot id
     * @param string $snapshotPath Subpath of the snapshot object to list (usually the folder which should be listed)
     * @return array
     */
    public function ls(string $snapshotId, string $snapshotPath): array;

    /**
     * Dumps the contents of a snapshot to a file or folder.
     * @param string $snapshotId Restic snapshot id
     * @param string $snapshotPath Subpath of the snapshot object to dump (file or folder)
     * @param string $target Target file or folder to dump to
     */
    public function dump(string $snapshotId, string $snapshotPath, string $target): void;
}