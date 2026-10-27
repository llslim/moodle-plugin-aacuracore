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
 * External AJAX API for per-activity Topic & Scenario Library management.
 *
 * Supports Course Edit Mode inline configuration of Topics & Scenarios per cmid,
 * saving AI Scenario Builder scenarios directly to the Course Topic Library,
 * and Site Administrator promotion of course scenarios/topics to the System Library.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aacuracore\external;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use context_course;
use context_module;
use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_aacuracore\library\topic_library_manager;
use local_aacuracore\scenario\scenario_loader;
use required_capability_exception;

/**
 * Class activity_library
 */
class activity_library extends external_api {

    /**
     * Parameter specification for local_aacuracore_activity_library.
     *
     * @return external_function_parameters
     */
    public static function api_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action to execute'),
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course Module ID (cmid)', VALUE_DEFAULT, 0),
            'topiccode' => new external_value(PARAM_ALPHANUMEXT, 'Selected Topic Code', VALUE_DEFAULT, ''),
            'scenariolist' => new external_value(PARAM_RAW, 'JSON array of enabled scenario codes', VALUE_DEFAULT, '[]'),
            'scenariocode' => new external_value(PARAM_ALPHANUMEXT, 'Default starting scenario code', VALUE_DEFAULT, ''),
            'allow_persona_switch' => new external_value(PARAM_INT, 'Whether students can switch personas (1 or 0)', VALUE_DEFAULT, 1),
            'grading_mode' => new external_value(PARAM_ALPHANUMEXT, 'Multi-scenario grading mode', VALUE_DEFAULT, 'single_highest'),
            'scenario_json' => new external_value(PARAM_RAW, 'Raw scenario JSON from AI Scenario Builder', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the requested Topic & Scenario Library action.
     *
     * @param string $action
     * @param int $courseid
     * @param int $cmid
     * @param string $topiccode
     * @param string $scenariolist
     * @param string $scenariocode
     * @param int $allowpersonaswitch
     * @param string $gradingmode
     * @param string $scenariojson
     * @return array
     */
    public static function api(
        string $action,
        int $courseid = 0,
        int $cmid = 0,
        string $topiccode = '',
        string $scenariolist = '[]',
        string $scenariocode = '',
        int $allowpersonaswitch = 1,
        string $gradingmode = 'single_highest',
        string $scenariojson = ''
    ): array {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::api_parameters(), [
            'action' => $action,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'topiccode' => $topiccode,
            'scenariolist' => $scenariolist,
            'scenariocode' => $scenariocode,
            'allow_persona_switch' => $allowpersonaswitch,
            'grading_mode' => $gradingmode,
            'scenario_json' => $scenariojson,
        ]);

        $cm = null;
        $aacurachat = null;
        if ($params['cmid'] > 0) {
            $cm = get_coursemodule_from_id('aacurachat', $params['cmid'], 0, false, IGNORE_MISSING);
            if ($cm) {
                $params['courseid'] = (int)$cm->course;
                $aacurachat = $DB->get_record('aacurachat', ['id' => $cm->instance], '*', IGNORE_MISSING);
            }
        }

        if ($cm) {
            $context = context_module::instance($cm->id);
        } else if ($params['courseid'] > 0) {
            $context = context_course::instance($params['courseid']);
        } else {
            $context = context_system::instance();
        }

        self::validate_context($context);

        $canmanage = has_capability('local/aacuracore:manage', $context)
            || has_capability('mod/aacurachat:addinstance', $context)
            || has_capability('moodle/course:manageactivities', $context);

        if (!$canmanage) {
            throw new required_capability_exception($context, 'local/aacuracore:manage', 'nopermissions', '');
        }

        topic_library_manager::ensure_seeded();

        switch ($params['action']) {
            case 'get_library':
                return self::handle_get_library((int)$params['courseid'], $aacurachat);

            case 'save_activity_config':
                return self::handle_save_activity_config(
                    (int)$params['courseid'],
                    (int)$params['cmid'],
                    $aacurachat,
                    $params['topiccode'],
                    $params['scenariolist'],
                    $params['scenariocode'],
                    (int)$params['allow_persona_switch'],
                    $params['grading_mode']
                );

            case 'save_builder_scenario':
                return self::handle_save_builder_scenario(
                    (int)$params['courseid'],
                    (int)$params['cmid'],
                    $aacurachat,
                    $params['topiccode'],
                    $params['scenario_json'],
                    (int)$USER->id
                );

            case 'promote_scenario':
                require_capability('moodle/site:config', context_system::instance());
                $ok = topic_library_manager::promote_to_system_scope($params['scenariocode'], (int)$params['courseid']);
                return [
                    'success' => $ok,
                    'message' => $ok
                        ? "Scenario '{$params['scenariocode']}' promoted to System Library."
                        : "Could not promote scenario '{$params['scenariocode']}'.",
                    'data' => json_encode(['scenariocode' => $params['scenariocode']]),
                ];

            case 'promote_topic':
                require_capability('moodle/site:config', context_system::instance());
                $ok = topic_library_manager::promote_topic_to_system($params['topiccode'], (int)$params['courseid']);
                return [
                    'success' => $ok,
                    'message' => $ok
                        ? "Topic '{$params['topiccode']}' promoted to System Library."
                        : "Could not promote topic '{$params['topiccode']}'.",
                    'data' => json_encode(['topiccode' => $params['topiccode']]),
                ];

            default:
                return [
                    'success' => false,
                    'message' => 'Unknown action: ' . $params['action'],
                    'data' => '{}',
                ];
        }
    }

