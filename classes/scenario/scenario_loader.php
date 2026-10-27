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

namespace local_aacuracore\scenario;

defined('MOODLE_INTERNAL') || die;

/**
 * Class scenario_loader
 *
 * Responsibilities:
 * - Loads preloaded parent scenarios (Anna, Brianna, Cathy, Mary).
 * - Loads site-wide custom uploaded scenarios from DB table local_aacuracore_custom_scenarios.
 * - Parses external JSON definitions into scenario_definition instances.
 *
 * @package   local_aacuracore\scenario
 * @copyright 2026 AAC-RERC Chatbot Team
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scenario_loader {

    /**
     * Loads a scenario by ID/code with per-activity (cmid) and Topic/Scenario Library resolution.
     *
     * Order of resolution (PRD FR-12):
     * 1. Activity Filearea (mod_aacurachat / scenariofile) for $cmid.
     * 2. Scenario Library DB (local_aacuracore_scenarios) matching $scenarioid (activity -> course -> system scope).
     * 3. Legacy custom scenarios table (local_aacuracore_custom_scenarios).
     * 4. Physical JSON file in local/aacuracore/scenarios/<id>.json.
     * 5. Preloaded static PHP fallback definitions (Anna, Brianna, Cathy, Mary).
     *
     * @param string $scenarioid
     * @param int $courseid
     * @param int $cmid
     * @return scenario_definition
     */
    public static function load(string $scenarioid, int $courseid = 0, int $cmid = 0): scenario_definition {
        global $DB, $CFG;

        $scenarioid = strtolower(trim($scenarioid));

        // 1. Check Activity Filearea (mod_aacurachat / scenariofile) for $cmid.
        if ($cmid > 0) {
            $fileloaded = self::load_from_activity_filearea($scenarioid, $cmid);
            if ($fileloaded !== null) {
                return $fileloaded;
            }
        }

        // 2. Check unified Scenario Library DB table (local_aacuracore_scenarios).
        try {
            if (class_exists('\\local_aacuracore\\library\\topic_library_manager')
                    && \local_aacuracore\library\topic_library_manager::tables_exist()) {
                $librec = \local_aacuracore\library\topic_library_manager::get_scenario_record($scenarioid, $courseid, $cmid);
                if ($librec && !empty($librec->json_data)) {
                    $json = json_decode($librec->json_data, true);
                    if (is_array($json)) {
                        return self::from_array($json);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through if library lookup fails.
        }

        // 3. Check legacy custom DB table for uploaded JSON profiles.
        try {
            if ($DB && $DB->get_manager()->table_exists('local_aacuracore_custom_scenarios')) {
                $custom = $DB->get_record('local_aacuracore_custom_scenarios', ['scenariocode' => $scenarioid]);
                if ($custom && !empty($custom->json_data)) {
                    $json = json_decode($custom->json_data, true);
                    if (is_array($json)) {
                        return self::from_array($json);
                    }
                }
            }
        } catch (\Exception $e) {
            // Fall through if DB table check fails
        }

        // 4. Check local JSON file
        $jsonfile = $CFG->dirroot . '/local/aacuracore/scenarios/' . $scenarioid . '.json';
        if (file_exists($jsonfile)) {
            $content = file_get_contents($jsonfile);
            $json = json_decode($content, true);
            if (is_array($json)) {
                return self::from_array($json);
            }
        }

        // 5. Fallback to preloaded profiles
        switch ($scenarioid) {
            case 'anna':
                return self::get_anna_profile();
            case 'brianna':
                return self::get_brianna_profile();
            case 'cathy':
                return self::get_cathy_profile();
            case 'mary':
            default:
                return self::get_default_profile($scenarioid);
        }
    }

    /**
     * Attempts to load a scenario definition from the mod_aacurachat 'scenariofile' filearea for a given cmid.
     *
     * @param string $scenarioid Requested scenario code (matches 'custom' or the uploaded JSON's scenario_id)
     * @param int $cmid Course module ID
     * @return scenario_definition|null
     */
    public static function load_from_activity_filearea(string $scenarioid, int $cmid): ?scenario_definition {
        if ($cmid <= 0 || !function_exists('get_file_storage')) {
            return null;
        }

        try {
            $context = \context_module::instance($cmid, IGNORE_MISSING);
            if (!$context) {
                return null;
            }
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_aacurachat', 'scenariofile', 0, 'id DESC', false);
            if (empty($files)) {
                return null;
            }

            foreach ($files as $file) {
                $content = $file->get_content();
                if (empty($content)) {
                    continue;
                }
                $json = json_decode($content, true);
                if (!is_array($json)) {
                    continue;
                }
                $uploadedid = strtolower(trim($json['scenario_id'] ?? 'custom'));
                if ($scenarioid === 'custom' || $scenarioid === $uploadedid) {
                    if (empty($json['scenario_id'])) {
                        $json['scenario_id'] = 'custom';
                    }
                    return self::from_array($json);
                }
            }
        } catch (\Throwable $e) {
            // Ignore file storage errors and allow normal fallback.
        }

        return null;
    }

    /**
     * Instantiates a scenario_definition from a parsed JSON array structure.
     *
     * @param array $data
     * @return scenario_definition
     */
    public static function from_array(array $data): scenario_definition {
        $id = $data['scenario_id'] ?? 'custom';
        $persona = $data['persona'] ?? [];
        $objectives = $data['learning_objectives'] ?? [];
        $states = $data['states'] ?? [];
        $prompttemplate = $data['prompt_template'] ?? null;
        $role = $persona['role'] ?? null;
        $minturns = isset($data['min_turns']) ? (int)$data['min_turns'] : null;
        $parentintensity = $data['parent_intensity'] ?? null;
        $topic = null;
        if (isset($data['topic'])) {
            if (is_array($data['topic'])) {
                $topic = $data['topic'];
            } else if (is_string($data['topic']) && trim($data['topic']) !== '') {
                $topic = ['code' => trim($data['topic'])];
            }
        }

        return new scenario_definition($id, $persona, $objectives, $states, $prompttemplate, $role, $minturns, $parentintensity, $topic);
    }

    /**
     * Static helper returning default profile for Anna.
     *
     * @return scenario_definition
     */
    private static function get_anna_profile(): scenario_definition {
        $states = self::get_default_dialogue_states();
        $states['START']['bot_prompt'] = "Thank you for meeting with me. I'm just really overwhelmed with Sarah starting pre-K. I feel like this iPad app isn't working for her at all, and her grandparents can't figure it out either. I'm worried we're not helping her communicate.";

        return new scenario_definition(
            'anna',
            [
                'name' => 'Anna Charles (Parent)',
                'child_preferred_pronoun' => 'she/her',
                'backstory' => 'Your name is Anna Charles and your daughter, Sarah, is 4 years old and has a diagnosis of Autism. Sarah is just starting pre-kindergarten at a new school. She received her diagnosis within the last year. She has been receiving speech therapy since 1-year of age. She currently uses a communication app on an iPad. You are a single mother of Sarah. You work two jobs and Sarah spends a lot of time with her grandparents. You feel guilty because you want to spend more time with Sarah, but it is difficult with your current employment. You are very overwhelmed with Sarah’s diagnosis and her lack of communication. You believe that the iPad is not working for Sarah and you don’t know how to help her. You’re frustrated and are meeting with your daughter Sarah’s teacher and want to figure out better alternatives for Sarah to communicate effectively using the iPad and with her grandparents who have difficulty with technology.',
                'initial_mood' => 'overwhelmed',
                'communication_style' => 'Frustrated, defensive, guilt-ridden, and uses blunt vocabulary.',
            ],
            ['active_listening', 'empathy_check', 'note_permission'],
            $states
        );
    }

    /**
     * Static helper returning default profile for Brianna.
     *
     * @return scenario_definition
     */
    private static function get_brianna_profile(): scenario_definition {
        $states = self::get_default_dialogue_states();
        $states['START']['bot_prompt'] = "Thanks for taking the time to meet. I've been so anxious because I tried reaching out to the school SLP with no response. I watched Wesley in class recently and he was so isolated from his classmates. He tried to laugh and join in, but no one could understand him. I'm terrified he's making no friends.";

        return new scenario_definition(
            'brianna',
            [
                'name' => 'Brianna Mitchell (Parent)',
                'child_preferred_pronoun' => 'he/him',
                'backstory' => 'Your name is Brianna Mitchell and your son, Wesley, in 8-years old. Wesley has severe apraxia. His speech is extremely difficult to understand. He currently uses a small handheld AAC device. Wesley has been receiving AAC services from an outpatient pediatric hospital for the past 2 years. He also receives 30 minutes of therapy from his school-based SLP. You are the mother of Wesley. You are married and Wesley is your only son. You emailed your son’s outpatient SLP and asked to meet. You are frustrated because you have tried to contact the school-SLP but you haven’t received a response. You are concerned that your son is socially isolated and is having difficulty making friends. Recently, you attended an event at Wesley’s school. While in his classroom, you were able to observe Wesley and his classmates. You noticed that Wesley was often alone and rarely interacted with his peers. At one point, you saw him laugh at a classmate’s joke and try to communicate to his classmates with no success. You’re worried and are meeting with your son’s teacher and want to figure out how Wesley could be more social in making friends, how to encourage him to use his device without being embarrassed, and if he will ever be able to use his speech.',
                'initial_mood' => 'anxious',
                'communication_style' => 'Highly concerned, worried, speaking rapidly about Wesley’s isolation.',
            ],
            ['de_escalation', 'active_listening', 'jargon_free_explanation'],
            $states
        );
    }

    /**
     * Static helper returning default profile for Cathy.
     *
     * @return scenario_definition
     */
    private static function get_cathy_profile(): scenario_definition {
        $states = self::get_default_dialogue_states();
        $states['START']['bot_prompt'] = "I'm glad we could sit down together. I'm really confused about Charlie's communication app. When the SLP showed it to us it made sense, but at home I feel completely lost using it. My husband thinks Charlie will talk when he's ready and that the app might stop him from learning to speak. I just don't know what to do.";

        return new scenario_definition(
            'cathy',
            [
                'name' => 'Cathy Fratner (Parent)',
                'child_preferred_pronoun' => 'he/him',
                'backstory' => 'Your name is Cathy Fratner and your son, Charlie, is a 2-year old boy with Down Syndrome. Charlie is not yet talking. He has an iPad with a communication app that his SLP recommended for him to use about 6 months ago. Charlie has been receiving speech and language services through early intervention. Once a week, his SLP goes to his daycare to provide therapy. You are the mother of Charlie. You are newly married and Charlie is your first child. You met with Charlie’s SLP about 6 months ago. She spent 2 hours with you and your husband. She introduced a communication app to you and showed you how to work the app. It seemed to make sense when the SLP used it with Charlie, but you always feel lost and frustrated when using the app. Your husband doesn’t think that Charlie should be using his iPad to communicate and that he will talk when he is ready. Now you are worried that Charlie won’t learn how to talk if he keeps using the app in therapy and at home. You’re worried and are meeting with your son’s teacher and want to figure out how Charlie could be use the iPad more regularly, if using the iPad consistently will prevent him in the future, and if you should be concerned that Charlie isn’t talking yet.',
                'initial_mood' => 'confused',
                'communication_style' => 'Doubtful, feeling lost about technology, highly eager to learn.',
            ],
            ['clarification_check', 'jargon_free_explanation', 'empathy_check'],
            $states
        );
    }

    /**
     * Default profile generator for generic configuration.
     *
     * @param string $id
     * @return scenario_definition
     */
    private static function get_default_profile(string $id): scenario_definition {
        $states = self::get_default_dialogue_states();
        $states['START']['bot_prompt'] = "I don't understand why we are changing my child's communication system again. Every time he gets used to something at school, you switch it! He is non-verbal and needs consistency.";

        return new scenario_definition(
            $id,
            [
                'name' => 'Mary (Parent)',
                'child_preferred_pronoun' => 'he/him',
                'backstory' => 'Parent of a non-verbal 6-year-old child. Feels overwhelmed and defensive about school accommodations.',
                'initial_mood' => 'defensive',
                'communication_style' => 'Blunt, highly emotional, protective of child.',
            ],
            ['active_listening', 'de_escalation'],
            $states
        );
    }

    /**
     * Returns standard, compliant dialog states corresponding to the PRD directed graph.
     *
     * @return array
     */
    private static function get_default_dialogue_states(): array {
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
