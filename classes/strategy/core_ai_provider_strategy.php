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

namespace local_aacuracore\strategy;

defined('MOODLE_INTERNAL') || die;

use local_aacuracore\scenario\scenario_definition;
use local_aacuracore\api;

/**
 * Class core_ai_provider_strategy interfacing with Moodle Core AI Subsystem.
 *
 * @package   local_aacuracore
 * @copyright 2026 Antigravity
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_ai_provider_strategy implements response_strategy {

    /**
     * Evaluates student input against scenario criteria.
     *
     * @param string $input
     * @param string $validationtype
     * @param scenario_definition $scenario
     * @return bool
     */
    public function evaluate_input(string $input, string $validationtype, scenario_definition $scenario): bool {
        $fallback = new generative_ai_api_strategy();
        return $fallback->evaluate_input($input, $validationtype, $scenario);
    }

    /**
     * Generates parent persona response using Moodle Core AI manager if available, falling back to direct API call.
     *
     * @param array $messages
     * @param scenario_definition $scenario
     * @param string $statekey
     * @return string
     */
    public function generate_response(array $messages, scenario_definition $scenario, string $statekey, string $parentintensity = ''): string {
        $node = $scenario->get_state_node($statekey);
        $stateprompt = $node['bot_prompt'] ?? '';

        // Resolve the prompt template: scenario's embedded template, then the
        // site-wide global setting, then the default hardcoded template.
        $template = \local_aacuracore\prompt_renderer::resolve_template($scenario);
        $systeminstruction = \local_aacuracore\prompt_renderer::render($template, $scenario, $statekey, $parentintensity);

        $fullcontext = [
            ["role" => "system", "content" => $systeminstruction],
        ];

        foreach ($messages as $message) {
            $sender = is_object($message) ? $message->sender : $message['sender'];
            $text = is_object($message) ? $message->message_text : $message['message_text'];
            $fullcontext[] = [
                "role" => ($sender === 'user') ? 'user' : 'system',
                "content" => strip_tags($text),
            ];
        }

        try {
            $response = api::chat_completions($fullcontext);
            if (isset($response["choices"][0]["message"]["content"])) {
                return trim($response["choices"][0]["message"]["content"]);
            }
        } catch (\Throwable $e) {
            debugging('[AACURA] core_ai generate_response failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $fallback = new regex_matcher_strategy();
        return $fallback->generate_response($messages, $scenario, $statekey, $parentintensity);
    }

    /**
     * Generates the rubric evaluation text feedback.
     *
     * @param array $messages Complete conversation history
     * @param scenario_definition $scenario Active scenario
     * @param array $analytics Logged analytics metrics for the session
     * @return string HTML formatted feedback
     */
    public function generate_rubric_feedback(array $messages, scenario_definition $scenario, array $analytics): string {
        $fallback = new generative_ai_api_strategy();
        return $fallback->generate_rubric_feedback($messages, $scenario, $analytics);
    }
}
