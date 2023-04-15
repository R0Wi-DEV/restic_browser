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

namespace OCA\ResticBrowser\Listener;

use OCA\Files_External\Service\BackendService;
use OCA\ResticBrowser\Backend\ResticBackendProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

class ExternalStoragesRegistrationListener implements IEventListener {
    /** @var BackendService */
    private $backendService;
    /** @var ResticBackendProvider */
    private $resticBackendProvider;

    public function __construct(BackendService $backendService, ResticBackendProvider $resticBackendProvider) {
        $this->backendService = $backendService;
        $this->resticBackendProvider = $resticBackendProvider;
    }

    public function handle(Event $event): void {
        $this->backendService->registerBackendProvider($this->resticBackendProvider);
    }
}