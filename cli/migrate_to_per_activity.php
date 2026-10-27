<?php
// This file is part of AACURA Core Engine for Moodle - http://moodle.org/
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
 * CLI Migration Utility for AACURA v3.0 (Per-Activity Configuration & Topic/Scenario Library).
 *
 * Idempotently verifies and applies:
 * 1. Creation of local_aacuracore_topics and local_aacuracore_scenarios tables.
 * 2. Addition of composite index userid_cmid_scenariocode_idx on local_aacuracore_sessions.
 * 3. Addition of v3.0 per-activity columns on mdl_aacurachat.
 * 4. Seeding of starter Topics and built-in roleplay Scenarios.
 * 5. Migration of legacy local_aacuracore_custom_scenarios rows into local_aacuracore_scenarios.
 * 6. Backfilling of cmid on historical local_aacuracore_sessions and scenariolist on mdl_aacurachat.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC Chatbot Team
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/library/topic_library_manager.php');

cli_heading('AACURA v3.0 Migration: Per-Activity Configuration & Topic/Scenario Library');

global $DB, $CFG;
$dbman = $DB->get_manager();

// 1. Ensure local_aacuracore_topics exists.
$topicstable = new xmldb_table('local_aacuracore_topics');
if (!$dbman->table_exists($topicstable)) {
    cli_writeln("Creating table 'local_aacuracore_topics'...");
    $topicstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $topicstable->add_field('topiccode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
    $topicstable->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $topicstable->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $topicstable->add_field('default_role_type', XMLDB_TYPE_CHAR, '50', null, null, null, 'parent');
    $topicstable->add_field('default_min_turns', XMLDB_TYPE_INTEGER, '6', null, null, null, '8');
    $topicstable->add_field('default_intensity', XMLDB_TYPE_CHAR, '20', null, null, null, 'medium');
    $topicstable->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'system');
    $topicstable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $topicstable->add_field('visible', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
    $topicstable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $topicstable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $topicstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $topicstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $topicstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $topicstable->add_index('topiccode_courseid_uq', XMLDB_INDEX_UNIQUE, ['topiccode', 'courseid']);
    $topicstable->add_index('scope_courseid_visible_idx', XMLDB_INDEX_NOTUNIQUE, ['scope', 'courseid', 'visible']);
    $dbman->create_table($topicstable);
    cli_writeln("Created table 'local_aacuracore_topics'.");
} else {
    cli_writeln("Table 'local_aacuracore_topics' already exists.");
}

// 2. Ensure local_aacuracore_scenarios exists.
$scenariostable = new xmldb_table('local_aacuracore_scenarios');
if (!$dbman->table_exists($scenariostable)) {
    cli_writeln("Creating table 'local_aacuracore_scenarios'...");
    $scenariostable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $scenariostable->add_field('scenariocode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
    $scenariostable->add_field('topicid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('topiccode', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'parent_counseling');
    $scenariostable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $scenariostable->add_field('role_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'parent');
    $scenariostable->add_field('role_label', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'Parent Persona');
    $scenariostable->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $scenariostable->add_field('json_data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
    $scenariostable->add_field('is_builtin', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('scope', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'system');
    $scenariostable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('visible', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
    $scenariostable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $scenariostable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $scenariostable->add_key('topic_fk', XMLDB_KEY_FOREIGN, ['topicid'], 'local_aacuracore_topics', ['id']);
    $scenariostable->add_index('scenariocode_course_cm_uq', XMLDB_INDEX_UNIQUE, ['scenariocode', 'courseid', 'cmid']);
    $scenariostable->add_index('topiccode_visible_idx', XMLDB_INDEX_NOTUNIQUE, ['topiccode', 'visible']);
    $dbman->create_table($scenariostable);
    cli_writeln("Created table 'local_aacuracore_scenarios'.");
} else {
    cli_writeln("Table 'local_aacuracore_scenarios' already exists.");
}

// 3. Ensure composite index on local_aacuracore_sessions.
$sessionstable = new xmldb_table('local_aacuracore_sessions');
if ($dbman->table_exists($sessionstable)) {
    $sessionidx = new xmldb_index('userid_cmid_scenariocode_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'cmid', 'scenariocode']);
    if (!$dbman->index_exists($sessionstable, $sessionidx)) {
        cli_writeln("Adding composite index 'userid_cmid_scenariocode_idx' on 'local_aacuracore_sessions'...");
        $dbman->add_index($sessionstable, $sessionidx);
    } else {
        cli_writeln("Index 'userid_cmid_scenariocode_idx' already exists on 'local_aacuracore_sessions'.");
    }
}

// 4. Ensure new columns on mdl_aacurachat.
$chattable = new xmldb_table('aacurachat');
if ($dbman->table_exists($chattable)) {
    $fields = [
        new xmldb_field('topiccode', XMLDB_TYPE_CHAR, '100', null, null, null, 'parent_counseling', 'introformat'),
        new xmldb_field('scenariolist', XMLDB_TYPE_TEXT, null, null, null, null, null, 'topiccode'),
        new xmldb_field('allow_persona_switch', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'scenariocode'),
        new xmldb_field('grading_mode', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'single_highest', 'allow_persona_switch'),
        new xmldb_field('generation_case', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'parent_intensity'),
        new xmldb_field('custom_prompt_template', XMLDB_TYPE_TEXT, null, null, null, null, null, 'generation_case'),
        new xmldb_field('custom_eval_template', XMLDB_TYPE_TEXT, null, null, null, null, null, 'custom_prompt_template'),
    ];
    foreach ($fields as $field) {
        if (!$dbman->field_exists($chattable, $field)) {
            cli_writeln("Adding column '{$field->getName()}' to 'aacurachat'...");
            $dbman->add_field($chattable, $field);
        }
    }
}

// 5. Seed starter topics & scenarios and migrate custom scenarios.
cli_writeln("Seeding default Topics and Scenarios & migrating legacy custom scenarios...");
\local_aacuracore\library\topic_library_manager::seed_defaults();
$topiccount = $DB->count_records('local_aacuracore_topics');
$scenariocount = $DB->count_records('local_aacuracore_scenarios');
cli_writeln("Library active: {$topiccount} topic(s), {$scenariocount} scenario(s).");

// 6. Backfill historical session cmids and activity scenariolists.
$backfilledsessions = \local_aacuracore\library\topic_library_manager::backfill_session_cmids();
$backfilledactivities = \local_aacuracore\library\topic_library_manager::backfill_activity_scenariolists();
cli_writeln("Backfilled cmid on {$backfilledsessions} session(s) and scenariolist on {$backfilledactivities} activity instance(s).");

cli_writeln("AACURA v3.0 per-activity migration complete!");
