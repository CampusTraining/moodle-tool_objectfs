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
 * Class candidates_factory
 * @package tool_objectfs
 * @author Gleimer Mora <gleimermora@catalyst-au.net>
 * @copyright Catalyst IT
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\object_manipulator\candidates;

use dml_exception;
use stdClass;

/**
 * manipulator_candidates_base
 */
abstract class manipulator_candidates_base implements manipulator_candidates {
    /**
     * Query name for logging, defined in each concrete subclass.
     * Declared here to satisfy static analysis. Must NOT have a type hint
     * because subclasses redeclare it without one (PHP requires consistency).
     *
     * @var string
     */
    protected $queryname = '';

    /** @var stdClass $config */
    protected $config;

    /**
     * manipulator_candidates_base constructor.
     * @param stdClass $config
     */
    public function __construct(stdClass $config) {
        $this->config = $config;
    }

    /**
     * get_query_name
     * @return string
     */
    public function get_query_name() {
        return $this->queryname;
    }

    /**
     * get
     * @return array
     * @throws dml_exception
     */
    public function get() {
        global $DB;
        return $DB->get_records_sql(
            $this->get_candidates_sql(),
            $this->get_candidates_sql_params(),
            0,
            $this->config->batchsize
        );
    }

    /**
     * Returns the SQL fragment (a ' AND contenthash NOT IN (...)' clause) to append
     * to candidates queries in order to skip contenthashes that belong to components
     * configured in tool_objectfs / excludedcomponents.
     *
     * Returns an empty string when no components are excluded, so there is zero
     * performance overhead on installations that do not use this feature.
     *
     * Must be used together with get_component_exclusion_params(). Both methods
     * delegate to component_filter::get_exclusion_sql_fragment() which caches its
     * result for the lifetime of the current request/process, ensuring the subquery
     * is built only once regardless of how many candidates instances are created.
     *
     * @return string SQL WHERE clause fragment starting with ' AND ...'
     */
    protected function get_component_exclusion_sql(): string {
        [$sql] = \tool_objectfs\local\store\component_filter::get_exclusion_sql_fragment();
        return $sql;
    }

    /**
     * Returns the named SQL parameters that accompany get_component_exclusion_sql().
     *
     * Must be merged into the array returned by get_candidates_sql_params() whenever
     * get_component_exclusion_sql() returns a non-empty string.
     *
     * @return array<string, mixed> Named params for the exclusion subquery.
     */
    protected function get_component_exclusion_params(): array {
        [, $params] = \tool_objectfs\local\store\component_filter::get_exclusion_sql_fragment();
        return $params;
    }
}
