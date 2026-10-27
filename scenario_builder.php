<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Topic & Scenario Library Manager and Custom Scenario Builder.
 *
 * Supports System-wide and Course-scoped Topics & Scenarios, single-topic
 * scenario assignment, JSON upload/direct registration, and Site Administrator
 * promotion of course-scoped scenarios/topics to the System Library.
 *
 * @package   local_aacuracore
 * @copyright 2026 AAC-RERC
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");

use local_aacuracore\library\topic_library_manager;
use local_aacuracore\scenario\scenario_loader;

require_login();

global $DB, $USER, $OUTPUT, $PAGE;

$courseid = optional_param('courseid', 0, PARAM_INT);
$syscontext = context_system::instance();
$issiteadmin = has_capability('moodle/site:config', $syscontext);

if ($courseid > 0) {
    $coursecontext = context_course::instance($courseid);
    $canmanage = has_capability('local/aacuracore:manage', $coursecontext)
        || has_capability('mod/aacurachat:addinstance', $coursecontext)
        || has_capability('moodle/course:manageactivities', $coursecontext)
        || $issiteadmin;
    if (!$canmanage) {
        require_capability('local/aacuracore:manage', $coursecontext);
    }
    $PAGE->set_context($coursecontext);
} else {
    require_capability('local/aacuracore:manage', $syscontext);
    $PAGE->set_context($syscontext);
}

topic_library_manager::ensure_seeded();

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$message = '';
$messagetype = 'info';

// 1. Handle Create / Update Topic Action.
if ($action === 'create_topic' && confirm_sesskey()) {
    $topiccode = clean_param(optional_param('topiccode', '', PARAM_ALPHANUMEXT), PARAM_ALPHANUMEXT);
    $topicname = clean_param(optional_param('topicname', '', PARAM_TEXT), PARAM_TEXT);
    $topicdesc = clean_param(optional_param('topicdescription', '', PARAM_TEXT), PARAM_TEXT);
    $defaultturns = optional_param('default_min_turns', 8, PARAM_INT);
    $defaultintensity = clean_param(optional_param('default_intensity', 'medium', PARAM_ALPHA), PARAM_ALPHA);
    $requestedscope = optional_param('scope', '', PARAM_ALPHA);

    if ($topiccode !== '' && $topicname !== '') {
        $scope = ($courseid > 0) ? 'course' : 'system';
        if ($issiteadmin && $requestedscope === 'system') {
            $scope = 'system';
        }
        $targetcourseid = ($scope === 'course') ? $courseid : 0;

        topic_library_manager::save_topic([
            'topiccode' => strtolower($topiccode),
            'name' => $topicname,
            'description' => $topicdesc,
            'default_min_turns' => max(1, $defaultturns),
            'default_intensity' => $defaultintensity ?: 'medium',
            'scope' => $scope,
            'courseid' => $targetcourseid,
            'userid' => (int)$USER->id,
        ]);
        $message = "Topic '{$topicname}' ({$topiccode}) saved to " . ucfirst($scope) . " Library!";
        $messagetype = 'success';
    } else {
        $message = 'Error: Topic code and name are required.';
        $messagetype = 'error';
    }
}

// 2. Handle Promote Topic to System Scope (Site Admin only).
if ($action === 'promote_topic' && confirm_sesskey()) {
    require_capability('moodle/site:config', $syscontext);
    $promotetopic = required_param('topiccode', PARAM_ALPHANUMEXT);
    $fromcourse = optional_param('fromcourseid', 0, PARAM_INT);
    if (topic_library_manager::promote_topic_to_system($promotetopic, $fromcourse)) {
        $message = "Topic '{$promotetopic}' promoted to System Library (available across all courses).";
        $messagetype = 'success';
    } else {
        $message = "Error: Could not promote topic '{$promotetopic}'.";
        $messagetype = 'error';
    }
}

