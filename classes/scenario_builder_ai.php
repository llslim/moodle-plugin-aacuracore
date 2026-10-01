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

use local_aacuracore\scenario\scenario_loader;

/**
 * AI-driven interactive scenario builder.
 *
 * Switches the chatbot into an interviewer role that interacts with an author
 * to build a scenario JSON conversationally, then generates and validates a
 * complete scenario document for export.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC Chatbot Team
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scenario_builder_ai {

    /** @var array Interview state machine flow. */
    const FLOW = [
        'ASK_SCENARIO_ID',
        'ASK_PERSONA_NAME',
        'ASK_BACKSTORY',
        'ASK_PRONOUN',
        'ASK_MOOD',
        'ASK_COMM_STYLE',
        'ASK_ROLE',
        'ASK_OBJECTIVES',
        'ASK_START_PROMPT',
        'ASK_RUBRIC',
        'ASK_MIN_TURNS',
        'ASK_PARENT_INTENSITY',
        'ASK_OPTIONAL_TEMPLATE',
        'CONFIRM',
        'GENERATE',
    ];

    /**
     * Builds the interviewer system prompt instructing the LLM to guide the
     * author through scenario creation using the LAFF-informed, structured flow.
     *
     * The prompt is configurable via the site-wide admin setting
     * `ai_builder_prompt_template`; if empty, the built-in default is used.
     *
     * @return string
     */
    public static function interviewer_system_prompt(): string {
        $custom = get_config('local_aacuracore', 'ai_builder_prompt_template');
        if (!empty($custom) && trim($custom) !== '{{default}}') {
            return $custom;
        }

        return <<<'EOT'
You are InterviewBot, a friendly scenario interviewer for the AACURA training system.
Your job is to guide the author through creating a complete roleplay scenario by asking
ONE question at a time and collecting their answers.

CRITICAL REQUIREMENT:
For EVERY field you request, you MUST explain clearly and concisely to the author HOW that
specific information will be used in the simulation (e.g. how it is stored, how it guides
the AI persona's roleplay responses, how it drives the dialogue state machine, or how it
impacts student scoring and rubric evaluation). Never ask for a raw value alone.

Request the following fields in this order (do not ask all at once - ONE per turn):
1. scenario_id (short lowercase code, e.g. 'doctor_consult')
   Explain to author: This unique identifier is used by Moodle and the database to track the scenario, persist active student chat sessions, and link simulation grades to the Gradebook.

2. persona_name (the bot character's full name, e.g. 'Anna Charles (Parent)' or 'Dr. Robert Vance')
   Explain to author: This name is displayed to students in the persona selection dropdown menu and chat header so trainees know exactly who they are consulting with.

3. backstory (the persona's narrative, child's diagnosis/age, communication tools, and family context)
   Explain to author: This background narrative is injected into the AI's roleplay system prompt, allowing the persona to speak realistically about their personal challenges, child's routine, and emotional history.

4. pronoun (he/him, she/her, they/them — optional for non-parent roles)
   Explain to author: This ensures the simulated persona consistently uses the child's correct gender pronouns throughout the dialogue, reinforcing family-centered practice.

5. initial_mood (e.g. overwhelmed, anxious, confused, defensive, professional, helpful)
   Explain to author: This establishes the persona's emotional starting baseline, determining how guarded, frustrated, or cooperative they sound before the student applies LAFF communication strategies.

6. communication_style (one line describing tone, e.g. 'blunt, defensive, and uses everyday non-clinical terms')
   Explain to author: This directs the AI on tone of voice, pacing, and speech habits to simulate authentic stakeholder behavior.

7. role (type + display label among: parent, doctor, manufacturer_rep, aac_user, school_admin, iep_coordinator, insurance_rep, other_therapist; plus formality and power dynamic)
   Explain to author: This configures the professional hierarchy and social dynamic (e.g. peer, client, or authority figure), adapting the universal LAFF framework across diverse stakeholder encounters.

8. learning_objectives (comma separated, e.g. active_listening, empathy_check, avoiding_jargon, finding_first_steps)
   Explain to author: These objectives define the pedagogical targets of the exercise and guide the post-simulation evaluation report generated for the student.

9. START state bot_prompt (the persona's core concern / presenting problem)
   Explain to author: When the trainee opens the consultation with an initial greeting (e.g. "What brings you in today?"), the persona delivers this presenting problem statement to initiate the discussion.

10. Per-state rubric criteria (for EACH dialogue state: START, EXPLORATION, ESCALATION, CONFUSION, RESOLUTION, FAIL_STATE)
    Explain to author: These criteria define the exact trainee behaviors that earn points. After the dialogue completes, the evaluation engine scores the student transcript against these benchmarks to calculate their grade out of 10 and produce detailed feedback.

11. min_turns (minimum student turns required before grading; default 8)
    Explain to author: This enforces dialogue depth, ensuring students engage in sustained communication and practice multiple LAFF steps before the simulation can be graded.

12. parent_intensity (one of: very_low, low, medium, high, very_high; default medium)
    Explain to author: This controls the persona's level of pushback and how persistently they challenge clinical jargon or require de-escalation.

13. OPTIONAL custom prompt_template (or say 'default')
    Explain to author: Allows authors to supply custom prompt formatting if they wish to override the default roleplay template.

RULES:
- Ask exactly ONE question per turn.
- For EVERY question, clearly explain how the requested information is used to create and run the scenario.
- After each answer, briefly confirm and acknowledge what the author provided, then ask the next field with its explanation.
- For field 10 (rubric), ask for the rubric criteria state by state (START, EXPLORATION, ESCALATION, CONFUSION, RESOLUTION, FAIL_STATE). The author may give them all at once or one state at a time.
- For field 11 (min_turns), accept a number (e.g. 8); if the author has no preference, default to 8.
- For field 12 (parent_intensity), accept one of the listed levels; if the author has no preference, default to 'medium'.
- Do NOT generate the final JSON until ALL fields are gathered.
- After you have all fields, ask the user to say "generate" to create the JSON.
When the user says "generate", output ONLY a valid JSON object (no markdown fences) that matches the AACURA scenario schema:
{
  "scenario_id": "...",
  "min_turns": 8 (optional, default 8),
  "parent_intensity": "medium" (optional, one of very_low|low|medium|high|very_high),
  "prompt_template": "..." (optional),
  "persona": {
    "name": "...", "backstory": "...", "initial_mood": "...", "communication_style": "...",
    "role": { "type": "...", "display_label": "...", "relationship_to_trainee": "...", "formality_level": "formal|professional|informal", "power_dynamic": "hierarchical_superior|peer|hierarchical_subordinate", "technical_expertise": "high|medium|low|experiential" }
  },
  "learning_objectives": [...],
  "states": {
    "START": {"bot_prompt":"...", "rubric":["1 point if ...", "..."], "expected_criteria": {"validation_type":"empathy_check","pass_route":"EXPLORATION","fail_route":"ESCALATION"}},
    "EXPLORATION": {"bot_prompt":"...", "rubric": ["1 point if ...", "..."], "expected_criteria": {"validation_type":"jargon_check","pass_route":"RESOLUTION","fail_route":"CONFUSION"}},
    "ESCALATION": {"bot_prompt":"...", "rubric": ["1 point if ...", "..."], "expected_criteria": {"validation_type":"de_escalation_check","pass_route":"EXPLORATION","fail_route":"FAIL_STATE"}},
    "CONFUSION": {"bot_prompt":"...", "rubric": ["1 point if ...", "..."], "expected_criteria": {"validation_type":"clarification_check","pass_route":"EXPLORATION","fail_route":"ESCALATION"}},
    "RESOLUTION": {"bot_prompt":"...", "rubric": ["1 point if ...", "..."], "expected_criteria": null},
    "FAIL_STATE": {"bot_prompt":"...", "rubric": ["1 point if ...", "..."], "expected_criteria": null}
  }
}
Each state's "rubric" MUST be an array of strings describing concrete trainee moves that earn a point in that state. If the author provided no rubric for a state, use an empty array [] for that state.
If the author supplies a custom prompt_template, include it; otherwise omit it.
Always keep the LAFF 'Don't Cry' framework in mind (Listen/Empathize, Ask, Focus, First Steps; no criticism, no defensive reaction, no unexplained jargon) for the learning objectives.
EOT;
    }

    /**
     * Routes a user message within the interviewer session and optionally returns
     * a generated scenario JSON once the author confirms "generate".
     *
     * @param array $history    Conversation history (role => user/message text).
     * @param string $userinput The author's latest message.
     * @return array {reply: string, json: array|null}
     */
    public static function process_turn(array $history, string $userinput): array {
        $lower = strtolower(trim($userinput));
        // Map the client __start__ sentinel to a natural starting instruction so
        // the interviewer poses the first question.
        if ($lower === '__start__') {
            $userinput = "Let's begin. Please ask me your first question.";
            $lower = 'begin';
        }
        $asking = ($lower === 'generate' || $lower === 'generate json' || $lower === 'done' || $lower === 'yes please');

        $messages = [];
        $messages[] = ['role' => 'system', 'content' => self::interviewer_system_prompt()];
        foreach ($history as $m) {
            $messages[] = [
                'role' => ($m['role'] === 'bot') ? 'assistant' : 'user',
                'content' => strip_tags($m['content'] ?? ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => strip_tags($userinput)];

        $response = api::chat_completions($messages);
        $reply = $response['choices'][0]['message']['content'] ?? '';
        if (empty($reply)) {
            return ['reply' => 'Sorry, I could not process that. Could you try again?', 'json' => null];
        }

        // If the author asked to generate, try to extract a JSON object.
        $json = null;
        if ($asking) {
            $json = self::extract_json($reply);
            if ($json) {
                $validation = self::validate_json($json);
                if (is_string($validation)) {
                    // Feed the validation error back, ask to fix.
                    $fixprompt = [
                        ['role' => 'system', 'content' => self::interviewer_system_prompt()],
                        ['role' => 'user', 'content' => $userinput],
                        ['role' => 'assistant', 'content' => $reply],
                        ['role' => 'user', 'content' => "The generated scenario failed validation: {$validation}. Please fix the JSON and output only valid JSON."],
                    ];
                    $fixresponse = api::chat_completions($fixprompt);
                    $fixreply = $fixresponse['choices'][0]['message']['content'] ?? '';
                    $fixedjson = self::extract_json($fixreply);
                    if ($fixedjson) {
                        $json = $fixedjson;
                        $reply = $fixreply;
                        $validation = self::validate_json($json);
                    }
                    if (is_string($validation)) {
                        return ['reply' => "⚠️ The generated scenario could not be validated: {$validation}.\n\nPlease correct the details and say 'generate' again.", 'json' => null];
                    }
                }
                return ['reply' => $reply, 'json' => $json];
            }
        }

        return ['reply' => $reply, 'json' => $json];
    }

    /**
     * Extract a JSON object from an LLM response (handles markdown code fences).
     *
     * @param string $text
     * @return array|null
     */
    protected static function extract_json(string $text): ?array {
        // Strip markdown fences if present.
        if (preg_match('/```json\s*([\s\S]*?)```/', $text, $m)) {
            $text = $m[1];
        } else if (preg_match('/```\s*([\s\S]*?)```/', $text, $m)) {
            $text = $m[1];
        }
        // Find the first { ... } block.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $candidate = substr($text, $start, $end - $start + 1);
        $json = json_decode($candidate, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Validate a scenario JSON structure. Returns true if valid, or an error string.
     *
     * @param array $json
     * @return true|string
     */
    protected static function validate_json(array $json) {
        if (empty($json['scenario_id'])) {
            return 'missing scenario_id';
        }
        if (empty($json['persona']['name'])) {
            return 'missing persona.name';
        }
        if (empty($json['states']['START']['bot_prompt'])) {
            return 'missing states.START.bot_prompt';
        }
        // Normalize the optional conversation settings.
        if (isset($json['min_turns'])) {
            $minturns = (int)$json['min_turns'];
            if ($minturns <= 0) {
                unset($json['min_turns']);
            } else {
                $json['min_turns'] = $minturns;
            }
        }
        $validintensities = ['very_low', 'low', 'medium', 'high', 'very_high'];
        if (isset($json['parent_intensity'])) {
            if (!in_array($json['parent_intensity'], $validintensities, true)) {
                unset($json['parent_intensity']);
            }
        }

        // Normalize per-state rubric values to arrays and validate their shape.
        foreach ($json['states'] ?? [] as $statekey => $node) {
            if (isset($node['rubric'])) {
                // Accept a comma/newline-separated string and split it into an array.
                if (is_string($node['rubric'])) {
                    $json['states'][$statekey]['rubric'] = array_values(array_filter(array_map('trim',
                        preg_split('/[\r\n,]+/', $node['rubric']))));
                }
                if (!is_array($json['states'][$statekey]['rubric'])) {
                    return "states.{$statekey}.rubric must be an array";
                }
            }
        }
        try {
            $sc = scenario_loader::from_array($json);
            if (!$sc) {
                return 'could not build scenario definition';
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return true;
    }
}