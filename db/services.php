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
 * services file.
 *
 * @package   local_aacuracore
 * @copyright 2024 Eduardo Kraus {@link http://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$functions = [
    "local_aacuracore_chat_3" => [
        "classpath" => "local/aacuracore/classes/external/chat_3.php",
        "classname" => "\\local_aacuracore\\external\\chat_3",
        "methodname" => "api",
        "description" => "ChatGPT API",
        "type" => "write",
        "ajax" => true,
    ],
    "local_aacuracore_history_3" => [
        "classpath" => "local/aacuracore/classes/external/history_3.php",
        "classname" => "\\local_aacuracore\\external\\history_3",
        "methodname" => "api",
        "description" => "Brings the conversation history",
        "type" => "write",
        "ajax" => true,
    ],

    "local_aacuracore_chat_4" => [
        "classpath" => "local/aacuracore/classes/external/chat_4.php",
        "classname" => "\\local_aacuracore\\external\\chat_4",
        "methodname" => "api",
        "description" => "ChatGPT API",
        "type" => "write",
        "ajax" => true,
    ],
    "local_aacuracore_history_4" => [
        "classpath" => "local/aacuracore/classes/external/history_4.php",
        "classname" => "\\local_aacuracore\\external\\history_4",
        "methodname" => "api",
        "description" => "Brings the conversation history",
        "type" => "write",
        "ajax" => true,
    ],
    "local_aacuracore_activity_library" => [
        "classpath" => "local/aacuracore/classes/external/activity_library.php",
        "classname" => "\\local_aacuracore\\external\\activity_library",
        "methodname" => "api",
        "description" => "Per-activity Topic and Scenario Library configuration and promotion API",
        "type" => "write",
        "ajax" => true,
    ],
];
