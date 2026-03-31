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
 * Migration task: pulls excluded-component files back from remote storage to local disk.
 *
 * Run this once after configuring the 'excludedcomponents' setting to ensure that
 * any files belonging to excluded components that were already pushed to remote
 * storage (S3, Azure, etc.) before the exclusion was configured are recovered to
 * local disk.
 *
 * The task is safe to run multiple times: files already local are detected and
 * skipped. It is disabled by default; an administrator must enable it manually,
 * run it, and may then disable it again (or leave it enabled as a daily safety net).
 *
 * @package   tool_objectfs
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\task;

use core\task\scheduled_task;
use tool_objectfs\local\manager;
use tool_objectfs\local\store\component_filter;

defined('MOODLE_INTERNAL') || die();

/**
 * Pull excluded component objects from remote storage (migration).
 */
class pull_excluded_objects_task extends scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('task:pull_excluded_objects', 'tool_objectfs');
    }

    /**
     * Finds all contenthashes that:
     *   1. Belong to an excluded component in mdl_files.
     *   2. Are currently EXTERNAL (location=2) or DUPLICATED (location=1) in
     *      tool_objectfs_objects (i.e. either only on remote, or on both).
     *
     * For each such hash, pulls the file back to local disk using the configured
     * ObjectFS filesystem client, then updates the location record to DUPLICATED.
     *
     * Files that are already LOCAL (location=0) are not queried.
     * Files locked by another process are skipped and will be retried on the next run.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $config = manager::get_objectfs_config();

        if (empty($config->filesystem)) {
            mtrace('tool_objectfs pull_excluded_objects_task: No filesystem configured — skipping.');
            return;
        }

        // Reset filter cache so we pick up the current config in this CLI/cron process.
        component_filter::reset_cache();
        $excluded = component_filter::get_excluded_components();

        if (empty($excluded)) {
            mtrace('tool_objectfs pull_excluded_objects_task: No excluded components configured — nothing to do.');
            return;
        }

        mtrace('tool_objectfs pull_excluded_objects_task: Excluded components: ' . implode(', ', $excluded));

        // Find contenthashes that are on remote AND belong to excluded components.
        [$insql, $params] = $DB->get_in_or_equal($excluded, SQL_PARAMS_NAMED, 'pullexcl');
        $params['ext']  = OBJECT_LOCATION_EXTERNAL;
        $params['dupl'] = OBJECT_LOCATION_DUPLICATED;

        $sql = "SELECT DISTINCT o.contenthash, o.filesize
                  FROM {tool_objectfs_objects} o
                 WHERE o.location IN (:ext, :dupl)
                   AND o.contenthash IN (
                       SELECT DISTINCT f.contenthash
                         FROM {files} f
                        WHERE f.component $insql
                          AND f.filename != '.'
                   )
                 ORDER BY o.filesize ASC";

        $records = $DB->get_records_sql($sql, $params);

        if (empty($records)) {
            mtrace('tool_objectfs pull_excluded_objects_task: No excluded-component files found on remote. Nothing to pull.');
            return;
        }

        mtrace(sprintf(
            'tool_objectfs pull_excluded_objects_task: Found %d file(s) on remote belonging to excluded components. Pulling...',
            count($records)
        ));

        /** @var \tool_objectfs\local\store\object_file_system $filesystem */
        $filesystem = new $config->filesystem();
        $pulled  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($records as $record) {
            // Acquire a short-lived lock (30s) so we don't collide with other tasks.
            $objectlock = $filesystem->acquire_object_lock($record->contenthash, 30);

            if (!$objectlock) {
                mtrace('  SKIP (locked by another process): ' . $record->contenthash);
                $skipped++;
                continue;
            }

            try {
                $newlocation = $filesystem->copy_object_from_external_to_local_by_hash(
                    $record->contenthash,
                    (int) $record->filesize
                );

                manager::update_object_by_hash($record->contenthash, $newlocation);

                if ($newlocation === OBJECT_LOCATION_DUPLICATED) {
                    mtrace('  OK  (pulled to local): ' . $record->contenthash);
                    $pulled++;
                } else {
                    // copy_object_from_external_to_local_by_hash returns the current
                    // location unchanged if the file was already local.
                    mtrace('  INFO (already local or unchanged): ' . $record->contenthash);
                    $skipped++;
                }
            } catch (\Exception $e) {
                mtrace('  ERROR pulling ' . $record->contenthash . ': ' . $e->getMessage());
                $errors++;
            } finally {
                $objectlock->release();
            }
        }

        mtrace(sprintf(
            'tool_objectfs pull_excluded_objects_task: Complete. Pulled: %d, Skipped: %d, Errors: %d.',
            $pulled,
            $skipped,
            $errors
        ));
    }
}