    /**
     * Build the Topic & Scenario Library payload for a course and activity.
     *
     * @param int $courseid
     * @param \stdClass|null $aacurachat
     * @return array
     */
    protected static function handle_get_library(int $courseid, ?\stdClass $aacurachat): array {
        $topics = topic_library_manager::get_topics($courseid);
        $scenarios = topic_library_manager::get_scenarios_for_topic(null, $courseid, true);

        $topicslist = [];
        foreach ($topics as $t) {
            $topicslist[] = [
                'topiccode' => $t->topiccode,
                'name' => $t->name,
                'description' => $t->description ?? '',
                'scope' => $t->scope,
                'courseid' => (int)$t->courseid,
                'default_min_turns' => (int)$t->default_min_turns,
                'default_intensity' => $t->default_intensity ?? 'medium',
            ];
        }

        $scenarioslist = [];
        foreach ($scenarios as $s) {
            $scenarioslist[] = [
                'scenariocode' => $s->scenariocode,
                'topiccode' => $s->topiccode,
                'name' => $s->name,
                'role_type' => $s->role_type ?? 'parent',
                'role_label' => $s->role_label ?? 'Parent',
                'description' => $s->description ?? '',
                'scope' => $s->scope,
                'courseid' => (int)$s->courseid,
                'is_builtin' => (int)$s->is_builtin,
            ];
        }

        $activitydata = [
            'topiccode' => $aacurachat->topiccode ?? 'parent_counseling',
            'scenariolist' => $aacurachat ? topic_library_manager::resolve_activity_scenariolist($aacurachat) : ['anna'],
            'scenariocode' => $aacurachat->scenariocode ?? 'anna',
            'allow_persona_switch' => isset($aacurachat->allow_persona_switch) ? (int)$aacurachat->allow_persona_switch : 1,
            'grading_mode' => !empty($aacurachat->grading_mode) ? $aacurachat->grading_mode : 'single_highest',
        ];

        return [
            'success' => true,
            'message' => 'Library loaded.',
            'data' => json_encode([
                'topics' => $topicslist,
                'scenarios' => $scenarioslist,
                'activity' => $activitydata,
            ]),
        ];
    }