// 3. Handle JSON File Upload OR Direct Form Registration Action.
if (($action === 'upload' || $action === 'save_form_scenario') && confirm_sesskey()) {
    $jsoncontent = '';
    if ($action === 'upload' && !empty($_FILES['jsonfile']['tmp_name'])) {
        $jsoncontent = file_get_contents($_FILES['jsonfile']['tmp_name']);
    } else if ($action === 'save_form_scenario') {
        $jsoncontent = optional_param('scenario_json', '', PARAM_RAW);
    }

    $data = json_decode($jsoncontent, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data) && !empty($data['scenario_id']) && !empty($data['persona']['name'])) {
        try {
            $def = scenario_loader::from_array($data);
            $scenariocode = $def->get_scenario_id();
            $name = $def->get_persona_name();
            $description = $def->get_backstory();
            $role = $def->get_role();

            $selectedtopic = optional_param('topiccode', '', PARAM_ALPHANUMEXT);
            if ($selectedtopic === '' && !empty($data['topic'])) {
                $selectedtopic = clean_param((string)$data['topic'], PARAM_ALPHANUMEXT);
            }
            if ($selectedtopic === '') {
                $selectedtopic = 'parent_counseling';
            }
            $data['topic'] = $selectedtopic;
            $normalizedjson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $requestedscope = optional_param('scope', '', PARAM_ALPHA);
            $scope = ($courseid > 0) ? 'course' : 'system';
            if ($issiteadmin && $requestedscope === 'system') {
                $scope = 'system';
            } else if (!$issiteadmin && $courseid > 0) {
                $scope = 'course';
            }
            $targetcourseid = ($scope === 'course') ? $courseid : 0;

            topic_library_manager::save_scenario([
                'scenariocode' => $scenariocode,
                'topiccode' => $selectedtopic,
                'name' => $name,
                'role_type' => $role['type'] ?? 'parent',
                'role_label' => $role['display_label'] ?? 'Parent',
                'description' => $description,
                'json_data' => $normalizedjson,
                'scope' => $scope,
                'courseid' => $targetcourseid,
                'is_builtin' => 0,
                'enabled' => 1,
                'userid' => (int)$USER->id,
            ]);

            // Also keep legacy custom_scenarios table synced for system-scoped scenarios.
            if ($scope === 'system' && $DB->get_manager()->table_exists('local_aacuracore_custom_scenarios')) {
                $legacy = $DB->get_record('local_aacuracore_custom_scenarios', ['scenariocode' => $scenariocode]);
                if ($legacy) {
                    $legacy->name = $name;
                    $legacy->description = $description;
                    $legacy->json_data = $normalizedjson;
                    $legacy->userid = $USER->id;
                    $legacy->timemodified = time();
                    $DB->update_record('local_aacuracore_custom_scenarios', $legacy);
                } else {
                    $legacy = new \stdClass();
                    $legacy->scenariocode = $scenariocode;
                    $legacy->name = $name;
                    $legacy->description = $description;
                    $legacy->json_data = $normalizedjson;
                    $legacy->userid = $USER->id;
                    $legacy->timecreated = time();
                    $legacy->timemodified = time();
                    $DB->insert_record('local_aacuracore_custom_scenarios', $legacy);
                }
            }

            $message = "Scenario '{$name}' ({$scenariocode}) registered in Topic '{$selectedtopic}' (" . ucfirst($scope) . " Library)!";
            $messagetype = 'success';
        } catch (\Throwable $e) {
            $message = 'Scenario validation error: ' . $e->getMessage();
            $messagetype = 'error';
        }
    } else {
        $message = 'Error: The provided JSON is not a valid AACURA Scenario profile.';
        $messagetype = 'error';
    }
}

// 4. Handle Promote Scenario to System Scope (Site Admin only).
if ($action === 'promote_scenario' && confirm_sesskey()) {
    require_capability('moodle/site:config', $syscontext);
    $promotecode = required_param('scenariocode', PARAM_ALPHANUMEXT);
    $fromcourse = optional_param('fromcourseid', 0, PARAM_INT);
    if (topic_library_manager::promote_to_system_scope($promotecode, $fromcourse)) {
        $message = "Scenario '{$promotecode}' promoted to the System Library (available across all courses).";
        $messagetype = 'success';
    } else {
        $message = "Error: Could not promote scenario '{$promotecode}'.";
        $messagetype = 'error';
    }
}

// 5. Handle Scenario Deletion Action.
if ($action === 'delete' && confirm_sesskey()) {
    $deletecode = required_param('scenariocode', PARAM_ALPHANUMEXT);
    $delcourseid = optional_param('fromcourseid', $courseid, PARAM_INT);

    $deleted = false;
    if ($DB->get_manager()->table_exists('local_aacuracore_scenarios')) {
        $records = $DB->get_records('local_aacuracore_scenarios', ['scenariocode' => $deletecode]);
        foreach ($records as $rec) {
            if ((int)$rec->is_builtin === 1) {
                continue;
            }
            if ($issiteadmin || ((int)$rec->courseid === $courseid && $courseid > 0)) {
                $DB->delete_records('local_aacuracore_scenarios', ['id' => $rec->id]);
                $deleted = true;
            }
        }
    }
    if ($DB->get_manager()->table_exists('local_aacuracore_custom_scenarios')) {
        $legacy = $DB->get_record('local_aacuracore_custom_scenarios', ['scenariocode' => $deletecode]);
        if ($legacy) {
            $DB->delete_records('local_aacuracore_custom_scenarios', ['id' => $legacy->id]);
            $deleted = true;
        }
    }

    if ($deleted) {
        $message = "Custom scenario profile '{$deletecode}' was deleted successfully.";
        $messagetype = 'success';
    } else {
        $message = "Error: Custom scenario '{$deletecode}' not found or is a protected built-in scenario.";
        $messagetype = 'error';
    }
}

// Fetch Topics & Scenarios for display.
if ($issiteadmin && $courseid === 0 && $DB->get_manager()->table_exists('local_aacuracore_topics')) {
    $topics = $DB->get_records('local_aacuracore_topics', null, 'scope DESC, sortorder ASC, name ASC');
    $libraryscenarios = $DB->get_records('local_aacuracore_scenarios', null, 'scope ASC, topiccode ASC, name ASC');
} else {
    $topics = topic_library_manager::get_topics($courseid);
    $libraryscenarios = topic_library_manager::get_scenarios_for_topic(null, $courseid, false);
}

$customscenarios = $DB->get_records('local_aacuracore_custom_scenarios', null, 'name ASC');

$pageparams = $courseid > 0 ? ['courseid' => $courseid] : [];
$PAGE->set_url('/local/aacuracore/scenario_builder.php', $pageparams);
$PAGE->set_title(get_string('modulename', 'local_aacuracore') . ' - Topic & Scenario Library');
$PAGE->set_heading(get_string('modulename', 'local_aacuracore') . ' - Topic & Scenario Library');

echo $OUTPUT->header();

if (!empty($message)) {
    $alertclass = ($messagetype === 'success') ? 'alert-success' : 'alert-danger';
    echo '<div class="alert ' . $alertclass . ' alert-dismissible fade show" role="alert">' . s($message) . '</div>';
}

require_once(__DIR__ . '/scenario_builder.html');
echo $OUTPUT->footer();
