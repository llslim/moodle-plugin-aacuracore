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
 * lib file.
 *
 * @package   local_aacuracore
 * @copyright 2024 Eduardo Kraus {@link http://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_aacuracore\core_hook_output;

/**
 * Hook before footer rendering.
 *
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_aacuracore_before_footer() {
    core_hook_output::before_footer_html_generation();
}

/**
 * Hook to extend the Moodle signup form with a secret registration key.
 *
 * @param \MoodleQuickForm $mform
 */
function local_aacuracore_extend_signup_form($mform) {
    $expected_key = get_config('local_aacuracore', 'registration_secret_key');
    if (!empty($expected_key)) {
        $mform->addElement('header', 'registration_key_header', get_string('registration_key_header', 'local_aacuracore'));
        $mform->addElement('text', 'registration_key', get_string('registration_key', 'local_aacuracore'), ['size' => 30]);
        $mform->setType('registration_key', PARAM_RAW_TRIMMED);
        $mform->addRule('registration_key', get_string('registration_key_required', 'local_aacuracore'), 'required', null, 'client');
        $mform->addHelpButton('registration_key', 'registration_key', 'local_aacuracore');
    }
}

/**
 * Validate the secret registration key submitted on the signup form.
 *
 * @param array $data
 * @return array
 */
function local_aacuracore_validate_extend_signup_form($data) {
    $errors = [];
    $expected_key = get_config('local_aacuracore', 'registration_secret_key');
    if (!empty($expected_key)) {
        if (empty($data['registration_key']) || trim($data['registration_key']) !== trim($expected_key)) {
            $errors['registration_key'] = get_string('registration_key_invalid', 'local_aacuracore');
        }
    }
    return $errors;
}
