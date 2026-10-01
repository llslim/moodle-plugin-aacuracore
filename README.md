# AACURA Backend Dialogue State Machine & Evaluation Engine (`local_aacuracore`)

Welcome to the core backend engine repository for **AACURA** (AAC Understanding & Reflective Assistant). This plugin acts as the central state machine coordinator, dialogue engine, and rubric evaluator for the chatbot training simulator in Moodle.

---

## 🚀 Architectural Design

The plugin is designed to decouple presentation from dialogue logic, utilizing classic software engineering patterns in PHP to remain highly adaptable.

### 1. State Pattern (Dialogue Management)
Instead of relying on nested conditionals or hardcoded database sequences, a formal State Pattern coordinates the student through dialogue phases. Concrete state classes (e.g. `StateIntro`, `StateExploration`, `StateEscalation`, `StateComplete`) encapsulate specific transition validations, keeping states modularized and extensible.

### 2. Strategy Pattern (Response Evaluation & Generation)
Response generation and rubric scoring logic are decoupled behind a common Strategy interface, permitting runtime strategy toggles:
* **Pattern Matching Strategy**: Evaluates criteria using regex and token overlaps with multi-turn intent detection and non-repeating candidate pool memory.
* **Generative AI Strategy**: Executes direct API integration with Google Gemini and OpenAI ChatGPT.
* **Core AI Subsystem Strategy**: Integrates with Moodle 5.x native `\core_ai\manager` APIs.

### 3. Frontend Decoupling & Independence (Disclaimer)
While `local_aacuracore` was historically derived from the legacy `local_geniai` project, it has been completely rewritten and restructured. **There is no dependency** on the legacy `local_geniai` codebase, configuration, or database tables. The core backend communicates with its companion user interface activity module `mod_aacurachat` via clean state data objects and Moodle APIs, allowing separate scaling, updates, and styling.

---

## 🌟 Key Features (Version 2.x)

### 1. 🛠️ Interactive Scenario Builder & Site-Wide Registry
* **Web UI Builder (`/local/aacuracore/scenario_builder.php`)**: Visual directed-graph scenario builder to design personas, define state nodes (START, EXPLORATION, ESCALATION, CONFUSION, RESOLUTION, FAIL_STATE), attach per-state rubrics, and export valid `.json` files.
* **Site-Wide Persona Registry**: Upload and register custom `.json` scenarios directly into the database (`local_aacuracore_custom_scenarios`) for immediate site-wide availability across all activities.
* **AI-Driven Conversational Interviewer**: Instructors can switch the chatbot into an interviewer role to build scenarios conversationally, with clear in-chat explanations of how each detail is used to build the scenario JSON.

### 2. 🧠 Multi-Turn Intent Detection & Anti-Repetition Memory Filter
* **Deterministic Offline Resilience (`regex_matcher_strategy`)**: Evaluates trainee input across prioritized intent categories (off-topic guardrails, note-taking permission, past AAC tools/PECS, family dynamics, classroom routines, and action planning).
* **Zero Repetition Guarantee**: Harvests prior conversation trajectory and filters candidate pools dynamically, ensuring the simulated persona never repeats a previously spoken response.
* **Persona & Pronoun Interpolation**: Automatically inflects grammatical pronouns and interpolates child names harvested directly from scenario backstories.

### 3. 🎯 Minimum Turn Semantics (`min_turns`)
* Replaces legacy maximum turns with **minimum turn count** semantics: the conversation must run for at least $N$ student turns (default 8) before final rubric evaluation and grading.
* If a terminal state (RESOLUTION or FAIL_STATE) is reached early, the dialogue cycles gracefully back into EXPLORATION so the persona continues engaging until the minimum turn threshold is satisfied.
* Fully configurable site-wide, in scenario JSON (`"min_turns": 8`), and as an activity-level override in `mod_aacurachat`.

### 4. 🎚️ Simulated Parent Assertiveness / Intensity Modulation
* Activity authors can dial simulated parent assertiveness across a 5-point scale: **Very Low** (passive, gentle), **Low**, **Medium** (default), **High** (firm, assertive), and **Very High** (confrontational).
* Injects behavioral constraints dynamically into the persona system prompt or modulates regex response candidate selection.

### 5. 🌐 Universal LAFF "Don't Cry" Foundation & Role Expansion
* Anchored in the **LAFF "Don't Cry"** pedagogical framework (**L**isten/Validate, **A**sk open questions, **F**ocus on practical issues, **F**ind first steps; never **C**riticize, **R**eact defensively, or **Y**ack jargon).
* Generalizes personas beyond parents to simulate any stakeholder: doctors, device manufacturers, AAC users, school administrators, IEP teams, and insurance representatives.

### 6. 📊 Per-State Rubric Scoring & Gradebook Integration
* Rubric scoring criteria are defined on individual state nodes rather than a monolithic static rubric.
* Trainee responses are evaluated against specific state learning objectives and computed scores are synced directly to the Moodle Gradebook.

### 7. 🧪 Comprehensive Diagnostics & Crawler Verification
* **Admin Diagnostics Tab**: Real-time view of connected AI providers, system prompt previews, simulation logs, and active turn configurations.
* **CLI Scenario Crawler (`aacuradebug_scenario.php`)**: Analyzes all conversation branches, loops, and terminal states, validating state graph integrity.

---

## 📝 Scenario Configurations & Example Graph Structure

Conversations in AACURA are driven by structured JSON scenarios defined as directed graphs. Each node represents a conversation state containing prompt templates, expected criteria, an optional per-state `rubric` array of scoring criteria, and conditional transition links.

