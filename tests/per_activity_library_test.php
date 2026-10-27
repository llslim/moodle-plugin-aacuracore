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

use local_aacuracore\library\topic_library_manager;
use local_aacuracore\prompt_renderer;
use local_aacuracore\scenario\scenario_loader;

/**
 * Unit tests for AACURA v3.0 Per-Activity Topic & Scenario Library,
 * multi-instance course isolation, scope promotion, and grading modes.
 *
 * @package     local_aacuracore
 * @category    test
 * @copyright   2026 AAC-RERC
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class per_activity_library_test extends \advanced_testcase {

    /**
     * Set up tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->ensure_schema();
    }

    /**
     * Ensure required tables exist in test environment.
     */
    private function ensure_schema(): void {
        global $DB;

        $dbman = $DB->get_manager();

        // 1. Ensure local_aacuracore_topics table exists.
        if (!$dbman->table_exists('local_aacuracore_topics')) {
            $table = new \xmldb_table('local_aacuracore_topics');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('topiccode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('default_min_turns', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '8');
            $table->add_field('default_intensity', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'medium');
            $table->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'system');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('is_builtin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // 2. Ensure local_aacuracore_scenarios table exists.
        if (!$dbman->table_exists('local_aacuracore_scenarios')) {
            $table = new \xmldb_table('local_aacuracore_scenarios');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('topiccode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('role_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'parent');
            $table->add_field('role_label', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'Parent');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('json_data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'system');
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('is_builtin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // 3. Ensure local_aacuracore_evaluations table exists.
        if (!$dbman->table_exists('local_aacuracore_evaluations')) {
            $table = new \xmldb_table('local_aacuracore_evaluations');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('total_score', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '0.00');
            $table->add_field('final_rubric_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }
    }

    /**
     * Test Topic Library seeding creates 5 starter topics and 8 starter scenarios.
     */
    public function test_starter_topic_library_seeding(): void {
        global $DB;

        topic_library_manager::ensure_seeded();

        $topics = topic_library_manager::get_topics(0);
        $this->assertArrayHasKey('parent_counseling', $topics);
        $this->assertArrayHasKey('medical_consult', $topics);
        $this->assertArrayHasKey('vendor_evaluation', $topics);
        $this->assertArrayHasKey('self_advocacy', $topics);
        $this->assertArrayHasKey('iep_collaboration', $topics);

        $scenarios = topic_library_manager::get_scenarios_for_topic(null, 0, false);
        $this->assertArrayHasKey('anna', $scenarios);
        $this->assertArrayHasKey('brianna', $scenarios);
        $this->assertArrayHasKey('cathy', $scenarios);
        $this->assertArrayHasKey('mary', $scenarios);
        $this->assertArrayHasKey('doctor_consult', $scenarios);
        $this->assertArrayHasKey('manufacturer_sales', $scenarios);
        $this->assertArrayHasKey('aac_user_advocate', $scenarios);
        $this->assertArrayHasKey('school_admin_iep', $scenarios);

        // Verify universal LAFF "Don't Cry" presence in seeded definitions.
        $doc = $scenarios['doctor_consult'];
        $this->assertSame('medical_consult', $doc->topiccode);
        $this->assertSame('doctor', $doc->role_type);
        $this->assertSame('Physician', $doc->role_label);
    }

    /**
     * Test course-scoped topic & scenario creation and course isolation.
     */
    public function test_course_scoped_topic_and_scenario_creation(): void {
        topic_library_manager::ensure_seeded();

        $course1 = 101;
        $course2 = 102;

        // Create a course-specific topic for Course 1.
        $topic = topic_library_manager::save_topic([
            'topiccode' => 'course1_specialty',
            'name' => 'Course 1 AAC Specialty',
            'description' => 'Course 1 specific topic',
            'scope' => 'course',
            'courseid' => $course1,
        ]);
        $this->assertSame('course', $topic->scope);
        $this->assertSame($course1, (int)$topic->courseid);

        // Course 1 should see this topic.
        $c1topics = topic_library_manager::get_topics($course1);
        $this->assertArrayHasKey('course1_specialty', $c1topics);

        // Course 2 should NOT see Course 1's topic.
        $c2topics = topic_library_manager::get_topics($course2);
        $this->assertArrayNotHasKey('course1_specialty', $c2topics);

        // Create a course-specific scenario for Course 1.
        $minimaljson = json_encode([
            'scenario_id' => 'c1_custom_parent',
            'topic' => 'course1_specialty',
            'persona' => [
                'name' => 'Course 1 Parent',
                'child_preferred_pronoun' => 'he/him',
                'backstory' => 'Custom course scenario backstory',
                'initial_mood' => 'anxious',
                'communication_style' => 'direct',
                'role' => [
                    'type' => 'parent',
                    'display_label' => 'Parent',
                ],
            ],
            'states' => [
                'START' => [
                    'bot_prompt' => 'Hello teacher',
                    'rubric' => ['1 point for greeting.'],
                    'expected_criteria' => [
                        'validation_type' => 'empathy_check',
                        'pass_route' => 'EXPLORATION',
                        'fail_route' => 'ESCALATION',
                    ],
                ],
            ],
        ]);

        topic_library_manager::save_scenario([
            'scenariocode' => 'c1_custom_parent',
            'topiccode' => 'course1_specialty',
            'name' => 'Course 1 Parent',
            'role_type' => 'parent',
            'role_label' => 'Parent',
            'json_data' => $minimaljson,
            'scope' => 'course',
            'courseid' => $course1,
        ]);

        // Resolving scenario in Course 1 should succeed.
        $sc1 = scenario_loader::load('c1_custom_parent', $course1);
        $this->assertSame('Course 1 Parent', $sc1->get_persona_name());

        // Resolving scenario in Course 2 should fail (isolated).
        $this->expectException(\moodle_exception::class);
        scenario_loader::load('c1_custom_parent', $course2);
    }

    /**
     * Test Site Administrator promotion of a course-scope scenario and topic to System scope.
     */
    public function test_site_admin_promotion_to_system_scope(): void {
        topic_library_manager::ensure_seeded();

        $course1 = 201;
        $course2 = 202;

        topic_library_manager::save_topic([
            'topiccode' => 'promotable_topic',
            'name' => 'Promotable Topic',
            'scope' => 'course',
            'courseid' => $course1,
        ]);

        $minimaljson = json_encode([
            'scenario_id' => 'promotable_scenario',
            'topic' => 'promotable_topic',
            'persona' => [
                'name' => 'Promoted Person',
                'child_preferred_pronoun' => 'she/her',
                'backstory' => 'Promoted scenario backstory',
                'initial_mood' => 'defensive',
                'communication_style' => 'firm',
                'role' => [
                    'type' => 'doctor',
                    'display_label' => 'Physician',
                ],
            ],
            'states' => [
                'START' => [
                    'bot_prompt' => 'Doctor speaking',
                    'rubric' => ['1 point for respectful address.'],
                    'expected_criteria' => [
                        'validation_type' => 'clarification_check',
                        'pass_route' => 'EXPLORATION',
                        'fail_route' => 'ESCALATION',
                    ],
                ],
            ],
        ]);

        topic_library_manager::save_scenario([
            'scenariocode' => 'promotable_scenario',
            'topiccode' => 'promotable_topic',
            'name' => 'Promoted Person',
            'json_data' => $minimaljson,
            'scope' => 'course',
            'courseid' => $course1,
        ]);

        // Prior to promotion, Course 2 cannot see the topic or scenario.
        $this->assertArrayNotHasKey('promotable_topic', topic_library_manager::get_topics($course2));

        // Promote scenario to system scope.
        $promoted = topic_library_manager::promote_to_system_scope('promotable_scenario', $course1);
        $this->assertTrue($promoted);

        // Promote topic to system scope.
        $topicpromoted = topic_library_manager::promote_topic_to_system('promotable_topic', $course1);
        $this->assertTrue($topicpromoted);

        // Now Course 2 CAN see the promoted topic and load the promoted scenario!
        $this->assertArrayHasKey('promotable_topic', topic_library_manager::get_topics($course2));
        $loaded = scenario_loader::load('promotable_scenario', $course2);
        $this->assertSame('Promoted Person', $loaded->get_persona_name());
        $this->assertSame('Physician', $loaded->get_role()['display_label']);
    }

    /**
     * Test multi-scenario grading modes (single_highest, single_latest, all_average).
     */
    public function test_multi_scenario_grading_modes(): void {
        global $DB;

        topic_library_manager::ensure_seeded();

        $userid = 42;
        $cmid = 555;

        // Create activity record mock.
        $activity = (object)[
            'id' => 10,
            'course' => 1,
            'topiccode' => 'parent_counseling',
            'scenariolist' => json_encode(['anna', 'brianna', 'cathy']),
            'scenariocode' => 'anna',
            'grading_mode' => 'single_highest',
        ];

        // Seed 2 completed sessions: anna (score 8.0, earlier), brianna (score 6.0, later).
        $sessanna = $DB->insert_record('local_aacuracore_sessions', (object)[
            'userid' => $userid,
            'courseid' => 1,
            'cmid' => $cmid,
            'scenariocode' => 'anna',
            'currentstate' => 'COMPLETE',
            'timecreated' => 1000,
            'timemodified' => 1100,
        ]);
        $DB->insert_record('local_aacuracore_evaluations', (object)[
            'sessionid' => $sessanna,
            'scenariocode' => 'anna',
            'total_score' => 8.00,
            'timecreated' => 1100,
        ]);

        $sessbrianna = $DB->insert_record('local_aacuracore_sessions', (object)[
            'userid' => $userid,
            'courseid' => 1,
            'cmid' => $cmid,
            'scenariocode' => 'brianna',
            'currentstate' => 'COMPLETE',
            'timecreated' => 1200,
            'timemodified' => 1300,
        ]);
        $DB->insert_record('local_aacuracore_evaluations', (object)[
            'sessionid' => $sessbrianna,
            'scenariocode' => 'brianna',
            'total_score' => 6.00,
            'timecreated' => 1300,
        ]);

        // Mode 1: single_highest -> should return 8.00
        $activity->grading_mode = 'single_highest';
        $gradeHighest = topic_library_manager::calculate_activity_grade($activity, $cmid, $userid);
        $this->assertEquals(8.00, $gradeHighest);

        // Mode 2: single_latest -> should return 6.00 (brianna was completed later)
        $activity->grading_mode = 'single_latest';
        $gradeLatest = topic_library_manager::calculate_activity_grade($activity, $cmid, $userid);
        $this->assertEquals(6.00, $gradeLatest);

        // Mode 3: all_average -> 3 scenarios assigned (anna: 8, brianna: 6, cathy: 0).
        // (8.0 + 6.0 + 0.0) / 3 = 4.67
        $activity->grading_mode = 'all_average';
        $gradeAverage = topic_library_manager::calculate_activity_grade($activity, $cmid, $userid);
        $this->assertEquals(4.67, $gradeAverage);
    }

    /**
     * Test prompt template override hierarchy (Activity Override > Scenario JSON > Default).
     */
    public function test_prompt_template_override_hierarchy(): void {
        $renderer = new prompt_renderer();

        // 1. Baseline: with no scenario or activity override, returns default.
        $this->assertSame(prompt_renderer::DEFAULT_TEMPLATE, $renderer->resolve_template(null));

        // 2. Scenario template override.
        $scenariotemplate = "Scenario Template: {{persona_name}}";
        $this->assertSame($scenariotemplate, $renderer->resolve_template($scenariotemplate));

        // 3. Activity template override takes precedence over scenario template.
        $renderer->set_activity_overrides("Activity Override: {{persona_name}}", null);
        $this->assertSame("Activity Override: {{persona_name}}", $renderer->resolve_template($scenariotemplate));
    }
}
