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

namespace OCA\ResticBrowser\Backend;

use OCA\Files_External\Lib\Auth\AuthMechanism;
use OCA\Files_External\Lib\Backend\Backend;
use OCA\Files_External\Lib\DefinitionParameter;
use OCA\ResticBrowser\Storage\ResticStorage;

class ResticBackend extends Backend {
    public function __construct() {
        $this
            ->setIdentifier('restic')
            ->addIdentifierAlias('\OC\Files\Storage\Restic') // legacy compat
            ->setStorageClass(ResticStorage::class)
            ->setText('Restic')
            ->addParameters([
                new DefinitionParameter('path', 'Restic Repository'),
            ])
            ->addAuthScheme(AuthMechanism::SCHEME_PASSWORD);
    }
}