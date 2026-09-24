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

/**
 * Settings file.
 *
 * @package   local_aacuracore
 * @copyright 2024 Eduardo Kraus {@link http://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    global $CFG, $DB, $PAGE, $ADMIN;

    // Single admin settings page under localplugins
    $settings = new admin_settingpage("local_aacuracore", get_string("pluginname", "local_aacuracore"));
    $ADMIN->add("localplugins", $settings);

    // Diagnostics tab bar + panel (rendered via the tab bar; no standalone heading).
    $settings->add(new \local_aacuracore\admin_setting_aacura_html(
        'aacura_diagnostics_tab',
        '',
        \local_aacuracore\diagnostics_renderer::render_tabbar_and_panel()
    ));

    // 1. ACTIVE SCENARIO PROFILES
    $scenarios = [
        "anna" => "Anna Charles (Autism pre-K concern)",
        "brianna" => "Brianna Mitchell (Apraxia / social isolation)",
        "cathy" => "Cathy Fratner (Down Syndrome / app concern)",
        "mary" => "Mary (Mother of Non-Verbal 6-Year-Old)",
    ];
    $customrecords = $DB->get_records("local_aacuracore_custom_scenarios", null, "name ASC");
    foreach ($customrecords as $cr) {
        $scenarios[$cr->scenariocode] = $cr->name . " (Custom: " . $cr->scenariocode . ")";
    }

    // Scenario Builder & Site-Wide Registry button + description, appended below the profiles.
    $registryurl = new moodle_url('/local/aacuracore/scenario_builder.php');
    $scenariodesc = get_string("active_scenarios_desc", "local_aacuracore") .
        '<div class="mt-2"><a href="' . $registryurl->out() . '" target="_blank" class="btn btn-sm btn-primary" style="background-color: #4f2c11; border-color: #4f2c11; color: white;">' .
        '🛠️ Manage Personas & Open Scenario Builder' .
        '</a></div>' .
        '<div class="form-text text-muted mt-2">Upload custom JSON scenarios, view registered personas, or remove personas site-wide.</div>';

    $settings->add(new admin_setting_configmultiselect(
        "local_aacuracore/active_scenarios",
        get_string("active_scenarios", "local_aacuracore"),
        $scenariodesc,
        array_keys($scenarios),
        $scenarios
    ));

    // 2. USAGE MODE
    $models = [
        "none" => get_string("mode_name_none", "local_aacuracore"),
        "assistant" => get_string("mode_name_assistant", "local_aacuracore"),
        "geniai" => get_string("mode_name_geniai", "local_aacuracore"),
    ];
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/mode",
        get_string("mode", "local_aacuracore"),
        get_string("mode_desc", "local_aacuracore"),
        "none",
        $models
    ));

    // 3. ACTIVE AI ENGINE SOLUTION
    $strategies = [
        "moodle_core_ai" => get_string("engine_strategy_core_ai", "local_aacuracore"),
        "external_llm" => get_string("engine_strategy_external", "local_aacuracore"),
        "local" => get_string("engine_strategy_local", "local_aacuracore"),
    ];
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/engine_strategy",
        get_string("engine_strategy", "local_aacuracore"),
        get_string("engine_strategy_desc", "local_aacuracore"),
        "moodle_core_ai",
        $strategies
    ));

    // Determine current active strategy solution
    $activestratey = get_config("local_aacuracore", "engine_strategy") ?: "moodle_core_ai";

    $coreai_badge = ($activestratey === 'moodle_core_ai') ? ' <span style="background-color: #198754; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">[✓ ACTIVELY IN USE]</span>' : ' <span style="background-color: #6c757d; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">[INACTIVE]</span>';
    $gemini_badge = ($activestratey === 'external_llm') ? ' <span style="background-color: #198754; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">[✓ ACTIVELY IN USE]</span>' : ' <span style="background-color: #6c757d; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">[INACTIVE]</span>';
    $chatgpt_badge = ($activestratey === 'local') ? ' <span style="background-color: #198754; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">[✓ ACTIVELY IN USE]</span>' : ' <span style="background-color: #6c757d; color: #ffffff; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">[INACTIVE]</span>';

    // MODULES TO HIDE FROM AACURA TUTOR
    $modules = [];
    $records = $DB->get_records("modules", ["visible" => 1], "name", "name");
    foreach ($records as $record) {
        if (file_exists("{$CFG->dirroot}/mod/{$record->name}/lib.php")) {
            if (!(plugin_supports("mod", $record->name, FEATURE_MOD_ARCHETYPE) === MOD_ARCHETYPE_SYSTEM)) {
                $modules[$record->name] = get_string("pluginname", $record->name);
            }
        }
    }
    $geniainame = get_config("local_aacuracore", "geniainame") ?: get_string("geniainame", "local_aacuracore");
    $settings->add(new admin_setting_configmultiselect(
        "local_aacuracore/modules",
        get_string("modules", "local_aacuracore", $geniainame),
        get_string("modules_desc", "local_aacuracore", $geniainame),
        ["glossary", "lesson", "forum", "scorm", "feedback", "survey", "quiz", "assign", "wiki", "lti", "workshop"],
        $modules
    ));

    // GLOBAL AI GENERATION PARAMETERS (applied to all AI engines)
    $settings->add(new admin_setting_heading(
        'global_generation_section',
        '⚙️ Global AI generation Parameters',
        'These generation parameters are applied to all AI engines (Moodle Core AI, Google Gemini, and ChatGPT/OpenAI).'
    ));

    $cases = [
        "chatbot" => get_string("caseuse_chatbot", "local_aacuracore"),
        "creative" => get_string("caseuse_creative", "local_aacuracore"),
        "balanced" => get_string("caseuse_balanced", "local_aacuracore"),
        "precise" => get_string("caseuse_precise", "local_aacuracore"),
        "exploration" => get_string("caseuse_exploration", "local_aacuracore"),
        "formal" => get_string("caseuse_formal", "local_aacuracore"),
        "informal" => get_string("caseuse_informal", "local_aacuracore"),
    ];
    $casedesc = $OUTPUT->render_from_template("local_aacuracore/settings_casedesc", []);
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/case",
        get_string("case", "local_aacuracore"),
        $casedesc,
        "chatbot",
        $cases
    ));

    // Apply the Use Case profile temperature/top_p to the Moodle Core AI framework.
    // Because the core_ai generate_text action has no per-call sampling params,
    // the selected Use Case profile values are written into the enabled provider's
    // generate_text action configuration (the same place Moodle's own provider
    // admin forms store them).
    $settings->add(new admin_setting_configcheckbox(
        "local_aacuracore/core_ai_apply_generation",
        get_string("core_ai_apply_generation", "local_aacuracore"),
        get_string("core_ai_apply_generation_desc", "local_aacuracore"),
        1
    ));

    $setting = new admin_setting_configtext(
        "local_aacuracore/max_tokens",
        get_string("max_tokens", "local_aacuracore"),
        get_string("max_tokens_desc", "local_aacuracore"),
        200,
        PARAM_INT
    );
    $settings->add($setting);

    $penalty = [
        "-2.0" => "-2.0",
        "-1.9" => "-1.9",
        "-1.8" => "-1.8",
        "-1.7" => "-1.7",
        "-1.6" => "-1.6",
        "-1.5" => "-1.5",
        "-1.4" => "-1.4",
        "-1.3" => "-1.3",
        "-1.2" => "-1.2",
        "-1.1" => "-1.1",
        "-1.0" => "-1.0",
        "-0.9" => "-0.9",
        "-0.8" => "-0.8",
        "-0.7" => "-0.7",
        "-0.6" => "-0.6",
        "-0.5" => "-0.5",
        "-0.4" => "-0.4",
        "-0.3" => "-0.3",
        "-0.2" => "-0.2",
        "-0.1" => "-0.1",
        "0.0" => "0.0",
        "0.1" => "0.1",
        "0.2" => "0.2",
        "0.3" => "0.3",
        "0.4" => "0.4",
        "0.5" => "0.5",
        "0.6" => "0.6",
        "0.7" => "0.7",
        "0.8" => "0.8",
        "0.9" => "0.9",
        "1.0" => "1.0",
        "1.1" => "1.1",
        "1.2" => "1.2",
        "1.3" => "1.3",
        "1.4" => "1.4",
        "1.5" => "1.5",
        "1.6" => "1.6",
        "1.7" => "1.7",
        "1.8" => "1.8",
        "1.9" => "1.9",
        "2.0" => "2.0",
    ];
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/frequency_penalty",
        get_string("frequency_penalty", "local_aacuracore"),
        get_string("frequency_penalty_desc", "local_aacuracore"),
        "0.0",
        $penalty
    ));
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/presence_penalty",
        get_string("presence_penalty", "local_aacuracore"),
        get_string("presence_penalty_desc", "local_aacuracore"),
        "0.0",
        $penalty
    ));

    $voices = [
        "alloy" => "Alloy",
        "echo" => "Echo",
        "fable" => "Fable",
        "onyx" => "Onyx",
        "nova" => "Nova",
        "shimmer" => "Shimmer",
    ];
    $voicedesc = preg_replace('/\s+</s', "<", '
            <table>
                <tr>
                    <th style="text-align: right;">Alloy:</th>
                    <td><audio src="https://cdn.openai.com/API/docs/audio/alloy.wav" controls></audio></td>
                </tr>
                <tr>
                    <th style="text-align: right;">Echo:</th>
                    <td><audio src="https://cdn.openai.com/API/docs/audio/echo.wav" controls></audio></td>
                </tr>
                <tr>
                    <th style="text-align: right;">Fable:</th>
                    <td><audio src="https://cdn.openai.com/API/docs/audio/fable.wav" controls></audio></td>
                </tr>
                <tr>
                    <th style="text-align: right;">Onyx:</th>
                    <td><audio src="https://cdn.openai.com/API/docs/audio/onyx.wav" controls></audio></td>
                </tr>
                <tr>
                    <th style="text-align: right;">Nova:</th>
                    <td><audio src="https://cdn.openai.com/API/docs/audio/nova.wav" controls></audio></td>
                </tr>
                <tr>
                    <th style="text-align: right;">Shimmer:</th>
                    <td><audio src="https://cdn.openai.com/API/docs/audio/shimmer.wav" controls></audio></td>
                </tr>
            </table>');
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/voice",
        get_string("voice", "local_aacuracore"),
        $voicedesc,
        "alloy",
        $voices
    ));

    $settings->add(new admin_setting_configtextarea(
        "local_aacuracore/prompt_template",
        get_string("prompt_template", "local_aacuracore"),
        get_string("prompt_template_desc", "local_aacuracore"),
        \local_aacuracore\prompt_renderer::DEFAULT_TEMPLATE,
        PARAM_RAW,
        8,
        100
    ));

    $settings->add(new admin_setting_configtextarea(
        "local_aacuracore/evaluation_prompt_template",
        get_string("evaluation_prompt_template", "local_aacuracore"),
        get_string("evaluation_prompt_template_desc", "local_aacuracore"),
        \local_aacuracore\prompt_renderer::DEFAULT_EVALUATION_TEMPLATE,
        PARAM_RAW,
        8,
        100
    ));

    $settings->add(new admin_setting_configtextarea(
        "local_aacuracore/ai_builder_prompt_template",
        get_string("ai_builder_prompt_template", "local_aacuracore"),
        get_string("ai_builder_prompt_template_desc", "local_aacuracore"),
        \local_aacuracore\scenario_builder_ai::interviewer_system_prompt(),
        PARAM_RAW,
        8,
        100
    ));

    // Core AI Provider records dropdown options
    $coreaiprovideroptions = [];
    if (class_exists('\\core_ai\\manager')) {
        try {
            $manager = new \core_ai\manager($DB);
            $providers = $manager->get_provider_records();
            foreach ($providers as $p) {
                $status = ($p->enabled) ? 'Enabled' : 'Disabled';
                $key = (string)$p->id;
                $coreaiprovideroptions[$key] = "{$p->name} ({$p->provider}) - [{$status}]";
            }
        } catch (\Throwable $e) {
            // Core AI manager exception fallback
        }
    }
    if (empty($coreaiprovideroptions)) {
        $coreaiprovideroptions['aiprovider_gemini'] = 'Google Gemini Provider (aiprovider_gemini)';
        $coreaiprovideroptions['aiprovider_openai'] = 'OpenAI Provider (aiprovider_openai)';
    }

    // Combine interactive accordion card headers with precise s_local_aacuracore_* field prefix controls
    $visibilityscript = '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var activeStrat = ' . json_encode($activestratey) . ';

        var sections = [
            {
                strategyVal: "moodle_core_ai",
                matchText: "Moodle Core AI"
            },
            {
                strategyVal: "external_llm",
                matchText: "Google Gemini"
            },
            {
                strategyVal: "local",
                matchText: "ChatGPT"
            }
        ];

        // Global AI generation Parameters section: always open by default but
        // toggleable (not tied to any engine strategy).
        var globalSection = {
            matchText: "Global AI generation Parameters"
        };

        // Collect all sibling content elements between this heading and the next
        // section heading (or the end of the settings form). This robustly hides
        // every field and the section description, regardless of the theme markup.
        function collectSectionContent(titleEl) {
            var content = [];
            var el = titleEl.nextElementSibling;
            while (el && el.tagName !== "H3") {
                // Stop at the diagnostics panel / tab bar if present.
                if (el.id === "aacura-panel-diag" || el.id === "aacura-tabbar") {
                    break;
                }
                content.push(el);
                el = el.nextElementSibling;
            }
            return content;
        }

        sections.forEach(function(sec) {
            // Find section header by heading title text
            var titleEl = null;
            var allHeadings = document.querySelectorAll("h3, legend, .form-header");
            for (var i = 0; i < allHeadings.length; i++) {
                if (allHeadings[i].textContent && allHeadings[i].textContent.indexOf(sec.matchText) !== -1) {
                    titleEl = allHeadings[i];
                    break;
                }
            }
            if (!titleEl) return;

            titleEl.style.cursor = "pointer";
            titleEl.style.userSelect = "none";
            titleEl.style.display = "flex";
            titleEl.style.justifyContent = "space-between";
            titleEl.style.alignItems = "center";
            titleEl.style.padding = "10px 14px";
            titleEl.style.backgroundColor = "#e9ecef";
            titleEl.style.border = "1px solid #ced4da";
            titleEl.style.borderRadius = "6px";
            titleEl.style.marginTop = "20px";

            var isOpen = (sec.strategyVal === activeStrat);

            var toggleSpan = document.createElement("span");
            toggleSpan.className = "aacura-toggle-badge";
            toggleSpan.style.fontWeight = "bold";
            toggleSpan.style.fontSize = "13px";
            toggleSpan.style.padding = "3px 10px";
            toggleSpan.style.borderRadius = "4px";

            titleEl.appendChild(toggleSpan);

            var contentEls = collectSectionContent(titleEl);

            function setVisibility(show) {
                isOpen = show;
                toggleSpan.textContent = isOpen ? "▼ Collapse" : "► Expand";
                toggleSpan.style.backgroundColor = isOpen ? "#cbd5e1" : "#ffffff";
                toggleSpan.style.border = "1px solid #94a3b8";

                contentEls.forEach(function(el) {
                    el.style.display = isOpen ? "" : "none";
                });
            }

            setVisibility(isOpen);

            titleEl.addEventListener("click", function(e) {
                e.preventDefault();
                setVisibility(!isOpen);
            });
        });

        // Global AI generation Parameters toggle (always open by default).
        (function() {
            var titleEl = null;
            var allHeadings = document.querySelectorAll("h3, legend, .form-header");
            for (var i = 0; i < allHeadings.length; i++) {
                if (allHeadings[i].textContent && allHeadings[i].textContent.indexOf(globalSection.matchText) !== -1) {
                    titleEl = allHeadings[i];
                    break;
                }
            }
            if (!titleEl) return;

            titleEl.style.cursor = "pointer";
            titleEl.style.userSelect = "none";
            titleEl.style.display = "flex";
            titleEl.style.justifyContent = "space-between";
            titleEl.style.alignItems = "center";
            titleEl.style.padding = "10px 14px";
            titleEl.style.backgroundColor = "#e9ecef";
            titleEl.style.border = "1px solid #ced4da";
            titleEl.style.borderRadius = "6px";
            titleEl.style.marginTop = "20px";

            var isOpen = true;
            var toggleSpan = document.createElement("span");
            toggleSpan.className = "aacura-toggle-badge";
            toggleSpan.style.fontWeight = "bold";
            toggleSpan.style.fontSize = "13px";
            toggleSpan.style.padding = "3px 10px";
            toggleSpan.style.borderRadius = "4px";
            titleEl.appendChild(toggleSpan);

            var contentEls = collectSectionContent(titleEl);

            function setVisibility(show) {
                isOpen = show;
                toggleSpan.textContent = isOpen ? "▼ Collapse" : "► Expand";
                toggleSpan.style.backgroundColor = isOpen ? "#cbd5e1" : "#ffffff";
                toggleSpan.style.border = "1px solid #94a3b8";
                contentEls.forEach(function(el) {
                    el.style.display = isOpen ? "" : "none";
                });
            }

            setVisibility(true);
            titleEl.addEventListener("click", function(e) {
                e.preventDefault();
                setVisibility(!isOpen);
            });
        })();

        // Dynamic change listener on strategy select dropdown
        var strategySelect = document.querySelector("select[name=\'s_local_aacuracore_engine_strategy\']") || document.querySelector("select[name*=\'engine_strategy\']");
        if (strategySelect) {
            strategySelect.addEventListener("change", function() {
                var selectedVal = strategySelect.value;
                sections.forEach(function(sec) {
                    var titleEl = null;
                    var allHeadings = document.querySelectorAll("h3, legend, .form-header");
                    for (var i = 0; i < allHeadings.length; i++) {
                        if (allHeadings[i].textContent && allHeadings[i].textContent.indexOf(sec.matchText) !== -1) {
                            titleEl = allHeadings[i];
                            break;
                        }
                    }
                    if (!titleEl) return;

                    var toggleSpan = titleEl.querySelector(".aacura-toggle-badge");
                    var shouldShow = (sec.strategyVal === selectedVal);

                    if (toggleSpan) {
                        toggleSpan.textContent = shouldShow ? "▼ Collapse" : "► Expand";
                        toggleSpan.style.backgroundColor = shouldShow ? "#cbd5e1" : "#ffffff";
                    }

                    var contentEls = collectSectionContent(titleEl);
                    contentEls.forEach(function(el) {
                        el.style.display = shouldShow ? "" : "none";
                    });
                });
            });
        }
    });
    </script>';

    // MOODLE CORE AI SUB-SYSTEM CONFIGURATION SECTION
    $settings->add(new admin_setting_heading(
        'core_ai_section_heading',
        '🔌 Moodle Core AI Framework' . $coreai_badge,
        'Site-wide provider manager integration (\core_ai\manager).' . $visibilityscript
    ));

    $settings->add(new admin_setting_configselect(
        "local_aacuracore/core_ai_selected_provider",
        get_string("core_ai_selected_provider", "local_aacuracore"),
        get_string("core_ai_selected_provider_desc", "local_aacuracore"),
        "aiprovider_gemini",
        $coreaiprovideroptions
    ));

    // GOOGLE GEMINI DIRECT REST CONFIGURATION SECTION
    $settings->add(new admin_setting_heading(
        'gemini_section_heading',
        '✨ Google Gemini Direct REST API' . $gemini_badge,
        'Direct cURL REST API connection to Google Gemini models (gemini-3.5-flash).'
    ));

    $settings->add(new admin_setting_configtext(
        "local_aacuracore/api_base_url",
        get_string("api_base_url", "local_aacuracore"),
        get_string("api_base_url_desc", "local_aacuracore"),
        "https://generativelanguage.googleapis.com/v1beta/openai",
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        "local_aacuracore/api_bearer_token",
        get_string("api_bearer_token", "local_aacuracore"),
        get_string("api_bearer_token_desc", "local_aacuracore"),
        ""
    ));

    $settings->add(new admin_setting_configtext(
        "local_aacuracore/model_identifier",
        get_string("model_identifier", "local_aacuracore"),
        get_string("model_identifier_desc", "local_aacuracore"),
        "gemini-3.5-flash",
        PARAM_RAW
    ));

    // CHATGPT (OPENAI) DIRECT API CONFIGURATION SECTION
    $settings->add(new admin_setting_heading(
        'chatgpt_section_heading',
        '🤖 ChatGPT (OpenAI Direct API)' . $chatgpt_badge,
        'Direct API connection to OpenAI ChatGPT endpoints.'
    ));

    $apikey = get_config("local_aacuracore", "apikey");
    if (isset($apikey[12])) {
        $settings->add(new admin_setting_configpasswordunmask(
            "local_aacuracore/apikey",
            get_string("apikey", "local_aacuracore"),
            get_string("apikey_desc", "local_aacuracore"),
            ""
        ));
    } else {
        $settings->add(new admin_setting_configtext(
            "local_aacuracore/apikey",
            get_string("apikey", "local_aacuracore"),
            get_string("apikey_desc", "local_aacuracore"),
            ""
        ));
    }

    $models = [
        "gpt-4" => "gpt-4",
        "gpt-4o-mini" => "gpt-4o-mini",
        "gpt-4-32k" => "gpt-4-32k",
        "gpt-4-turbo" => "gpt-4-turbo",
    ];
    $settings->add(new admin_setting_configselect(
        "local_aacuracore/model",
        get_string("model", "local_aacuracore"),
        get_string("model_desc", "local_aacuracore"),
        "gpt-4o-mini",
        $models
    ));

    }