    /**
     * Save per-activity Topic & Scenario configuration from Course Edit Mode.
     *
     * @param int $courseid
     * @param int $cmid
     * @param \stdClass|null $aacurachat
     * @param string $topiccode
     * @param string $scenariolistjson
     * @param string $scenariocode
     * @param int $allowpersonaswitch
     * @param string $gradingmode
     * @return array
     */
    protected static function handle_save_activity_config(
        int $courseid,
        int $cmid,
        ?\stdClass $aacurachat,
        string $topiccode,
        string $scenariolistjson,
        string $scenariocode,
        int $allowpersonaswitch,
        string $gradingmode
    ): array {
        global $DB, $CFG;

        if (!$aacurachat || $cmid <= 0) {
            return [
                'success' => false,
                'message' => 'Activity instance not found for cmid ' . $cmid,
                'data' => '{}',
            ];
        }

        $decodedlist = json_decode($scenariolistjson, true);
        $cleanlist = [];
        if (is_array($decodedlist)) {
            foreach ($decodedlist as $code) {
                $cleaned = clean_param((string)$code, PARAM_ALPHANUMEXT);
                if ($cleaned !== '' && !in_array($cleaned, $cleanlist, true)) {
                    $cleanlist[] = $cleaned;
                }
            }
        }

        if (empty($cleanlist)) {
            $fallback = $scenariocode !== '' ? $scenariocode : ($aacurachat->scenariocode ?? 'anna');
            $cleanlist = [$fallback];
        }

        if ($scenariocode === '' || !in_array($scenariocode, $cleanlist, true)) {
            $scenariocode = $cleanlist[0];
        }

        $validgradingmodes = ['single_highest', 'single_latest', 'all_average'];
        if (!in_array($gradingmode, $validgradingmodes, true)) {
            $gradingmode = 'single_highest';
        }

        $aacurachat->topiccode = $topiccode !== '' ? $topiccode : ($aacurachat->topiccode ?? 'parent_counseling');
        $aacurachat->scenariolist = json_encode(array_values($cleanlist));
        $aacurachat->scenariocode = $scenariocode;
        $aacurachat->allow_persona_switch = $allowpersonaswitch ? 1 : 0;
        $aacurachat->grading_mode = $gradingmode;
        $aacurachat->timemodified = time();

        $DB->update_record('aacurachat', $aacurachat);

        // Recalculate grades if grading_mode or scenariolist changed.
        $modlib = $CFG->dirroot . '/mod/aacurachat/lib.php';
        if (file_exists($modlib)) {
            require_once($modlib);
            if (function_exists('aacurachat_update_grades')) {
                aacurachat_update_grades($aacurachat);
            }
        }

        // Build updated persona dropdown options for the frontend.
        $allscenarios = topic_library_manager::get_scenarios_for_topic(null, $courseid, true);
        $personas = [];
        foreach ($cleanlist as $code) {
            if (isset($allscenarios[$code])) {
                $sc = $allscenarios[$code];
                $personas[] = [
                    'code' => $sc->scenariocode,
                    'name' => $sc->name . (!empty($sc->role_label) ? " ({$sc->role_label})" : ''),
                    'role_label' => $sc->role_label ?: 'Parent',
                    'selected' => ($sc->scenariocode === $scenariocode),
                ];
            } else {
                $personas[] = [
                    'code' => $code,
                    'name' => ucfirst($code),
                    'role_label' => 'Parent',
                    'selected' => ($code === $scenariocode),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Activity Topic & Scenario configuration saved.',
            'data' => json_encode([
                'topiccode' => $aacurachat->topiccode,
                'scenariolist' => $cleanlist,
                'scenariocode' => $aacurachat->scenariocode,
                'allow_persona_switch' => (int)$aacurachat->allow_persona_switch,
                'grading_mode' => $aacurachat->grading_mode,
                'personas_options' => $personas,
            ]),
        ];
    }

    /**
     * Save a scenario JSON generated by the Automated Scenario Builder into the Course Topic Library
     * and optionally attach it to the current activity's scenariolist.
     *
     * @param int $courseid
     * @param int $cmid
     * @param \stdClass|null $aacurachat
     * @param string $topiccode
     * @param string $scenariojson
     * @param int $userid
     * @return array
     */
    protected static function handle_save_builder_scenario(
        int $courseid,
        int $cmid,
        ?\stdClass $aacurachat,
        string $topiccode,
        string $scenariojson,
        int $userid
    ): array {
        global $DB;

        $decoded = json_decode($scenariojson, true);
        if (!is_array($decoded) || empty($decoded['scenario_id'])) {
            return [
                'success' => false,
                'message' => 'Invalid scenario JSON payload.',
                'data' => '{}',
            ];
        }

        try {
            $def = scenario_loader::from_array($decoded);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Scenario validation failed: ' . $e->getMessage(),
                'data' => '{}',
            ];
        }

        $resolvedtopic = $topiccode;
        if ($resolvedtopic === '' && !empty($decoded['topic'])) {
            $resolvedtopic = clean_param((string)$decoded['topic'], PARAM_ALPHANUMEXT);
        }
        if ($resolvedtopic === '' && $aacurachat && !empty($aacurachat->topiccode)) {
            $resolvedtopic = $aacurachat->topiccode;
        }
        if ($resolvedtopic === '') {
            $resolvedtopic = 'parent_counseling';
        }

        $decoded['topic'] = $resolvedtopic;
        $role = $def->get_role();

        $saved = topic_library_manager::save_scenario([
            'scenariocode' => $def->get_scenario_id(),
            'topiccode' => $resolvedtopic,
            'name' => $def->get_persona_name(),
            'role_type' => $role['type'] ?? 'parent',
            'role_label' => $role['display_label'] ?? 'Parent',
            'description' => $def->get_backstory(),
            'json_data' => json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'scope' => ($courseid > 0) ? 'course' : 'system',
            'courseid' => $courseid,
            'is_builtin' => 0,
            'enabled' => 1,
            'userid' => $userid,
        ]);

        // Also enable on the current activity if cmid is provided.
        $cleanlist = [];
        if ($aacurachat && $cmid > 0) {
            $cleanlist = topic_library_manager::resolve_activity_scenariolist($aacurachat);
            if (!in_array($saved->scenariocode, $cleanlist, true)) {
                $cleanlist[] = $saved->scenariocode;
            }
            $aacurachat->scenariolist = json_encode(array_values($cleanlist));
            $aacurachat->scenariocode = $saved->scenariocode;
            if (empty($aacurachat->topiccode)) {
                $aacurachat->topiccode = $resolvedtopic;
            }
            $aacurachat->timemodified = time();
            $DB->update_record('aacurachat', $aacurachat);
        }

        return [
            'success' => true,
            'message' => "Scenario '{$saved->name}' ({$saved->scenariocode}) saved to the Course Topic Library"
                . ($cmid > 0 ? ' and enabled for this activity.' : '.'),
            'data' => json_encode([
                'scenariocode' => $saved->scenariocode,
                'name' => $saved->name,
                'topiccode' => $saved->topiccode,
                'role_label' => $saved->role_label,
                'scope' => $saved->scope,
                'scenariolist' => $cleanlist,
            ]),
        ];
    }

    /**
     * Return value specification for local_aacuracore_activity_library.
     *
     * @return external_single_structure
     */
    public static function api_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Operation success flag'),
            'message' => new external_value(PARAM_TEXT, 'Human-readable status message'),
            'data' => new external_value(PARAM_RAW, 'JSON-encoded response payload'),
        ]);
    }
}
