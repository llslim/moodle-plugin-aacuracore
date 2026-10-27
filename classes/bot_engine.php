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

    /** @var int $min_turns Minimum student turns before grading (activity-level override, else global). */
    private int $min_turns;

    /** @var string $parentIntensity Assertiveness/aggressiveness level (activity-level override, else global). */
    private string $parent_intensity;

    /** @var string $topic_code Resolved topic code for this activity/scenario. */
    private string $topic_code = 'parent_counseling';

    /** @var string $generation_case Resolved AI generation profile override. */
    private string $generation_case = '';

    /** @var string|null $custom_prompt_template Per-activity persona system prompt override. */
    private ?string $custom_prompt_template = null;

    /** @var string|null $custom_eval_template Per-activity rubric evaluation prompt override. */
    private ?string $custom_eval_template = null;

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
        $this->scenario = scenario_loader::load($scenariocode, $courseid, $cmid);

        if ($strategy !== null) {
            $this->strategy = $strategy;
        } else {
            $strategytype = get_config('local_aacuracore', 'engine_strategy') ?: 'moodle_core_ai';
            // Auto-heal legacy 'regex' setting left behind by old diagnostics bug (unless in PHPUnit tests).
            if ($strategytype === 'regex' && !defined('PHPUNIT_TEST')) {
                $strategytype = 'moodle_core_ai';
                set_config('engine_strategy', 'moodle_core_ai', 'local_aacuracore');
            }
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
     * Resolve per-activity configuration overrides using the 4-tier priority hierarchy (PRD FR-10):
     * 1. Activity Instance Override (cmid)
     * 2. Scenario JSON Definition
     * 3. Topic Library Default (local_aacuracore_topics)
     * 4. Site-Wide Fallback (local_aacuracore)
     */
    private function resolve_activity_settings(): void {
        global $DB;

        // Tier 4: Site-Wide Fallback defaults.
        $minturns = (int)get_config('local_aacuracore', 'min_turns');
        if ($minturns <= 0) {
            $minturns = (int)get_config('local_aacuracore', 'max_turns');
        }
        if ($minturns <= 0) {
            $minturns = 8;
        }
        $intensity = get_config('local_aacuracore', 'parent_intensity') ?: 'medium';
        $gencase = get_config('local_aacuracore', 'case') ?: '';
        $promptoverride = null;
        $evaloverride = null;

        // Resolve the aacurachat instance record by cmid first, falling back safely to courseid when cmid === 0.
        $aacurachat = null;
        if ($this->cmid > 0) {
            $cm = get_coursemodule_from_id('aacurachat', $this->cmid, 0, false, IGNORE_MISSING);
            if ($cm) {
                $aacurachat = $DB->get_record('aacurachat', ['id' => $cm->instance]);
            }
        }
        if (!$aacurachat && $this->courseid > 0 && $DB->get_manager()->table_exists('aacurachat')) {
            $records = $DB->get_records('aacurachat', ['course' => $this->courseid], 'id ASC', '*', 0, 1);
            $aacurachat = reset($records) ?: null;
        }

        $this->topic_code = (!empty($aacurachat->topiccode))
            ? strtolower(trim($aacurachat->topiccode))
            : $this->scenario->get_topic_code();

        // Tier 3: Topic Library Default.
        if (class_exists('\\local_aacuracore\\library\\topic_library_manager')
                && \local_aacuracore\library\topic_library_manager::tables_exist()) {
            $topic = \local_aacuracore\library\topic_library_manager::get_topic_by_code($this->topic_code, $this->courseid);
            if ($topic) {
                if (!empty($topic->default_min_turns) && (int)$topic->default_min_turns > 0
                        && ((int)$topic->default_min_turns !== 8 || $minturns === 8)) {
                    $minturns = (int)$topic->default_min_turns;
                }
                if (!empty($topic->default_intensity)
                        && ($topic->default_intensity !== 'medium' || $intensity === 'medium')) {
                    $intensity = $topic->default_intensity;
                }
            }
        }

        // Tier 2: Scenario JSON Definition.
        if ($this->scenario->get_min_turns() !== null && $this->scenario->get_min_turns() > 0) {
            $minturns = $this->scenario->get_min_turns();
        }
        if (!empty($this->scenario->get_parent_intensity())) {
            $intensity = $this->scenario->get_parent_intensity();
        }

        // Tier 1: Activity Instance Override (cmid).
        if ($aacurachat) {
            if (property_exists($aacurachat, 'min_turns') && !empty($aacurachat->min_turns) && (int)$aacurachat->min_turns > 0) {
                $minturns = (int)$aacurachat->min_turns;
            } else if (property_exists($aacurachat, 'max_turns') && !empty($aacurachat->max_turns) && (int)$aacurachat->max_turns > 0) {
                $minturns = (int)$aacurachat->max_turns;
            }
            if (property_exists($aacurachat, 'parent_intensity') && !empty($aacurachat->parent_intensity)) {
                $intensity = $aacurachat->parent_intensity;
            }
            if (property_exists($aacurachat, 'generation_case') && !empty($aacurachat->generation_case)) {
                $gencase = $aacurachat->generation_case;
            }
            if (property_exists($aacurachat, 'custom_prompt_template') && !empty($aacurachat->custom_prompt_template)) {
                $promptoverride = $aacurachat->custom_prompt_template;
            }
            if (property_exists($aacurachat, 'custom_eval_template') && !empty($aacurachat->custom_eval_template)) {
                $evaloverride = $aacurachat->custom_eval_template;
            }
        }

        $this->min_turns = $minturns;
        $this->parent_intensity = $intensity;
        $this->generation_case = $gencase;
        $this->custom_prompt_template = $promptoverride;
        $this->custom_eval_template = $evaloverride;

        prompt_renderer::set_activity_overrides($this->custom_prompt_template, $this->custom_eval_template);
        api::set_active_generation_case($this->generation_case);
    }

    /**
     * Gets the resolved minimum student turns before grading for this activity/scenario.
     *
     * @return int
     */
    public function get_min_turns(): int {
        return $this->min_turns;
    }

    /**
     * Backward-compatible alias for get_min_turns().
     *
     * @return int
     */
    public function get_max_turns(): int {
        return $this->min_turns;
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
     * Gets the resolved topic code for this activity/scenario.
     *
     * @return string
     */
    public function get_topic_code(): string {
        return $this->topic_code;
    }

    /**
     * Gets the resolved AI generation case profile.
     *
     * @return string
     */
    public function get_generation_case(): string {
        return $this->generation_case;
    }

    /**
     * Gets the active course module ID (cmid).
     *
     * @return int
     */
    public function get_cmid(): int {
        return $this->cmid;
    }

    /**
     * Looks up existing active session or initializes a new state machine session.
     * Strictly isolates sessions by (userid, cmid, scenariocode) so multi-instance
     * courses and multi-scenario activities never collide.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $scenariocode
     * @return \stdClass
     */
    private function lookup_or_create_session(int $userid, int $courseid, string $scenariocode): \stdClass {
        global $DB;

        $scenariocode = strtolower(trim($scenariocode));

        $record = $DB->get_record('local_aacuracore_sessions', [
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $this->cmid,
            'scenariocode' => $scenariocode,
        ], '*', IGNORE_MULTIPLE);

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

            // The chatbot waits for an initial message from the user before presenting the core problem.
        } else {
            $record->timemodified = time();
            $DB->update_record('local_aacuracore_sessions', $record);
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

        // The chatbot waits for an initial message from the user before presenting the core problem.
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

        // Check if the persona has delivered its initial presenting problem yet.
        $botmessages = $DB->count_records('local_aacuracore_messages', [
            'sessionid' => $this->sessionrecord->id,
            'sender' => 'system'
        ]);

        // If the bot has not spoken yet:
        // If the user begins with an initial greeting or intake inquiry (and does not already demonstrate empathy),
        // deliver the persona's core concern / presenting problem in character from the START node, while
        // keeping session state in START so the trainee can respond with empathy on the next turn.
        if ($botmessages === 0) {
            $hasempathy = $this->strategy->evaluate_input($cleanedmessage, 'empathy_check', $this->scenario);
            if (!$hasempathy && $this->is_initial_greeting_or_intake($cleanedmessage)) {
                $this->log_message('user', $cleanedmessage);
                $messages = $this->get_messages();
                $botreply = $this->strategy->generate_response($messages, $this->scenario, 'START', $this->parent_intensity);
                if (empty($botreply)) {
                    $startnode = $this->scenario->get_state('START');
                    $botreply = $startnode['bot_prompt'] ?? '';
                }
                $this->log_message('system', $botreply);
                return $botreply;
            }
        }

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
        $minturns = $this->min_turns;

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
     * Programmatically triggers Moodle Gradebook sync updates using per-activity cmid
     * and the activity's configured multi-scenario grading_mode.
     *
     * @param float $finalscore
     */
    private function trigger_gradebook_sync(float $finalscore): void {
        global $CFG, $DB;

        $libfile = $CFG->dirroot . '/mod/aacurachat/lib.php';
        if (file_exists($libfile)) {
            require_once($libfile);
            if (function_exists('aacurachat_grade_item_update')) {
                $aacurachat = null;
                $cmid = (int)$this->sessionrecord->cmid;
                if ($cmid > 0) {
                    $cm = get_coursemodule_from_id('aacurachat', $cmid, 0, false, IGNORE_MISSING);
                    if ($cm) {
                        $aacurachat = $DB->get_record('aacurachat', ['id' => $cm->instance]);
                    }
                }
                if (!$aacurachat && $this->courseid > 0 && $DB->get_manager()->table_exists('aacurachat')) {
                    $records = $DB->get_records('aacurachat', ['course' => $this->courseid], 'id ASC', '*', 0, 1);
                    $aacurachat = reset($records) ?: null;
                }

                if ($aacurachat) {
                    $computedgrade = $finalscore;
                    if (class_exists('\\local_aacuracore\\library\\topic_library_manager')) {
                        $computedgrade = \local_aacuracore\library\topic_library_manager::calculate_activity_grade(
                            $aacurachat,
                            $cmid,
                            (int)$this->sessionrecord->userid,
                            $finalscore
                        );
                    }
                    if ($computedgrade !== null) {
                        $grade = new \stdClass();
                        $grade->userid = $this->sessionrecord->userid;
                        $grade->rawgrade = $computedgrade;
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

    /**
     * Determines whether an initial user message is a greeting or intake question
     * (e.g., asking what brings the persona in or opening the consultation).
     *
     * @param string $message
     * @return bool
     */
    protected function is_initial_greeting_or_intake(string $message): bool {
        $clean = strtolower(trim($message));
        if (empty($clean)) {
            return true;
        }

        $patterns = [
            'brings you', 'what brings', 'how can i help',
            'how may i help', 'what can i do', 'what can we do',
            'hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening',
            'welcome', 'thanks for coming', 'thank you for coming', 'thanks for meeting',
            'thank you for meeting', 'meet with me', 'how are you', 'how is your day',
            'how are things', 'what is going on', 'what\'s going on',
            'nice to meet', 'glad we could meet', 'glad you could make it', 'pleasure to meet',
            'what brings you in'
        ];

        foreach ($patterns as $pattern) {
            if (strpos($clean, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
