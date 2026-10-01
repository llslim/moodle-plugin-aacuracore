<?php
// This file is part of AACURA Core Engine for Moodle - http://moodle.org/
//
// CLI Migration Script: Migrates max_turns column to min_turns in aacurachat table.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('AACURA Schema Migration: max_turns -> min_turns');

global $DB, $CFG;

$dbman = $DB->get_manager();

$table = new xmldb_table('aacurachat');

if (!$dbman->table_exists($table)) {
    cli_writeln("Table 'aacurachat' does not exist yet.");
    exit(0);
}

// Rename max_turns to min_turns if max_turns exists
if ($dbman->field_exists($table, new xmldb_field('max_turns'))) {
    cli_writeln("Field 'max_turns' found in 'aacurachat'. Renaming to 'min_turns'...");
    $field = new xmldb_field('max_turns', XMLDB_TYPE_INTEGER, '6', null, null, null, '8', 'scenariocode');
    $dbman->rename_field($table, $field, 'min_turns');
    cli_writeln("Renamed 'max_turns' -> 'min_turns' successfully.");
} else if ($dbman->field_exists($table, new xmldb_field('min_turns'))) {
    cli_writeln("Field 'min_turns' already exists in 'aacurachat'.");
} else {
    cli_writeln("Adding 'min_turns' column to 'aacurachat'...");
    $field = new xmldb_field('min_turns', XMLDB_TYPE_INTEGER, '6', null, null, null, '8', 'scenariocode');
    $dbman->add_field($table, $field);
    cli_writeln("Added 'min_turns' column.");
}

try {
    if (file_exists($CFG->dirroot . '/mod/aacurachat/version.php')) {
        $plugin = new stdClass();
        include($CFG->dirroot . '/mod/aacurachat/version.php');
        if (isset($plugin->version)) {
            $mod = $DB->get_record('modules', ['name' => 'aacurachat']);
            if ($mod) {
                $DB->set_field('modules', 'version', $plugin->version, ['id' => $mod->id]);
                cli_writeln("Updated mod_aacurachat module version in DB to {$plugin->version}.");
            }
        }
    }

    if (file_exists($CFG->dirroot . '/local/aacuracore/version.php')) {
        $plugin = new stdClass();
        include($CFG->dirroot . '/local/aacuracore/version.php');
        if (isset($plugin->version)) {
            set_config('version', $plugin->version, 'local_aacuracore');
            cli_writeln("Updated local_aacuracore version in DB to {$plugin->version}.");
        }
    }
} catch (\Throwable $e) {
    cli_writeln("Notice: Version sync encountered: " . $e->getMessage());
}

cli_writeln("Migration complete!");
