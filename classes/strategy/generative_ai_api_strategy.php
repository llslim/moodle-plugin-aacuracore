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
 * Class generative_ai_api_strategy interfacing with LLM endpoints for evaluative roleplay.
 *
 * @package   local_aacuracore
 * @copyright 2026 Antigravity
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generative_ai_api_strategy implements response_strategy {
    /**
     * Dynamically evaluates student text using a targeted LLM evaluation prompt.
     *
     * @param string $input
     * @param string $validationtype
     * @param scenario_definition $scenario
     * @return bool
     */
    public function evaluate_input(string $input, string $validationtype, scenario_definition $scenario): bool {
        // Role-generic LAFF "Don't Cry" anchored validation.
        // The guideline is derived from the validation_type, framed in a
        // role-neutral way so the same checks apply to any persona role.
        $role = $scenario->get_role() ?? [];
        $rolelabel = $role['display_label'] ?? 'counterpart';

        // Build the guideline based on the validation type. Default handles
        // any custom validation_type a scenario author defines.
        $guidelines = [
            'empathy_check' => "Determine if the trainee expressed genuine empathy, active listening, or validated the {$rolelabel}'s feelings, perspective, or expertise. The trainee must sound supportive and understanding, not defensive or purely technical.",
            'jargon_check' => "Scan the trainee's message for unexplained clinical jargon, acronyms, or professional abbreviations (e.g. 'AAC', 'SGD', 'IEP', 'SLP', 'apraxia'). If jargon was used, the trainee MUST have explained or introduced its definition in accessible, {$rolelabel}-friendly language. If no jargon was used, they pass.",
            'de_escalation_check' => "Evaluate if the trainee spoke respectfully, validated the {$rolelabel}'s concern, and actively attempted to de-escalate the interaction. Defensive, dismissive, or passive-aggressive responses fail.",
            'clarification_check' => "Evaluate if the trainee clearly explained the technology/processes or politely asked clarifying questions to address the {$rolelabel}'s confusion without overwhelming them with technical abbreviations.",
        ];

        // Support scenario-defined custom validation guidelines, else fall back.
        $guideline = $guidelines[$validationtype] ?? "Decide if the trainee's statement is professional, respectful, constructive, and aligns with the LAFF 'Don't Cry' framework (Listen/Empathize, Ask, Focus, Find First Steps; no criticizing, reacting defensively, or unexplained jargon).";

        $prompt = [
            [
                "role" => "system",
                "content" => "You are an expert pedagogical evaluator. Your job is to analyze a trainee's message during a simulated interaction with a {$rolelabel}.\n\n" .
                             "Pedagogical Guideline to evaluate: " . $guideline . "\n\n" .
                             "Respond with only 'yes' (if they passed/met the criteria) or 'no' (if they failed/missed the opportunity).",
            ],
            [
                "role" => "user",
                "content" => "Trainee's statement to evaluate: \"" . strip_tags($input) . "\"",
            ],
        ];

        try {
            $response = api::chat_completions($prompt, true);
            if (isset($response["choices"][0]["message"]["content"])) {
                $decision = strtolower(trim($response["choices"][0]["message"]["content"]));
                return (strpos($decision, 'yes') !== false);
            }
            if (isset($response['error']['message'])) {
                throw new \Exception($response['error']['message']);
            }
            throw new \Exception("External API returned no choices");
        } catch (\Exception $e) {
            // Fallback to pattern matcher if external API fails
            $fallback = new regex_matcher_strategy();
            return $fallback->evaluate_input($input, $validationtype, $scenario);
        }

        return false;
    }

    /**
     * Dynamic conversational persona reply generation using complete history and active state directives.
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

        // Format and interleave conversation history safely supporting both arrays and stdClass objects
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
            if (isset($response['error']['message'])) {
                throw new \Exception($response['error']['message']);
            }
            throw new \Exception("External API returned no choices");
        } catch (\Throwable $e) {
            debugging('[AACURA] generate_response LLM call failed: ' . $e->getMessage() . '. Falling back to progressive fallback strategy.', DEBUG_DEVELOPER);
            $fallback = new regex_matcher_strategy();
            return $fallback->generate_response($messages, $scenario, $statekey, $parentintensity);
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
        $teacherreplies = [];
        $turn = 1;
        foreach ($messages as $message) {
            $sender = is_object($message) ? $message->sender : $message['sender'];
            $text = is_object($message) ? $message->message_text : $message['message_text'];
            if ($sender === 'user') {
                $teacherreplies[] = "Turn " . $turn . ": " . $text;
                $turn++;
            }
        }

        // Build the rubric from the scenario's per-state rubric entries.
        // Each state may define a "rubric" array of criteria lines. If a state
        // has no rubric defined, fall back to the default static rubric so
        // existing scenarios continue to evaluate correctly.
        $defaultrubric = [
            "greeting" => "1 point if teacher starts with a greeting.",
            "empathy" => "1 point for a statement of empathy.",
            "note_permission" => "1 point if teacher asks to take notes.",
            "presenting_problem" => "1 point for asking 'what brings you in today?'.",
            "duration" => "1 point for asking 'how long has this been a problem?'.",
            "exception" => "1 point for asking 'was this ever not a problem?'.",
            "consultation" => "1 point for asking 'have you spoken to anyone else?'.",
            "wrap_up" => "1 point for asking 'anything else to add?'.",
        ];

        $rubric = [];
        foreach ($scenario->get_states() as $statekey => $node) {
            if (isset($node['rubric']) && is_array($node['rubric']) && !empty($node['rubric'])) {
                foreach ($node['rubric'] as $index => $criterion) {
                    $rubric[strtolower($statekey) . '_' . ($index + 1)] = $criterion;
                }
            }
        }
        if (empty($rubric)) {
            $rubric = $defaultrubric;
        }

        $formattedrubric = implode("\n", array_map(
            fn($k, $v) => ucfirst(str_replace("_", " ", $k)) . ": " . $v,
            array_keys($rubric),
            $rubric
        ));

        // Resolve and render the editable evaluation (rubric) prompt template.
        $evaluationtemplate = \local_aacuracore\prompt_renderer::resolve_evaluation_template();
        $evaluationtemplate = str_replace('{{rubric}}', $formattedrubric, $evaluationtemplate);

        // Formulate feedback compile prompt for OpenAI with explicit HTML rendering instructions
        $fullcontext = [
            [
                "role" => "system",
                "content" => $evaluationtemplate,
            ],
        ];

        foreach ($teacherreplies as $reply) {
            $fullcontext[] = ["role" => "user", "content" => $reply];
        }

        try {
            debugging('[AACURA] generate_rubric_feedback: calling chat_completions, context size=' . count($fullcontext), DEBUG_DEVELOPER);
            $response = api::chat_completions($fullcontext);
            if (isset($response["choices"][0]["message"]["content"])) {
                $rawcontent = trim($response["choices"][0]["message"]["content"]);
                // Strip markdown code fences if LLM accidentally returns them
                $rawcontent = preg_replace('/^```(?:html)?\s*/i', '', $rawcontent);
                $rawcontent = preg_replace('/\s*```$/', '', $rawcontent);
                return trim($rawcontent);
            }
            if (isset($response['error']['message'])) {
                throw new \Exception($response['error']['message']);
            }
            throw new \Exception("External API returned no choices");
        } catch (\Throwable $e) {
            debugging('[AACURA] generate_rubric_feedback: Throwable: ' . $e->getMessage() . '. Falling back to pattern matcher strategy.', DEBUG_DEVELOPER);
            $fallback = new regex_matcher_strategy();
            return $fallback->generate_rubric_feedback($messages, $scenario, $analytics);
        }
    }
}