### Example Scenario Schema Snippet:
```json
{
  "code": "parent_exploration",
  "name": "LAFF Strategy: parent exploration phase",
  "start_node": "intro_greeting",
  "nodes": {
    "intro_greeting": {
      "bot_prompt": "Hello, thank you for meeting with me to discuss my child's communication device...",
      "expected_strategy": "pattern_matching",
      "criteria": {
        "empathy": ["glad", "happy", "understand", "here to help"],
        "jargon_avoidance": ["!SLP", "!AAC", "!assistive tech"]
      },
      "transitions": {
        "success": "gather_information",
        "fallback": "intro_greeting_retry"
      }
    }
  }
}
```
*For a complete guide on how to design and upload scenarios, see [scenario_creation_guide.md](scenario_creation_guide.md).*

---

## 📥 Installation Guide

Follow these steps to deploy the plugin into your Moodle environment:

### Option A: Installation via Composer (Recommended)
Add the repository to your Moodle project's root `composer.json` and require it:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/llslim/moodle-plugin-aacuracore.git"
    }
],
"require": {
    "llslim/moodle-plugin-aacuracore": "dev-dev"
}
```

Then run:
```bash
composer update
```

### Option B: Manual Installation
1. Download the latest release or ZIP archive from [llslim/moodle-plugin-aacuracore](https://github.com/llslim/moodle-plugin-aacuracore).
2. Install via Moodle Administration:
   ```
   Site Administration → Plugins → Install Plugins → Install plugin from ZIP file
   ```
   Or clone directly into Moodle's `local/` directory:
   ```bash
   git clone https://github.com/llslim/moodle-plugin-aacuracore.git local/aacuracore
   ```

### ⚙️ Post-Installation Setup
Once installed, configure the AI Providers and plugin settings. See [siteadmin_aacura_setup.md](file:///d:/Antigravity1x_backup/windows-projects/AAC-RERC%20Chatbot/siteadmin_aacura_setup.md) for full instructions.

---

## 📂 Core Documentation & Specification Map

Refer to these dedicated guides to understand and manage specific components:

| Documentation Link | Description / Scope |
| :--- | :--- |
| ⚙️ **[siteadmin_aacura_setup.md](siteadmin_aacura_setup.md)** | Step-by-step Moodle site setup guide for AI providers and AACURA settings. |
| 🤖 **[ai_strategy.md](ai_strategy.md)** | Explains responses, Google Gemini REST compliance, and rubric scoring. |
| 💬 **[laff_framework_guide.md](laff_framework_guide.md)** | Overview of the LAFF Don't Cry communication strategy and evaluation checks. |
| 🌐 **[role_expansion_PRD.md](role_expansion_PRD.md)** | Specification for multi-role simulation anchored in universal LAFF rules. |
| 🎭 **[conversational_roleplay_PRD.md](conversational_roleplay_PRD.md)** | PRD for dynamic non-repeating conversational roleplay and turn minimums. |
| 📝 **[prompt_template_PRD.md](prompt_template_PRD.md)** | PRD for customizable global persona and evaluation prompt templates. |
| 🛠️ **[ai_scenario_builder_PRD.md](ai_scenario_builder_PRD.md)** | PRD for the AI-driven interactive scenario builder (interviewer-mode creation). |
| 💯 **[scoring_explained.md](scoring_explained.md)** | Explains how final grading scores are calculated, deducted, and synchronized with Gradebook. |
| 📖 **[scenario_creation_guide.md](scenario_creation_guide.md)** | Guidelines on preloaded scenario configurations and custom JSON schema. |
| 🧪 **[test_suite_guide.md](test_suite_guide.md)** | Guide for executing PHPUnit test suites and QA validation rules. |
| 🏷️ **[version_history.md](version_history.md)** | Release notes mapping commit hashes to semantic release versions. |
| 🛠️ **[REFACTORING_GUIDE.md](REFACTORING_GUIDE.md)** | Rationale and step-by-step notes on the renaming refactoring. |

---

## 🔄 Automated Database Migration for Existing Sites

### 1. Upgrading from Legacy `local_geniai` / `mod_geniai`
For sites upgrading from legacy installations, run the migration utility to rename tables and update configurations:

```bash
php local/aacuracore/cli/migrate_geniai_to_aacura.php
```

### 2. Upgrading Turn Count Schema (`max_turns` → `min_turns`)
For existing installations upgrading to version 2.x, run the turn schema migration utility to update `aacurachat` table columns and module versions:

```bash
php local/aacuracore/cli/migrate_turns_schema.php
```

---

## 🛠️ Developer CLI Diagnostics Cheatsheet

Use the scenario crawler diagnostic script to verify state graphs and simulate conversation sessions:

```bash
php local/aacuracore/cli/aacuradebug_scenario.php [options]
```

### Parameter Guide
* **`--scenario <code_name>`**: Runs the diagnostic graph crawler only on a specific scenario (e.g. `--scenario parent_exploration`).
* **`--enable-debug`**: Enables developer debugging (`debug=developer`, `debugdisplay=1`) on the Moodle site.
* **`--disable-debug`**: Disables developer debugging, reverting Moodle back to its production settings.
* **`--check-configs`**: Tests active LLM provider API credentials and prints system availability diagnostics.
* **`--phpunit`**: Automatically executes Moodle unit tests for scenarios via the `local_aacuracore\scenarios_test` class.
* **`--all`**: Runs the complete diagnostic suite (crawl all scenarios, check configurations, and execute PHPUnit validations).
