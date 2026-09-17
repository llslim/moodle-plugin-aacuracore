# AACURA Engine Version History & Tagging Log

### Current Version: **v1.5.0-dev**

This log documents the commit history on the `dev` branch, mapping each update to its determined semantic version based on the project's git tagging policy.

## Release History (Git Tag ↔ Moodle Version ↔ Release Number)

| Git Tag | Moodle `$plugin->version` | `$plugin->release` | Commit Message / Description |
| :--- | :--- | :--- | :--- |
| *pending* | `2026091700` | `2.4.16` | fix: resolve Issue #13 — eliminate settings regex config overwrite, dynamic roleplay prompt, and non-repeating progressive fallbacks |
| *pending* | `2026081011` | `2.4.11` | feat: editable evaluation prompt + per-activity max_turns/parent_intensity overrides |
| *pending* | `2026081010` | `2.4.10` | feat: add global persona system prompt template setting + diagnostics preview |
| *pending* | `2026081009` | `2.4.9` | feat: add global parent assertiveness/aggressiveness setting (parent_intensity) |
| *pending* | `2026081008` | `2.4.8` | feat: make max student turns configurable (default 8) |
| *pending* | `2026081007` | `2.4.7` | feat: add Moodle Core AI temperature/top_p override via provider action config injection |
| *pending* | `2026081006` | `2.4.6` | feat: move generation parameters (use cases, max_tokens, penalties, voice) to global scope for all AI engines |
| *pending* | `2026081005` | `2.4.5` | feat: add per-state rubric to scenario builder, AI builder, and feedback |
| *pending* | `2026081003` | `2.4.4` | feat: role expansion — generalized bot persona types with universal LAFF foundation |
| *pending* | `2026081003` | `2.4.3` | feat: embed configurable LLM prompt template in scenario JSON |
| `v1.4.0-dev` | `2026081002` | `2.4.2` | feat: add Diagnostics tab to settings page |
| `v1.3.1-dev` | `2026081001` | `2.4.1` | Resolves #2: Save computed scores to evaluations |
| `v1.3.0-dev` | `2026052517` | `2.4.1` | Resolves #1: Fix analytics logging and Gradebook integration |
| `v1.2.4-dev` | `2026052517` | `2.4.1` | fix: correct naming in CLI migration script |

## Commit History (Semantic Version Mapping)

| Commit Hash | Version | Commit Message / Description | Version Type |
| :--- | :--- | :--- | :--- |
| `4d86eb3` | **v1.4.1** | fix: repair accordion behavior and reorder settings page fields (Resolves #3) | Revision (Fix) |
| `2141989` | **v1.4.0** | fix: register mod_aacurachat plugin metadata in gradebook test | Revision (Fix) |
| `3d02642` | **v1.4.0** | fix: make gradebook_test.php self-contained for CI | Revision (Fix) |
| `66034ae` | **v1.4.0** | feat: add Diagnostics tab to settings page showing configs, connected provider, and scenario graphs | **Minor Feature** |
| `7e1f13c` | **v1.3.1** | Resolves #2: Save computed scores to local_aacuracore_evaluations and calculate score in pattern matching strategy | Revision (Fix) |
| `f9cb35b` | **v1.3.0** | Resolves #1: Fix analytics logging and Gradebook integration | **Minor Feature** |
| `eeae02b` | **v1.2.4** | fix: correct naming in CLI migration script and fix CI Behat triggers | Revision (Fix) |
| `72d50da` | **v1.2.3** | docs: rename development branch references to dev | Revision (Patch) |
| `0aeaa66` | **v1.2.2** | docs: add scenario_test.md and link from README.md and gemini.md | Revision (Patch) |
| `70ebead` | **v1.2.1** | refactor: rename test_scenarios.php to aacuradebug_scenario.php | Revision (Patch) |
| `8bd4fd1` | **v1.2.0** | test: implement CLI options for test_scenarios.php (scenario selection, debugging toggles, config checks, phpunit) | **Minor Feature** |
| `c15500a` | **v1.1.2** | fix: change is_success() to native get_success() for core_ai response mapping | Revision (Fix) |
| `87edc9c` | **v1.1.1** | test: add PHPUnit scenarios validation test | Revision (Patch) |
| `979e64e` | **v1.1.0** | test: add CLI test script to crawl and verify all scenario conversation routes | **Minor Feature** |
| `2116d28` | **v1.0.8** | refactor: replace custom file log with native Moodle debugging() calls | Revision (Patch) |
| `cc39268` | **v1.0.7** | fix: update core_ai action namespace from action to aiactions | Revision (Fix) |
| `928f4e9` | **v1.0.6** | debug: add verbose error logging inside core_ai path | Revision (Patch) |
| `a5de90c` | **v1.0.5** | fix: pass required $DB argument to core_ai\manager() constructor (Moodle 5.x) | Revision (Fix) |
| `b4c83bc` | **v1.0.4** | debug: add core_ai process_action diagnostic logging | Revision (Patch) |
| `38161d2` | **v1.0.3** | debug: write diagnostics to readable log file | Revision (Patch) |
| `7584798` | **v1.0.2** | debug: add error_log diagnostics to generate_rubric_evaluation | Revision (Patch) |
| `f27fb2f` | **v1.0.1** | fix: resolve rubric feedback API failure on session completion | Revision (Fix) |
| *Baseline* | **v1.0.0** | Initial baseline release with Moodle 5.x component migration | **Major / Baseline** |
