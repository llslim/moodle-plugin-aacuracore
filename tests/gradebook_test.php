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

defined('MOODLE_INTERNAL') || die();

/**
 * Integration tests for AACURA Chatbot Gradebook sync and turn evaluation.
 *
 * @package     local_aacuracore
 * @category    test
 * @copyright   2026 Antigravity
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradebook_test extends \advanced_testcase {

    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->ensure_aacurachat_plugin();
    }

    /**
     * Ensures the aacurachat table and module record exist.
     *
     * The gradebook integration depends on the separate mod_aacurachat activity
     * plugin, which is not installed in this plugin's CI environment. This helper
     * creates the minimal table and module registration needed for the tests to
     * run self-contained.
     */
    private function ensure_aacurachat_plugin(): void {
        global $DB, $CFG;

        // 1. Create the aacurachat table if it does not exist.
        if (!$DB->get_manager()->table_exists('aacurachat')) {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('aacurachat');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('intro', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'anna');
            $table->add_field('min_turns', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('max_turns', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('parent_intensity', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // 2. Register the aacurachat module if it does not exist.
        if (!$DB->record_exists('modules', ['name' => 'aacurachat'])) {
            $module = new \stdClass();
            $module->name = 'aacurachat';
            $module->cron = 0;
            $module->lastcron = 0;
            $module->search = '';
            $module->visible = 1;
            $module->version = 2026081001;
            $DB->insert_record('modules', $module);
        }

        // 3. Provide the mod/aacurachat/lib.php grading hook so the bot engine's
        //    trigger_gradebook_sync() can create the grade item. The real activity
        //    plugin is a separate repository not installed in this plugin's CI.
        $moddir = $CFG->dirroot . '/mod/aacurachat';
        $libfile = $moddir . '/lib.php';
        if (!file_exists($libfile)) {
            check_dir_exists($moddir, true, true);

            // Minimal version.php so core_component recognizes mod_aacurachat.
            $verfile = $moddir . '/version.php';
            if (!file_exists($verfile)) {
                file_put_contents($verfile, <<<'PHP'
<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'mod_aacurachat';
$plugin->version   = 2026081001;
$plugin->requires  = 2025041400;
PHP
                );
            }

            $libcontent = <<<'PHP'
<?php
defined('MOODLE_INTERNAL') || die();

function aacurachat_add_instance(stdClass $data, $mform = null): int {
    global $DB;
    // The test pre-inserts the aacurachat record; add_moduleinfo() calls this
    // to create the instance. Return the existing instance id for this course.
    $record = $DB->get_record('aacurachat', ['course' => $data->course]);
    if ($record) {
        return (int)$record->id;
    }
    return 0;
}

function aacurachat_update_instance(stdClass $data, $mform = null): bool {
    return true;
}

function aacurachat_grade_item_update(stdClass $aacurachat, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $aacurachat->name,
        'idnumber' => $aacurachat->idnumber ?? '',
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => 10,
        'grademin' => 0,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/aacurachat', $aacurachat->course, 'mod', 'aacurachat', $aacurachat->id, 0, $grades, $params);
}
PHP;
            file_put_contents($libfile, $libcontent);
        }

        // 4. Reset the core_component cache so it picks up the new plugin.
        \core_component::reset();
    }

    /**
     * Test successful scenario path and Gradebook recording for all parent scenarios.
     */
    public function test_gradebook_save_success_path() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $scenarios = ['anna', 'brianna', 'cathy', 'mary'];

        foreach ($scenarios as $scenario) {
            $this->resetAfterTest(true); // Reset database state for next loop iteration
            
            // Set engine strategy to regex matcher for deterministic offline unit testing
            set_config('engine_strategy', 'regex', 'local_aacuracore');

            // 1. Generate Course, Student, and Activity
            $generator = $this->getDataGenerator();
            $course = $generator->create_course();
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');

            // Insert aacurachat record manually
            $record = new \stdClass();
            $record->course = $course->id;
            $record->name = "Chatbot - {$scenario}";
            $record->scenariocode = $scenario;
            $record->intro = 'Test Intro';
            $record->introformat = FORMAT_HTML;
            $record->min_turns = 3; // Minimum 3 turns before grading; verifies no early termination at a terminal state below the minimum.
            $record->max_turns = 3;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->id = $DB->insert_record('aacurachat', $record);

            // Create Moodle Course Module using the proper API so the section
            // assignment and course cache stay consistent (avoids the
            // "Failed integrity check" debugging() notice from rebuild_course_cache()).
            $module = $DB->get_record('modules', ['name' => 'aacurachat'], '*', MUST_EXIST);
            $moduleinfo = new \stdClass();
            $moduleinfo->modulename = 'aacurachat';
            $moduleinfo->module = $module->id;
            $moduleinfo->course = $course->id;
            $moduleinfo->name = "Chatbot - {$scenario}";
            $moduleinfo->instance = $record->id;
            $moduleinfo->section = 0;
            $moduleinfo->visible = 1;
            $moduleinfo->visibleoncoursepage = 1;
            $moduleinfo->groupmode = 0;
            $moduleinfo->groupingid = 0;
            $moduleinfo->completion = 0;
            $moduleinfo = \add_moduleinfo($moduleinfo, $course);
            $cmid = (int)$moduleinfo->coursemodule;

            rebuild_course_cache($course->id);

            // Set global user context to Steve
            $this->setUser($student);

            // 2. Instantiate bot_engine and reset session
            $engine = new \local_aacuracore\bot_engine($student->id, $course->id, $cmid, $scenario);
            $engine->reset_session();

            // 3. Send Turn 1: Should pass empathy_check (triggers transition START -> EXPLORATION)
            $reply1 = $engine->process_user_turn("I understand your concerns and want to help you.");
            $this->assertNotEmpty($reply1);
            
            // Verify session state transitioned to EXPLORATION
            $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => $scenario]);
            $this->assertEquals('EXPLORATION', $session->current_state);

            // Verify user message was logged in messages history
            $usercount = $DB->count_records('local_aacuracore_messages', ['sessionid' => $session->id, 'sender' => 'user']);
            $this->assertEquals(1, $usercount);

            // Verify analytics was logged as valid (1.00)
            $analytic1 = $DB->get_record('local_aacuracore_analytics', ['sessionid' => $session->id, 'metric_type' => 'empathy_check']);
            $this->assertNotNull($analytic1);
            $this->assertEquals(1.00, $analytic1->metric_value);

            // 4. Send Turn 2: Should pass jargon_check (triggers transition EXPLORATION -> RESOLUTION).
            //    Because the minimum turn count (3) has NOT been reached, the conversation must NOT
            //    terminate yet. Verify the terminal RESOLUTION is cycled back into EXPLORATION.
            $reply2 = $engine->process_user_turn("This is a simple device with picture symbols.");
            $this->assertNotEmpty($reply2);

            $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => $scenario]);
            // Minimum turns not met => session should still be active (cycled to EXPLORATION), NOT reset to START.
            $this->assertNotEquals('START', $session->current_state, "Conversation should not terminate before the minimum turn count");

            // Verify user message count is now 2
            $usercount = $DB->count_records('local_aacuracore_messages', ['sessionid' => $session->id, 'sender' => 'user']);
            $this->assertEquals(2, $usercount);

            // Verify jargon_check analytics was logged as valid (1.00)
            $analytic2 = $DB->get_record('local_aacuracore_analytics', ['sessionid' => $session->id, 'metric_type' => 'jargon_check']);
            $this->assertNotNull($analytic2);
            $this->assertEquals(1.00, $analytic2->metric_value);

            // 4b. Send Turn 3: Now the minimum turn count (3) is met, so grading should fire
            //     and the session should be reset back to START.
            $reply3 = $engine->process_user_turn("This is a simple device with picture symbols.");
            $this->assertNotEmpty($reply3);

            $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => $scenario]);
            $this->assertEquals('START', $session->current_state, "Conversation should terminate after reaching the minimum turn count");

            // 5. Verify Moodle Gradebook sync
            $gradeitem = $DB->get_record('grade_items', ['courseid' => $course->id, 'iteminstance' => $record->id, 'itemmodule' => 'aacurachat']);
            $this->assertNotNull($gradeitem, "Grade item for 'aacurachat' should be created automatically");

            $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $student->id]);
            $this->assertNotNull($grade, "Student's grade record must exist in Moodle Gradebook");
            $this->assertEquals(10.00000, $grade->finalgrade, "Steve should get 10/10 since 0 metrics were missed");

            // 6. Verify local_aacuracore_evaluations persistence
            $eval = $DB->get_record('local_aacuracore_evaluations', ['sessionid' => $session->id]);
            $this->assertNotNull($eval, "Evaluation record should be saved in the database");
            $this->assertEquals(10.00, $eval->score, "Saved evaluation score should be 10.00");
            $this->assertNotEmpty($eval->feedback, "Saved evaluation feedback should not be empty");
        }
    }

    /**
     * Test failed scenario path and Gradebook recording for all parent scenarios.
     */
    public function test_gradebook_save_fail_path() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $scenarios = ['anna', 'brianna', 'cathy', 'mary'];

        foreach ($scenarios as $scenario) {
            $this->resetAfterTest(true); // Reset database state for next loop iteration
            
            // Set engine strategy to regex matcher for deterministic offline unit testing
            set_config('engine_strategy', 'regex', 'local_aacuracore');

            // 1. Generate Course, Student, and Activity
            $generator = $this->getDataGenerator();
            $course = $generator->create_course();
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');

            // Insert aacurachat record manually
            $record = new \stdClass();
            $record->course = $course->id;
            $record->name = "Chatbot - {$scenario}";
            $record->scenariocode = $scenario;
            $record->intro = 'Test Intro';
            $record->introformat = FORMAT_HTML;
            $record->min_turns = 3; // Minimum 3 turns before grading; verifies no early termination at a terminal state below the minimum.
            $record->max_turns = 3;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->id = $DB->insert_record('aacurachat', $record);

            // Create Moodle Course Module using the proper API so the section
            // assignment and course cache stay consistent (avoids the
            // "Failed integrity check" debugging() notice from rebuild_course_cache()).
            $module = $DB->get_record('modules', ['name' => 'aacurachat'], '*', MUST_EXIST);
            $moduleinfo = new \stdClass();
            $moduleinfo->modulename = 'aacurachat';
            $moduleinfo->module = $module->id;
            $moduleinfo->course = $course->id;
            $moduleinfo->name = "Chatbot - {$scenario}";
            $moduleinfo->instance = $record->id;
            $moduleinfo->section = 0;
            $moduleinfo->visible = 1;
            $moduleinfo->visibleoncoursepage = 1;
            $moduleinfo->groupmode = 0;
            $moduleinfo->groupingid = 0;
            $moduleinfo->completion = 0;
            $moduleinfo = \add_moduleinfo($moduleinfo, $course);
            $cmid = (int)$moduleinfo->coursemodule;

            rebuild_course_cache($course->id);

            // Set global user context to Steve
            $this->setUser($student);

            // 2. Instantiate bot_engine and reset session
            $engine = new \local_aacuracore\bot_engine($student->id, $course->id, $cmid, $scenario);
            $engine->reset_session();

            // 3. Send Turn 1: Should FAIL empathy_check (triggers transition START -> ESCALATION)
            $reply1 = $engine->process_user_turn("Whatever. This is what we have.");
            $this->assertNotEmpty($reply1);
            
            // Verify session state transitioned to ESCALATION
            $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => $scenario]);
            $this->assertEquals('ESCALATION', $session->current_state);

            // Verify user message was logged
            $usercount = $DB->count_records('local_aacuracore_messages', ['sessionid' => $session->id, 'sender' => 'user']);
            $this->assertEquals(1, $usercount);

            // Verify analytics was logged as invalid (0.00)
            $analytic1 = $DB->get_record('local_aacuracore_analytics', ['sessionid' => $session->id, 'metric_type' => 'empathy_check']);
            $this->assertNotNull($analytic1);
            $this->assertEquals(0.00, $analytic1->metric_value);

            // 4. Send Turn 2: Should FAIL de_escalation_check (triggers transition ESCALATION -> FAIL_STATE).
            //    Because the minimum turn count (3) has NOT been reached, the conversation must NOT
            //    terminate yet. Verify the session remains active (NOT reset to START).
            $reply2 = $engine->process_user_turn("I don't care, talk to the principal.");
            $this->assertNotEmpty($reply2);

            $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => $scenario]);
            // Minimum turns not met => session should still be active, NOT reset to START.
            $this->assertNotEquals('START', $session->current_state, "Conversation should not terminate before the minimum turn count");

            // Verify de_escalation_check analytics was logged as invalid (0.00)
            $analytic2 = $DB->get_record('local_aacuracore_analytics', ['sessionid' => $session->id, 'metric_type' => 'de_escalation_check']);
            $this->assertNotNull($analytic2);
            $this->assertEquals(0.00, $analytic2->metric_value);

            // 4b. Send Turn 3: Now the minimum turn count (3) is met, so grading should fire
            //     and the session should be reset back to START.
            $reply3 = $engine->process_user_turn("I don't care, talk to the principal.");
            $this->assertNotEmpty($reply3);

            $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => $scenario]);
            $this->assertEquals('START', $session->current_state, "Conversation should terminate after reaching the minimum turn count");

            // 5. Verify Moodle Gradebook sync
            $gradeitem = $DB->get_record('grade_items', ['courseid' => $course->id, 'iteminstance' => $record->id, 'itemmodule' => 'aacurachat']);
            $this->assertNotNull($gradeitem);

            $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $student->id]);
            $this->assertNotNull($grade);
            $this->assertEquals(8.00000, $grade->finalgrade, "Steve should get 8/10 since 2 metrics were missed (empathy_check & de_escalation_check)");

            // 6. Verify local_aacuracore_evaluations persistence
            $eval = $DB->get_record('local_aacuracore_evaluations', ['sessionid' => $session->id]);
            $this->assertNotNull($eval, "Evaluation record should be saved in the database");
            $this->assertEquals(8.00, $eval->score, "Saved evaluation score should be 8.00");
            $this->assertNotEmpty($eval->feedback, "Saved evaluation feedback should not be empty");
        }
    }

    /**
     * Test the default 8-turn minimum behavior.
     *
     * With no per-activity max_turns override, the conversation should default
     * to an 8-turn MINIMUM. Reaching a terminal state (RESOLUTION) early must
     * NOT terminate the conversation before those 8 turns are completed.
     */
    public function test_default_minimum_turns_is_eight(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        set_config('engine_strategy', 'regex', 'local_aacuracore');
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        // Insert aacurachat record WITHOUT setting max_turns so the default applies.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->name = 'Chatbot - default turns';
        $record->scenariocode = 'anna';
        $record->intro = 'Test Intro';
        $record->introformat = FORMAT_HTML;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('aacurachat', $record);

        $engine = new \local_aacuracore\bot_engine($student->id, $course->id, 0, 'anna');

        // Default minimum turns should be 8.
        $this->assertEquals(8, $engine->get_max_turns(), "Default minimum turns should be 8 when no activity override is set");

        // Turn 1: empathy pass -> EXPLORATION.
        $reply1 = $engine->process_user_turn("I understand your concerns and want to help you.");
        $this->assertNotEmpty($reply1);

        // Turn 2: jargon pass -> would reach RESOLUTION (a terminal state) but must
        // NOT terminate because the 8-turn minimum has not been met.
        $reply2 = $engine->process_user_turn("This is a simple device with picture symbols.");
        $this->assertNotEmpty($reply2);

        $session = $DB->get_record('local_aacuracore_sessions', ['userid' => $student->id, 'scenariocode' => 'anna']);
        $this->assertNotEquals('START', $session->current_state,
            "Conversation must NOT terminate early even after reaching a terminal state below the 8-turn minimum");

        // Confirm only 2 user turns have been logged (still below the minimum).
        $usercount = $DB->count_records('local_aacuracore_messages', ['sessionid' => $session->id, 'sender' => 'user']);
        $this->assertEquals(2, $usercount);
    }
}
