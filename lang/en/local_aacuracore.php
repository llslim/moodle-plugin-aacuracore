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
 * lang pt_br file.
 *
 * @package   local_aacuracore
 * @copyright 2024 Eduardo Kraus {@link http://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['active_scenarios'] = 'Active Preloaded Scenario Profiles';
$string['active_scenarios_desc'] = 'Select which preloaded parent scenarios are enabled and available for selection in AACURA chatbot activities.';
$string['apikey'] = 'OpenAI API Key';
$string['apikey_desc'] = 'The API key of your OpenAI account';
$string['case'] = 'Use Cases (Global Generation Profile)';
$string['caseuse_balanced'] = 'Balanced Responses => Temperature 0.5 - 0.7, Top_p 0.7';
$string['caseuse_chatbot'] = 'Chatbot => Temperature 0.2 - 0.6, Top_p 0.8';
$string['caseuse_creative'] = 'Creative Generation => Temperature 0.7 - 1.0, Top_p 0.8';
$string['caseuse_exploration'] = 'Exploration of Options => Temperature 0.8 - 1.0, Top_p 0.9';
$string['caseuse_formal'] = 'Formal Tone => Temperature 0.3 - 0.5, Top_p 0.6';
$string['caseuse_informal'] = 'Informal Tone => Temperature 0.7 - 0.9, Top_p 0.8';
$string['caseuse_precise'] = 'Precise Responses => Temperature 0.0 - 0.3, Top_p 1.0';
$string['clear_history_title'] = 'Clear all history';
$string['close_title'] = 'Close chat';
$string['course_home'] = 'The student is outside the course, and their name is "{$a->userfullname}".';
$string['course_user'] = 'The student is in the course "{$a->course}", and their name is "{$a->userfullname}".';
$string['frequency_penalty'] = 'Frequency Penalty (Global)';
$string['frequency_penalty_desc'] = 'This global parameter is used to discourage the model from repeating the same words or phrases too often in the generated text. It is a value added to the log probability of a token each time it occurs in the generated text. A higher frequency penalty will make the model more conservative about using repeated tokens. Applied to all AI engines.';
$string['geniai:manage'] = 'Manage GeniAI';
$string['geniai:view'] = 'View GeniAI';
$string['geniainame'] = 'AACURA - AAC Understanding & Reflective Assistant';
$string['geniainame_desc'] = 'Define the name of your assistant';
$string['h5p-accordion-desc'] = 'Create a Glossary allowing students to quickly access answers without being overwhelmed by excessive text.';
$string['h5p-accordion-title'] = 'Glossary';
$string['h5p-advancedtext-desc'] = 'Create a digital book from your content, organizing it into chapters in a logical and engaging way to ensure cohesive and captivating material division.';
$string['h5p-advancedtext-title'] = 'Digital Book';
$string['h5p-block-title'] = 'Block Title';
$string['h5p-create'] = 'Create H5P with GeniAI';
$string['h5p-create-new'] = 'Create new H5P with GeniAI';
$string['h5p-create-this'] = 'Create with this resource';
$string['h5p-create-title'] = 'H5P Title';
$string['h5p-create-title-desc'] = 'Define the main title for the H5P content to be displayed to users in the interface.';
$string['h5p-createpage-title'] = 'Create new {$a}';
$string['h5p-crossword-desc'] = 'Create an interactive crossword game to engage students, using keywords from your content to promote fun and dynamic learning.';
$string['h5p-crossword-title'] = 'Crossword Puzzle';
$string['h5p-delete-success'] = 'H5P successfully deleted!';
$string['h5p-dialogcards-desc'] = 'Create flashcards that act as interactive exercises to help students memorize words, phrases, or key concepts from texts. On the front of each card, there’s a hint or clue, and when flipped, the student reveals the corresponding information. These cards can be used in language learning, solving math problems, or helping students memorize important facts like historical events, formulas, or names.';
$string['h5p-dialogcards-title'] = 'Flashcards';
$string['h5p-dragtext-desc'] = 'Create a Drag the Words game where the student must drag the missing part of the text to its correct place, forming a complete expression. This game can be used to assess whether the student remembers the content they read or understands what was covered. Additionally, it helps the student reflect more deeply on the text, promoting better content assimilation.';
$string['h5p-dragtext-title'] = 'Drag the Words Game';
$string['h5p-example'] = 'See example';
$string['h5p-findthewords-desc'] = 'Create a word search game where students must find and select words in a grid based on a provided list.';
$string['h5p-findthewords-title'] = 'Word Search Game';
$string['h5p-interactivebook-desc'] = 'Create an Interactive Book that combines various interactive content, such as interactive videos, glossaries, quizzes, drag-and-drop activities, crosswords, word searches, and more, organized across multiple pages. Add a summary at the end, showing the total score the student obtained throughout the book.';
$string['h5p-interactivebook-title'] = 'Interactive Book';
$string['h5p-interactivevideo-desc'] = 'Create an interactive video with chapters and a glossary highlighting key points of the content. At the end, add an interactive summary to reinforce learning and review the topics covered.';
$string['h5p-interactivevideo-title'] = 'Interactive Video';
$string['h5p-manager'] = 'Manage H5P with GeniAI';
$string['h5p-manager-scorm'] = 'Manage SCORM with GeniAI';
$string['h5p-next-step'] = 'Next step';
$string['h5p-no-apikey'] = '<p>Configuring the ChatGPT API key is necessary for the account creation system to work properly. This will allow the system to communicate with ChatGPT to perform the required operations during the account creation process.<p><p><a href="{$a}">Click here to configure the ChatGPT API key.</a></p>';
$string['h5p-page-title'] = 'Create an H5P with GeniAI';
$string['h5p-questionset-desc'] = 'Create a Question Set that allows students to solve a sequence of diverse questions, including types such as multiple-choice and true/false, offering an interactive and challenging experience.';
$string['h5p-questionset-title'] = 'Quizzes';
$string['h5p-readmore'] = '...more';
$string['h5p-return'] = 'Back to Content Bank';
$string['h5p-title'] = 'Manage GeniAI Content Bank';
$string['max_tokens'] = 'Maximum words in response (Global)';
$string['max_tokens_desc'] = 'Maximum number of words that can be generated in each request. Applied to all AI engines.';
$string['min_turns'] = 'Minimum student turns before grading';
$string['min_turns_desc'] = 'The minimum number of student turns (exchanges) the conversation must run before it is automatically evaluated and graded. The dialogue continues for at least this many turns even if a terminal state is reached early. Default is 8.';
$string['max_turns'] = 'Minimum student turns before grading';
$string['max_turns_desc'] = 'The minimum number of student turns (exchanges) the conversation must run before it is automatically evaluated and graded. The dialogue continues for at least this many turns even if a terminal state is reached early. Default is 8.';
$string['parent_intensity'] = 'Parent Assertiveness / Aggressiveness';
$string['parent_intensity_desc'] = 'Dial the simulated parent\'s assertiveness and aggressiveness up or down. This injects a behavior instruction into the persona\'s system prompt so the LLM plays the parent more passively or more confrontationally.';
$string['parent_intensity_very_low'] = 'Very Low (very gentle, passive)';
$string['parent_intensity_low'] = 'Low (mildly assertive)';
$string['parent_intensity_medium'] = 'Medium (moderately assertive)';
$string['parent_intensity_high'] = 'High (assertive & firm)';
$string['parent_intensity_very_high'] = 'Very High (confrontational, aggressive)';
$string['prompt_template'] = 'Persona System Prompt Template (Global)';
$string['prompt_template_desc'] = 'A site-wide default LLM system prompt for the persona. This applies to all scenarios unless a scenario defines its own <code>prompt_template</code> in its JSON. Use <code>{{placeholder}}</code> tokens: <code>{{persona_name}}</code>, <code>{{backstory}}</code>, <code>{{pronoun}}</code>, <code>{{communication_style}}</code>, <code>{{initial_mood}}</code>, <code>{{statekey}}</code>, <code>{{stateprompt}}</code>, <code>{{scenario_id}}</code>, <code>{{learning_objectives}}</code>, <code>{{parent_intensity}}</code>.';
$string['evaluation_prompt_template'] = 'Evaluation (Rubric) System Prompt (Global)';
$string['evaluation_prompt_template_desc'] = 'The LLM system prompt used in the second call that evaluates the conversation and produces rubric feedback. This is called after the parent-persona dialogue completes (when the max turn count is reached or a terminal state is hit). Use the <code>{{rubric}}</code> placeholder to inject the scenario\'s per-state rubric criteria.';
$string['ai_builder_prompt_template'] = 'Automated Scenario Builder Interviewer Prompt';
$string['ai_builder_prompt_template_desc'] = 'The system prompt used by the Automated Scenario Builder (InterviewBot) to guide an author through creating a roleplay scenario interactively. This is the `interviewer_system_prompt()` returned by `scenario_builder_ai`. Changes here take effect the next time the Automated Scenario Builder is started.';
$string['message_01'] = 'Hello, {$a}! 🌟';
$string['message_02_course'] = 'Welcome to the course {$a->coursename} on Moodle {$a->moodlename}!

I am AACURA — your AAC Understanding & Reflective Assistant — and I’m here to support you throughout your learning journey.

This AI-powered chatbot is designed to help you practice and demonstrate effective communication with parents of children who use AAC (Augmentative and Alternative Communication) tools. As a pre-service teacher, your task is to carry out a simulated parent-teacher conversation using the LAFF don’t CRY strategy.

🗣️ You will engage in a structured back-and-forth dialogue with a simulated parent persona of at least 8 turns. After the minimum number of exchanges, your responses will be automatically evaluated based on the LAFF framework.

📊 Your score (out of 10) will be displayed and automatically recorded in the Moodle gradebook.

✨ Key Features:
- Use the dropdown to select a parent persona scenario.
- Click Light/Dark to toggle between visual themes.
- Use the Microphone button to speak instead of typing (if enabled).
- Click Clear/Restart Chat to restart the conversation at any time.
- Use the Generate PDF button to export your conversation log for your records.

🎯 To pass this assignment, you must demonstrate strong application of LAFF principles — especially showing empathy, asking relevant questions, and following through with reflective teaching practice.

🔄 Ready to begin or restart? Just click Clear/Restart Chat and let AACURA guide you again!

Good luck, and let’s make every conversation count!';

$string['message_02_geniai'] = 'Hello! I am {$a}, here to help you. If you prefer, you can send me an audio message, and I will respond in audio as well. If you prefer to write, I will reply in text. Whichever you prefer!';
$string['message_02_home'] = 'I am {$a}, and I am here to make your learning journey as amazing as possible.
How can I assist you today? 🌟📚';
$string['mode'] = 'Usage Mode';
$string['mode_desc'] = 'Define which usage mode for the balloon you desire';
$string['mode_name_assistant'] = 'Moodle Assistant';
$string['mode_name_geniai'] = 'GeniAI Tutor';
$string['mode_name_none'] = 'No chat balloon';
$string['model'] = 'The API Model';
$string['model_desc'] = 'The API model to be executed in OpenAI. Available values are on the <a href="https://platform.openai.com/docs/models/overview" target="_blank">OpenAI website</a><br>
* <strong>gpt-4</strong>: Much more powerful, slightly more expensive, takes a bit longer to respond, and requires a <a href="https://help.openai.com/en/articles/7102672-how-can-i-access-gpt-4" target="_blank">prepayment of $1</a> to test.<br>
* <strong>gpt-4o-mini</strong>:  Less powerful than gpt-4, but faster and cheaper. No prepayment is required.';
$string['modulename'] = 'AACURA - AAC Understanding & Reflective Assistant';
$string['modules'] = 'Modules to hide from {$a}';
$string['modules_desc'] = 'This list contains the modules that should not be made available to students, ensuring they are not used in exercises.';
$string['online'] = 'Online';
$string['pluginname'] = 'AACURA - AAC Understanding & Reflective Assistant';
$string['presence_penalty'] = 'Presence Penalty (Global)';
$string['presence_penalty_desc'] = 'This global parameter is used to encourage the model to include a variety of tokens in the generated text. It is a value subtracted from the log probability of a token each time it is generated. A higher presence penalty value will make the model more likely to generate tokens not yet included in the generated text. Applied to all AI engines.';
$string['privacy:metadata'] = 'The GeniAI plugin stores conversation history and transmits only the full name, course name, and URL to OpenAI, without sharing any other personal data.';
$string['report_completion_tokens'] = 'Number of Tokens received';
$string['report_datecreated'] = 'Day';
$string['report_filename'] = 'GPT Assistance Usage Report';
$string['report_info'] = '<p>In the presented report, only the first 100 lines are available. To access all records, please download the complete document.</p><p>Regarding tokens, a general rule of thumb is that one token roughly corresponds to about 4 characters of common English text. This equals approximately ¾ of a word (so, 100 tokens ~= 75 words). Learn more on the <a href="https://platform.openai.com/tokenizer" target="_blank">Language Model Tokenization</a> page.</p>';
$string['report_model'] = 'ChatGPT Model';
$string['report_prompt_tokens'] = 'Number of Tokens Sent';
$string['report_title'] = 'Report';
$string['send_message'] = 'Send Message';
$string['settings'] = 'Configure GeniAI';
$string['settings_casedesc'] = 'The temperature and Top_p parameters defined for each scenario, such as text and code generation, creative writing, chatbot, textual comments generation, data analysis, and exploratory writing. Each configuration impacts the model’s creativity and coherence in content generation. These global generation profiles are applied to all AI engines (Moodle Core AI, Google Gemini, and ChatGPT/OpenAI).<br><br>See the table below for guidance on using Temperature and Top_p:<br>';
$string['settings_casedesc_balancedresp'] = 'Balanced Responses';
$string['settings_casedesc_balancedresp_desc'] = 'Balanced responses between accuracy and creativity. Ideal for natural and friendly conversations.';
$string['settings_casedesc_caseuse'] = 'Use Case';
$string['settings_casedesc_chatbot'] = 'Chatbot';
$string['settings_casedesc_chatbot_desc'] = 'Fast, consistent, and contextual responses for real-time interaction with users.';
$string['settings_casedesc_creativegen'] = 'Creative Generation';
$string['settings_casedesc_creativegen_desc'] = 'Produces more creative, original, or exploratory responses. Useful for brainstorming or storytelling.';
$string['settings_casedesc_description'] = 'Description';
$string['settings_casedesc_formaltones'] = 'Formal Tones';
$string['settings_casedesc_formaltones_desc'] = 'Creates more formal or technical texts with less creative variation.';
$string['settings_casedesc_optionexplore'] = 'Option Exploration';
$string['settings_casedesc_optionexplore_desc'] = 'Generates multiple alternative responses to consider different approaches to a question.';
$string['settings_casedesc_preciseresp'] = 'Precise Responses';
$string['settings_casedesc_preciseresp_desc'] = 'Maximum accuracy and predictability. Recommended for technical or informative tasks.';
$string['settings_casedesc_relaxedtones'] = 'Relaxed Tones';
$string['settings_casedesc_relaxedtones_desc'] = 'Generates lighter and informal texts with a creative and friendly approach.';
$string['settings_casedesc_temperature'] = 'Temperature';
$string['settings_casedesc_top_p'] = 'Top_p';
$string['talk_geniai'] = 'Talk to {$a} here';
$string['url_moodle'] = 'The Moodle URL is "{$a->wwwroot}" and the Moodle name is "{$a->fullname}"';
$string['voice'] = 'Voice used in the audio response (Global)';
$string['write_message'] = 'Write a message...';

$string['engine_strategy'] = 'Active AI Engine Solution';
$string['engine_strategy_desc'] = 'Choose which AI engine solution actively powers the AACURA dialogue state machine and rubric evaluation.';
$string['engine_strategy_core_ai'] = '🔌 Moodle Core AI Framework (\core_ai\manager)';
$string['engine_strategy_external'] = '✨ Google Gemini Direct REST API (gemini-3.5-flash)';
$string['engine_strategy_local'] = '🤖 ChatGPT (OpenAI Direct API) / Local Engine';
$string['api_base_url'] = 'API Endpoint Base URL';
$string['api_base_url_desc'] = 'Sanitized base URL for specifying the target server. Defaults to Google Gemini Cloud API but can be configured to an internal university Gemma instance.';
$string['api_bearer_token'] = 'API Bearer Token / Secret Key';
$string['api_bearer_token_desc'] = 'Masked authorization secret token needed to process external LLM inference requests.';
$string['model_identifier'] = 'Model Variant Identifier';
$string['model_identifier_desc'] = 'The exact model variant string (e.g. gemini-3.5-flash, gemini-2.0-flash).';

$string['core_ai_apply_generation'] = 'Apply generation parameters to Moodle Core AI';
$string['core_ai_apply_generation_desc'] = 'When enabled, the temperature and Top_p values from the selected Use Case profile above are written into the active Moodle Core AI provider\'s "Generate text" action configuration. Moodle Core AI does not accept per-call sampling parameters, so this is the supported way to control sampling for the Core AI path.';

$string['tab_core_ai'] = 'Moodle Core AI';
$string['tab_gemini'] = 'Google Gemini';
$string['tab_chatgpt'] = 'ChatGPT (OpenAI)';
$string['active_solution_badge'] = 'Active Engine Solution';
$string['active_solution_info'] = 'This solution is currently set as the active engine strategy in AACURA.';
$string['inactive_solution_info'] = 'This solution is currently inactive. Select it under LLM Engine Strategy above to activate.';
$string['core_ai_providers_heading'] = 'Site-Wide Moodle Core AI Providers';
$string['core_ai_providers_desc'] = 'The table below lists all AI Providers registered in Moodle Core AI Subsystem (\core_ai\manager). Core AI providers are managed centrally in Site Administration > General > AI > AI Providers.';
$string['core_ai_selected_provider'] = 'Active Core AI Provider Plugin';
$string['core_ai_selected_provider_desc'] = 'Select which registered Moodle Core AI provider plugin to use when Moodle Core AI Framework strategy is active.';

