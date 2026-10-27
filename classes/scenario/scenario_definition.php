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

namespace local_aacuracore\scenario;

defined('MOODLE_INTERNAL') || die;

/**
 * Class representing a structured roleplay scenario configuration.
 *
 * @package   local_aacuracore
 * @copyright 2026 Antigravity
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scenario_definition {
    /** @var string Unique identifier for this scenario */
    private $id;

    /** @var array Persona metadata (name, backstory, communication_style) */
    private $persona;

    /** @var array List of pedagogical learning objectives */
    private $learningobjectives;

    /** @var array Map of state details */
    private $states;

    /** @var string|null Optional LLM prompt template with {{placeholder}} tokens */
    private $prompttemplate;

    /** @var array|null Optional role metadata (type, display_label, formality, etc.) */
    private $role;

    /** @var int|null Optional minimum student turn count (N) before grading. */
    private $minturns;

    /** @var string|null Optional parent intensity/assertiveness level. */
    private $parentintensity;

    /** @var array|null Optional topic metadata (code, title) */
    private $topic;

    /**
     * Constructor.
     *
     * @param string $id
     * @param array $persona
     * @param array $learningobjectives
     * @param array $states
     * @param string|null $prompttemplate Optional LLM prompt template
     * @param array|null $role Optional role metadata
     * @param int|null $minturns Optional minimum student turn count before grading
     * @param string|null $parentintensity Optional parent assertiveness level
     * @param array|null $topic Optional topic metadata (code, title)
     */
    public function __construct(string $id, array $persona, array $learningobjectives, array $states,
            ?string $prompttemplate = null, ?array $role = null, ?int $minturns = null, ?string $parentintensity = null,
            ?array $topic = null) {
        $this->id = $id;
        $this->persona = $persona;
        $this->learningobjectives = $learningobjectives;
        $this->states = $states;
        $this->prompttemplate = $prompttemplate;
        $this->role = $role;
        $this->minturns = $minturns;
        $this->parentintensity = $parentintensity;
        $this->topic = $topic;
    }

    /**
     * Gets the unique identifier for this scenario.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Gets the persona configuration metadata.
     *
     * @return array
     */
    public function get_persona(): array {
        return $this->persona;
    }

    /**
     * Gets the optional role metadata for this scenario.
     *
     * @return array|null
     */
    public function get_role(): ?array {
        return $this->role;
    }

    /**
     * Gets the pedagogical learning objectives list.
     *
     * @return array
     */
    public function get_learning_objectives(): array {
        return $this->learningobjectives;
    }

    /**
     * Gets all states configured.
     *
     * @return array
     */
    public function get_states(): array {
        return $this->states;
    }

    /**
     * Gets details for a specific state node.
     *
     * @param string $statekey
     * @return array|null
     */
    public function get_state_node(string $statekey): ?array {
        return $this->states[$statekey] ?? null;
    }

    /**
     * Alias for get_state_node to support state lookups.
     *
     * @param string $statekey
     * @return array|null
     */
    public function get_state(string $statekey): ?array {
        return $this->get_state_node($statekey);
    }

    /**
     * Gets the optional LLM prompt template with {{placeholder}} tokens.
     *
     * @return string|null
     */
    public function get_prompt_template(): ?string {
        return $this->prompttemplate;
    }

    /**
     * Gets the optional minimum student turn count (N) before grading.
     *
     * @return int|null
     */
    public function get_min_turns(): ?int {
        return $this->minturns;
    }

    /**
     * Gets the optional parent intensity/assertiveness level.
     *
     * @return string|null
     */
    public function get_parent_intensity(): ?string {
        return $this->parentintensity;
    }

    /**
     * Gets the optional topic metadata array (code, title).
     *
     * @return array|null
     */
    public function get_topic(): ?array {
        return $this->topic;
    }

    /**
     * Gets the topic code for this scenario, defaulting to 'parent_counseling'.
     *
     * @return string
     */
    public function get_topic_code(): string {
        if (is_array($this->topic) && !empty($this->topic['code'])) {
            return (string)$this->topic['code'];
        }
        return 'parent_counseling';
    }
}
