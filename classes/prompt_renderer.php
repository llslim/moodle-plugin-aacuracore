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

defined('MOODLE_INTERNAL') || die;

use local_aacuracore\scenario\scenario_definition;

/**
 * Central placeholder-substitution utility for LLM prompt templates.
 *
 * Both generative_ai_api_strategy and core_ai_provider_strategy use this
 * class to render the configurable prompt_template from a scenario JSON,
 * replacing {{placeholder}} tokens with the corresponding scenario fields.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC Chatbot Team
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_renderer {

    /** @var string Default fallback template matching the original hardcoded behavior. */
    const DEFAULT_TEMPLATE = <<<'EOT'
Your name is {{persona_name}}. Backstory:
{{backstory}}

Persona voice and communication style: {{communication_style}}
Your intensity as a {{role_display_label}}: {{parent_intensity}}

ROLEPLAY & CONVERSATION GUIDELINES:
- Stay strictly in character as the {{role_display_label}} (formality level: {{formality_level}}).
- Carefully read the full conversation history. Always respond directly and contextually to what the trainee just said.
- Build naturally on the conversation. You are encouraged to bring in realistic everyday details, family situations, or past experiences that fit your backstory.
- NEVER repeat the exact same sentence, question, or canned response you already said earlier in the conversation.
- As the conversation progresses, respond constructively if the trainee shows empathy, asks good questions, and suggests practical steps.
- If the trainee uses confusing jargon or dismisses your concerns, react realistically with confusion or frustration.
- Keep each response conversational and realistic (typically 2 to 4 sentences). Do not write essays or break character.

Current stage topic focus: {{stateprompt}} (Use this to guide the topic of discussion for this phase in the '{{statekey}}' state, but respond dynamically to what the trainee actually said without parroting this exact text).
EOT;

    /** @var string Default evaluation (rubric feedback) prompt template. */
    const DEFAULT_EVALUATION_TEMPLATE = <<<'EOT'
You are evaluating a simulated parent-teacher conversation.

Below are only the teacher's replies (from role: `user`).
Do NOT evaluate any system or parent messages — ONLY evaluate the teacher replies.

Rubric:
{{rubric}}

Feedback Format:
🎯 Your goal is to group feedback into the 4 steps of LAFF:
1. Listen, empathize, and communicate respect
2. Ask questions and ask permission to take notes
3. Focus on the issue
4. Find a first step

🧮 Scoring:
- Start from 10 points.
- Award 1 point for each clearly demonstrated rubric-aligned move.
- Do not show point deductions.
- Instead, if something was missed, write it as a Missed opportunity: .
- Mention the turn number (teacher turn) in parentheses.

IMPORTANT FORMATTING INSTRUCTIONS:
- Output clean, raw, fully rendered HTML tags (e.g. <h3>, <h4>, <strong>, <ul>, <li>, <p>).
- Do NOT wrap your output in markdown code blocks.
- Do NOT output raw markdown asterisks or hash headers.

