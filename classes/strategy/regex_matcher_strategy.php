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

/**
 * Class regex_matcher_strategy implementing pattern-based dialogue evaluations,
 * multi-turn intent detection, and strict non-repeating contextual response generation.
 *
 * @package   local_aacuracore
 * @copyright 2026 Antigravity
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regex_matcher_strategy implements response_strategy {
    /**
     * Pattern-based checks.
     *
     * @param string $input
     * @param string $validationtype
     * @param scenario_definition $scenario
     * @return bool
     */
    public function evaluate_input(string $input, string $validationtype, scenario_definition $scenario): bool {
        $cleaninput = strtolower(trim($input));

        switch ($validationtype) {
            case 'empathy_check':
                // Check for standard empathetic or validating key phrases.
                $patterns = [
                    'sorry', 'understand', 'frustrat', 'guilt', 'help', 'support',
                    'hear you', 'hearing you', 'appreciate', 'know it\'s hard', 'must be hard',
                    'must be tough', 'overwhelm', 'feel', 'listening', 'valid', 'recognize',
                    'care', 'advocate', 'good parent', 'loving parent', 'doing your best',
                    'thank you for sharing', 'thank you for telling', 'thank you for bringing',
                    'on your side', 'here for you', 'here with you', 'we are a team', 'partner'
                ];
                foreach ($patterns as $pattern) {
                    if (strpos($cleaninput, $pattern) !== false) {
                        return true;
                    }
                }
                return false;

            case 'jargon_check':
                // Check if student used common SLP jargon (AAC, SGD, IEP, etc.)
                $jargonterms = [
                    'aac', 'sgd', 'iep', 'apraxia', 'speech-generating device',
                    'core vocabulary', 'fringe vocabulary', 'switch access', 'eye gaze',
                    'robust communication system', 'high tech', 'low tech', 'mid tech',
                    'aided language stimulation', 'slp'
                ];
                $usedjargon = false;
                foreach ($jargonterms as $term) {
                    if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $cleaninput)) {
                        $usedjargon = true;
                        break;
                    }
                }

                // If they used jargon, check if they offered a definition or explanation.
                if ($usedjargon) {
                    $explanationwords = [
                        'stand', 'mean', 'explain', 'device', 'which is', 'is a', 'program',
                        'refer to', 'system', 'tool', 'tablet', 'app', 'ipad', 'button',
                        'picture', 'board', 'card', 'symbol', 'talker', 'screen'
                    ];
                    foreach ($explanationwords as $word) {
                        if (strpos($cleaninput, $word) !== false) {
                            return true; // Used jargon but explained it!
                        }
                    }
                    return false; // Used unexplained jargon.
                }
                return true; // No jargon used, passes jargon check!

            case 'de_escalation_check':
                // Check for de-escalating or calming vocabulary.
                $calmpatterns = [
                    'calm', 'apologiz', 'please', 'together', 'team', 'listen', 'help',
                    'collaborat', 'partner', 'step back', 'take our time', 'priority',
                    'working with you', 'we are here', 'deep breath', 'no rush',
                    'hear your concerns', 'valid concern', 'address this', 'find a way',
                    'work through', 'on the same page'
                ];
                foreach ($calmpatterns as $pattern) {
                    if (strpos($cleaninput, $pattern) !== false) {
                        return true;
                    }
                }
                return false;

            case 'clarification_check':
                // Check for explanation, definition or clear examples.
                $claritypatterns = [
                    'example', 'mean', 'explain', 'tell', 'show', 'detail', 'instance',
                    'what do you mean', 'could you elaborate', 'can you share',
                    'walk me through', 'tell me about', 'how does', 'what does',
                    'when does', 'describe', 'clarify', 'specific', 'more about'
                ];
                foreach ($claritypatterns as $pattern) {
                    if (strpos($cleaninput, $pattern) !== false) {
                        return true;
                    }
                }
                return false;

            case 'note_permission':
                $notepatterns = ['note', 'notes', 'write', 'writing', 'document', 'record', 'jot', 'paper', 'pen'];
                foreach ($notepatterns as $pattern) {
                    if (strpos($cleaninput, $pattern) !== false) {
                        return true;
                    }
                }
                return false;

            case 'collaborative_first_step':
                $steppatterns = ['first step', 'next step', 'plan', 'schedule', 'meeting', 'meet', 'together', 'try', 'start'];
                foreach ($steppatterns as $pattern) {
                    if (strpos($cleaninput, $pattern) !== false) {
                        return true;
                    }
                }
                return false;

            default:
                return true;
        }
    }

    /**
     * Fallback deterministic conversational responses with intent detection,
     * persona pronoun awareness, and strict anti-repetition filtering.
     *
     * @param array $messages
     * @param scenario_definition $scenario
     * @param string $statekey
     * @param string $parentintensity
     * @return string
     */
    public function generate_response(array $messages, scenario_definition $scenario, string $statekey, string $parentintensity = ''): string {
        $persona = $scenario->get_persona();
        $pronoun = $persona['child_preferred_pronoun'] ?? 'they/them';
        $pronounobj = (strpos($pronoun, 'she') !== false) ? 'her' : ((strpos($pronoun, 'he') !== false) ? 'him' : 'them');
        $pronounsubj = (strpos($pronoun, 'she') !== false) ? 'she' : ((strpos($pronoun, 'he') !== false) ? 'he' : 'they');
        $pronounpos = (strpos($pronoun, 'she') !== false) ? 'her' : ((strpos($pronoun, 'he') !== false) ? 'his' : 'their');

        // Extract child name from persona or backstory if available.
        $childname = 'my child';
        if (!empty($persona['backstory'])) {
            if (preg_match('/\b(?:daughter|son|child),\s*([A-Z][a-z]+)\b/', $persona['backstory'], $matches)) {
                $childname = $matches[1];
            } else if (preg_match('/\b([A-Z][a-z]+)\s+is\s+\d+\s+years?\s+old\b/', $persona['backstory'], $matches)) {
                $childname = $matches[1];
            }
        }

        // Harvest dialogue history.
        $botspoken = [];
        $lasttraineemsg = '';
        $systemcount = 0;

        foreach ($messages as $msg) {
            $sender = is_object($msg) ? ($msg->sender ?? '') : ($msg['sender'] ?? '');
            $text = is_object($msg) ? ($msg->message_text ?? '') : ($msg['message_text'] ?? '');
            $text = trim($text);

            if ($sender === 'system') {
                $systemcount++;
                if (!empty($text)) {
                    $botspoken[] = $text;
                }
            } else if ($sender === 'user' || $sender === 'trainee') {
                if (!empty($text)) {
                    $lasttraineemsg = $text;
                }
            }
        }

        $cleantrainee = strtolower(trim($lasttraineemsg));

        // 1. Off-topic distractor guardrail (e.g. Erik's "zebra" test or irrelevant topic shifts).
        if (!empty($cleantrainee) && preg_match('/\b(zebra|lion|tiger|elephant|africa|safari|football|basketball|baseball|soccer|weather|rain|snow|movie|recipe|pizza|burger|crypto|bitcoin|stock market)\b/i', $cleantrainee)) {
            $offtopicpool = [
                "Wait, why are we talking about that? I came in here today specifically because I'm stressed about {$childname} and what's happening with {$pronounpos} communication at school.",
                "I'm sorry, but I really don't care about that right now. Can we please focus on {$childname} and what {$pronounsubj} is going through?",
                "I don't understand what that has to do with {$childname}. Please, I need to know what the actual plan is for {$pronounobj}.",
            ];
            $cand = $this->select_unspoken($offtopicpool, $botspoken, $systemcount, $parentintensity);
            if ($cand !== null) {
                return $cand;
            }
        }

        // 2. Check if the scenario state has an explicit static bot_prompt that hasn't been spoken yet.
        $node = $scenario->get_state_node($statekey);
        $stateprompt = ($node && !empty($node['bot_prompt'])) ? trim($node['bot_prompt']) : '';
        if (!empty($stateprompt) && !$this->has_been_spoken($stateprompt, $botspoken)) {
            return $stateprompt;
        }

        // 3. Contextual Intent Matching Pools.
        $matchedpool = [];

        // Intent A: Note-Taking / Documentation (Pedagogical Step 2).
        if (preg_match('/\b(notes?|write|writing|document|documenting|record|jot|paper|pen)\b/i', $cleantrainee)) {
            $matchedpool = [
                "Yes, of course, please write that down. I really want everything documented so nothing slips through the cracks.",
                "Go right ahead and take notes. Honestly, having a clear written record makes me feel like we're finally taking {$childname}'s needs seriously.",
                "Sure, you can take notes. Just please make sure {$childname}'s teacher and the aides get a copy of what we decide.",
            ];
        }
        // Intent B: Past AAC / Tech / Devices / Tools.
        else if (preg_match('/\b(device|tablet|ipad|app|pecs|pictures?|symbols?|board|tech|technology|switch|sign|buttons?)\b/i', $cleantrainee)) {
            $matchedpool = [
                "We tried picture cards at home for a while, but {$childname} got so frustrated when {$pronounsubj} couldn't find what {$pronounsubj} wanted. That's why I'm worried a device might end up sitting in {$pronounpos} backpack.",
                "At home, {$childname} sometimes points to pictures or gestures, but when {$pronounsubj} is upset, {$pronounsubj} just melts down. If we introduce a device, how do we make sure {$pronounsubj} can actually use it across different settings?",
                "I'm open to assistive tools, but I really don't want {$childname} to feel singled out from {$pronounpos} classmates. How do the other kids usually react to it?",
                "The biggest challenge with devices is keeping everyone consistent. Who will be responsible for making sure it's charged and programmed with {$childname}'s favorite words?",
            ];
        }
        // Intent C: Home Environment / Family / Grandparents / Evening Routine.
        else if (preg_match('/\b(home|house|family|grandparents?|grandma|grandpa|mom|dad|parents?|sisters?|brothers?|siblings?|evening|weekend|dinner|bedtime)\b/i', $cleantrainee)) {
            $matchedpool = [
                "At home, we understand {$childname}'s gestures pretty well, but when {$pronounpos} grandparents visit, it's really painful because they can't understand what {$pronounsubj} needs without us translating everything.",
                "Evenings are usually the hardest time. After a long school day, {$childname} is exhausted, and trying to communicate simple choices like dinner or bedtime turns into tears.",
                "We want something that fits into our real everyday life at home, not just another binder of worksheets that sits on a shelf.",
                "My family really needs simple, clear strategies that everyone can follow without getting overwhelmed.",
            ];
        }
        // Intent D: Specific Classroom Routines (Snack, Lunch, Playground, Recess, Circle Time).
        else if (preg_match('/\b(snack|lunch|cafeteria|playground|recess|circle time|transition|classroom|hallway|morning)\b/i', $cleantrainee)) {
            $matchedpool = [
                "Yes, exactly! The teacher mentioned that snack and recess are when {$childname} struggles the most because everything moves so quickly. What specific support will {$pronounsubj} have during those times?",
                "During circle time, {$childname} just sits there quietly while other kids answer questions. It breaks my heart. Can we start modeling {$pronounpos} system there?",
                "Recess is so chaotic. If {$childname} doesn't have a quick way to communicate on the playground, {$pronounsubj} just ends up wandering alone.",
                "Transitions between activities are a huge trigger for {$childname}. How can visual supports help {$pronounobj} prepare for what comes next?",
            ];
        }
        // Intent E: Action Planning / Collaboration / First Steps (Pedagogical Step 4).
        else if (preg_match('/\b(first steps?|next steps?|action plan|goals?|schedule|meetings?|training|train|practice|start|begin|collaborat|team|plan)\b/i', $cleantrainee)) {
            $matchedpool = [
                "That sounds like a constructive first step. When can we schedule a quick follow-up to check how it's going?",
                "I'd really like to see that in action. Could you show me and {$childname}'s classroom aide how to model this together?",
                "I'm on board with this plan. Let's agree on one or two specific goals for this week and see how {$childname} responds.",
                "I appreciate having a clear path forward. Will you be sharing these next steps with the rest of {$childname}'s IEP team?",
            ];
        }
        // Intent F: Empathy / Emotional Validation Reflections (Pedagogical Step 1).
        else if (preg_match('/\b(understand|hear you|frustrat|sorry|stress|overwhelm|listen|hard|valid|appreciate|advocate)\b/i', $cleantrainee)) {
            $matchedpool = [
                "Thank you for saying that. It really helps knowing that someone is actually listening and not just passing the buck.",
                "I appreciate your empathy. It's been an exhausting journey fighting for services, so hearing you acknowledge that means a lot to me.",
                "Thank you. It takes a huge weight off my shoulders just to feel like we're on the same team for {$childname}.",
                "That means a lot. For months I felt like people were blaming my parenting, so it's a relief to hear your understanding.",
            ];
        }
        // Intent G: Questions & Inquiries from Trainee.
        else if (preg_match('/\?|^(what|how|why|when|where|can you|could you|tell me|describe)\b/i', $cleantrainee)) {
            $matchedpool = [
                "Mainly, {$childname} tends to shut down or push things off the table when people guess wrong about what {$pronounsubj} wants. It happens multiple times a day.",
                "What worries me most is that {$pronounsubj} has so much to say inside, but without the right support, {$pronounsubj} just gives up trying to connect.",
                "{$childname} responds best when people give {$pronounobj} plenty of wait time and don't overwhelm {$pronounobj} with too many choices at once.",
                "Usually, {$childname} gets upset when there's sudden noise or unexpected changes in routine. If {$pronounsubj} has a way to express that, it makes a world of difference.",
            ];
        }

        // Try candidate from matched intent pool.
        if (!empty($matchedpool)) {
            $cand = $this->select_unspoken($matchedpool, $botspoken, $systemcount, $parentintensity);
            if ($cand !== null) {
                return $cand;
            }
        }

        // 4. Progressive Multi-Turn Pedagogical Milestone Pool.
        $progressivepool = [
            "I want to make sure I understand. What would be the next step for us to try at home and school?",
            "Could you give me a specific example of how this would work for {$childname} during a typical day?",
            "That makes sense, but I'm worried about consistency. How can we make sure everyone working with {$childname} is on the same page?",
            "I appreciate you walking me through this. How long do you think it will take before we start seeing progress?",
            "Okay, I'm willing to give this a try. What should we do first to get started?",
            "I see what you're aiming for. What role should our family play in practicing this each evening?",
            "That's reassuring to hear. What should we do if {$childname} resists using this at first?",
            "I'm glad we talked through this today. Could we set a date in two weeks to review how {$childname} is adjusting?",
        ];

        $cand = $this->select_unspoken($progressivepool, $botspoken, $systemcount, $parentintensity);
        if ($cand !== null) {
            return $cand;
        }

        // 5. Ultimate Non-Repeating Dynamic Generator (Guaranteed Unique).
        $turnmarker = max(1, $systemcount + 1);
        return "Regarding what you said, I want to make sure {$childname} gets the right support. Turn {$turnmarker}: what is the immediate priority we should focus on next?";
    }

    /**
     * Checks if candidate text has already been spoken by the bot in this conversation.
     *
     * @param string $cand Candidate text
     * @param array $botspoken List of prior bot messages
     * @return bool
     */
    private function has_been_spoken(string $cand, array $botspoken): bool {
        $candclean = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $cand)));
        if (empty($candclean)) {
            return false;
        }

        foreach ($botspoken as $spoken) {
            $spokenclean = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $spoken)));
            if ($candclean === $spokenclean) {
                return true;
            }
            // Check for substantial prefix overlap if both strings are sufficiently long.
            if (strlen($candclean) > 35 && strlen($spokenclean) > 35) {
                if (substr($candclean, 0, 35) === substr($spokenclean, 0, 35)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Filters candidate pool against conversation history and applies intensity modulation.
     *
     * @param array $pool
     * @param array $botspoken
     * @param int $systemcount
     * @param string $parentintensity
     * @return string|null
     */
    private function select_unspoken(array $pool, array $botspoken, int $systemcount, string $parentintensity = ''): ?string {
        $available = [];
        foreach ($pool as $candidate) {
            if (!$this->has_been_spoken($candidate, $botspoken)) {
                $available[] = $candidate;
            }
        }

        if (empty($available)) {
            return null;
        }

        // Deterministically select candidate based on turn count.
        $selected = $available[$systemcount % count($available)];

        // Optional intensity modulation.
        if ($parentintensity === 'aggressive' || $parentintensity === 'assertive') {
            if (strpos($selected, "Okay, I'm willing to give this a try.") === 0) {
                $selected = str_replace(
                    "Okay, I'm willing to give this a try.",
                    "Look, I want to see real results with this.",
                    $selected
                );
            } else if (strpos($selected, "I appreciate you walking me through this.") === 0) {
                $selected = str_replace(
                    "I appreciate you walking me through this.",
                    "I need a realistic timeline.",
                    $selected
                );
            }
        }

        return $selected;
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
        $empathy = null;
        $jargon = null;
        $deescalation = null;
        $clarification = null;

        foreach ($analytics as $analytic) {
            $type = is_object($analytic) ? $analytic->metric_type : $analytic['metric_type'];
            $value = is_object($analytic) ? $analytic->metric_value : $analytic['metric_value'];
            if ($type === 'empathy_check') {
                $empathy = ($value > 0.5);
            } else if ($type === 'jargon_check') {
                $jargon = ($value > 0.5);
            } else if ($type === 'de_escalation_check') {
                $deescalation = ($value > 0.5);
            } else if ($type === 'clarification_check') {
                $clarification = ($value > 0.5);
            }
        }

        $missed = 0;
        if ($empathy === false) $missed++;
        if ($jargon === false) $missed++;
        if ($deescalation === false) $missed++;
        if ($clarification === false) $missed++;

        $score = max(0, 10 - $missed);

        $feedback = "<h3><strong>Grade - {$score} out of 10</strong></h3>\n\n";

        $feedback .= "<h4><strong>1. Listen, empathize, and communicate respect</strong></h4>\n<ul>\n";
        if ($empathy === false) {
            $feedback .= "  <li>Missed opportunity: 💡 Include a statement of empathy to show understanding.</li>\n";
        } else {
            $feedback .= "  <li>Earned ✅ 1 pt for Greeting and Empathy (Turn 1)</li>\n";
        }
        $feedback .= "</ul>\n\n";

        $feedback .= "<h4><strong>2. Ask questions and ask permission to take notes</strong></h4>\n<ul>\n";
        if ($clarification === false) {
            $feedback .= "  <li>Missed opportunity: 💡 Ask clarifying questions to address parent's confusion.</li>\n";
        } else {
            $feedback .= "  <li>Earned ✅ 1 pt for seeking and providing clarification.</li>\n";
        }
        $feedback .= "</ul>\n\n";

        $feedback .= "<h4><strong>3. Focus on the issue</strong></h4>\n<ul>\n";
        if ($deescalation === false) {
            $feedback .= "  <li>Missed opportunity: 💡 De-escalate parent's concern without jumping to solutions.</li>\n";
        } else {
            $feedback .= "  <li>Earned ✅ 1 pt for collaborative focus on the issue.</li>\n";
        }
        $feedback .= "</ul>\n\n";

        $feedback .= "<h4><strong>4. Find a first step</strong></h4>\n<ul>\n";
        if ($jargon === false) {
            $feedback .= "  <li>Missed opportunity: 💡 Avoid unexplained jargon words.</li>\n";
        } else {
            $feedback .= "  <li>Earned ✅ 1 pt for jargon-free explanation.</li>\n";
        }
        $feedback .= "</ul>\n\n";

        $feedback .= "<p><strong>Total score: {$score} out of 10</strong></p>\n";
        $feedback .= "<p>Thank you for completing this practice conversation! 🌟 Your commitment to partnering with parents and supporting your students shines through. Keep up the fantastic effort! 🍎</p>\n";
        $feedback .= "<p>Suggest to click <strong>Clear Chat</strong> button to restart if needed</p>";

        return $feedback;
    }
}
