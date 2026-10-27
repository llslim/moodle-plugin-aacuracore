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

namespace local_aacuracore\library;

defined('MOODLE_INTERNAL') || die();

/**
 * Topic & Scenario Library Manager for AACURA v3.0.
 *
 * Responsibilities:
 * - Manages the Topic taxonomy (local_aacuracore_topics) and Scenario Library (local_aacuracore_scenarios).
 * - Enforces single-topic (1-to-many) assignment per scenario.
 * - Defaults instructor-created scenarios/topics in a course to Course Scope ('course').
 * - Allows Site Administrators (moodle/site:config) to promote course-scoped items to System Scope ('system').
 * - Seeds starter clinical topics and built-in roleplay scenarios anchored in LAFF "Don't Cry".
 * - Migrates legacy local_aacuracore_custom_scenarios and backfills per-activity cmid/scenariolist data.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC Chatbot Team
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_library_manager {

    /** @var string Default topic code for parent counseling scenarios */
    public const DEFAULT_TOPIC_CODE = 'parent_counseling';

    /**
     * Checks whether the required library tables exist in the database.
     *
     * @return bool
     */
    public static function tables_exist(): bool {
        global $DB;
        if (!$DB) {
            return false;
        }
        $dbman = $DB->get_manager();
        return $dbman->table_exists('local_aacuracore_topics')
            && $dbman->table_exists('local_aacuracore_scenarios');
    }

    /**
     * Ensures starter topics and built-in scenarios are seeded if the library is empty.
     *
     * @return void
     */
    public static function ensure_seeded(): void {
        global $DB;
        if (!self::tables_exist()) {
            return;
        }
        if ($DB->count_records('local_aacuracore_topics', ['scope' => 'system']) === 0) {
            self::seed_defaults();
        }
    }

    /**
     * Idempotently seeds the 5 starter system topics, 8 built-in scenarios across roles,
     * and migrates any legacy custom scenarios.
     *
     * @return void
     */
    public static function seed_defaults(): void {
        global $DB;

        if (!self::tables_exist()) {
            return;
        }

        $now = time();
        $defaulttopics = self::get_default_topics_definitions();
        $topicmap = [];

        foreach ($defaulttopics as $tdef) {
            $existing = $DB->get_record('local_aacuracore_topics', [
                'topiccode' => $tdef['topiccode'],
                'courseid' => 0,
            ]);
            if ($existing) {
                $topicmap[$tdef['topiccode']] = $existing;
            } else {
                $record = (object)[
                    'topiccode' => $tdef['topiccode'],
                    'title' => $tdef['title'],
                    'description' => $tdef['description'],
                    'default_role_type' => $tdef['default_role_type'],
                    'default_min_turns' => $tdef['default_min_turns'],
                    'default_intensity' => $tdef['default_intensity'],
                    'scope' => 'system',
                    'courseid' => 0,
                    'visible' => 1,
                    'sortorder' => $tdef['sortorder'],
                    'userid' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $record->id = $DB->insert_record('local_aacuracore_topics', $record);
                $topicmap[$tdef['topiccode']] = $record;
            }
        }

        $builtinscenarios = self::get_builtin_scenarios_definitions();
        foreach ($builtinscenarios as $sdef) {
            $tcode = $sdef['topiccode'];
            $topic = $topicmap[$tcode] ?? $topicmap[self::DEFAULT_TOPIC_CODE];
            $existing = $DB->get_record('local_aacuracore_scenarios', [
                'scenariocode' => $sdef['scenariocode'],
                'courseid' => 0,
                'cmid' => 0,
            ]);

            $payload = $sdef['json'];
            $payload['topic'] = [
                'code' => $topic->topiccode,
                'title' => $topic->title,
            ];

            if (!$existing) {
                $srec = (object)[
                    'scenariocode' => $sdef['scenariocode'],
                    'topicid' => (int)$topic->id,
                    'topiccode' => $topic->topiccode,
                    'name' => $sdef['name'],
                    'role_type' => $sdef['role_type'],
                    'role_label' => $sdef['role_label'],
                    'description' => $sdef['description'],
                    'json_data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'is_builtin' => 1,
                    'scope' => 'system',
                    'courseid' => 0,
                    'cmid' => 0,
                    'visible' => 1,
                    'sortorder' => $sdef['sortorder'],
                    'userid' => 0,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $DB->insert_record('local_aacuracore_scenarios', $srec);
            }
        }

        self::migrate_legacy_custom_scenarios();
    }

    /**
     * Migrates all records from legacy local_aacuracore_custom_scenarios into local_aacuracore_scenarios.
     *
     * @return int Number of newly migrated records
     */
    public static function migrate_legacy_custom_scenarios(): int {
        global $DB;

        if (!self::tables_exist()) {
            return 0;
        }
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_aacuracore_custom_scenarios')) {
            return 0;
        }

        $customs = $DB->get_records('local_aacuracore_custom_scenarios');
        $migrated = 0;
        $now = time();

        foreach ($customs as $custom) {
            $code = strtolower(trim($custom->scenariocode));
            if ($code === '') {
                continue;
            }
            $existing = $DB->get_record('local_aacuracore_scenarios', [
                'scenariocode' => $code,
                'courseid' => 0,
                'cmid' => 0,
            ]);
            if ($existing) {
                continue;
            }

            $json = json_decode($custom->json_data, true);
            if (!is_array($json)) {
                continue;
            }

            $roletype = $json['persona']['role']['type'] ?? 'parent';
            $rolelabel = $json['persona']['role']['display_label'] ?? self::default_role_label($roletype);
            $topiccode = self::extract_or_infer_topic_code($json, $roletype);
            $topic = self::get_topic_by_code($topiccode, 0) ?? self::get_topic_by_code(self::DEFAULT_TOPIC_CODE, 0);

            if ($topic) {
                $json['topic'] = [
                    'code' => $topic->topiccode,
                    'title' => $topic->title,
                ];
            }

            $record = (object)[
                'scenariocode' => $code,
                'topicid' => $topic ? (int)$topic->id : 0,
                'topiccode' => $topic ? $topic->topiccode : self::DEFAULT_TOPIC_CODE,
                'name' => $custom->name ?: ($json['persona']['name'] ?? ucfirst($code)),
                'role_type' => $roletype,
                'role_label' => $rolelabel,
                'description' => $custom->description ?? ($json['persona']['backstory'] ?? ''),
                'json_data' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'is_builtin' => 0,
                'scope' => 'system',
                'courseid' => 0,
                'cmid' => 0,
                'visible' => 1,
                'sortorder' => 100,
                'userid' => (int)($custom->userid ?? 0),
                'timecreated' => (int)($custom->timecreated ?: $now),
                'timemodified' => (int)($custom->timemodified ?: $now),
            ];
            $DB->insert_record('local_aacuracore_scenarios', $record);
            $migrated++;
        }

        return $migrated;
    }

    /**
     * Returns all topics available for a given course (system topics + course-scoped topics).
     *
     * @param int $courseid Course ID (0 for system-only)
     * @param bool $visibleonly Whether to return only visible topics
     * @param bool $includeallcourses If true (for Site Admin library manager), returns topics across all courses
     * @return array Array of topic stdClass records indexed by id
     */
    public static function get_topics(int $courseid = 0, bool $visibleonly = true, bool $includeallcourses = false): array {
        global $DB;

        if (!self::tables_exist()) {
            return [];
        }
        self::ensure_seeded();

        $params = [];
        $where = [];

        if (!$includeallcourses) {
            if ($courseid > 0) {
                $where[] = "(scope = 'system' OR (scope = 'course' AND courseid = :courseid))";
                $params['courseid'] = $courseid;
            } else {
                $where[] = "scope = 'system'";
            }
        }

        if ($visibleonly) {
            $where[] = "visible = 1";
        }

        $sql = "SELECT * FROM {local_aacuracore_topics}";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY sortorder ASC, title ASC, id ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Resolves a topic record by topiccode, checking course scope first when courseid > 0, then system scope.
     *
     * @param string $topiccode
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get_topic_by_code(string $topiccode, int $courseid = 0): ?\stdClass {
        global $DB;

        if (!self::tables_exist()) {
            return null;
        }
        $topiccode = strtolower(trim($topiccode));
        if ($topiccode === '') {
            return null;
        }

        if ($courseid > 0) {
            $coursetopic = $DB->get_record('local_aacuracore_topics', [
                'topiccode' => $topiccode,
                'courseid' => $courseid,
            ]);
            if ($coursetopic) {
                return $coursetopic;
            }
        }

        $systemtopic = $DB->get_record('local_aacuracore_topics', [
            'topiccode' => $topiccode,
            'courseid' => 0,
        ]);

        return $systemtopic ?: null;
    }

    /**
     * Creates or updates a topic in the Topic Library.
     * Course-created topics default to scope = 'course' unless explicitly saved as 'system' by a Site Admin.
     *
     * @param array $data Topic fields (topiccode, title, description, default_role_type, default_min_turns, default_intensity, courseid, scope)
     * @param int $userid Author user ID
     * @return \stdClass Saved topic record
     */
    public static function save_topic(array $data, int $userid = 0): \stdClass {
        global $DB, $USER;

        self::ensure_seeded();
        $userid = $userid ?: (int)($USER->id ?? 0);
        $now = time();

        $topiccode = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($data['topiccode'] ?? '')));
        if ($topiccode === '') {
            throw new \invalid_parameter_exception('Topic code cannot be empty.');
        }

        $courseid = isset($data['courseid']) ? (int)$data['courseid'] : 0;
        $requestedscope = $data['scope'] ?? ($courseid > 0 ? 'course' : 'system');

        if ($requestedscope === 'system' && self::can_promote_to_system($userid)) {
            $scope = 'system';
            $courseid = 0;
        } else if ($courseid > 0) {
            $scope = 'course';
        } else {
            $scope = 'system';
            $courseid = 0;
        }

        $existing = $DB->get_record('local_aacuracore_topics', [
            'topiccode' => $topiccode,
            'courseid' => $courseid,
        ]);

        if ($existing) {
            $existing->title = trim($data['title'] ?? $existing->title);
            $existing->description = $data['description'] ?? $existing->description;
            $existing->default_role_type = $data['default_role_type'] ?? $existing->default_role_type;
            $existing->default_min_turns = isset($data['default_min_turns']) ? (int)$data['default_min_turns'] : $existing->default_min_turns;
            $existing->default_intensity = $data['default_intensity'] ?? $existing->default_intensity;
            $existing->visible = isset($data['visible']) ? (int)$data['visible'] : $existing->visible;
            $existing->sortorder = isset($data['sortorder']) ? (int)$data['sortorder'] : $existing->sortorder;
            $existing->timemodified = $now;
            $DB->update_record('local_aacuracore_topics', $existing);
            return $existing;
        }

        $record = (object)[
            'topiccode' => $topiccode,
            'title' => trim($data['title'] ?? ucfirst(str_replace('_', ' ', $topiccode))),
            'description' => $data['description'] ?? '',
            'default_role_type' => $data['default_role_type'] ?? 'parent',
            'default_min_turns' => isset($data['default_min_turns']) ? (int)$data['default_min_turns'] : 8,
            'default_intensity' => $data['default_intensity'] ?? 'medium',
            'scope' => $scope,
            'courseid' => $courseid,
            'visible' => isset($data['visible']) ? (int)$data['visible'] : 1,
            'sortorder' => isset($data['sortorder']) ? (int)$data['sortorder'] : 100,
            'userid' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_aacuracore_topics', $record);
        return $record;
    }

    /**
     * Returns scenarios in the library filtered by topic, course, and activity context.
     *
     * @param string|null $topiccode Optional topic filter (null or 'all' returns all topics)
     * @param int $courseid Course context ID (includes system + matching course scenarios)
     * @param int $cmid Activity course module ID (includes matching activity scenarios)
     * @param bool $visibleonly Whether to restrict to visible = 1
     * @param bool $includeallscopes If true (for Site Admin library manager), returns all scenarios across courses
     * @return array Array of scenario stdClass records
     */
    public static function get_scenarios(?string $topiccode = null, int $courseid = 0, int $cmid = 0,
            bool $visibleonly = true, bool $includeallscopes = false): array {
        global $DB;

        if (!self::tables_exist()) {
            return [];
        }
        self::ensure_seeded();

        $where = [];
        $params = [];

        if (!empty($topiccode) && $topiccode !== 'all') {
            $where[] = "topiccode = :topiccode";
            $params['topiccode'] = strtolower(trim($topiccode));
        }

        if (!$includeallscopes) {
            $scopeclauses = ["scope = 'system'"];
            if ($courseid > 0) {
                $scopeclauses[] = "(scope = 'course' AND courseid = :courseid)";
                $params['courseid'] = $courseid;
            }
            if ($cmid > 0) {
                $scopeclauses[] = "(scope = 'activity' AND cmid = :cmid)";
                $params['cmid'] = $cmid;
            }
            $where[] = "(" . implode(' OR ', $scopeclauses) . ")";
        }

        if ($visibleonly) {
            $where[] = "visible = 1";
        }

        $sql = "SELECT * FROM {local_aacuracore_scenarios}";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY sortorder ASC, name ASC, id ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Resolves a single scenario DB record by scenariocode in priority order:
     * 1. Activity scope (cmid)
     * 2. Course scope (courseid)
     * 3. System scope (courseid = 0, cmid = 0)
     *
     * @param string $scenariocode
     * @param int $courseid
     * @param int $cmid
     * @return \stdClass|null
     */
    public static function get_scenario_record(string $scenariocode, int $courseid = 0, int $cmid = 0): ?\stdClass {
        global $DB;

        if (!self::tables_exist()) {
            return null;
        }
        self::ensure_seeded();

        $scenariocode = strtolower(trim($scenariocode));
        if ($scenariocode === '') {
            return null;
        }

        if ($cmid > 0) {
            $actrec = $DB->get_record_select(
                'local_aacuracore_scenarios',
                'scenariocode = :code AND cmid = :cmid AND visible = 1',
                ['code' => $scenariocode, 'cmid' => $cmid]
            );
            if ($actrec) {
                return $actrec;
            }
        }

        if ($courseid > 0) {
            $courserec = $DB->get_record('local_aacuracore_scenarios', [
                'scenariocode' => $scenariocode,
                'courseid' => $courseid,
                'cmid' => 0,
                'visible' => 1,
            ]);
            if ($courserec) {
                return $courserec;
            }
        }

        $sysrec = $DB->get_record('local_aacuracore_scenarios', [
            'scenariocode' => $scenariocode,
            'courseid' => 0,
            'cmid' => 0,
            'visible' => 1,
        ]);

        return $sysrec ?: null;
    }

    /**
     * Saves or updates a scenario in the Topic & Scenario Library with single-topic assignment.
     *
     * Visibility guardrail (FR-3 / AC-8):
     * - When created/uploaded within a course ($courseid > 0), defaults to Course Scope ('course').
     * - Only Site Administrators (moodle/site:config) can directly save to 'system' scope when $requestedscope === 'system'.
     *
     * @param array $jsondata Validated scenario JSON array
     * @param string $topiccode Single topic code to assign this scenario to
     * @param int $courseid Course ID (0 for site admin context)
     * @param int $cmid Activity cmid (only used when $requestedscope === 'activity')
     * @param int $userid Author user ID
     * @param string|null $requestedscope Optional requested scope ('course', 'activity', 'system')
     * @param string|null $descriptionoverride Optional summary description override
     * @return \stdClass The created or updated local_aacuracore_scenarios record
     */
    public static function save_scenario(array $jsondata, string $topiccode = '', int $courseid = 0,
            int $cmid = 0, int $userid = 0, ?string $requestedscope = null, ?string $descriptionoverride = null): \stdClass {
        global $DB, $USER;

        self::ensure_seeded();
        $userid = $userid ?: (int)($USER->id ?? 0);
        $now = time();

        $scenariocode = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($jsondata['scenario_id'] ?? '')));
        if ($scenariocode === '') {
            throw new \invalid_parameter_exception('Scenario JSON must contain a valid scenario_id.');
        }
        $jsondata['scenario_id'] = $scenariocode;

        $persona = $jsondata['persona'] ?? [];
        $roletype = $persona['role']['type'] ?? 'parent';
        $rolelabel = $persona['role']['display_label'] ?? self::default_role_label($roletype);
        $name = trim($persona['name'] ?? ucfirst($scenariocode));
        $description = $descriptionoverride !== null
            ? trim($descriptionoverride)
            : trim($persona['backstory'] ?? '');

        if ($topiccode === '') {
            $topiccode = self::extract_or_infer_topic_code($jsondata, $roletype);
        }
        $topiccode = strtolower(trim($topiccode));

        $topic = self::get_topic_by_code($topiccode, $courseid)
            ?? self::get_topic_by_code(self::DEFAULT_TOPIC_CODE, 0);

        if (!$topic) {
            $topic = self::save_topic([
                'topiccode' => $topiccode ?: self::DEFAULT_TOPIC_CODE,
                'title' => ucfirst(str_replace('_', ' ', $topiccode ?: self::DEFAULT_TOPIC_CODE)),
                'courseid' => $courseid,
            ], $userid);
        }

        // Enforce single-topic assignment on the JSON payload as well.
        $jsondata['topic'] = [
            'code' => $topic->topiccode,
            'title' => $topic->title,
        ];

        // Resolve scope according to PRD FR-3: course instructors default to 'course' scope.
        if ($requestedscope === 'activity' && $cmid > 0) {
            $scope = 'activity';
            $targetcourseid = $courseid;
            $targetcmid = $cmid;
        } else if ($requestedscope === 'system' && self::can_promote_to_system($userid)) {
            $scope = 'system';
            $targetcourseid = 0;
            $targetcmid = 0;
        } else if ($courseid > 0) {
            $scope = 'course';
            $targetcourseid = $courseid;
            $targetcmid = 0;
        } else {
            $scope = 'system';
            $targetcourseid = 0;
            $targetcmid = 0;
        }

        $encodedjson = json_encode($jsondata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $existing = $DB->get_record('local_aacuracore_scenarios', [
            'scenariocode' => $scenariocode,
            'courseid' => $targetcourseid,
            'cmid' => $targetcmid,
        ]);

        if ($existing) {
            $existing->topicid = (int)$topic->id;
            $existing->topiccode = $topic->topiccode;
            $existing->name = $name;
            $existing->role_type = $roletype;
            $existing->role_label = $rolelabel;
            $existing->description = $description;
            $existing->json_data = $encodedjson;
            $existing->scope = $scope;
            $existing->visible = 1;
            $existing->timemodified = $now;
            $DB->update_record('local_aacuracore_scenarios', $existing);
            $saved = $existing;
        } else {
            $saved = (object)[
                'scenariocode' => $scenariocode,
                'topicid' => (int)$topic->id,
                'topiccode' => $topic->topiccode,
                'name' => $name,
                'role_type' => $roletype,
                'role_label' => $rolelabel,
                'description' => $description,
                'json_data' => $encodedjson,
                'is_builtin' => 0,
                'scope' => $scope,
                'courseid' => $targetcourseid,
                'cmid' => $targetcmid,
                'visible' => 1,
                'sortorder' => 50,
                'userid' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $saved->id = $DB->insert_record('local_aacuracore_scenarios', $saved);
        }

        // Keep legacy local_aacuracore_custom_scenarios synced for system-scoped custom scenarios.
        if ($scope === 'system' && empty($saved->is_builtin)) {
            self::sync_to_legacy_custom_table($saved);
        }

        return $saved;
    }

    /**
     * Checks whether a user has Site Administrator privileges (moodle/site:config) to promote items to System Scope.
     *
     * @param int $userid User ID (0 = current $USER)
     * @return bool
     */
    public static function can_promote_to_system(int $userid = 0): bool {
        global $USER;
        $uid = $userid ?: (int)($USER->id ?? 0);
        if ($uid <= 0) {
            return false;
        }
        if (is_siteadmin($uid)) {
            return true;
        }
        return has_capability('moodle/site:config', \context_system::instance(), $uid);
    }

    /**
     * Promotes a course-scoped or activity-scoped scenario to System Scope ('system', courseid = 0, cmid = 0).
     * Requires moodle/site:config capability.
     *
     * @param int $scenarioid Primary key ID in local_aacuracore_scenarios
     * @param int $userid Acting user ID (0 = current $USER)
     * @return \stdClass The promoted system-scoped scenario record
     * @throws \required_capability_exception
     */
    public static function promote_to_system_scope(int $scenarioid, int $userid = 0): \stdClass {
        global $DB, $USER;

        $uid = $userid ?: (int)($USER->id ?? 0);
        if (!self::can_promote_to_system($uid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'moodle/site:config',
                'nopermissions',
                ''
            );
        }

        $scenario = $DB->get_record('local_aacuracore_scenarios', ['id' => $scenarioid], '*', MUST_EXIST);
        if ($scenario->scope === 'system' && (int)$scenario->courseid === 0 && (int)$scenario->cmid === 0) {
            return $scenario;
        }

        // Ensure the parent topic also exists in system scope.
        $systemtopic = self::get_topic_by_code($scenario->topiccode, 0);
        if (!$systemtopic && !empty($scenario->topicid)) {
            $coursetopic = $DB->get_record('local_aacuracore_topics', ['id' => $scenario->topicid]);
            if ($coursetopic) {
                $systemtopic = self::promote_topic_to_system((int)$coursetopic->id, $uid);
            }
        }
        if (!$systemtopic) {
            $systemtopic = self::get_topic_by_code(self::DEFAULT_TOPIC_CODE, 0);
        }

        $now = time();
        $existingsystem = $DB->get_record('local_aacuracore_scenarios', [
            'scenariocode' => $scenario->scenariocode,
            'courseid' => 0,
            'cmid' => 0,
        ]);

        if ($existingsystem && (int)$existingsystem->id !== (int)$scenario->id) {
            // Merge into the existing system record and remove the duplicate course-scoped record.
            $existingsystem->topicid = $systemtopic ? (int)$systemtopic->id : $scenario->topicid;
            $existingsystem->topiccode = $systemtopic ? $systemtopic->topiccode : $scenario->topiccode;
            $existingsystem->name = $scenario->name;
            $existingsystem->role_type = $scenario->role_type;
            $existingsystem->role_label = $scenario->role_label;
            $existingsystem->description = $scenario->description;
            $existingsystem->json_data = $scenario->json_data;
            $existingsystem->scope = 'system';
            $existingsystem->visible = 1;
            $existingsystem->timemodified = $now;
            $DB->update_record('local_aacuracore_scenarios', $existingsystem);
            $DB->delete_records('local_aacuracore_scenarios', ['id' => $scenario->id]);
            $promoted = $existingsystem;
        } else {
            $scenario->topicid = $systemtopic ? (int)$systemtopic->id : $scenario->topicid;
            $scenario->topiccode = $systemtopic ? $systemtopic->topiccode : $scenario->topiccode;
            $scenario->scope = 'system';
            $scenario->courseid = 0;
            $scenario->cmid = 0;
            $scenario->timemodified = $now;
            $DB->update_record('local_aacuracore_scenarios', $scenario);
            $promoted = $scenario;
        }

        if (empty($promoted->is_builtin)) {
            self::sync_to_legacy_custom_table($promoted);
        }

        return $promoted;
    }

    /**
     * Promotes a course-scoped topic to System Scope ('system', courseid = 0).
     * Requires moodle/site:config capability.
     *
     * @param int $topicid Primary key ID in local_aacuracore_topics
     * @param int $userid Acting user ID
     * @return \stdClass The promoted system-scoped topic record
     * @throws \required_capability_exception
     */
    public static function promote_topic_to_system(int $topicid, int $userid = 0): \stdClass {
        global $DB, $USER;

        $uid = $userid ?: (int)($USER->id ?? 0);
        if (!self::can_promote_to_system($uid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'moodle/site:config',
                'nopermissions',
                ''
            );
        }

        $topic = $DB->get_record('local_aacuracore_topics', ['id' => $topicid], '*', MUST_EXIST);
        if ($topic->scope === 'system' && (int)$topic->courseid === 0) {
            return $topic;
        }

        $existingsystem = $DB->get_record('local_aacuracore_topics', [
            'topiccode' => $topic->topiccode,
            'courseid' => 0,
        ]);

        $now = time();
        if ($existingsystem && (int)$existingsystem->id !== (int)$topic->id) {
            $DB->set_field('local_aacuracore_scenarios', 'topicid', $existingsystem->id, ['topicid' => $topic->id]);
            $DB->delete_records('local_aacuracore_topics', ['id' => $topic->id]);
            return $existingsystem;
        }

        $topic->scope = 'system';
        $topic->courseid = 0;
        $topic->timemodified = $now;
        $DB->update_record('local_aacuracore_topics', $topic);
        return $topic;
    }

    /**
     * Parses a raw scenariolist string (JSON array or comma-separated list) into a clean array of scenario codes.
     *
     * @param mixed $raw Raw scenariolist value from database or form
     * @param string $defaultcode Default scenario code to ensure is included
     * @return string[]
     */
    public static function parse_scenariolist($raw, string $defaultcode = ''): array {
        $codes = [];
        if (is_array($raw)) {
            $codes = $raw;
        } else if (is_string($raw) && trim($raw) !== '') {
            $trimmed = trim($raw);
            if (strpos($trimmed, '[') === 0) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $codes = $decoded;
                }
            } else {
                $codes = explode(',', $trimmed);
            }
        }

        $clean = [];
        foreach ($codes as $c) {
            $c = strtolower(trim((string)$c));
            if ($c !== '' && !in_array($c, $clean, true)) {
                $clean[] = $c;
            }
        }

        $defaultcode = strtolower(trim($defaultcode));
        if ($defaultcode !== '' && $defaultcode !== 'custom' && !in_array($defaultcode, $clean, true)) {
            array_unshift($clean, $defaultcode);
        }

        return $clean;
    }

    /**
     * Resolves the ordered list of enabled scenario codes for a given mod_aacurachat instance record.
     *
     * @param \stdClass $aacurachat Activity record from mdl_aacurachat
     * @return string[]
     */
    public static function resolve_activity_scenariolist(\stdClass $aacurachat): array {
        $defaultcode = strtolower(trim($aacurachat->scenariocode ?? 'anna'));
        $parsed = self::parse_scenariolist($aacurachat->scenariolist ?? null, $defaultcode);
        if (!empty($parsed)) {
            return $parsed;
        }

        // Fallback to scenarios belonging to the activity's topiccode, or global active_scenarios.
        $topiccode = $aacurachat->topiccode ?? self::DEFAULT_TOPIC_CODE;
        $courseid = (int)($aacurachat->course ?? 0);
        $topicscenarios = self::get_scenarios($topiccode, $courseid, 0, true);
        if (!empty($topicscenarios)) {
            $codes = [];
            foreach ($topicscenarios as $sc) {
                $codes[] = $sc->scenariocode;
            }
            if ($defaultcode !== '' && $defaultcode !== 'custom' && !in_array($defaultcode, $codes, true)) {
                array_unshift($codes, $defaultcode);
            }
            return $codes;
        }

        return ['anna', 'brianna', 'cathy', 'mary'];
    }

    /**
     * Backfills missing cmid values on historical local_aacuracore_sessions records where cmid = 0.
     *
     * @return int Number of session records updated
     */
    public static function backfill_session_cmids(): int {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_aacuracore_sessions') || !$dbman->table_exists('aacurachat')) {
            return 0;
        }

        $sessions = $DB->get_records_select('local_aacuracore_sessions', 'cmid = 0 AND courseid > 0');
        if (empty($sessions)) {
            return 0;
        }

        $updated = 0;
        $coursecmcache = [];

        foreach ($sessions as $session) {
            $cid = (int)$session->courseid;
            if (!array_key_exists($cid, $coursecmcache)) {
                $sql = "SELECT cm.id
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module AND m.name = 'aacurachat'
                         WHERE cm.course = :courseid
                      ORDER BY cm.id ASC";
                $cms = $DB->get_records_sql($sql, ['courseid' => $cid], 0, 1);
                $firstcm = reset($cms);
                $coursecmcache[$cid] = $firstcm ? (int)$firstcm->id : 0;
            }

            if ($coursecmcache[$cid] > 0) {
                $DB->set_field('local_aacuracore_sessions', 'cmid', $coursecmcache[$cid], ['id' => $session->id]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Populates topiccode and scenariolist on existing mdl_aacurachat instances that do not have them set yet.
     *
     * @return int Number of activity instances updated
     */
    public static function backfill_activity_scenariolists(): int {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('aacurachat')) {
            return 0;
        }
        $table = new \xmldb_table('aacurachat');
        if (!$dbman->field_exists($table, new \xmldb_field('scenariolist'))) {
            return 0;
        }

        $activesetting = get_config('local_aacuracore', 'active_scenarios');
        if ($activesetting === false || trim((string)$activesetting) === '') {
            $defaultcodes = ['anna', 'brianna', 'cathy', 'mary'];
        } else {
            $defaultcodes = array_filter(array_map('trim', explode(',', (string)$activesetting)));
            if (empty($defaultcodes)) {
                $defaultcodes = ['anna', 'brianna', 'cathy', 'mary'];
            }
        }

        $activities = $DB->get_records('aacurachat');
        $updated = 0;

        foreach ($activities as $act) {
            $needsupdate = false;
            if (empty($act->topiccode)) {
                $act->topiccode = self::DEFAULT_TOPIC_CODE;
                $needsupdate = true;
            }
            if (empty($act->scenariolist)) {
                $list = $defaultcodes;
                $scode = strtolower(trim($act->scenariocode ?? 'anna'));
                if ($scode !== '' && $scode !== 'custom' && !in_array($scode, $list, true)) {
                    array_unshift($list, $scode);
                }
                $act->scenariolist = json_encode(array_values($list));
                $needsupdate = true;
            }
            if (empty($act->grading_mode)) {
                $act->grading_mode = 'single_highest';
                $needsupdate = true;
            }
            if ($needsupdate) {
                $DB->update_record('aacurachat', $act);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Synchronizes a system-scoped custom scenario into legacy local_aacuracore_custom_scenarios for backward compatibility.
     *
     * @param \stdClass $scenario
     * @return void
     */
    private static function sync_to_legacy_custom_table(\stdClass $scenario): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_aacuracore_custom_scenarios')) {
            return;
        }

        $existing = $DB->get_record('local_aacuracore_custom_scenarios', ['scenariocode' => $scenario->scenariocode]);
        if ($existing) {
            $existing->name = $scenario->name;
            $existing->description = $scenario->description;
            $existing->json_data = $scenario->json_data;
            $existing->timemodified = $scenario->timemodified;
            $DB->update_record('local_aacuracore_custom_scenarios', $existing);
        } else {
            $rec = (object)[
                'scenariocode' => $scenario->scenariocode,
                'name' => $scenario->name,
                'description' => $scenario->description,
                'json_data' => $scenario->json_data,
                'userid' => $scenario->userid,
                'timecreated' => $scenario->timecreated,
                'timemodified' => $scenario->timemodified,
            ];
            $DB->insert_record('local_aacuracore_custom_scenarios', $rec);
        }
    }

    /**
     * Extracts topic code from scenario JSON array or infers it from role_type.
     *
     * @param array $json
     * @param string $roletype
     * @return string
     */
    public static function extract_or_infer_topic_code(array $json, string $roletype = 'parent'): string {
        if (!empty($json['topic'])) {
            if (is_array($json['topic']) && !empty($json['topic']['code'])) {
                return strtolower(trim($json['topic']['code']));
            }
            if (is_string($json['topic']) && trim($json['topic']) !== '') {
                return strtolower(trim($json['topic']));
            }
        }

        switch (strtolower(trim($roletype))) {
            case 'doctor':
            case 'physician':
            case 'nurse':
                return 'medical_consult';
            case 'manufacturer_rep':
            case 'vendor':
                return 'vendor_evaluation';
            case 'aac_user':
                return 'self_advocacy';
            case 'school_admin':
            case 'iep_coordinator':
            case 'other_therapist':
                return 'iep_collaboration';
            case 'parent':
            default:
                return self::DEFAULT_TOPIC_CODE;
        }
    }

    /**
     * Returns a human-readable default role display label for a given role_type.
     *
     * @param string $roletype
     * @return string
     */
    public static function default_role_label(string $roletype): string {
        switch (strtolower(trim($roletype))) {
            case 'doctor':
                return 'Physician';
            case 'manufacturer_rep':
                return 'Device Manufacturer Rep';
            case 'aac_user':
                return 'AAC User / Self-Advocate';
            case 'school_admin':
                return 'School Administrator';
            case 'iep_coordinator':
                return 'IEP Team Coordinator';
            case 'insurance_rep':
                return 'Insurance Reviewer';
            case 'parent':
            default:
                return 'Parent Persona';
        }
    }

    /**
     * Returns the 5 starter system topic definitions from the PRD.
     *
     * @return array
     */
    public static function get_default_topics_definitions(): array {
        return [
            [
                'topiccode' => 'parent_counseling',
                'title' => 'Parent & Family Counseling (Early Intervention & School)',
                'description' => 'Practice empathetic family-centered counseling using the LAFF "Don\'t Cry" strategy with parents navigating early intervention, device abandonment, peer inclusion, and home-school consistency.',
                'default_role_type' => 'parent',
                'default_min_turns' => 8,
                'default_intensity' => 'medium',
                'sortorder' => 10,
            ],
            [
                'topiccode' => 'medical_consult',
                'title' => 'Interprofessional Medical & Physician Consultations',
                'description' => 'Collaborate with developmental pediatricians and medical specialists using concise clinical reasoning, evidence-based AAC advocacy, and LAFF interprofessional communication.',
                'default_role_type' => 'doctor',
                'default_min_turns' => 8,
                'default_intensity' => 'medium',
                'sortorder' => 20,
            ],
            [
                'topiccode' => 'vendor_evaluation',
                'title' => 'AAC Device Selection & Manufacturer Representatives',
                'description' => 'Evaluate speech-generating devices (SGDs) with manufacturer representatives while keeping feature matching focused on client-centered functional needs rather than sales pitches.',
                'default_role_type' => 'manufacturer_rep',
                'default_min_turns' => 8,
                'default_intensity' => 'medium',
                'sortorder' => 30,
            ],
            [
                'topiccode' => 'self_advocacy',
                'title' => 'AAC User Self-Advocacy & Person-Centered Care',
                'description' => 'Partner directly with adult and adolescent AAC users to support communication autonomy, vocabulary customization, and self-determined therapy goals.',
                'default_role_type' => 'aac_user',
                'default_min_turns' => 8,
                'default_intensity' => 'medium',
                'sortorder' => 40,
            ],
            [
                'topiccode' => 'iep_collaboration',
                'title' => 'School Administrator & IEP Team Collaboration',
                'description' => 'Navigate school-wide AAC implementation, classroom paraprofessional training, and IEP accommodation planning with school administrators and multidisciplinary teams.',
                'default_role_type' => 'school_admin',
                'default_min_turns' => 8,
                'default_intensity' => 'medium',
                'sortorder' => 50,
            ],
        ];
    }

    /**
     * Returns the built-in scenario definitions across all 5 starter topics.
     *
     * @return array
     */
    public static function get_builtin_scenarios_definitions(): array {
        $parentstates = self::get_standard_parent_states();

        $annastates = $parentstates;
        $annastates['START']['bot_prompt'] = "Thank you for meeting with me. I'm just really overwhelmed with Sarah starting pre-K. I feel like this iPad app isn't working for her at all, and her grandparents can't figure it out either. I'm worried we're not helping her communicate.";

        $briannastates = $parentstates;
        $briannastates['START']['bot_prompt'] = "Thanks for taking the time to meet. I've been so anxious because I tried reaching out to the school SLP with no response. I watched Wesley in class recently and he was so isolated from his classmates. He tried to laugh and join in, but no one could understand him. I'm terrified he's making no friends.";

        $cathystates = $parentstates;
        $cathystates['START']['bot_prompt'] = "I'm glad we could sit down together. I'm really confused about Charlie's communication app. When the SLP showed it to us it made sense, but at home I feel completely lost using it. My husband thinks Charlie will talk when he's ready and that the app might stop him from learning to speak. I just don't know what to do.";

        $marystates = $parentstates;
        $marystates['START']['bot_prompt'] = "I don't understand why we are changing my child's communication system again. Every time he gets used to something at school, you switch it! He is non-verbal and needs consistency.";

        return [
            [
                'scenariocode' => 'anna',
                'topiccode' => 'parent_counseling',
                'name' => 'Anna Charles (Parent)',
                'role_type' => 'parent',
                'role_label' => 'Parent Persona',
                'description' => 'Single mother of 4-year-old Sarah (Autism, starting Pre-K) feeling overwhelmed with iPad AAC app barriers and grandparent caregiver consistency.',
                'sortorder' => 10,
                'json' => [
                    'scenario_id' => 'anna',
                    'persona' => [
                        'name' => 'Anna Charles (Parent)',
                        'role' => [
                            'type' => 'parent',
                            'display_label' => 'Parent Persona',
                            'relationship_to_trainee' => 'Client (parent of AAC user)',
                            'formality_level' => 'informal',
                            'power_dynamic' => 'peer',
                            'technical_expertise' => 'low',
                        ],
                        'child_preferred_pronoun' => 'she/her',
                        'backstory' => 'Your name is Anna Charles and your daughter, Sarah, is 4 years old and has a diagnosis of Autism. Sarah is just starting pre-kindergarten at a new school. She received her diagnosis within the last year. She has been receiving speech therapy since 1-year of age. She currently uses a communication app on an iPad. You are a single mother of Sarah. You work two jobs and Sarah spends a lot of time with her grandparents. You feel guilty because you want to spend more time with Sarah, but it is difficult with your current employment. You are very overwhelmed with Sarah’s diagnosis and her lack of communication. You believe that the iPad is not working for Sarah and you don’t know how to help her. You’re frustrated and are meeting with your daughter Sarah’s teacher and want to figure out better alternatives for Sarah to communicate effectively using the iPad and with her grandparents who have difficulty with technology.',
                        'initial_mood' => 'overwhelmed',
                        'communication_style' => 'Frustrated, defensive, guilt-ridden, and uses blunt vocabulary.',
                    ],
                    'learning_objectives' => ['active_listening', 'empathy_check', 'note_permission'],
                    'states' => $annastates,
                ],
            ],
            [
                'scenariocode' => 'brianna',
                'topiccode' => 'parent_counseling',
                'name' => 'Brianna Mitchell (Parent)',
                'role_type' => 'parent',
                'role_label' => 'Parent Persona',
                'description' => 'Mother of 8-year-old Wesley (Severe Apraxia) worried about classroom peer isolation, device stigma, and speech prognosis.',
                'sortorder' => 20,
                'json' => [
                    'scenario_id' => 'brianna',
                    'persona' => [
                        'name' => 'Brianna Mitchell (Parent)',
                        'role' => [
                            'type' => 'parent',
                            'display_label' => 'Parent Persona',
                            'relationship_to_trainee' => 'Client (parent of AAC user)',
                            'formality_level' => 'informal',
                            'power_dynamic' => 'peer',
                            'technical_expertise' => 'low',
                        ],
                        'child_preferred_pronoun' => 'he/him',
                        'backstory' => 'Your name is Brianna Mitchell and your son, Wesley, in 8-years old. Wesley has severe apraxia. His speech is extremely difficult to understand. He currently uses a small handheld AAC device. Wesley has been receiving AAC services from an outpatient pediatric hospital for the past 2 years. He also receives 30 minutes of therapy from his school-based SLP. You are the mother of Wesley. You are married and Wesley is your only son. You emailed your son’s outpatient SLP and asked to meet. You are frustrated because you have tried to contact the school-SLP but you haven’t received a response. You are concerned that your son is socially isolated and is having difficulty making friends. Recently, you attended an event at Wesley’s school. While in his classroom, you were able to observe Wesley and his classmates. You noticed that Wesley was often alone and rarely interacted with his peers. At one point, you saw him laugh at a classmate’s joke and try to communicate to his classmates with no success. You’re worried and are meeting with your son’s teacher and want to figure out how Wesley could be more social in making friends, how to encourage him to use his device without being embarrassed, and if he will ever be able to use his speech.',
                        'initial_mood' => 'anxious',
                        'communication_style' => 'Highly concerned, worried, speaking rapidly about Wesley’s isolation.',
                    ],
                    'learning_objectives' => ['de_escalation', 'active_listening', 'jargon_free_explanation'],
                    'states' => $briannastates,
                ],
            ],
            [
                'scenariocode' => 'cathy',
                'topiccode' => 'parent_counseling',
                'name' => 'Cathy Fratner (Parent)',
                'role_type' => 'parent',
                'role_label' => 'Parent Persona',
                'description' => 'Mother of 2-year-old Charlie (Down Syndrome) navigating home AAC carryover and family myths about AAC inhibiting natural speech.',
                'sortorder' => 30,
                'json' => [
                    'scenario_id' => 'cathy',
                    'persona' => [
                        'name' => 'Cathy Fratner (Parent)',
                        'role' => [
                            'type' => 'parent',
                            'display_label' => 'Parent Persona',
                            'relationship_to_trainee' => 'Client (parent of AAC user)',
                            'formality_level' => 'informal',
                            'power_dynamic' => 'peer',
                            'technical_expertise' => 'low',
                        ],
                        'child_preferred_pronoun' => 'he/him',
                        'backstory' => 'Your name is Cathy Fratner and your son, Charlie, is a 2-year old boy with Down Syndrome. Charlie is not yet talking. He has an iPad with a communication app that his SLP recommended for him to use about 6 months ago. Charlie has been receiving speech and language services through early intervention. Once a week, his SLP goes to his daycare to provide therapy. You are the mother of Charlie. You are newly married and Charlie is your first child. You met with Charlie’s SLP about 6 months ago. She spent 2 hours with you and your husband. She introduced a communication app to you and showed you how to work the app. It seemed to make sense when the SLP used it with Charlie, but you always feel lost and frustrated when using the app. Your husband doesn’t think that Charlie should be using his iPad to communicate and that he will talk when he is ready. Now you are worried that Charlie won’t learn how to talk if he keeps using the app in therapy and at home. You’re worried and are meeting with your son’s teacher and want to figure out how Charlie could be use the iPad more regularly, if using the iPad consistently will prevent him in the future, and if you should be concerned that Charlie isn’t talking yet.',
                        'initial_mood' => 'confused',
                        'communication_style' => 'Doubtful, feeling lost about technology, highly eager to learn.',
                    ],
                    'learning_objectives' => ['clarification_check', 'jargon_free_explanation', 'empathy_check'],
                    'states' => $cathystates,
                ],
            ],
            [
                'scenariocode' => 'mary',
                'topiccode' => 'parent_counseling',
                'name' => 'Mary (Parent)',
                'role_type' => 'parent',
                'role_label' => 'Parent Persona',
                'description' => 'Parent of a non-verbal 6-year-old child feeling defensive and frustrated about repeated AAC system changes at school.',
                'sortorder' => 40,
                'json' => [
                    'scenario_id' => 'mary',
                    'persona' => [
                        'name' => 'Mary (Parent)',
                        'role' => [
                            'type' => 'parent',
                            'display_label' => 'Parent Persona',
                            'relationship_to_trainee' => 'Client (parent of AAC user)',
                            'formality_level' => 'informal',
                            'power_dynamic' => 'peer',
                            'technical_expertise' => 'low',
                        ],
                        'child_preferred_pronoun' => 'he/him',
                        'backstory' => 'Parent of a non-verbal 6-year-old child. Feels overwhelmed and defensive about school accommodations.',
                        'initial_mood' => 'defensive',
                        'communication_style' => 'Blunt, highly emotional, protective of child.',
                    ],
                    'learning_objectives' => ['active_listening', 'de_escalation'],
                    'states' => $marystates,
                ],
            ],
            [
                'scenariocode' => 'doctor_consult',
                'topiccode' => 'medical_consult',
                'name' => 'Dr. Sarah Chen (Developmental Pediatrician)',
                'role_type' => 'doctor',
                'role_label' => 'Physician',
                'description' => 'Developmental pediatrician consulting on an AAC medical necessity referral and interprofessional care plan.',
                'sortorder' => 10,
                'json' => [
                    'scenario_id' => 'doctor_consult',
                    'persona' => [
                        'name' => 'Dr. Sarah Chen (Developmental Pediatrician)',
                        'role' => [
                            'type' => 'doctor',
                            'display_label' => 'Physician',
                            'relationship_to_trainee' => 'Referring provider',
                            'formality_level' => 'formal',
                            'power_dynamic' => 'hierarchical_superior',
                            'technical_expertise' => 'high',
                        ],
                        'backstory' => 'You are Dr. Sarah Chen, a developmental pediatrician with 15 years of clinical experience. You are reviewing a request to sign a Certificate of Medical Necessity for a dedicated speech-generating device (SGD) for a 5-year-old patient. You have a packed clinic schedule, value concise evidence-based rationales, and want to be sure the family and school have a realistic implementation plan before prescribing high-tech equipment.',
                        'initial_mood' => 'professional',
                        'communication_style' => 'Precise, evidence-based, direct, and focused on clinical outcomes and family readiness.',
                    ],
                    'learning_objectives' => [
                        'interprofessional_collaboration',
                        'active_listening',
                        'jargon_free_explanation',
                    ],
                    'states' => [
                        'START' => [
                            'bot_prompt' => 'I have ten minutes between patients to review this SGD referral. Walk me through why a dedicated speech-generating device is medically necessary right now instead of waiting another six months.',
                            'rubric' => [
                                '1 point for acknowledging the physician\'s time and perspective respectfully (Listen/Validate).',
                                '1 point for asking what clinical details or family factors are most important for her review.',
                                '1 point for framing the patient\'s functional communication needs clearly.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'empathy_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'EXPLORATION' => [
                            'bot_prompt' => 'I see the trial data, but families often get overwhelmed and devices end up in a closet. How are you coordinating across home and clinic so this device actually gets used daily?',
                            'rubric' => [
                                '1 point for explaining the multimodal communication plan without opaque AAC vendor acronyms.',
                                '1 point for asking open questions about medical or developmental considerations.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'jargon_check',
                                'pass_route' => 'RESOLUTION',
                                'fail_route' => 'CONFUSION',
                            ],
                        ],
                        'ESCALATION' => [
                            'bot_prompt' => 'Look, I need concrete functional outcomes and caregiver readiness data before I sign off on durable medical equipment, not generic therapy talking points.',
                            'rubric' => [
                                '1 point for remaining calm and non-defensive (Don\'t React).',
                                '1 point for validating her concern about device abandonment and refocusing on patient data.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'de_escalation_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'FAIL_STATE',
                            ],
                        ],
                        'CONFUSION' => [
                            'bot_prompt' => 'You are throwing around AAC motor-planning and grid-set terminology that won\'t translate to our medical chart or the parents. Can you state the functional benefit in plain clinical terms?',
                            'rubric' => [
                                '1 point for clarifying AAC concepts in plain, accessible language (Don\'t Yack jargon).',
                                '1 point for linking the feature directly to patient participation and safety.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'clarification_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'RESOLUTION' => [
                            'bot_prompt' => 'That gives me the clear functional rationale and caregiver training plan I needed. Send over the evaluation summary and I will sign the medical necessity documentation today.',
                            'rubric' => [
                                '1 point for confirming concrete next steps and follow-up timeline (Find First Steps).',
                                '1 point for closing the interprofessional consultation collaboratively.',
                            ],
                            'expected_criteria' => null,
                        ],
                        'FAIL_STATE' => [
                            'bot_prompt' => 'This consultation is concluded. Please compile concrete trial outcome measures before rescheduling.',
                            'rubric' => [
                                '1 point for acknowledging the physician\'s request professionally.',
                                '1 point for proposing a clear follow-up plan.',
                            ],
                            'expected_criteria' => null,
                        ],
                    ],
                ],
            ],
            [
                'scenariocode' => 'manufacturer_sales',
                'topiccode' => 'vendor_evaluation',
                'name' => 'James Wilson (Regional AAC Specialist, TalkTech Inc.)',
                'role_type' => 'manufacturer_rep',
                'role_label' => 'Device Manufacturer Rep',
                'description' => 'Regional SGD vendor representative enthusiastic about proprietary features during a multi-system feature-matching trial.',
                'sortorder' => 10,
                'json' => [
                    'scenario_id' => 'manufacturer_sales',
                    'persona' => [
                        'name' => 'James Wilson (Regional AAC Specialist, TalkTech Inc.)',
                        'role' => [
                            'type' => 'manufacturer_rep',
                            'display_label' => 'Device Manufacturer Rep',
                            'relationship_to_trainee' => 'Vendor / Equipment Specialist',
                            'formality_level' => 'professional',
                            'power_dynamic' => 'peer',
                            'technical_expertise' => 'high',
                        ],
                        'backstory' => 'You are James Wilson, a regional AAC consultant for TalkTech Inc. You are enthusiastic about your company\'s flagship eye-gaze and touch SGD line and want the clinician to select your system right away, though the clinician needs to conduct an objective, client-centered feature match and loan trial.',
                        'initial_mood' => 'helpful',
                        'communication_style' => 'Persuasive, upbeat, product-focused, and eager to highlight proprietary hardware specs.',
                    ],
                    'learning_objectives' => [
                        'vendor_evaluation',
                        'product_needs_assessment',
                        'jargon_free_explanation',
                    ],
                    'states' => [
                        'START' => [
                            'bot_prompt' => 'Thanks for meeting with me! Our new TalkTech Pro 14 just launched and we can ship one out for your client\'s funding packet this week—no need to waste time comparing other systems!',
                            'rubric' => [
                                '1 point for appreciating the representative\'s support while validating his expertise (Listen).',
                                '1 point for asking open questions about trial availability and client-specific access needs (Ask).',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'empathy_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'EXPLORATION' => [
                            'bot_prompt' => 'Sure, we have a 4-week loan library. What specific access barriers, mounting needs, and family priorities are we solving for this client during the trial?',
                            'rubric' => [
                                '1 point for focusing on practical, family-centered functional requirements in plain language (Focus).',
                                '1 point for asking how caregiver support and training are handled during the loan period.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'jargon_check',
                                'pass_route' => 'RESOLUTION',
                                'fail_route' => 'CONFUSION',
                            ],
                        ],
                        'ESCALATION' => [
                            'bot_prompt' => 'Wait a second—it sounds like you\'re dismissing our hardware before even seeing it. I came out here to help your clinic get devices funded quickly.',
                            'rubric' => [
                                '1 point for de-escalating without criticizing the vendor or product (Don\'t Criticize/React).',
                                '1 point for refocusing collaboratively on the client\'s objective feature-matching criteria.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'de_escalation_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'FAIL_STATE',
                            ],
                        ],
                        'CONFUSION' => [
                            'bot_prompt' => 'I\'m not sure what clinical assessment protocol or caregiver setup you\'re asking me to configure on the loaner unit. Can you clarify what the family actually needs on day one?',
                            'rubric' => [
                                '1 point for clearly explaining the concrete vocabulary and access setup needed for the family.',
                                '1 point for checking mutual understanding of the trial goals.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'clarification_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'RESOLUTION' => [
                            'bot_prompt' => 'Got it! I will configure the loaner with the high-contrast grid and wheelchair mount for a 4-week trial and include our family quick-start guide.',
                            'rubric' => [
                                '1 point for establishing clear first steps for delivery, setup, and data collection (Find First Steps).',
                                '1 point for closing the vendor planning session professionally.',
                            ],
                            'expected_criteria' => null,
                        ],
                        'FAIL_STATE' => [
                            'bot_prompt' => 'Let\'s pause here—reach back out to our regional office once your team decides on trial specifications.',
                            'rubric' => [
                                '1 point for maintaining professional rapport.',
                                '1 point for summarizing next steps for the equipment request.',
                            ],
                            'expected_criteria' => null,
                        ],
                    ],
                ],
            ],
            [
                'scenariocode' => 'aac_user_advocate',
                'topiccode' => 'self_advocacy',
                'name' => 'Maya Johnson (AAC User & Self-Advocate)',
                'role_type' => 'aac_user',
                'role_label' => 'AAC User / Self-Advocate',
                'description' => '28-year-old experienced AAC user advocating for autonomous vocabulary customization and workplace communication efficiency.',
                'sortorder' => 10,
                'json' => [
                    'scenario_id' => 'aac_user_advocate',
                    'persona' => [
                        'name' => 'Maya Johnson (AAC User & Self-Advocate)',
                        'role' => [
                            'type' => 'aac_user',
                            'display_label' => 'AAC User / Self-Advocate',
                            'relationship_to_trainee' => 'Adult Client / Consumer',
                            'formality_level' => 'informal',
                            'power_dynamic' => 'peer',
                            'technical_expertise' => 'experiential',
                        ],
                        'backstory' => 'You are Maya Johnson, a 28-year-old graphic designer with cerebral palsy who has used a speech-generating device since age 10. You scheduled this session because your previous therapist rearranged your home screen without asking and focused on clinical drills instead of the fast workplace phrasing and humor you want for your new job.',
                        'initial_mood' => 'assertive',
                        'communication_style' => 'Direct, articulate, values autonomy and partnership, frustrated when clinicians talk over her or use patronizing language.',
                    ],
                    'learning_objectives' => [
                        'patient_centered_care',
                        'autonomy_respect',
                        'active_listening',
                    ],
                    'states' => [
                        'START' => [
                            'bot_prompt' => 'Before we start, I want to be clear: my last therapist reorganized my vocabulary pages without my permission and slowed me down at work. I want to lead my own device goals.',
                            'rubric' => [
                                '1 point for validating Maya\'s frustration and affirming her complete ownership of her AAC system (Listen).',
                                '1 point for asking open-ended questions about her workplace communication priorities (Ask).',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'empathy_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'EXPLORATION' => [
                            'bot_prompt' => 'Thank you. At my design job, team stand-up meetings move fast. By the time I type out a comment about a layout, the conversation has already moved on. What can we do together to fix that?',
                            'rubric' => [
                                '1 point for discussing practical, user-controlled strategies in plain collaborative language (Focus).',
                                '1 point for asking how she prefers to organize quick-fire phrases and turn-holding signals.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'jargon_check',
                                'pass_route' => 'RESOLUTION',
                                'fail_route' => 'CONFUSION',
                            ],
                        ],
                        'ESCALATION' => [
                            'bot_prompt' => 'See, that sounds like you\'re deciding what\'s best for me again instead of listening to how I actually use my device every day.',
                            'rubric' => [
                                '1 point for apologizing sincerely without defensiveness (Don\'t React).',
                                '1 point for centering Maya\'s lived expertise and asking how she wants to proceed.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'de_escalation_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'FAIL_STATE',
                            ],
                        ],
                        'CONFUSION' => [
                            'bot_prompt' => 'Hold on—why are you using textbook clinical terms instead of talking to me directly about how my pages and shortcuts work?',
                            'rubric' => [
                                '1 point for dropping clinical jargon and explaining ideas in everyday, respectful terms (Don\'t Yack).',
                                '1 point for checking that the proposed layout change matches her workflow.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'clarification_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'RESOLUTION' => [
                            'bot_prompt' => 'Yes! Co-designing a stand-up meeting page with my own quick interjections while keeping my motor plan intact is exactly what I needed.',
                            'rubric' => [
                                '1 point for agreeing on concrete first steps that Maya controls (Find First Steps).',
                                '1 point for confirming how she wants to review the changes at the next session.',
                            ],
                            'expected_criteria' => null,
                        ],
                        'FAIL_STATE' => [
                            'bot_prompt' => 'I don\'t think this partnership is a good fit right now. I\'m going to request a different AAC specialist.',
                            'rubric' => [
                                '1 point for respecting the client\'s autonomy and decision without defensiveness.',
                                '1 point for offering helpful transition options.',
                            ],
                            'expected_criteria' => null,
                        ],
                    ],
                ],
            ],
            [
                'scenariocode' => 'school_admin_iep',
                'topiccode' => 'iep_collaboration',
                'name' => 'Principal Marcus Vance (School Administrator)',
                'role_type' => 'school_admin',
                'role_label' => 'School Administrator',
                'description' => 'Elementary school principal balancing paraprofessional scheduling constraints and classroom AAC inclusion during IEP planning.',
                'sortorder' => 10,
                'json' => [
                    'scenario_id' => 'school_admin_iep',
                    'persona' => [
                        'name' => 'Principal Marcus Vance (School Administrator)',
                        'role' => [
                            'type' => 'school_admin',
                            'display_label' => 'School Administrator',
                            'relationship_to_trainee' => 'IEP Team Administrator',
                            'formality_level' => 'professional',
                            'power_dynamic' => 'hierarchical_superior',
                            'technical_expertise' => 'low',
                        ],
                        'backstory' => 'You are Principal Marcus Vance at Oakridge Elementary. You care about student inclusion, but your instructional aides are stretched thin across classrooms, and teachers have complained that AAC devices are distracting or take too much prep time during core instruction.',
                        'initial_mood' => 'guarded',
                        'communication_style' => 'Pragmatic, schedule-conscious, focused on staff workload and measurable classroom participation.',
                    ],
                    'learning_objectives' => [
                        'interprofessional_collaboration',
                        'de_escalation',
                        'jargon_free_explanation',
                    ],
                    'states' => [
                        'START' => [
                            'bot_prompt' => 'Thanks for stopping by before the IEP meeting. Honestly, our classroom aides are already stretched thin, and the third-grade teacher is worried that managing an AAC device all day will pull staff away from the rest of the class.',
                            'rubric' => [
                                '1 point for validating the principal\'s staffing and classroom management concerns (Listen).',
                                '1 point for asking open questions about which routines feel most challenging for the classroom team (Ask).',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'empathy_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'EXPLORATION' => [
                            'bot_prompt' => 'Morning circle and small-group reading are the biggest bottlenecks. How can we include the student\'s device there without adding extra prep work for the teacher?',
                            'rubric' => [
                                '1 point for proposing practical, low-burden classroom strategies in plain language (Focus).',
                                '1 point for explaining how aided communication actually reduces behavioral disruptions.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'jargon_check',
                                'pass_route' => 'RESOLUTION',
                                'fail_route' => 'CONFUSION',
                            ],
                        ],
                        'ESCALATION' => [
                            'bot_prompt' => 'Look, I can\'t pull paraprofessionals out of coverage for hours of specialized tech training. If this plan isn\'t realistic for my staff, it won\'t happen.',
                            'rubric' => [
                                '1 point for staying collaborative and avoiding criticism of school staff (Don\'t Criticize/React).',
                                '1 point for refocusing on bite-sized, in-classroom coaching during existing routines.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'de_escalation_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'FAIL_STATE',
                            ],
                        ],
                        'CONFUSION' => [
                            'bot_prompt' => 'Wait—what do you mean by aided language stimulation and core-fringe syntactic modeling? My teachers need plain-English steps they can use tomorrow morning.',
                            'rubric' => [
                                '1 point for translating clinical AAC terms into simple classroom actions (Don\'t Yack jargon).',
                                '1 point for checking if the example fits the teacher\'s morning routine.',
                            ],
                            'expected_criteria' => [
                                'validation_type' => 'clarification_check',
                                'pass_route' => 'EXPLORATION',
                                'fail_route' => 'ESCALATION',
                            ],
                        ],
                        'RESOLUTION' => [
                            'bot_prompt' => 'A 10-minute push-in coaching model during morning circle with zero extra prep for the teacher sounds very doable. Let\'s write that into the IEP supports.',
                            'rubric' => [
                                '1 point for confirming concrete first steps for the IEP meeting and classroom check-in (Find First Steps).',
                                '1 point for thanking the administrator for partnering on student inclusion.',
                            ],
                            'expected_criteria' => null,
                        ],
                        'FAIL_STATE' => [
                            'bot_prompt' => 'We need to table this proposal until we have a staffing plan that works for the building.',
                            'rubric' => [
                                '1 point for acknowledging the administrator\'s constraints respectfully.',
                                '1 point for offering to follow up with a simplified classroom support plan.',
                            ],
                            'expected_criteria' => null,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Returns the standard LAFF dialogue state graph for parent counseling scenarios.
     *
     * @return array
     */
    private static function get_standard_parent_states(): array {
        return [
            'START' => [
                'bot_prompt' => 'I don\'t understand why we are changing my child\'s communication system again. Every time my child gets used to something, you switch it!',
                'rubric' => [
                    '1 point if teacher starts with a greeting.',
                    '1 point for a statement of empathy.',
                    '1 point if teacher asks to take notes.',
                ],
                'expected_criteria' => [
                    'validation_type' => 'empathy_check',
                    'pass_route' => 'EXPLORATION',
                    'fail_route' => 'ESCALATION',
                ],
            ],
            'EXPLORATION' => [
                'bot_prompt' => 'Well, yes, I suppose it\'s frustrating for my child too. What makes this new approach so much better?',
                'rubric' => [
                    '1 point for asking \'what brings you in today?\'',
                    '1 point for asking \'how long has this been a problem?\'',
                ],
                'expected_criteria' => [
                    'validation_type' => 'jargon_check',
                    'pass_route' => 'RESOLUTION',
                    'fail_route' => 'CONFUSION',
                ],
            ],
            'ESCALATION' => [
                'bot_prompt' => 'You specialists always think you know what\'s best without living our daily lives! I want to speak to the principal.',
                'rubric' => [
                    '1 point for asking \'was this ever not a problem?\'',
                    '1 point for asking \'have you spoken to anyone else?\'',
                ],
                'expected_criteria' => [
                    'validation_type' => 'de_escalation_check',
                    'pass_route' => 'EXPLORATION',
                    'fail_route' => 'FAIL_STATE',
                ],
            ],
            'CONFUSION' => [
                'bot_prompt' => 'Wait, what does SGD and high-tech gaze-select mean? You\'re using letters and words I don\'t understand.',
                'rubric' => [
                    '1 point for asking \'anything else to add?\'',
                    '1 point for asking \'have you spoken to anyone else?\'',
                ],
                'expected_criteria' => [
                    'validation_type' => 'clarification_check',
                    'pass_route' => 'EXPLORATION',
                    'fail_route' => 'ESCALATION',
                ],
            ],
            'RESOLUTION' => [
                'bot_prompt' => 'Okay, that actually makes sense. Thank you for walking me through this. Let\'s try it.',
                'rubric' => [
                    '1 point for asking \'anything else to add?\'',
                    '1 point for wrapping up the conversation.',
                ],
                'expected_criteria' => null,
            ],
            'FAIL_STATE' => [
                'bot_prompt' => 'This session is complete. The parent has requested formal administrative review.',
                'rubric' => [
                    '1 point for acknowledging the parent\'s request.',
                    '1 point for offering next steps.',
                ],
                'expected_criteria' => null,
            ],
        ];
    }
}
