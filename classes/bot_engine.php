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

namespace local_aacuracore;

use local_aacuracore\scenario\scenario_loader;
use local_aacuracore\scenario\scenario_definition;
use local_aacuracore\scenario\state_node;

defined('MOODLE_INTERNAL') || die;

/**
 * Core Orchestrator Class bot_engine.
 *
 * Responsibilities:
 * - Manages session lifecycle state machine.
 * - Handles prompt/response pipelines.
 * - Logs turn history to DB tables local_aacuracore_sessions, local_aacuracore_messages, and local_aacuracore_analytics.
 * - Triggers dynamic rubric evaluation & Gradebook integration on turn completion.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC Chatbot Team
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bot_engine {

    /** @var int $userid */
    private int $userid;

    /** @var int $courseid */
    private int $courseid;

    /** @var int $cmid */
    private int $cmid;

    /** @var scenario_definition $scenario */
    private scenario_definition $scenario;

    /** @var mixed $strategy */
    private $strategy;

    /** @var \stdClass $sessionrecord */
    private \stdClass $sessionrecord;

    /** @var int $max_turns Minimum student turns before grading (activity-level override, else global). */
    private int $max_turns;

    /** @var string $parentIntensity Assertiveness/aggressiveness level (activity-level override, else global). */
    private string $parent_intensity;

    /**
     * Constructor.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $cmid
     * @param string $scenariocode
     * @param mixed $strategy Optional strategy override (avoids mutating global config)
     */
    public function __construct(int $userid, int $courseid, int $cmid = 0, string $scenariocode = 'anna', $strategy = null) {
        $this->userid = $userid;
        $this->courseid = $courseid;
        $this->cmid = $cmid;
        $this->scenario = scenario_loader::load($scenariocode, $courseid);

        if ($strategy !== null) {
            $this->strategy = $strategy;
        } else {
            $strategytype = get_config('local_aacuracore', 'engine_strategy') ?: 'external_llm';
            if ($strategytype === 'regex') {
                $this->strategy = new \local_aacuracore\strategy\regex_matcher_strategy();
            } else if ($strategytype === 'moodle_core_ai') {
                $this->strategy = new \local_aacuracore\strategy\core_ai_provider_strategy();
            } else {
                $this->strategy = new \local_aacuracore\strategy\generative_ai_api_strategy();
            }
        }

        $this->resolve_activity_settings();

        $this->sessionrecord = $this->lookup_or_create_session($userid, $courseid, $scenariocode);
    }

    /**
     * Resolve per-activity max_turns and parent_intensity overrides.
     *
     * Reads the aacurachat activity record for this course (when present) and
     * uses its max_turns/parent_intensity columns if set; otherwise falls back
     * to the site-wide global settings (or defaults). The turn count acts as a
     * MINIMUM: the conversation is graded only after this many student turns.
     */
    private function resolve_activity_settings(): void {
        global $DB;

        // Defaults (global config, then hardcoded).
        $maxturns = (int)get_config('local_aacuracore', 'max_turns');
        $intensity = get_config('local_aacuracore', 'parent_intensity') ?: 'medium';
        if ($maxturns <= 0) {
            $maxturns = 8;
        }

        // Try activity-level overrides.
        if ($this->courseid > 0) {
            $aacurachat = $DB->get_record('aacurachat', ['course' => $this->courseid]);
            if ($aacurachat) {
                if (property_exists($aacurachat, 'max_turns') && !empty($aacurachat->max_turns)) {
                    $maxturns = (int)$aacurachat->max_turns;
                }
                if (property_exists($aacurachat, 'parent_intensity') && !empty($aacurachat->parent_intensity)) {
                    $intensity = $aacurachat->parent_intensity;
                }
            }
        }

        // Scenario-level fallback (from the scenario JSON) when no activity override set.
        if ($maxturns === 8 && $this->scenario->get_min_turns() !== null && $this->scenario->get_min_turns() > 0) {
            $maxturns = $this->scenario->get_min_turns();
        }
        if ($intensity === 'medium' && !empty($this->scenario->get_parent_intensity())) {
            $intensity = $this->scenario->get_parent_intensity();
        }

        $this->max_turns = $maxturns;
        $this->parent_intensity = $intensity;
    }

    /**
     * Gets the resolved minimum student turns before grading for this activity/scenario.
     *
     * @return int
     */
    public function get_max_turns(): int {
        return $this->max_turns;
    }

    /**
     * Gets the resolved parent assertiveness/aggressiveness level.
     *
     * @return string
     */
    public function get_parent_intensity(): string {
        return $this->parent_intensity;
    }

    /**
     * Looks up existing active session or initializes a new state machine session.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $scenariocode
     * @return \stdClass
     */
    private function lookup_or_create_session(int $userid, int $courseid, string $scenariocode): \stdClass {
        global $DB;

        $record = $DB->get_record('local_aacuracore_sessions', [
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $this->cmid,
        ], '*', IGNORE_MULTIPLE);

        if ($record && $record->scenariocode !== $scenariocode) {
            $record->scenariocode = $scenariocode;
            $DB->update_record('local_aacuracore_sessions', $record);
        }

        if (!$record) {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->cmid = $this->cmid;
            $record->scenariocode = $scenariocode;
            $record->current_state = 'START';
            $record->timecreated = time();
            $record->timemodified = time();
            $record->id = $DB->insert_record('local_aacuracore_sessions', $record);

            // Seed initial parent prompt into messages if scenario defines START state prompt
            $startnode = $this->scenario->get_state('START');
            if ($startnode && !empty($startnode['bot_prompt'])) {
                $msg = new \stdClass();
                $msg->sessionid = $record->id;
                $msg->sender = 'system';
                $msg->message_text = $startnode['bot_prompt'];
                $msg->timestamp = time();
                $DB->insert_record('local_aacuracore_messages', $msg);
            }
        }

        return $record;
    }

    /**
     * Gets the active scenario definition.
     *
     * @return scenario_definition
     */
    public function get_scenario(): scenario_definition {
        return $this->scenario;
    }

    /**
     * Gets the active response strategy.
     *
     * @return mixed
     */
    public function get_strategy() {
        return $this->strategy;
    }

    /**
     * Sets the active response strategy directly.
     *
     * @param mixed $strategy
     */
    public function set_strategy($strategy): void {
        $this->strategy = $strategy;
    }

    /**
     * Gets active database session record.
     *
     * @return \stdClass
     */
    public function get_session_record(): \stdClass {
        return $this->sessionrecord;
    }

    /**
     * Gets the active state node array data.
     *
     * @return array|null
     */
    public function get_current_state(): ?array {
        $statekey = $this->sessionrecord->current_state ?? 'START';
        return $this->scenario->get_state_node($statekey);
    }

    /**
     * Logs conversation turn message to DB.
     *
     * @param string $sender 'user' | 'system'
     * @param string $message
     * @return int message ID
     */
    public function log_message(string $sender, string $message): int {
        global $DB;

        $record = new \stdClass();
        $record->sessionid = $this->sessionrecord->id;
        $record->sender = $sender;
        $record->message_text = $message;
        $record->timestamp = time();

        return (int)$DB->insert_record('local_aacuracore_messages', $record);
    }

    /**
     * Logs performance metrics to local_aacuracore_analytics DB table.
     *
     * @param string $metrictype
     * @param float $value
     * @return int record ID
     */
    public function log_analytic(string $metrictype, float $value): int {
        global $DB;

        $record = new \stdClass();
        $record->sessionid = $this->sessionrecord->id;
        $record->metric_type = $metrictype;
        $record->metric_value = $value;
        $record->timestamp = time();

        return (int)$DB->insert_record('local_aacuracore_analytics', $record);
    }

    /**
     * Returns all turn messages for active session.
     *
     * @return array
     */
    public function get_messages(): array {
        global $DB;
        return array_values($DB->get_records('local_aacuracore_messages', ['sessionid' => $this->sessionrecord->id], 'timestamp ASC, id ASC'));
    }

    /**
     * Calculates current turn count (student turns).
     *
     * @return int
     */
    public function get_turn_count(): int {
        global $DB;
        return (int)$DB->count_records('local_aacuracore_messages', ['sessionid' => $this->sessionrecord->id, 'sender' => 'user']);
    }

    /**
     * Resets the active session back to START.
     */
    public function reset_session(): void {
        global $DB;

        $DB->delete_records('local_aacuracore_messages', ['sessionid' => $this->sessionrecord->id]);
        $DB->delete_records('local_aacuracore_analytics', ['sessionid' => $this->sessionrecord->id]);
        $DB->delete_records('local_aacuracore_evaluations', ['sessionid' => $this->sessionrecord->id]);

        $this->sessionrecord->current_state = 'START';
        $this->sessionrecord->timemodified = time();
        $DB->update_record('local_aacuracore_sessions', $this->sessionrecord);

        // Seed initial parent prompt into messages if scenario defines START state prompt
        $startnode = $this->scenario->get_state('START');
        if ($startnode && !empty($startnode['bot_prompt'])) {
            $msg = new \stdClass();
            $msg->sessionid = $this->sessionrecord->id;
            $msg->sender = 'system';
            $msg->message_text = $startnode['bot_prompt'];
            $msg->timestamp = time();
            $DB->insert_record('local_aacuracore_messages', $msg);
        }
    }

    /**
     * Processes user text message through the pipeline:
     * 1. XSS / Script Sanitization.
     * 2. Dialog state transition validation and routing.
     * 3. Response strategy generation.
     * 4. Persistent database logging.
     * 5. Rubric evaluation check on Turn 10.
     *
     * @param string $usermessage
     * @return string generated response html
     */
    public function process_user_turn(string $usermessage): string {
        global $DB;

        // 1. Sanitization Layer
        $cleanedmessage = clean_param($usermessage, PARAM_CLEANHTML);

        // Log user message in history
        $this->log_message('user', $cleanedmessage);

        // 2. State & Intent validation routing
        $statekey = $this->sessionrecord->current_state ?? 'START';
        $currentnode = $this->scenario->get_state_node($statekey);
        $nextstatekey = 'EXPLORATION'; // Default transition route

        if ($currentnode && !empty($currentnode['expected_criteria'])) {
            $criteria = $currentnode['expected_criteria'];
            $validationtype = $criteria['validation_type'];
            $passroute = $criteria['pass_route'];
            $failroute = $criteria['fail_route'];

            // Evaluate validation criteria against response strategy
            $isvalid = $this->strategy->evaluate_input($cleanedmessage, $validationtype, $this->scenario);

            // Store analytics marker in database
            $this->log_analytic($validationtype, $isvalid ? 1.00 : 0.00);

            $nextstatekey = $isvalid ? $passroute : $failroute;
        }

        // Update database session state
        $this->sessionrecord->current_state = $nextstatekey;
        $this->sessionrecord->timemodified = time();
        $DB->update_record('local_aacuracore_sessions', $this->sessionrecord);

        $turncount = $this->get_turn_count();

        // Use the resolved minimum turns (activity-level override, else global/default 8).
        // This is treated as a MINIMUM: the conversation must run for at least this
        // many student turns before it is graded and terminated. Reaching a
        // terminal state early does NOT end the conversation early; instead the
        // dialogue continues until the minimum turn count is satisfied.
        $minturns = $this->max_turns;

        // If a terminal state (RESOLUTION/FAIL_STATE) was reached but we are still
        // below the minimum turn count, keep the conversation going by cycling back
        // into EXPLORATION so the parent persona continues engaging.
        if (($nextstatekey === 'RESOLUTION' || $nextstatekey === 'FAIL_STATE') && $turncount < $minturns) {
            $nextstatekey = 'EXPLORATION';
            $this->sessionrecord->current_state = $nextstatekey;
            $this->sessionrecord->timemodified = time();
            $DB->update_record('local_aacuracore_sessions', $this->sessionrecord);
        }

        // 3. Check for final rubric grading completion (only once min turns reached)
        if ($turncount >= $minturns) {
            $terminalnode = $this->scenario->get_state_node($nextstatekey);
            $parentclosing = ($terminalnode && !empty($terminalnode['bot_prompt'])) ? $terminalnode['bot_prompt'] : '';

            if (!empty($parentclosing)) {
                $this->log_message('system', $parentclosing);
            }

            // Retrieve messages and analytics to compute feedback and final score
            $messages = $this->get_messages();
            $analytics = $DB->get_records('local_aacuracore_analytics', ['sessionid' => $this->sessionrecord->id]);

            $feedback = $this->strategy->generate_rubric_feedback($messages, $this->scenario, $analytics);
            $fullclosingresponse = !empty($parentclosing) ? ($parentclosing . "<br><br>" . $feedback) : $feedback;

            $this->log_message('system', $feedback);

            // Compute final score
            $totalscore = 10;
            $missedcount = 0;
            foreach ($analytics as $analytic) {
                if ($analytic->metric_value == 0.00) {
                    $missedcount++;
                }
            }
            $finalscore = max(0, $totalscore - $missedcount);

            // Save evaluation record to local_aacuracore_evaluations
            $this->save_evaluation($finalscore, $feedback);

            // Sync performance metrics straight to Gradebook via trigger
            $this->trigger_gradebook_sync($finalscore);

            // Auto reset/clear active state variables
            $this->sessionrecord->current_state = 'START';
            $DB->update_record('local_aacuracore_sessions', $this->sessionrecord);

            return $fullclosingresponse;
        }

        // 4. Regular response generation using active strategy
        $messages = $this->get_messages();
        $botreply = $this->strategy->generate_response($messages, $this->scenario, $nextstatekey, $this->parent_intensity);

        if (!empty($botreply)) {
            // Log parent response in history
            $this->log_message('system', $botreply);
        }

        return $botreply;
    }

    /**
     * Programmatically triggers Moodle Gradebook sync updates.
     *
     * @param float $finalscore
     */
    private function trigger_gradebook_sync(float $finalscore): void {
        global $CFG, $DB;

        // Trigger standard mod_aacurachat library grading hook
        $libfile = $CFG->dirroot . '/mod/aacurachat/lib.php';
        if (file_exists($libfile)) {
            require_once($libfile);
            if (function_exists('aacurachat_grade_item_update')) {
                // Fetch course module record
                $cm = get_coursemodule_from_id('aacurachat', $this->sessionrecord->cmid);
                if ($cm) {
                    $aacurachat = $DB->get_record('aacurachat', ['id' => $cm->instance]);
                    if ($aacurachat) {
                        $grade = new \stdClass();
                        $grade->userid = $this->sessionrecord->userid;
                        $grade->rawgrade = $finalscore;
                        aacurachat_grade_item_update($aacurachat, $grade);
                    }
                }
            }
        }
    }

    /**
     * Persists final evaluation details to the local_aacuracore_evaluations table.
     *
     * @param float $score
     * @param string $feedback
     * @return int record ID
     */
    public function save_evaluation(float $score, string $feedback): int {
        global $DB;

        // Clean any existing evaluation for the current session to prevent clutter
        $DB->delete_records('local_aacuracore_evaluations', ['sessionid' => $this->sessionrecord->id]);

        $eval = new \stdClass();
        $eval->sessionid = $this->sessionrecord->id;
        $eval->score = $score;
        $eval->feedback = $feedback;
        $eval->timecreated = time();

        return (int)$DB->insert_record('local_aacuracore_evaluations', $eval);
    }
}
