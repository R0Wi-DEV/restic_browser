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

namespace OCA\ResticBrowser\Util;

use OCP\ICache;

final class CacheUtil {

    /**
     * Gets a value from the cache, or creates it if it doesn't exist yet.
     * @param string $key The key to use in the cache
     * @param ICache $cache The cache to use
     * @param callable $valueCreator A function that creates the value if it doesn't exist yet
     * @param int $ttl Time To Live in seconds. Defaults to 60*60*24
     */
    public static function getCached(string $key, ICache $cache, callable $valueCreator, int $ttl = 0) {
        $cached = $cache->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $valueCreator();
        $cache->set($key, $value, $ttl);
        return $value;
    }
}