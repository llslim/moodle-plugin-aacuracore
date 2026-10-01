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

use local_aacuracore\scenario\scenario_loader;
use local_aacuracore\prompt_renderer;
use local_aacuracore\api;

/**
 * Unit tests for the features added on 2026-08-20:
 *  - Global generation parameters (Use Cases => temperature/top_p)
 *  - Per-state rubric criteria in scenarios
 *  - Configurable max student turns (+ per-activity override)
 *  - Parent assertiveness/aggressiveness intensity
 *  - Global persona system prompt template + evaluation prompt template
 *  - AI scenario builder rubric flow
 *
 * @package     local_aacuracore
 * @category    test
 * @copyright   2026 AAC-RERC Chatbot Team
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_and_prompt_test extends \advanced_testcase {

    /**
     * Set up tests: reset DB state after every test so set_config() calls
     * (which write to the config table) are rolled back.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Returns a sample scenario array with per-state rubric entries.
     *
     * @return array
     */
    private function sample_scenario(): array {
        return [
            'scenario_id' => 'rubric_test',
            'persona' => [
                'name' => 'Parent Test',
                'child_preferred_pronoun' => 'she/her',
                'backstory' => 'Test backstory.',
                'initial_mood' => 'anxious',
                'communication_style' => 'Concerned and direct.',
            ],
            'learning_objectives' => ['active_listening'],
            'states' => [
                'START' => [
                    'bot_prompt' => 'I am worried about my child.',
                    'rubric' => [
                        '1 point if teacher starts with a greeting.',
                        '1 point for a statement of empathy.',
                    ],
                    'expected_criteria' => [
                        'validation_type' => 'empathy_check',
                        'pass_route' => 'RESOLUTION',
                        'fail_route' => 'FAIL_STATE',
                    ],
                ],
                'EXPLORATION' => [
                    'bot_prompt' => 'Tell me more.',
                    'rubric' => [
                        '1 point for asking an open question.',
                    ],
                    'expected_criteria' => [
                        'validation_type' => 'jargon_check',
                        'pass_route' => 'RESOLUTION',
                        'fail_route' => 'FAIL_STATE',
                    ],
                ],
                'RESOLUTION' => [
                    'bot_prompt' => 'Thank you.',
                    'rubric' => [
                        '1 point for wrapping up.',
                    ],
                    'expected_criteria' => null,
                ],
                'FAIL_STATE' => [
                    'bot_prompt' => 'Goodbye.',
                    'rubric' => [],
                    'expected_criteria' => null,
                ],
            ],
        ];
    }

    /**
     * Test that per-state rubric arrays are preserved through scenario_loader::from_array().
     */
    public function test_per_state_rubric_loaded() {
        $sc = scenario_loader::from_array($this->sample_scenario());

        $states = $sc->get_states();
        $this->assertArrayHasKey('START', $states);
        $this->assertArrayHasKey('rubric', $states['START']);
        $this->assertCount(2, $states['START']['rubric']);
        $this->assertEquals('1 point if teacher starts with a greeting.', $states['START']['rubric'][0]);

        $this->assertCount(1, $states['EXPLORATION']['rubric']);
    }

    /**
     * Test that a state with an empty rubric array is still loaded.
     */
    public function test_empty_rubric_loaded() {
        $sc = scenario_loader::from_array($this->sample_scenario());
        $fail = $sc->get_state('FAIL_STATE');
        $this->assertNotEmpty($fail);
        $this->assertEquals([], $fail['rubric']);
    }

    /**
     * Test that the default preloaded scenarios carry per-state rubric arrays.
     */
    public function test_default_scenarios_have_rubric() {
        foreach (['anna', 'brianna', 'cathy', 'mary'] as $code) {
            $sc = scenario_loader::load($code, 0);
            $states = $sc->get_states();
            $this->assertArrayHasKey('rubric', $states['START'], "Scenario '{$code}' START should define rubric");
            $this->assertNotEmpty($states['START']['rubric'], "Scenario '{$code}' START rubric should not be empty");
        }
    }

    /**
     * Test the global generation params mapper across all use cases.
     */
    public function test_generation_params_mapping() {
        $cases = [
            'creative' => ['temperature' => 0.7, 'top_p' => 0.8],
            'balanced' => ['temperature' => 0.5, 'top_p' => 0.7],
            'precise' => ['temperature' => 0.0, 'top_p' => 1.0],
            'exploration' => ['temperature' => 0.8, 'top_p' => 0.9],
            'formal' => ['temperature' => 0.3, 'top_p' => 0.6],
            'informal' => ['temperature' => 0.7, 'top_p' => 0.8],
            'chatbot' => ['temperature' => 0.2, 'top_p' => 0.8],
        ];

        foreach ($cases as $case => $expected) {
            set_config('case', $case, 'local_aacuracore');
            $params = api::get_generation_params();
            $this->assertEquals($expected['temperature'], $params['temperature'], "Temperature for case '{$case}'");
            $this->assertEquals($expected['top_p'], $params['top_p'], "Top_p for case '{$case}'");
        }
    }

    /**
     * Test get_generation_params falls back when case is unknown.
     */
    public function test_generation_params_unknown_case() {
        set_config('case', 'does_not_exist', 'local_aacuracore');
        $params = api::get_generation_params();
        $this->assertEquals(0.5, $params['temperature']);
        $this->assertEquals(0.5, $params['top_p']);
    }

    /**
     * Test parent_intensity instruction mapping for each level.
     */
    public function test_parent_intensity_instruction() {
        $verylow = prompt_renderer::intensity_instruction('very_low');
        $this->assertNotEmpty($verylow);
        $this->assertStringContainsString('gentle', $verylow);

        $veryhigh = prompt_renderer::intensity_instruction('very_high');
        $this->assertStringContainsString('confrontational', $veryhigh);

        // Unknown level falls back to medium instruction.
        $unknown = prompt_renderer::intensity_instruction('bogus');
        $this->assertNotEmpty($unknown);
        $this->assertStringContainsString('moderately assertive', $unknown);
    }

    /**
     * Test the default persona template exposes the parent_intensity placeholder.
     */
    public function test_default_template_contains_intensity() {
        $this->assertStringContainsString('{{parent_intensity}}', prompt_renderer::DEFAULT_TEMPLATE);
        $this->assertStringContainsString('{{communication_style}}', prompt_renderer::DEFAULT_TEMPLATE);
    }

    /**
     * Test resolve_template fallback chain: scenario -> global -> default.
     */
    public function test_resolve_template_chain() {
        // Scenario has its own template -> that one wins.
        $data = $this->sample_scenario();
        $data['prompt_template'] = 'SCENARIO TEMPLATE {{persona_name}}';
        $sc = scenario_loader::from_array($data);
        $this->assertEquals('SCENARIO TEMPLATE {{persona_name}}',
            prompt_renderer::resolve_template($sc));

        // No scenario template, global set -> global wins.
        $data2 = $this->sample_scenario();
        unset($data2['prompt_template']);
        $sc2 = scenario_loader::from_array($data2);
        set_config('prompt_template', 'GLOBAL TEMPLATE {{statekey}}', 'local_aacuracore');
        $this->assertEquals('GLOBAL TEMPLATE {{statekey}}',
            prompt_renderer::resolve_template($sc2));

        // Neither -> default constant.
        set_config('prompt_template', '', 'local_aacuracore');
        $this->assertEquals(prompt_renderer::DEFAULT_TEMPLATE,
            prompt_renderer::resolve_template($sc2));
    }

    /**
     * Test render substitutes parent_intensity using the given override level.
     */
    public function test_render_injects_parent_intensity() {
        $sc = scenario_loader::from_array($this->sample_scenario());

        $rendered = prompt_renderer::render(
            prompt_renderer::DEFAULT_TEMPLATE,
            $sc,
            'START',
            'high'
        );

        $this->assertStringNotContainsString('{{', $rendered);
        $this->assertStringContainsString('assertive and firm', $rendered);
        $this->assertStringContainsString('Parent Test', $rendered);
    }

    /**
     * Test the evaluation template resolver and that it references {{rubric}}.
     */
    public function test_evaluation_template_resolve() {
        $template = prompt_renderer::resolve_evaluation_template();
        $this->assertNotEmpty($template);
        $this->assertStringContainsString('{{rubric}}', $template);
    }

    /**
     * Test the DEFAULT_EVALUATION_TEMPLATE has the formatting markers.
     */
    public function test_evaluation_template_markers() {
        $t = prompt_renderer::DEFAULT_EVALUATION_TEMPLATE;
        $this->assertStringContainsString('Grade - X out of 10', $t);
        $this->assertStringContainsString('{{rubric}}', $t);
    }

    /**
     * Test that AI scenario builder flow includes the rubric step.
     */
    public function test_scenario_builder_flow_includes_rubric() {
        $this->assertContains('ASK_RUBRIC', \local_aacuracore\scenario_builder_ai::FLOW);

        $prompt = scenario_builder_ai::interviewer_system_prompt();
        $this->assertStringContainsString('rubric', strtolower($prompt));
        $this->assertStringContainsString('"rubric"', $prompt);
    }

    /**
     * Create the aacurachat table (with max_turns + parent_intensity columns) and
     * register the module, so bot_engine can resolve per-activity settings offline.
     */
    private function ensure_aacurachat_table(): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('aacurachat')) {
            $dbman = $DB->get_manager();
            $table = new \xmldb_table('aacurachat');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('intro', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'anna');
            $table->add_field('min_turns', XMLDB_TYPE_INTEGER, '6', null, null, null, '8', 'scenariocode');
            $table->add_field('max_turns', XMLDB_TYPE_INTEGER, '6', null, null, null, '8', 'min_turns');
            $table->add_field('parent_intensity', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'max_turns');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }
    }

    /**
     * Test bot_engine uses activity-level max_turns / min_turns override when set.
     */
    public function test_bot_engine_activity_max_turns_override() {
        global $DB;
        $this->resetAfterTest(true);
        $this->ensure_aacurachat_table();

        set_config('engine_strategy', 'regex', 'local_aacuracore');
        set_config('min_turns', 8, 'local_aacuracore');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $this->setUser($student);

        // Activity with an explicit min_turns override of 12.
        $record = new \stdClass();
        $record->course = $course->id;
        $record->name = 'Activity A';
        $record->scenariocode = 'anna';
        $record->intro = 'Test';
        $record->introformat = FORMAT_HTML;
        $record->min_turns = 12;
        $record->max_turns = 12;
        $record->parent_intensity = 'very_high';
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('aacurachat', $record);

        $engine = new \local_aacuracore\bot_engine($student->id, $course->id, 0, 'anna');
        $this->assertEquals(12, $engine->get_min_turns());
        $this->assertEquals(12, $engine->get_max_turns());
        $this->assertEquals('very_high', $engine->get_parent_intensity());
    }

    /**
     * Test bot_engine falls back to global settings when activity has no overrides.
     */
    public function test_bot_engine_activity_falls_back_to_global() {
        global $DB;
        $this->resetAfterTest(true);
        $this->ensure_aacurachat_table();

        set_config('max_turns', 6, 'local_aacuracore');
        set_config('parent_intensity', 'low', 'local_aacuracore');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $this->setUser($student);

        // Activity with empty overrides (0 min_turns, 0 max_turns, empty intensity).
        $record = new \stdClass();
        $record->course = $course->id;
        $record->name = 'Activity B';
        $record->scenariocode = 'anna';
        $record->intro = 'Test';
        $record->introformat = FORMAT_HTML;
        $record->min_turns = 0;
        $record->max_turns = 0;
        $record->parent_intensity = '';
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('aacurachat', $record);

        $engine = new \local_aacuracore\bot_engine($student->id, $course->id, 0, 'anna');
        $this->assertEquals(6, $engine->get_min_turns());
        $this->assertEquals(6, $engine->get_max_turns());
        $this->assertEquals('low', $engine->get_parent_intensity());
    }
}