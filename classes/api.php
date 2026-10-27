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

/**
 * Class api
 *
 * @package   local_aacuracore
 * @copyright 2025 Eduardo Kraus https://eduardokraus.com/
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /** @var string Active per-activity generation profile override */
    private static string $active_generation_case = '';

    /**
     * Sets the active per-activity generation case override.
     *
     * @param string $case
     * @return void
     */
    public static function set_active_generation_case(string $case = ''): void {
        self::$active_generation_case = trim($case);
    }

    /**
     * Resolves the aacurachat activity instance and course module ID (cmid).
     * Strictly uses $cmid when provided (> 0), with a safe non-throwing fallback
     * to the first activity in $courseid for legacy callers omitting cmid.
     *
     * @param int $courseid
     * @param int $cmid
     * @return array [\stdClass|null $aacurachat, int $resolvedcmid]
     */
    public static function resolve_activity_and_cmid(int $courseid, int $cmid = 0): array {
        global $DB;

        $aacurachat = null;
        $resolvedcmid = (int)$cmid;

        if ($resolvedcmid > 0) {
            $cm = get_coursemodule_from_id('aacurachat', $resolvedcmid, 0, false, IGNORE_MISSING);
            if ($cm && $DB->get_manager()->table_exists('aacurachat')) {
                $aacurachat = $DB->get_record('aacurachat', ['id' => $cm->instance]);
                if ($courseid <= 0) {
                    $courseid = (int)$cm->course;
                }
            }
        }

        if (!$aacurachat && $courseid > 0 && $DB->get_manager()->table_exists('aacurachat')) {
            $records = $DB->get_records('aacurachat', ['course' => $courseid], 'id ASC', '*', 0, 1);
            $aacurachat = reset($records) ?: null;
            if ($aacurachat && $resolvedcmid <= 0) {
                $cm = get_coursemodule_from_instance('aacurachat', $aacurachat->id, $courseid, false, IGNORE_MISSING);
                $resolvedcmid = $cm ? (int)$cm->id : 0;
            }
        }

        return [$aacurachat ?: null, $resolvedcmid];
    }

    /**
     * Finds the most recently active non-builder session for a user in a specific activity instance.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $cmid
     * @return \stdClass|null
     */
    public static function get_latest_active_session(int $userid, int $courseid, int $cmid): ?\stdClass {
        global $DB;

        $sql = "SELECT *
                  FROM {local_aacuracore_sessions}
                 WHERE userid = :userid
                   AND courseid = :courseid
                   AND cmid = :cmid
                   AND scenariocode <> :buildersentinel
              ORDER BY timemodified DESC, id DESC";
        $records = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'buildersentinel' => '__builder__',
        ], 0, 1);

        return reset($records) ?: null;
    }

    /**
     * History api function.
     *
     * @param int $courseid
     * @param string $action
     * @param int $cmid
     * @return array
     *
     * @throws \dml_exception
     */
    public static function history_api($courseid, $action, $cmid = 0) {
        global $DB, $USER;

        [$aacurachat, $cmid] = self::resolve_activity_and_cmid((int)$courseid, (int)$cmid);

        $scenariocode = $aacurachat->scenariocode ?? 'anna';
        $activesession = self::get_latest_active_session((int)$USER->id, (int)$courseid, $cmid);
        if ($activesession) {
            $scenariocode = $activesession->scenariocode;
        }

        $engine = new \local_aacuracore\bot_engine($USER->id, (int)$courseid, $cmid, $scenariocode);

        if ($action === "clear") {
            $engine->reset_session();
        }

        $messages = $engine->get_messages();
        $returnmessage = [];

        foreach ($messages as $message) {
            $content = $message->message_text;
            if (strpos($content, "<audio") === false) {
                // If text contains HTML tags (e.g. <h3>, <strong>, <ul>, <br>), pass through directly; otherwise parse markdown.
                if (preg_match('/<[a-z][\s\S]*>/i', $content)) {
                    // Raw HTML rendered directly
                } else if (class_exists('\\local_aacuracore\\local\\markdown\\parse_markdown')) {
                    $parsemarkdown = new \local_aacuracore\local\markdown\parse_markdown();
                    $content = $parsemarkdown->markdown_text($content);
                }
            }

            $returnmessage[] = [
                "role" => ($message->sender === 'user') ? 'user' : 'system',
                "content" => $content,
                "format" => "html",
            ];
        }

        return [
            "result" => "true",
            "content" => json_encode($returnmessage),
        ];
    }

    /**
     * Chat api function.
     *
     * @param string $message
     * @param int $courseid
     * @param null $audio
     * @param string $lang
     * @param int $cmid
     * @return array
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function chat_api($message, $courseid, $audio = null, $lang = "en", $cmid = 0) {
        global $DB, $USER;

        $maxMessageLength = 2500;
        if (strlen($message) > $maxMessageLength) {
            return ["result" => false, "format" => "text", "content" => "Error... Message too long. Please limit to {$maxMessageLength} characters."];
        }

        $maxAudioSizeBytes = 1000 * 1024;
        $transcription = null;
        if ($audio) {
            $audio = str_replace("data:audio/mp3;base64,", "", $audio);
            $audiodata = base64_decode($audio);
            if (strlen($audiodata) > $maxAudioSizeBytes) {
                return [
                    "result" => false,
                    "format" => "text",
                    "content" => "Error... Audio file is too large. Please upload audio under 1 KB.",
                ];
            }
            $transcription = self::transcriptions($audio, $lang);
            $message = $transcription["text"];
        }

        $cleanedMessage = strip_tags(trim($message));

        [$aacurachat, $cmid] = self::resolve_activity_and_cmid((int)$courseid, (int)$cmid);

        // Intercept special persona change command
        if (preg_match('/^\$\$persona=([a-zA-Z0-9_\-]+)\$\$$/', $cleanedMessage, $matches)) {
            $selected = strtolower(trim($matches[1]));
            $engine = new \local_aacuracore\bot_engine($USER->id, (int)$courseid, $cmid, $selected);
            $engine->reset_session();

            $session = $engine->get_session_record();
            $session->scenariocode = $selected;
            $session->current_state = 'START';
            $session->timemodified = time();
            $DB->update_record('local_aacuracore_sessions', $session);

            // The chatbot waits for an initial message from the user before presenting the core problem.
            return [
                "result" => "true",
                "format" => "html",
                "content" => "",
            ];
        }

        // Intercept the AI scenario builder command (edit-mode interactive interview).
        if (preg_match('/^\$\$builder\$\$$/', $cleanedMessage)) {
            return [
                "result" => "true",
                "format" => "html",
                "content" => '<strong>🧠 AI Scenario Builder</strong><br>Interview mode is active. I will ask you one question at a time to build your scenario. Type your answers in the chat. When finished, type <code>generate</code> to produce the scenario JSON.',
            ];
        }

        // Route interactive builder turns using an isolated '__builder__' session record so active persona sessions are never corrupted.
        if (preg_match('/^\^builder_turn\$\$(.*)$/s', $cleanedMessage, $bt)) {
            $builderinput = $bt[1];
            $state = $DB->get_record('local_aacuracore_sessions', [
                'userid' => $USER->id,
                'courseid' => (int)$courseid,
                'cmid' => $cmid,
                'scenariocode' => '__builder__',
            ], '*', IGNORE_MULTIPLE);

            if (!$state) {
                $state = (object)[
                    'userid' => $USER->id,
                    'courseid' => (int)$courseid,
                    'cmid' => $cmid,
                    'scenariocode' => '__builder__',
                    'current_state' => 'START',
                    'timecreated' => time(),
                    'timemodified' => time(),
                ];
                $state->id = $DB->insert_record('local_aacuracore_sessions', $state);
            } else if ($builderinput === '__start__') {
                $DB->delete_records('local_aacuracore_messages', ['sessionid' => $state->id]);
            }

            // Gather builder history from the session messages (system sender = builder).
            $history = [];
            $historyrows = $DB->get_records('local_aacuracore_messages', ['sessionid' => $state->id], 'id ASC');
            if ($historyrows) {
                foreach ($historyrows as $row) {
                    $role = ($row->sender === 'user') ? 'author' : 'bot';
                    $history[] = ['role' => $role, 'content' => $row->message_text];
                }
            }

            $result = \local_aacuracore\scenario_builder_ai::process_turn($history, $builderinput);
            $reply = $result['reply'];

            // Persist author message + bot reply to the transient builder session.
            // Skip the __start__ sentinel so it doesn't appear as a user answer.
            if ($builderinput !== '__start__') {
                $DB->insert_record('local_aacuracore_messages', (object)[
                    'sessionid' => $state->id,
                    'sender' => 'user',
                    'message_text' => $builderinput,
                    'timestamp' => time(),
                ]);
            }
            $DB->insert_record('local_aacuracore_messages', (object)[
                'sessionid' => $state->id,
                'sender' => 'system',
                'message_text' => $reply,
                'timestamp' => time(),
            ]);

            // If a valid scenario JSON was produced, embed it for export.
            if (!empty($result['json'])) {
                $json = json_encode($result['json'], JSON_PRETTY_PRINT);
                return [
                    "result" => "true",
                    "format" => "html",
                    "builder_json" => $json,
                    "content" => $reply . "\n\n<div class='alert alert-success'>✅ Scenario generated! Use the 'Export JSON' or 'Save to Activity & Topic Library' action below.</div>",
                ];
            }

            return ["result" => "true", "format" => "html", "content" => $reply];
        }

        // Moderate inappropriate content
        $moderationPrompt = [
            ["role" => "system", "content" => "You're a moderation AI. Decide if the following message contains profanity, foul language, mild insults words like dumb, etc. , or inappropriate content. Reply with only 'yes' or 'no'."],
            ["role" => "user", "content" => $cleanedMessage],
        ];

        $check = self::chat_completions($moderationPrompt);
        $decision = strtolower(trim($check["choices"][0]["message"]["content"] ?? "no"));

        // Load active scenario from DB or fallback
        $activescenariocode = 'anna';
        if ($aacurachat && !empty($aacurachat->scenariocode)) {
            $activescenariocode = $aacurachat->scenariocode;
        }
        $activesession = self::get_latest_active_session((int)$USER->id, (int)$courseid, $cmid);
        if ($activesession) {
            $activescenariocode = $activesession->scenariocode;
        }

        if ($decision === "yes") {
            $engine = new \local_aacuracore\bot_engine($USER->id, (int)$courseid, $cmid, $activescenariocode);
            $engine->reset_session();
            return [
                "result" => "true",
                "format" => "html",
                "content" => "<strong>Grade - 0 out of 10</strong><br>Your message contains inappropriate language. This session is terminated.",
            ];
        }

        // Instantiate core engine and run active turn logic
        $engine = new \local_aacuracore\bot_engine($USER->id, (int)$courseid, $cmid, $activescenariocode);
        $botreply = $engine->process_user_turn($cleanedMessage);

        // If botreply already contains HTML tags (e.g. <h3>, <ul>, <br>), pass through directly; otherwise parse markdown.
        if (preg_match('/<[a-z][\s\S]*>/i', $botreply)) {
            $content = $botreply;
        } else if (class_exists('\\local_aacuracore\\local\\markdown\\parse_markdown')) {
            $parsemarkdown = new \local_aacuracore\local\markdown\parse_markdown();
            $content = $parsemarkdown->markdown_text($botreply);
        } else {
            $content = $botreply;
        }

        return [
            "result" => "true",
            "format" => "html",
            "content" => $content,
            "transcription" => $transcription ? $transcription["text"] : null,
        ];
    }

    public static function chat_completions($messages, $ignoremaxtoken = false) {
        global $DB, $USER;

        // Compute the active generation profile (case => temperature/top_p) once.
        $caseparams = self::get_generation_params();

        // Check if Moodle Core AI provider framework is enabled & available
        if (class_exists('\\core_ai\\manager')) {
            try {
                $manager = new \core_ai\manager($DB);
                $allproviders = $manager->get_provider_records();
                // Only proceed if at least one provider is actually enabled
                $enabledproviders = array_filter($allproviders, fn($p) => !empty($p->enabled));
                if (!empty($enabledproviders)) {
                    // When enabled, propagate the Use Case profile temperature/top_p
                    // into the provider's generate_text action config. Moodle Core AI's
                    // generate_text action has no per-call sampling params, so these
                    // are injected where Moodle's own admin forms store them (the
                    // provider action settings).
                    $coreaiapply = get_config('local_aacuracore', 'core_ai_apply_generation');
                    if ($coreaiapply && $coreaiapply !== '0') {
                        $coreaitemp = $caseparams['temperature'] ?? 0.5;
                        $coreaitopp = $caseparams['top_p'] ?? 0.8;

                        $providerinstances = $manager->get_provider_instances();
                        foreach ($providerinstances as $providerinstance) {
                            $actionconfig = $providerinstance->actionconfig;
                            $ok = false;
                            $actionkey = null;
                            foreach ([
                                'core_ai\aiactions\generate_text',
                                \core_ai\aiactions\generate_text::class,
                            ] as $possiblekey) {
                                if (isset($actionconfig[$possiblekey])) {
                                    $actionkey = $possiblekey;
                                    break;
                                }
                            }
                            if ($actionkey !== null) {
                                $settings = $actionconfig[$actionkey]['settings'] ?? [];
                                $settings['temperature'] = $coreaitemp;
                                $settings['top_p'] = $coreaitopp;
                                $actionconfig[$actionkey]['settings'] = $settings;
                                $ok = true;
                            }
                            if ($ok) {
                                try {
                                    $manager->update_provider_instance(
                                        provider: $providerinstance,
                                        actionconfig: $actionconfig
                                    );
                                } catch (\Throwable $e) {
                                    debugging('[AACURA] core_ai inject generation params failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                                }
                            }
                        }
                    }

                    // core_ai generate_text only accepts a single plaintext prompt.
                    // Flatten the full message array (system instructions + all turns) into one combined string.
                    $promptparts = [];
                    foreach ($messages as $msg) {
                        $role = $msg['role'] ?? 'user';
                        $content = $msg['content'] ?? '';
                        if ($role === 'system') {
                            $promptparts[] = "[INSTRUCTIONS]\n" . $content;
                        } else {
                            $promptparts[] = "[" . strtoupper($role) . "]\n" . $content;
                        }
                    }
                    $prompttext = implode("\n\n", $promptparts);

                    $action = new \core_ai\aiactions\generate_text(
                        contextid: \context_system::instance()->id,
                        userid: $USER->id,
                        prompttext: $prompttext
                    );
                    $result = $manager->process_action($action);
                    if ($result && method_exists($result, 'get_success') && $result->get_success()) {
                        $data = $result->get_response_data();
                        $generatedtext = $data['generatedcontent'] ?? ($data['response'] ?? '');
                        if (!empty($generatedtext)) {
                            return [
                                "choices" => [
                                    [
                                        "message" => [
                                            "content" => $generatedtext,
                                            "role" => "assistant",
                                        ]
                                    ]
                                ]
                            ];
                        }
                    } else {
                        $err = 'Unknown failure or empty result';
                        if ($result) {
                            if (method_exists($result, 'get_errormessage')) {
                                $err = $result->get_errormessage();
                            } else if (method_exists($result, 'get_response_data')) {
                                $err = json_encode($result->get_response_data());
                            }
                        }
                        debugging('[AACURA] core_ai process_action failed: ' . $err, DEBUG_DEVELOPER);
                    }
                }
            } catch (\Throwable $e) {
                debugging('[AACURA] core_ai caught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), DEBUG_DEVELOPER);
            }
        }

        $strategy = get_config("local_aacuracore", "engine_strategy");
        if ($strategy === 'external_llm') {
            $apikey = get_config("local_aacuracore", "api_bearer_token");
            $model = get_config("local_aacuracore", "model_identifier");
            $api_base_url = get_config("local_aacuracore", "api_base_url");
        } else {
            $apikey = get_config("local_aacuracore", "apikey");
            $model = get_config("local_aacuracore", "model");
            $api_base_url = "https://api.openai.com/v1";
        }

        $maxtokens = get_config("local_aacuracore", "max_tokens");
        $frequencypenalty = get_config("local_aacuracore", "frequency_penalty");
        $presencepenalty = get_config("local_aacuracore", "presence_penalty");

        $temperature = $caseparams['temperature'];
        $topp = $caseparams['top_p'];

        $messagesok = [];
        foreach ($messages as $message) {
            $message["content"] = strip_tags($message["content"]);
            $messagesok[] = $message;
        }

        $post = (object)[
            "model" => $model,
            "messages" => $messagesok,
            "temperature" => $temperature,
            "top_p" => $topp,
        ];

        if (floatval($frequencypenalty) != 0.0) {
            $post->frequency_penalty = floatval($frequencypenalty);
        }
        if (floatval($presencepenalty) != 0.0) {
            $post->presence_penalty = floatval($presencepenalty);
        }

        if (!$ignoremaxtoken) {
            $post->max_tokens = intval($maxtokens);
        }

        // Guard: if no API key is configured, skip the cURL call entirely
        if (empty($apikey)) {
            return ["choices" => []];
        }

        $baseurl = rtrim($api_base_url, '/');
        $url = $baseurl . '/chat/completions';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased from 15s — LLM inference can take up to 30s

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer {$apikey}",
        ]);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return [
                "error" => [
                    "message" => "http error: " . curl_error($ch),
                ],
            ];
        }
        curl_close($ch);

        $gpt = json_decode($result, true);

        $usage = (object)[
            "send" => json_encode($post, JSON_PRETTY_PRINT),
            "receive" => $result,
            "model" => $model,
            "prompt_tokens" => intval($gpt["usage"]["prompt_tokens"] ?? 0),
            "completion_tokens" => intval($gpt["usage"]["completion_tokens"] ?? 0),
            "timecreated" => time(),
            "datecreated" => date("Y-m-d", time()),
        ];
        try {
            $DB->insert_record("local_aacuracore_usage", $usage);
        } catch (\dml_exception $e) {
            echo $e->getMessage();
        }

        return $gpt;
    }

    /**
     * Resolve the active generation profile (use-case => temperature/top_p).
     *
     * The "case" setting maps a named generation profile to concrete sampling
     * parameters. These are used as the defaults for the direct REST path and
     * as the fallback for the Moodle Core AI path when no manual override is set.
     *
     * @param string|null $caseoverride Optional explicit generation case override
     * @return array {temperature: float, top_p: float}
     */
    public static function get_generation_params(?string $caseoverride = null): array {
        $case = !empty($caseoverride)
            ? $caseoverride
            : (!empty(self::$active_generation_case) ? self::$active_generation_case : get_config("local_aacuracore", "case"));
        switch ($case) {
            case "creative":
                return ['temperature' => .7, 'top_p' => .8];
            case "balanced":
                return ['temperature' => .5, 'top_p' => .7];
            case "precise":
                return ['temperature' => .0, 'top_p' => 1.0];
            case "exploration":
                return ['temperature' => .8, 'top_p' => .9];
            case "formal":
                return ['temperature' => .3, 'top_p' => .6];
            case "informal":
                return ['temperature' => .7, 'top_p' => .8];
            case "chatbot":
                return ['temperature' => .2, 'top_p' => .8];
            default:
                return ['temperature' => .5, 'top_p' => .5];
        }
    }

    /**
     * Handles audio transcription using OpenAI's Whisper API
     */
    private static function transcriptions($audio, $lang) {
        global $CFG;

        $audio = str_replace("data:audio/mp3;base64,", "", $audio);
        $audiodata = base64_decode($audio);
        $filename = uniqid();
        $filepath = "{$CFG->dataroot}/temp/{$filename}.mp3";
        file_put_contents($filepath, $audiodata);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/audio/transcriptions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            "file" => curl_file_create($filepath),
            "model" => "whisper-1",
            "response_format" => "verbose_json",
            "language" => $lang,
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: multipart/form-data",
            "Authorization: Bearer " . get_config("local_aacuracore", "apikey"),
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($result);

        return [
            "text" => $result->text,
            "language" => $result->language,
            "filename" => $filename,
        ];
    }

    /**
     * Converts text to speech using OpenAI's TTS API
     */
    private static function speech($input) {
        global $CFG;

        $json = json_encode((object)[
            "model" => "tts-1",
            "input" => $input,
            "voice" => get_config("local_aacuracore", "voice"),
            "response_format" => "mp3",
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/audio/speech");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . get_config("local_aacuracore", "apikey"),
        ]);

        $audiodata = curl_exec($ch);
        curl_close($ch);

        $filename = uniqid();
        $filepath = "{$CFG->dataroot}/temp/{$filename}.mp3";
        file_put_contents($filepath, $audiodata);

        return "{$CFG->wwwroot}/local/aacuracore/load-audio-temp.php?filename={$filename}";
    }
}
