<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Component exclusion filter for ObjectFS.
 *
 * Provides a configurable list of Moodle components whose files should
 * always remain on local disk and never be pushed to remote object storage.
 *
 * Design notes:
 * - Three levels of cache (all per-request / per-process):
 *     $excludedcomponents : parsed config string, lazy-loaded once.
 *     $hashcache          : DB lookup result per contenthash (grows during batch runs).
 *     $sqlfragment        : built SQL+params fragment, computed once per request.
 * - All caches are reset via reset_cache(), primarily for unit testing.
 * - The subquery strategy ("ANY SCORM usage") is intentionally conservative:
 *   if a contenthash is shared between mod_scorm and another component, it is
 *   still excluded from push/delete to guarantee SCORM correctness.
 *
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helper for component-level ObjectFS exclusion.
 */
class component_filter {

    /**
     * Cached parsed list of excluded components.
     * Null means "not loaded yet"; empty array means "no exclusions configured".
     *
     * @var string[]|null
     */
    private static ?array $excludedcomponents = null;

    /**
     * Per-request cache: contenthash => bool (has excluded component).
     * Avoids repeated DB queries when the same hash appears in a batch.
     *
     * @var array<string, bool>
     */
    private static array $hashcache = [];

    /**
     * Cached [sql_fragment, params] for use in candidates SQL queries.
     * Null means "not computed yet".
     *
     * @var array{string, array<string, mixed>}|null
     */
    private static ?array $sqlfragment = null;

    /**
     * Returns the list of excluded Moodle components from plugin config.
     *
     * The config value (tool_objectfs / excludedcomponents) is a newline-separated
     * list of component names, e.g.:
     *
     *   mod_scorm
     *   mod_folder
     *
     * @return string[] e.g. ['mod_scorm', 'mod_folder']
     */
    public static function get_excluded_components(): array {
        if (self::$excludedcomponents !== null) {
            return self::$excludedcomponents;
        }

        // Use manager::get_objectfs_config() — NOT get_config() directly.
        // The manager merges DB values over PHP defaults, so the default 'mod_scorm'
        // is returned even if the admin has never saved the settings page (i.e. the
        // key does not yet exist in mdl_config_plugins).
        $config = \tool_objectfs\local\manager::get_objectfs_config();
        $raw = (string) ($config->excludedcomponents ?? '');

        if (empty(trim($raw))) {
            self::$excludedcomponents = [];
            return self::$excludedcomponents;
        }

        self::$excludedcomponents = array_values(
            array_filter(
                array_map('trim', explode("\n", $raw))
            )
        );

        return self::$excludedcomponents;
    }

    /**
     * Returns true if the given Moodle component is in the exclusion list.
     *
     * This is the fast O(n) in-memory path, zero DB queries. Use it when
     * the component is already known (e.g. from a stored_file object).
     *
     * @param string $component e.g. 'mod_scorm'
     * @return bool
     */
    public static function is_component_excluded(string $component): bool {
        return in_array($component, self::get_excluded_components(), true);
    }

    /**
     * Returns true if ANY file record for the given contenthash belongs to
     * an excluded component.
     *
     * Used as a safety guard in push/delete operations where only the contenthash
     * is available (no stored_file object). Results are cached per-request to
     * avoid N DB queries during batch operations.
     *
     * Intentionally conservative: if the same binary file is referenced by both
     * mod_scorm and another component, it is still excluded from push/delete.
     * This guarantees that SCORM files are never left without a local copy.
     *
     * @param string $contenthash SHA-1 content hash
     * @return bool
     */
    public static function contenthash_has_excluded_component(string $contenthash): bool {
        if (array_key_exists($contenthash, self::$hashcache)) {
            return self::$hashcache[$contenthash];
        }

        $excluded = self::get_excluded_components();
        if (empty($excluded)) {
            self::$hashcache[$contenthash] = false;
            return false;
        }

        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($excluded, SQL_PARAMS_NAMED, 'compexcl');
        $params['contenthash'] = $contenthash;

        // filename != '.' excludes virtual directory entries (contenthash = sha1('')).
        $result = $DB->record_exists_sql(
            "SELECT 1
               FROM {files} f
              WHERE f.contenthash = :contenthash
                AND f.component $insql
                AND f.filename != '.'",
            $params
        );

        self::$hashcache[$contenthash] = $result;
        return $result;
    }

    /**
     * Returns a [sql_fragment, params] pair to append to candidates SQL queries
     * in order to exclude contenthashes belonging to excluded components.
     *
     * The sql_fragment is a complete ' AND contenthash NOT IN (...)' clause ready
     * to concatenate to the candidates SELECT. It is '' when no components are
     * configured (zero performance overhead on default installations).
     *
     * The result is cached for the request/process lifetime because it is
     * deterministic for a given config and DB state.
     *
     * @return array{string, array<string, mixed>} [sql_fragment, named_params]
     */
    public static function get_exclusion_sql_fragment(): array {
        if (self::$sqlfragment !== null) {
            return self::$sqlfragment;
        }

        $excluded = self::get_excluded_components();
        if (empty($excluded)) {
            self::$sqlfragment = ['', []];
            return self::$sqlfragment;
        }

        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($excluded, SQL_PARAMS_NAMED, 'candexcl');

        $sql = " AND contenthash NOT IN (
                     SELECT DISTINCT f.contenthash
                       FROM {files} f
                      WHERE f.component $insql
                        AND f.filename != '.'
                 )";

        self::$sqlfragment = [$sql, $params];
        return self::$sqlfragment;
    }

    /**
     * Resets all internal caches.
     *
     * Must be called between test cases and whenever the plugin config changes
     * within the same process (e.g. admin saves new exclusion list).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$excludedcomponents = null;
        self::$hashcache          = [];
        self::$sqlfragment        = null;
    }
}
