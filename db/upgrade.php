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
 * upgrade file.
 *
 * @package   local_aacuracore
 * @copyright 2024 Eduardo Kraus {@link http://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade file.
 *
 * @param int $oldversion
 *
 * @return bool
 *
 * @throws Exception
 */
function xmldb_local_aacuracore_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024020501) {
        $table = new xmldb_table("local_aacuracore_usage");
        $field = new xmldb_field("model", XMLDB_TYPE_CHAR, "40", null, true, null, null, "receive");

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $model = get_config("local_aacuracore", "model");
        $sql = "UPDATE {local_aacuracore_usage} SET model = '{$model}'";
        $DB->execute($sql);

        upgrade_plugin_savepoint(true, 2024020501, 'local', 'aacuracore');
    }

    if ($oldversion < 2024040500) {
        $table = new xmldb_table("local_aacuracore_usage");
        $field = new xmldb_field("datecreated", XMLDB_TYPE_CHAR, "10", null, true, null, null, "timecreated");

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $usages = $DB->get_records("local_aacuracore_usage");
        foreach ($usages as $usage) {
            $usage->datecreated = date("Y-m-d", $usage->timecreated);
            $DB->update_record("local_aacuracore_usage", $usage);
        }

        upgrade_plugin_savepoint(true, 2024040500, 'local', 'aacuracore');
    }

    if ($oldversion < 2025011400) {
        // Criação da tabela local_aacuracore_h5p.
        $table = new xmldb_table("local_aacuracore_h5p");

        // Definindo os campos da tabela local_aacuracore_h5p.
        $table->add_field("id", XMLDB_TYPE_INTEGER, "10", true, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field("contextid", XMLDB_TYPE_INTEGER, "10", null, XMLDB_NOTNULL);
        $table->add_field("contentbanktid", XMLDB_TYPE_INTEGER, "10", null, XMLDB_NOTNULL);
        $table->add_field("title", XMLDB_TYPE_CHAR, "255", null, XMLDB_NOTNULL);
        $table->add_field("type", XMLDB_TYPE_CHAR, "40", null, XMLDB_NOTNULL);
        $table->add_field("data", XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field("timecreated", XMLDB_TYPE_INTEGER, "20", null, XMLDB_NOTNULL);

        // Definindo a chave primária.
        $table->add_key("primary", XMLDB_KEY_PRIMARY, ["id"]);

        // Verificando se a tabela existe e criando-a se não.
        if (!$DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->create_table($table);
        }

        // Criação da tabela local_aacuracore_h5ppages.
        $table = new xmldb_table("local_aacuracore_h5ppages");

        // Definindo os campos da tabela local_aacuracore_h5ppages.
        $table->add_field("id", XMLDB_TYPE_INTEGER, "10", true, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field("h5pid", XMLDB_TYPE_INTEGER, "10", null, XMLDB_NOTNULL);
        $table->add_field("title", XMLDB_TYPE_CHAR, "255", null, XMLDB_NOTNULL);
        $table->add_field("type", XMLDB_TYPE_CHAR, "40", null, XMLDB_NOTNULL);
        $table->add_field("data", XMLDB_TYPE_TEXT, null, XMLDB_NOTNULL);
        $table->add_field("timecreated", XMLDB_TYPE_INTEGER, "20", null, XMLDB_NOTNULL);

        // Definindo a chave primária.
        $table->add_key("primary", XMLDB_KEY_PRIMARY, ["id"]);

        // Verificando se a tabela existe e criando-a se não.
        if (!$DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->create_table($table);
        }

        // Atualizando a versão para 2025011400.
        upgrade_plugin_savepoint(true, 2025011400, 'local', 'aacuracore');
    }

    if ($oldversion < 2026052500) {
        // Table local_aacuracore_sessions.
        $table1 = new xmldb_table('local_aacuracore_sessions');
        $table1->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table1->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table1->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table1->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table1->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table1->add_field('current_state', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'START');
        $table1->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table1->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table1->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table1)) {
            $dbman->create_table($table1);
        }

        // Table local_aacuracore_messages.
        $table2 = new xmldb_table('local_aacuracore_messages');
        $table2->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table2->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_field('sender', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'user');
        $table2->add_field('message_text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table2->add_field('timestamp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table2->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table2->add_key('session_fk', XMLDB_KEY_FOREIGN, ['sessionid'], 'local_aacuracore_sessions', ['id']);

        if (!$dbman->table_exists($table2)) {
            $dbman->create_table($table2);
        }

        // Table local_aacuracore_analytics.
        $table3 = new xmldb_table('local_aacuracore_analytics');
        $table3->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table3->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table3->add_field('metric_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table3->add_field('metric_value', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00');
        $table3->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table3->add_key('session_fk', XMLDB_KEY_FOREIGN, ['sessionid'], 'local_aacuracore_sessions', ['id']);

        if (!$dbman->table_exists($table3)) {
            $dbman->create_table($table3);
        }

        upgrade_plugin_savepoint(true, 2026052500, 'local', 'aacuracore');
    }

    if ($oldversion < 2026052515) {
        $table = new xmldb_table('local_aacuracore_custom_scenarios');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('json_data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('scenariocode_unique', XMLDB_KEY_UNIQUE, ['scenariocode']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026052515, 'local', 'aacuracore');
    }

    if ($oldversion < 2026081001) {
        // Table local_aacuracore_evaluations.
        $table = new xmldb_table('local_aacuracore_evaluations');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('score', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00');
        $table->add_field('feedback', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('session_fk', XMLDB_KEY_FOREIGN, ['sessionid'], 'local_aacuracore_sessions', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081001, 'local', 'aacuracore');
    }

    if ($oldversion < 2026082501) {
        // Seed the AI Assistant builder interviewer prompt default if not configured.
        require_once(__DIR__ . '/../classes/scenario_builder_ai.php');
        $current = get_config('local_aacuracore', 'ai_builder_prompt_template');
        if (empty($current)) {
            set_config(
                'ai_builder_prompt_template',
                \local_aacuracore\scenario_builder_ai::interviewer_system_prompt(),
                'local_aacuracore'
            );
        }

        upgrade_plugin_savepoint(true, 2026082501, 'local', 'aacuracore');
    }

    if ($oldversion < 2026082502) {
        // Rename the assistant display name from "AURA Tutor" to "AACURA Tutor".
        $geniainame = get_config('local_aacuracore', 'geniainame');
        if ($geniainame === 'AURA Tutor') {
            set_config('geniainame', 'AACURA Tutor', 'local_aacuracore');
        }

        upgrade_plugin_savepoint(true, 2026082502, 'local', 'aacuracore');
    }

    if ($oldversion < 2026082503) {
        // Fix the evaluation prompt template leak: the old default contained the
        // literal instruction "<p>A warm thank-you message with emojis</p>" which the
        // LLM copied verbatim into the feedback. Migrate any stored value that still
        // contains this placeholder prose to the corrected default (unless the admin
        // has meaningfully customized the template beyond that one line).
        require_once(__DIR__ . '/../classes/prompt_renderer.php');
        $evaltemplate = get_config('local_aacuracore', 'evaluation_prompt_template');
        $leaked = 'A warm thank-you message with emojis';
        if (!empty($evaltemplate) && strpos($evaltemplate, $leaked) !== false) {
            set_config(
                'evaluation_prompt_template',
                \local_aacuracore\prompt_renderer::DEFAULT_EVALUATION_TEMPLATE,
                'local_aacuracore'
            );
        }

        upgrade_plugin_savepoint(true, 2026082503, 'local', 'aacuracore');
    }

    if ($oldversion < 2026082504) {
        // Persistent log of full minimum-turn simulation runs so admins can
        // review the last N-turn test results for each persona.
        $table = new xmldb_table('local_aacuracore_sim_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('minturns', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '8');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('detail', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('created', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082504, 'local', 'aacuracore');
    }

    if ($oldversion < 2026092400) {
        // Migrate legacy 'regex' engine strategy left behind by old diagnostics renderer bug.
        $strategy = get_config('local_aacuracore', 'engine_strategy');
        if ($strategy === 'regex') {
            set_config('engine_strategy', 'moodle_core_ai', 'local_aacuracore');
        }

        // Migrate stored prompt template if it contains the legacy repeating constraint.
        require_once(__DIR__ . '/../classes/prompt_renderer.php');
        $storedprompt = get_config('local_aacuracore', 'prompt_template');
        if (!empty($storedprompt) && strpos($storedprompt, 'On this turn, you must convey the following core concern: "{{stateprompt}}"') !== false) {
            set_config('prompt_template', \local_aacuracore\prompt_renderer::DEFAULT_TEMPLATE, 'local_aacuracore');
        }

        upgrade_plugin_savepoint(true, 2026092400, 'local', 'aacuracore');
    }

    return true;
}