HTML Structure:
Start with: <h3><strong>Grade - X out of 10</strong></h3>
For each LAFF step, use <h4><strong>Step Name</strong></h4>
Under each step, use an HTML list <ul><li>...</li></ul> with list items:
- <li>Earned ✅ 1 pt for ___ (turn #)</li>
- <li>Missed opportunity: 💡 ___</li>

End with:
<p><strong>Total score: X out of 10</strong></p>
Write a warm, personalized thank-you message (2-3 sentences) addressed to the trainee, using their demonstrated effort in the conversation, and include a couple of encouraging emojis. Output it as a <p> tag.
<p>Suggest to click <strong>Clear Chat</strong> button to restart if needed</p>
EOT;

    /**
     * Resolve the effective evaluation (rubric) prompt template.
     *
     * Fallback chain:
     *   1. Site-wide global setting local_aacuracore/evaluation_prompt_template
     *   2. Hardcoded DEFAULT_EVALUATION_TEMPLATE constant
     *
     * @return string The evaluation template to render.
     */
    public static function resolve_evaluation_template(): string {
        $globalsetting = get_config('local_aacuracore', 'evaluation_prompt_template');
        if (!empty($globalsetting)) {
            return $globalsetting;
        }
        return self::DEFAULT_EVALUATION_TEMPLATE;
    }

    /**
     * Build a natural-language instruction describing how assertive/aggressive
     * the persona should be, derived from the global parent_intensity setting.
     *
     * @param string $intensitylevel One of very_low|low|medium|high|very_high
     * @return string Instruction line used in the {{parent_intensity}} placeholder.
     */
    public static function intensity_instruction(string $intensitylevel = ''): string {
        if (empty($intensitylevel)) {
            $intensitylevel = get_config('local_aacuracore', 'parent_intensity') ?: 'medium';
        }
        $map = [
            'very_low'   => 'extremely gentle, deferential, and cooperative; expresses feelings softly and never challenges; assume a very passive, agreeable tone.',
            'low'        => 'mildly assertive; mostly cooperative and polite, only occasionally expressing mild worry or firmness.',
            'medium'     => 'moderately assertive; clearly communicate your concerns and stand by your point of view without being rude or hostile.',
            'high'       => 'assertive and firm; press your wishes strongly, occasionally interrupt, and make your dissatisfaction clear while staying mostly professional.',
            'very_high'  => 'highly assertive and confrontational; be demanding, frustrated, sharply worded, and prone to expressing anger or ultimatums while still staying in character.',
        ];
        return $map[$intensitylevel] ?? $map['medium'];
    }

    /**
     * Resolve the effective prompt template for a scenario.
     *
     * Fallback chain:
     *   1. Scenario's embedded prompt_template (most specific)
     *   2. Site-wide global setting local_aacuracore/prompt_template
     *   3. Hardcoded DEFAULT_TEMPLATE constant
     *
     * @param scenario_definition $scenario The active scenario.
     * @return string The template to render.
     */
    public static function resolve_template(scenario_definition $scenario): string {
        $template = $scenario->get_prompt_template();
        if (!empty($template)) {
            return $template;
        }
        $globalsetting = get_config('local_aacuracore', 'prompt_template');
        if (!empty($globalsetting)) {
            return $globalsetting;
        }
        return self::DEFAULT_TEMPLATE;
    }

    /**
     * Substitute placeholders in a template string with scenario values.
     *
     * @param string $template    The raw template with {{placeholder}} tokens.
     * @param scenario_definition $scenario The active scenario.
     * @param string $statekey    The current dialogue state key.
     * @param string $intensitylevel Optional per-activity/global intensity override.
     * @return string The rendered template with all placeholders replaced.
     */
    public static function render(string $template, scenario_definition $scenario, string $statekey, string $intensitylevel = ''): string {
        $persona = $scenario->get_persona();
        $node = $scenario->get_state_node($statekey);
        $stateprompt = $node['bot_prompt'] ?? '';
        $role = $scenario->get_role() ?? [];

        $replacements = [
            '{{persona_name}}'              => $persona['name'] ?? '',
            '{{backstory}}'                 => $persona['backstory'] ?? '',
            '{{pronoun}}'                   => $persona['child_preferred_pronoun'] ?? 'he/him',
            '{{communication_style}}'       => $persona['communication_style'] ?? '',
            '{{initial_mood}}'              => $persona['initial_mood'] ?? '',
            '{{statekey}}'                  => $statekey,
            '{{stateprompt}}'               => $stateprompt,
            '{{scenario_id}}'               => $scenario->get_id(),
            '{{learning_objectives}}'       => implode(', ', $scenario->get_learning_objectives()),
            '{{role_type}}'                 => $role['type'] ?? 'parent',
            '{{role_display_label}}'        => $role['display_label'] ?? 'Parent',
            '{{relationship_to_trainee}}'   => $role['relationship_to_trainee'] ?? '',
            '{{formality_level}}'           => $role['formality_level'] ?? 'informal',
            '{{power_dynamic}}'             => $role['power_dynamic'] ?? 'peer',
            '{{technical_expertise}}'       => $role['technical_expertise'] ?? 'low',
            '{{parent_intensity}}'           => self::intensity_instruction($intensitylevel),
        ];

        return strtr($template, $replacements);
    }
}