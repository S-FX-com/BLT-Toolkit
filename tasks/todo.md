# Build checklist

Phase checklists for BLT Toolkit, seeded from SPEC 17 (phases and acceptance criteria) and SPEC 18 (testing). Requirement IDs refer to [SPEC.md](../SPEC.md). Build in phase order: a phase is done when every acceptance criterion passes, and the next phase does not start with one failing (SPEC 0.3). Each phase also ends with `composer lint`, `composer phpcs` and `composer test` clean.

Legend: `[x]` done and verified; `[ ]` open. Where a check cannot be made in the build environment, the line says what was done instead and what is still owed.

Each phase has three parts: **Build** (the deliverables, by file from SPEC 4.3 and by requirement), **Acceptance** (SPEC 17's criteria, word for word, tagged with the requirements they prove) and, where useful, **Also check** (criteria derived from the requirements, not listed in SPEC 17). A criterion that needs a real WordPress site carries a *How to verify* note.

**How things are verified.** Unit tests (`composer test`, no WordPress needed) cover the pure classes against known vectors (SPEC 18). WordPress-coupled behavior is exercised against a real WordPress install: the shared dev site (WordPress 7.1.3 on SQLite, PHP 8.3, `WP_DEBUG` on) or one built with `bin/dev-site.sh` (`blt-site new NAME`, `blt-site wp NAME …`, `blt-site serve NAME PORT`, `blt-site log NAME`), driven over HTTP with `curl` and a cookie jar so real auth cookies, nonces and entry points (`wp-login.php`, `admin.php`, `admin-post.php`, `admin-ajax.php`, `/wp-json/`) are used. `wp db query` needs a MySQL client and does not work on SQLite; read tables with `blt-site wp NAME eval` and `$wpdb` instead. What that setup cannot show, and is owed on a staging site: real MySQL or MariaDB, PHP 7.4 at run time, a multisite network, a persistent object cache unless `redis-server` is available, Cloudflare APO, a shared host, and a real update download from GitHub.

## Phase 0: Scaffold

**Status: build complete; acceptance verified on 2026-10-21 except two items that cannot be checked here (PHP 7.4 at run time, and the update path, which needs published releases). See the notes on those items.**

Scope (SPEC 17): Repo per CONVENTIONS 5 and 16.1; family layer registered with the `github` group; update checker with `24`; CI `release.yml` ported from BLT Secure; module registry; settings; schema with both tables; activation grants (5.1) and baseline snapshot; empty admin screens on the design system; multisite activation refused.

### Build

- [x] Vendored byte-identical: `DESIGN.md`, `CONVENTIONS.md`, `includes/blt-family/`, `blt-family-smoke.php`, `assets/img/`, `assets/css/blt-design-system.css` and plugin-update-checker v5p7 under `includes/lib/` (CONVENTIONS 5, 16.1). Checked with `cmp`, `sha256sum` and `diff -r` against BLT Activity Log and BLT Secure, which match each other (see `tasks/lessons.md` for BLT Events' drift).
- [x] `blt-toolkit.php`: header (SPEC 3: WordPress 6.3, PHP 7.4, `Network: false`, Author `S-FX.com`), constants `BLT_TOOLKIT_VERSION`, `_FILE`, `_DIR`, `_URL`, `_BASENAME`, `_REST_NS`, `blt_family_register()` with `groups => array( 'github' )`, update checker built with `24` and release assets only, `BLT_Family_Updates::apply()`, token resolved on `init`, computed autoloader (probes `includes/` then `admin/`), activation and deactivation hooks, boot on `plugins_loaded` priority 1 (SPEC 4.2, CONVENTIONS 5.1, 9.1).
- [x] `includes/class-blt-toolkit-plugin.php` registry and boot: `boot()`, `activate()`, `deactivate()`, `modules()` (`blt_toolkit_modules`), `module_status()` (`on`, `off`, `conflict`, `disabled`), `is_active()`, `module()` (SPEC 4.1). `BLT_TOOLKIT_DISABLE_MODULES` (SEC-15).
- [x] `includes/class-blt-toolkit-module.php` interface, and module stubs `class-blt-toolkit-switch.php`, `class-blt-toolkit-usernames.php`, `class-blt-toolkit-roles.php` that boot nothing yet.
- [x] `includes/class-blt-toolkit-legacy.php`: detection of User Switching, Username Changer and User Role Editor; the matching module does not boot (GEN-1). Importers come in Phase 6.
- [x] `includes/class-blt-toolkit-settings.php`: `defaults()` exactly SPEC 14.1, `all()`, `get()`, `save_tab()` sanitized by hand, `set()`, `github_token()` (constant, then encrypted `blt_toolkit_secrets`, then the shared `github` group), `set_github_token()` (SPEC 6.4, CONVENTIONS 6.2).
- [x] `includes/class-blt-toolkit-crypto.php` copied from BLT Secure's envelope: libsodium, then OpenSSL AES-GCM, never a base64 tier; tamper returns `WP_Error` (SPEC 2, CONVENTIONS 17 item 2).
- [x] `includes/class-blt-toolkit-schema.php`: `{prefix}blt_toolkit_log` and `{prefix}blt_toolkit_snapshots` by `dbDelta` (SPEC 14.3), `blt_toolkit_db_version`, `maybe_upgrade()` on `init`, `table()`.
- [x] `includes/class-blt-toolkit-caps.php`: `own()` (SPEC 5.1), `meta()`, `grant_admin()` in one roles write, `tiers()` (SPEC 5.4, `blt_toolkit_risky_caps`), `tier()`, `lockout_critical()` (SEC-12, `blt_toolkit_lockout_critical_caps`).
- [x] `includes/class-blt-toolkit-role-diff.php` (pure: `canonicalize()`, `hash()`, `diff()`, SNAP-5) and `includes/class-blt-toolkit-snapshots.php` (`take()` skips a hash equal to the newest, `baseline()`; SNAP-1, SNAP-2).
- [x] Activation: refuse on multisite (D1), install schema, seed settings, baseline snapshot, grant administrator capabilities. Deactivation changes no data (SPEC 14.6).
- [x] `includes/class-blt-toolkit-guard.php` (`register()`, `is_toolkit_admin()`, SPEC 5.2) and `includes/class-blt-toolkit-log.php`, both loaded with every module off.
- [x] `admin/class-blt-toolkit-admin.php`: top-level **BLT Toolkit** menu with `BLT_Family_Brand::menu_icon()` and `print_menu_icon_style()` on `admin_head`, submenus with SPEC 13.1 capabilities, **Users > Roles**, design system enqueued on BLT screens only, PRG router.
- [x] Empty screens on the design system (`<div class="wrap blt-ui blt-<page>">`, page header with the BLT mark): `admin/class-blt-toolkit-admin-overview.php`, `-roles.php`, `-usernames.php`, `-activity.php`, `-tools.php`, `-settings.php` (SPEC 13.2, CONVENTIONS 14.4). Settings tabs as links; Advanced tab carries **Check for Updates** (DESIGN 4).
- [x] `uninstall.php` per SPEC 14.6, removing only BLT Toolkit's own capabilities and markers (see `tasks/lessons.md`).
- [x] CI: `.github/workflows/ci.yml` (syntax on PHP 7.4 to 8.3, phpcs, unit tests, version sync, POT drift, zip build), `.github/workflows/release.yml` ported from BLT Secure, `.github/scripts/check-version-sync.sh`, `bump-version.php`, `build-zip.sh`, `verify-zip.sh`, `.distignore`. *Verified locally:* both workflows parse as YAML, and the scripts they run (`check-version-sync.sh`, `build-zip.sh` through its no-rsync fallback, `verify-zip.sh`, `bump-version.php` through its unit test, `make-pot.js`) pass. *Owed:* the first run on GitHub Actions, including PHP 7.4 lint and phpunit and the rsync path of `build-zip.sh`.
- [x] Tooling: `composer.json` (dev only), `phpcs.xml.dist` (WordPress-Extra, PHPCompatibilityWP 7.4+), `phpunit.xml.dist`, `tests/bootstrap.php`, tests for Caps, Crypto, Role_Diff and Settings; `bin/make-pot.js` and `languages/blt-toolkit.pot`; `bin/dev-site.sh`. Also `tests/test-bump-version.php`, ported from BLT Activity Log.
- [x] Docs: `README.md`, `readme.txt` (Stable tag equals the header), `CHANGELOG.md`, `HOOKS.md`, `AGENTS.md`, `tasks/todo.md`, `tasks/lessons.md` (CONVENTIONS 15).

### Acceptance

- [ ] Activates cleanly on PHP 7.4 / WordPress 6.3 and PHP 8.3 / latest WordPress with `WP_DEBUG` on and no notices. (SPEC 3, D10)
  - *Verified 2026-10-21, PHP 8.3 / WordPress 7.1.3 (site `p0verify`):* activated from the Plugins screen over HTTP with `WP_DEBUG` on; then, signed in as admin, the front page, a post, `wp-login.php`, `/wp-json/`, all six BLT screens, all five Tools tabs, all six Settings tabs and 27 core admin screens returned 200. `nav-menus.php` and `widgets.php` returned core's own "Your theme does not support navigation menus or widgets" (block theme), not a BLT error. The debug log stayed empty. Deactivated and activated again over HTTP and with WP-CLI: still exactly one snapshot (the baseline), the nine capabilities and the settings unchanged, and the log empty.
  - *Verified 2026-10-21, PHP 8.3 / WordPress 6.3 (`bin/dev-site.sh DIR 6.3`, SQLite Database Integration 3.1.1):* the same activation and screen sweep (all BLT screens and tabs return 200 on the design system). The only log lines are core's `get_class()` deprecation in `class-wp-http.php` line 329, which WordPress 6.3 raises on every HTTP API request under PHP 8.3 and which already appears at install and login before BLT Toolkit is active. Nothing comes from BLT Toolkit's files. Tables, baseline, capabilities and settings match the 7.1.3 site.
  - *Owed:* PHP 7.4 at run time. No PHP 7.4 binary exists in the build environment; CI's PHP 7.4 lint and phpunit jobs cover syntax and the unit tests, and PHPCompatibilityWP (7.4+) is clean. Run the sweep above with `PHP=php7.4` on WordPress 6.3 on a host that has it.
  - *How to verify:* `bin/dev-site.sh DIR 6.3` with a PHP 7.4 binary (set `PHP=php7.4` for both `bin/dev-site.sh` and `blt-site`) and `bin/dev-site.sh DIR latest` with PHP 8.3; `blt-site wp NAME plugin activate blt-toolkit`; log in over HTTP and open every BLT screen and Settings tab; `blt-site log NAME` stays empty. Confirm the two tables, the settings, the baseline snapshot and the nine capabilities on `administrator`. PHP 7.4 at run time is owed if no 7.4 binary is available (CI lints 7.4 only).
- [x] `composer lint`, `composer phpcs`, `composer test` clean. Header and constant versions match (CI check). (CONVENTIONS 14.2, 14.3, 9.2)
  - *Verified 2026-10-21:* `composer lint` (every PHP file), `composer phpcs` (33 files, WordPress-Extra and PHPCompatibilityWP, no errors or warnings) and `composer test` (60 tests, 465 assertions) pass, with `COMPOSER_ALLOW_SUPERUSER=1` in the root container. `.github/scripts/check-version-sync.sh` reports `header=0.1.0 constant=0.1.0 stable_tag=0.1.0`.
- [ ] Release zip has one top-level `blt-toolkit/`, includes the update checker, excludes dev files. A second release updates a test site through the updater. (CONVENTIONS 9.1, 9.2, DESIGN 4)
  - *Verified 2026-10-21 (first half):* `build-zip.sh 0.1.0` (no-rsync fallback) and `verify-zip.sh 0.1.0` pass: 170 files, all under one `blt-toolkit/` folder, with `includes/lib/plugin-update-checker/` (including `Puc/v5/PucFactory.php` and `vendor/Parsedown.php`), the family layer, `LICENSE`, `readme.txt` and the POT. No `SPEC.md`, `CLAUDE.md`, `AGENTS.md`, `tests/`, `tasks/`, `bin/`, `vendor/`, `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist` or `.github/`.
  - *Owed:* "A second release updates a test site through the updater" can only be checked once a merge to `main` has published releases where the update checker looks.
  - *How to verify:* locally, `.github/scripts/build-zip.sh 0.1.0 && .github/scripts/verify-zip.sh 0.1.0`. After the first merge, install the published `blt-toolkit-0.1.1.zip` on a clean site, merge again to publish 0.1.2, use **Check for Updates**, update from the Plugins screen, and confirm the new version and that settings and data survived. Blocked until releases are published where the update checker looks (`S-FX-com/BLT-Toolkit`; see `tasks/lessons.md`).
- [x] Activation on multisite is refused with a readable notice. (D1, SPEC 2)
  - *Verified 2026-10-21 on a real multisite network on SQLite (site `p0ms`):* `core multisite-convert --skip-config`, with the multisite constants passed per process (WP-CLI `--exec`, and a `php -S` router for HTTP) so the shared `wp-config.php` was never edited. Per-site activation and network activation were both refused over HTTP (Plugins and Network Admin > Plugins: the `wp_die()` page "BLT Toolkit supports single-site installs only in this version, so it cannot be activated on a multisite network, either for the whole network or for one site." with a Back link) and with WP-CLI (`Error:` with the same sentence, exit 1). Afterwards: no BLT table, option, site option or capability, and the plugin not active. Forced into `active_plugins`, it booted nothing (no menu, its page returned 403, no tables or options) and showed the "not running on this network" notice in the site and network admin.
  - *How to verify:* convert a throwaway site to multisite (`blt-site wp NAME core multisite-convert --skip-config`, then pass the multisite constants per process, because the dev sites share one `wp-config.php`; see `tasks/lessons.md`), then try network activation and per-site activation from the admin and with WP-CLI. Both are refused with the notice, and no table, option or capability is created. If the SQLite drop-in cannot run multisite, this is owed on a MySQL staging network.

### Also check

- [x] A module named in `BLT_TOOLKIT_DISABLE_MODULES`, or whose legacy plugin is active, reports `disabled` or `conflict`, registers no hooks and has its class loaded by nothing, not even a screen that names it; the Guard and Log still load. When both apply it reports `conflict`, as GEN-1 asks, and the note names the constant too. (SEC-15, GEN-1, SPEC 4.1)
  - *Verified 2026-10-21:* with `BLT_TOOLKIT_DISABLE_MODULES` set to `switch`, `module_status( 'switch' )` is `disabled` and the module does not boot while the other two do; with all three named, no module class is even loaded, while `BLT_Toolkit_Guard` and `BLT_Toolkit_Log` are; with a `user_switching` class declared, `switch` reports `conflict`.
  - *Re-verified 2026-10-21 after the Phase 0 review (site `p0fix`), this time while screens render:* the first check above held only for requests that render no BLT screen, because `BLT_Toolkit_Admin::modules()` built every idle module to read its name. Screens now name idle modules from `BLT_Toolkit_Plugin::labels()`. With all three named in the constant, rendering Overview, Settings > General, Roles and Usernames (`blt-site wp NAME --exec='define(…)' eval-file`) left `BLT_Toolkit_Switch`, `BLT_Toolkit_Usernames` and `BLT_Toolkit_Roles` unloaded; with only a `URE_Lib` class declared, only `BLT_Toolkit_Roles` stayed unloaded. With a `user_switching` class declared and `switch` also named in the constant, `switch` reports `conflict`, its Overview card shows the Conflict badge, and the note names User Switching, then the constant, then links to Plugins.
- [x] A front-end page view by a signed-out visitor runs no queries from this plugin. (SEC-9)
  - *Verified 2026-10-21:* a `php -S` router with `SAVEQUERIES` recorded every query and its caller chain for the home page, a post, a page, an author archive, a search, the RSS feed, `wp-login.php` and `/wp-json/wp/v2/posts`, signed out. No query came from BLT Toolkit or named a `blt_` table or option, and the counts (26, 30, 22, 26, 27, 15, 7, 10) were identical with the plugin inactive. Its options (`blt_toolkit_settings`, `blt_toolkit_db_version`) are autoloaded; `blt_toolkit_secrets` is autoload no, and the update checker does not load on the front end.
  - *Re-verified 2026-10-21 after the Phase 0 review (site `p0fix`), with the schema broken:* `blt_toolkit_db_version` deleted, the log table dropped and its CREATE forced to fail through a pre-initialized `query` filter in the router. The home page, a post, a search, `wp-login.php`, `/wp-json/wp/v2/posts` and the RSS feed ran no query from BLT Toolkit and logged nothing. Before the fix each request ran dbDelta, and even after it the missing (so not autoloaded) version option cost one query per request until the check was limited to wp-admin, WP-Cron and WP-CLI. The next admin screen retried the install (one logged database error while the CREATE still failed), and a WP-CLI command with the CREATE allowed repaired it.
  - *How to verify:* a `query` filter in an mu-plugin that records queries mentioning `blt_toolkit`, then request the home page, a post and `wp-login.php` signed out.
- [x] Uninstall with the default settings removes nothing; with **Delete all plugin data on uninstall** on it removes what SPEC 14.6 lists, keeps other BLT plugins' `blt_*` capabilities, roles and `blt_family_shared` / `blt_family_opt_in`. (SPEC 14.6)
  - *Verified 2026-10-21 (by including `uninstall.php` with `WP_UNINSTALL_PLUGIN` defined, the plugin deactivated; see `tasks/lessons.md`):* first seeded every kind of data (both tables with rows, settings, secrets, a job option, the import ledger, the transient, all four user meta keys, BLT Toolkit capabilities and `blt_readonly_*` markers on roles and on a user, `blt_toolkit_*` and update-checker cron events, `external_updates-blt-toolkit`), plus another plugin's `blt_view_activity_log` on two roles and a user, `blt_family_shared`, `blt_family_opt_in`, a custom role, an owner's capability edit and BLT Activity Log's update cron. With the setting off, a before-and-after dump was identical. With it switched on through Settings > Advanced over HTTP, everything of BLT Toolkit's was gone, and everything else above was untouched: no role deleted, no snapshot restored.
  - *Re-verified 2026-10-21 after the Phase 0 review (site `p0fix`), same method and seed:* with `delete_data_on_uninstall` stored as the string `"false"` (what `wp option patch update blt_toolkit_settings advanced delete_data_on_uninstall false` writes, which `empty()` used to read as on) and as `false`, the before-and-after dumps were identical; switched on over HTTP, the tables, options, secrets, job option, import ledger, transient, user meta, BLT Toolkit's capabilities and markers, its cron events and `external_updates-blt-toolkit` were gone, while `blt_family_shared`, `blt_family_opt_in`, the custom role, the owner's `moderate_comments` edit, `blt_view_activity_log` on the editor role and a user, and BLT Activity Log's update cron stayed.
- [x] Data model on activation matches SPEC 14: both tables column for column (types, nullability, defaults) with the `created_at`, `module_action`, `actor_id` and `object_lookup` (`object_id(40)`) indexes; `blt_toolkit_db_version` = `1.0.0`; `blt_toolkit_settings` identical to the SPEC 14.1 array and autoloaded; a GitHub token saved through Settings > Advanced stored encrypted in `blt_toolkit_secrets` with autoload no (no plaintext in the row), read back by `github_token()`, and kept when the field is submitted blank; `administrator` holds the nine SPEC 5.1 capabilities and no other role holds any. (SPEC 14.1, 14.2, 14.3, 5.1, SNAP-2)
- [x] Activation never changes a role unless the tables and the baseline are stored, and a refused activation (multisite included) leaves no scheduled event behind. (SNAP-2, D1)
  - *Verified 2026-10-21 (site `p0fix`, WP-CLI, failures forced with a pre-initialized `query` filter passed through `--exec`):* with the snapshots table's CREATE failing, and again with the baseline INSERT failing, `plugin activate` printed the readable refusal and exited 1; afterwards the plugin was inactive, `administrator` held no BLT Toolkit capability, and `puc_cron_check_updates-blt-toolkit` was not scheduled. A normal activation then stored one baseline, without the BLT Toolkit capabilities, before granting them. On the same site converted to multisite (`--skip-config`, constants per process), per-site and network activation were refused and left no update-checker event (before the fix, the event survived the refusal).
- [x] Two activations at once leave one baseline, the older one. (SNAP-2)
  - *Verified 2026-10-21 (site `p0fix`):* two overlapping `BLT_Toolkit_Snapshots::baseline()` processes, the first held for 3 seconds before its INSERT by a `query` filter, both returned the ID of the first row stored, and the table held exactly one baseline.
- [x] Settings written outside the Settings screen read the way the screen would have saved them. (CONVENTIONS 6.2, SPEC 14.6)
  - *Verified 2026-10-21:* unit tests read `false`, `off`, `no`, `0`, `''` and `garbage` as off and `true`, `on`, `yes`, `1` as on (`advanced.delete_data_on_uninstall`, a built-in and a filter-added module toggle), clamp out-of-range integers, apply the whitelists and clean damaged lists. On site `p0fix`, after `wp option patch` stored `"false"`, `"off"` and `0`, `delete_data_on_uninstall` read false, `module_status( 'switch' )` read `off` and `log.retention_days` read 1; with `usernames.disallowed` holding a nested array and an object, Settings > Usernames returned 200 with only `ok` in the field and nothing in the debug log (before the fix: a warning, then a fatal error).
- [x] The update checker offers only the `blt-toolkit-X.Y.Z.zip` release asset: a release without it, the newest tag and the branch are never offered. (CONVENTIONS 2, 9.2 item 4)
  - *Verified 2026-10-21 (site `p0fix`, `eval-file` with `pre_http_request` mocking GitHub):* a latest release with no assets now yields no update (before: `0.1.5` from `.../zipball/v0.1.5`), even though the mocked `/tags` and branch answered; with the asset attached, the update points at `blt-toolkit-0.1.5.zip`.
- [x] A link cannot make a BLT notice report a Test connection result that never happened, or put its own words in one. (SEC-7)
  - *Verified 2026-10-21 (site `p0fix`, over HTTP and with `eval-file`):* `blt_notice=test_unreachable&err=<any words>` and `blt_notice=test_result&http=200&via=stored`, with or without a made-up `blt_sig`, render no notice. A real Test connection (the sandbox proxy answers 403) renders its result; changing any value in that URL, or opening it as another user, renders nothing.

## Phase 1: Guard, log, snapshots

**Status: not started.**

Scope (SPEC 17): SEC-1 to SEC-15, LOG-1 to LOG-6, SNAP-1 to SNAP-5, Activity screen.

### Build

- [ ] `includes/class-blt-toolkit-guard.php`: `map_meta_cap` with a small short-circuit set (SEC-9); SEC-1 `do_not_allow` for `edit_user`, `delete_user`, `remove_user`, `promote_user`, `blt_switch_to_user`, `blt_change_username` on administrators; meta capabilities `blt_switch_to_user` and `blt_change_username` (SPEC 5.1); SEC-2, SEC-3, SEC-5 checks for write paths; superset results cached per role set and per-user caps hash within the request (SEC-9).
- [ ] `editable_roles` filter that only removes roles (SEC-4).
- [ ] SEC-6: `pre_get_users` scoped to the Users list table, `rest_user_query`, `views_users` counts; filter `blt_toolkit_hide_admins_from_non_admins`.
- [ ] `includes/class-blt-toolkit-superset.php` (pure, SEC-8): effective `allcaps` where `true`, ignoring role-name keys and `do_not_allow`; explicit `false` not held.
- [ ] Guard checks for the write paths of later phases: SEC-10 typed confirmation of a Critical grant, SEC-12 lockout-critical set and `administrator` undeletable, SEC-13 self-lockout. (UI arrives with the Roles screens in Phases 4 and 5.)
- [ ] SEC-14 recovery: `BLT_TOOLKIT_RECOVERY` checked on `init`; on the next admin request by a user with the `administrator` role, snapshot (`recovery`), re-grant the lockout-critical set and every BLT capability, log, persistent notice until the constant is removed.
- [ ] `includes/class-blt-toolkit-log.php`: `record()` (LOG-1); actor attribution while switched (LOG-2); a failed insert goes to `error_log()` and is flagged for Site Health, never to the user (LOG-3); daily prune in batches of 1,000 with `blt_toolkit_log_retention_days` (LOG-4; it moves onto the `log_prune` job when Jobs lands in Phase 3); IP from `REMOTE_ADDR`, `CF-Connecting-IP` only from Cloudflare ranges when BLT Secure's check can be reused without forking the family layer (LOG-5); `blt_toolkit_logged` (LOG-6).
- [ ] Snapshots: taken before every roles write (SNAP-1); retention newest 50 plus baseline with `blt_toolkit_snapshot_retention` (SNAP-3); list, diff and restore logic (SNAP-4; the Tools UI is Phase 6); `BLT_Toolkit_Role_Diff` complete (SNAP-5).
- [ ] `admin/class-blt-toolkit-admin-activity.php`: filter toolbar (module, action, actor, acting as, date range), paginated `WP_List_Table` in a card, row disclosure for details and diff, Export CSV (POST with nonce); `blt_view_toolkit_log` (SPEC 13.2).
- [ ] Unit tests: `BLT_Toolkit_Superset` (SEC-8 cases), `BLT_Toolkit_Role_Diff` (order independence, hash stability, `true`/`false` flips, display name changes).
- [ ] Once SEC-1, SEC-6, SEC-14 and LOG-1 pass, put back the present tense that Phase 0 held off: the Modules card text on Overview and Settings > General ("Protection for administrators and the activity log always run"), the `modules_saved` notice, the Protection card on Settings > Roles (drop its "Coming soon" line), the Recovery card on Settings > Advanced (drop the restore field's "Coming soon" badge and "This line does nothing yet"), and "Protection for administrators and the activity log keep running." in the "Switch modules off" description.
- [ ] `HOOKS.md`: the Phase 1 hooks marked Available (`blt_toolkit_logged`, `blt_toolkit_hide_admins_from_non_admins`, `blt_toolkit_snapshot_retention`, `blt_toolkit_log_retention_days`), and `blt_toolkit_is_toolkit_admin` and `blt_toolkit_lockout_critical_caps` once the Guard and recovery call them.

### Acceptance

- [ ] A non-admin with `edit_users` and `promote_users` cannot edit, delete, change the role of, or switch to an administrator through `users.php`, `user-edit.php`, `admin-ajax.php` or `POST /wp/v2/users/{id}`. (SEC-1, SEC-7, URE-1)
  - *How to verify:* create a role with `read`, `list_users`, `edit_users`, `promote_users`, `delete_users` and a user holding it; log in as that user over HTTP. Against an administrator, try the edit form (forged POST with a valid nonce for the user's own profile), the delete and bulk change-role actions on `users.php`, an `admin-ajax.php` action, and `POST /wp/v2/users/{id}` with a REST nonce. Every one is refused and the administrator's row is unchanged. Switching (Phase 2) is proved here through `current_user_can( 'blt_switch_to_user', $admin_id )` returning false.
- [ ] The same user cannot assign a role whose capabilities exceed theirs through the profile dropdown, the Users bulk action or REST. (SEC-4, SEC-2, URE-3)
  - *How to verify:* the role dropdown on `user-edit.php` and `user-new.php` lists only subset roles; a forged POST of `role=editor`, a bulk `new_role=administrator` on `users.php` and `POST /wp/v2/users/{id}` with `roles: ["editor"]` are all refused.
- [ ] With `BLT_TOOLKIT_RECOVERY` defined, an administrator whose role lost `manage_options` regains it on the next admin request, and a notice persists until the constant is removed. (SEC-14, SEC-12)
  - *How to verify:* `blt-site wp NAME cap remove administrator manage_options`; define the constant in the test site's `wp-config.php`; load `/wp-admin/` as admin; `blt-site wp NAME cap list administrator` shows it again, a `recovery` snapshot and a log row exist, the notice shows on every admin screen; remove the constant and the notice is gone.
- [ ] Unit tests: superset (SEC-8 cases including `false` caps and role-name keys), diff and hash canonicalization. (SEC-8, SNAP-5)

### Also check

- [ ] SEC-6: a non-admin user manager does not see administrators in the Users list, its role counts, or `GET /wp/v2/users`.
- [ ] LOG-3: forcing the log insert to fail (rename the table) does not interrupt the action being logged.
- [ ] SNAP-1: two identical saves in a row produce one snapshot.

## Phase 2: Switch

**Status: not started.**

Scope (SPEC 17): SW-1 to SW-28.

### Build

- [ ] `includes/class-blt-toolkit-switch.php` module: permission through `blt_switch_to_user` and `blt_switch_users` (SW-1); request handling on `init` through `wp_login_url()` for `blt_switch_to_user`, `blt_switch_back`, `blt_switch_off` with per-target nonces (SW-2); flows SW-4, SW-5, SW-6 with session information (`blt_switched_from_id`, `blt_switched_from_session`, `blt_switched_into`, `blt_switched_at`); duplicate-switch 409 page with `force=1` (SW-8) and `qm/cease` (SW-23); locale switching for messages to the original user (SW-11); logging with "acting as" (LOG-2).
- [ ] `includes/class-blt-toolkit-switch-cookies.php`: cookie stack (pure stack operations, I/O separate), the three cookies and their constants (SW-3), `blt_toolkit_switch_send_cookies`, remember-me inheritance (SW-7), clear on `wp_login` and `wp_logout`, `unset( $_COOKIE[ LOGGED_IN_COOKIE ] )` on `clear_auth_cookie` (SW-10).
- [ ] `includes/class-blt-toolkit-switch-redirects.php`: `redirect_to`, `redirect_to_post`, `_term`, `_user`, `_comment` (public URLs only), core's `login_redirect` / `logout_redirect`, then `blt_toolkit_switch_redirect`; carry the edited item on switch off from an edit screen (SW-9).
- [ ] `includes/class-blt-toolkit-switch-ui.php`: Users row action (SW-12), profile button with `aria-label` (SW-13), toolbar "Viewing as" and "Switch back", Switch Off node (SW-14), author archive toolbar (SW-15), notices and `wp_die` links (SW-16), footer link with `assets/css/blt-toolkit-front.css` and `blt_toolkit_switch_show_footer_link` (SW-17), login message (SW-18), toolbar search with `assets/js/blt-toolkit-switch-search.js` (SW-20), command palette with `assets/js/blt-toolkit-command-palette.js` (SW-26), Meta widget link (Appendix B).
- [ ] `includes/class-blt-toolkit-rest.php`: `GET blt-toolkit/v1/switch/users` (SW-20, `blt_toolkit_switch_search_limit`) and the `blt_toolkit_switch_url` user field (SW-27).
- [ ] Compatibility hooks `switch_to_user`, `switch_back_user`, `switch_off_user` and the `current_user_switched()` shim, behind the setting (SW-19, D2, GEN-2).
- [ ] Support Agent role button with preview on Settings > Switching (SW-21).
- [ ] `includes/class-blt-toolkit-switch-woocommerce.php`, loaded only with WooCommerce (SW-22).
- [ ] Privacy policy text for switch cookies in `includes/class-blt-toolkit-privacy.php` (SW-24).
- [ ] Settings > Switching tab (footer link, switch off, search, compatibility hooks, WooCommerce).
- [ ] Toolbar node color `var(--blt-primary, #2271b1)`; front-end tokens `--blt-tk-*` documented in `README.md` (SW-14, D14).
- [ ] Unit tests: switch cookie stack (push, pop, nested depth, malformed JSON yields an empty stack).
- [ ] `HOOKS.md`: the Phase 2 hooks and the shim marked Available.

### Acceptance

- [ ] Switch three levels deep, then switch back one level at a time to the original user and original session. (SW-4, SW-5, SW-3)
  - *How to verify:* roles A ⊃ B ⊃ C where A and B hold `blt_switch_users`; admin switches to an A user, then to a B user, then to a C user, each over HTTP with one cookie jar. Record session tokens with `blt-site wp NAME user session list`. Switch back three times and confirm the current user (`GET /wp-json/wp/v2/users/me`) and the restored session token at each step.
- [ ] Switch off: logged out; login screen and site footer offer switch back; switching back restores the original session. (SW-6, SW-17, SW-18, SW-5)
- [ ] Duplicate-switch warning appears for a second admin; `force` continues. (SW-8, SW-23)
  - *How to verify:* two admins in two cookie jars; the second gets HTTP 409 with "{Name} is currently switched to {Target}…"; repeating with `force=1` switches.
- [ ] Switch back destroys the target's switched session (verify in `session_tokens`). (SW-5)
  - *How to verify:* `blt-site wp NAME user meta get TARGET session_tokens` before and after the switch back.
- [ ] A Support Agent can switch to Subscribers but not to Editors or Administrators. (SW-21, SEC-1, SEC-2)
- [ ] Every switch, switch back and switch off is logged with actor and acting-as. (LOG-2, X-2)
  - *How to verify:* read `{prefix}blt_toolkit_log` with `blt-site wp NAME eval` after the three-level test: `actor_id` is the admin on every row, `acting_as_id` the user switched from.
- [ ] With compat hooks on, a listener on `switch_to_user` fires with User Switching's arguments. (SW-19, D2)
  - *How to verify:* an mu-plugin listener writes `func_get_args()` to the debug log; turn the setting off and confirm it no longer fires.
- [ ] Works with BLT Secure's custom login slug and its two-factor flow (switching does not prompt; switching back does not prompt); works with BLT M365 WP SSO (switched session is not kicked out by SSO session checks). (SW-2)
  - *How to verify:* a site with BLT Secure (custom slug and TOTP on) and one with BLT M365 WP SSO. Owed on staging if those plugins are not available in the build environment.
- [ ] WooCommerce: the cart and session from the admin do not leak into the customer session. (SW-22)
  - *How to verify:* on a WooCommerce site, the admin adds a product to the cart, switches to a customer from the order screen, and sees the customer's own cart; switching back from My Account restores the admin's.

### Also check

- [ ] Every switch link and action goes through `wp_login_url()`, so it works with a login URL filter and is never page-cached. (SW-2)
- [ ] The footer link and its stylesheet load only for switched requests; a visitor's page view loads neither. (SW-17, SEC-9)

## Phase 3: Usernames

**Status: not started.**

Scope (SPEC 17): UN-1 to UN-30 (UN-23 shortcode may slip to 3b).

### Build

- [ ] `includes/class-blt-toolkit-usernames.php` module.
- [ ] `includes/class-blt-toolkit-username-validator.php`: rules UN-1 to UN-9 in order with their own messages, lookups injected, `blt_toolkit_username_min_length`, `blt_toolkit_disallowed_usernames`, `blt_toolkit_validate_username`.
- [ ] `includes/class-blt-toolkit-username-changer.php`: the UN-10 procedure (authorize, clone, validate, update by ID requiring one row, nicename, display name and nickname, `clean_user_cache()` with the old object then the ID, sessions, hooks, log, email, result); UN-11 `site_admins` step written now; UN-12 switched-session warning; UN-14 sessions; UN-15 email with `blt_toolkit_username_email`.
- [ ] `includes/class-blt-toolkit-nicename.php` (pure, UN-13) and `includes/class-blt-toolkit-author-redirects.php` (UN-13a, `blt_toolkit_previous_nicenames`).
- [ ] `includes/class-blt-toolkit-username-profile.php`: profile section and nonce (UN-16), `user_profile_update_errors` (UN-17), `profile_update` (UN-18), Users row action (UN-19); `assets/js/blt-toolkit-usernames.js`; `GET blt-toolkit/v1/usernames/check`.
- [ ] Self-service: Settings > Usernames role checkboxes write `blt_change_own_username`, snapshotted and logged (UN-20); cooldown with `blt_toolkit_username_changed_at` (UN-21); optional password (UN-22).
- [ ] `includes/class-blt-toolkit-jobs.php`: runner on Action Scheduler or WP-Cron plus loopback, `POST blt-toolkit/v1/jobs/{id}/tick` (JOB-1); time-boxed ticks, `blt_toolkit_tick_budget`, state in `blt_toolkit_job_{id}` (JOB-2); pinned settings (JOB-3); progress UI, cancel, retry failed, stall fallback with `assets/js/blt-toolkit-jobs.js` (JOB-4); job types (JOB-5); `blt_toolkit_before_job` / `blt_toolkit_after_job`. Move the log prune onto `log_prune`.
- [ ] `includes/class-blt-toolkit-csv.php` and `includes/class-blt-toolkit-rename-planner.php` (pure), and the **Bulk rename from CSV** card on `admin/class-blt-toolkit-admin-usernames.php`: template download (POST, batches of 500), upload, plan, dry run, backup acknowledgment, `username_bulk` job, `blt_toolkit_bulk_rename_max_rows` (UN-30, D8).
- [ ] Usernames screen: Recent changes card and a link to settings (SPEC 13.2). Settings > Usernames tab.
- [ ] Unit tests: `BLT_Toolkit_Username_Validator` (every UN rule), `BLT_Toolkit_Nicename` (accents, spaces, `@`, 60-character logins, `-2` and `-3` collisions, matching `wp_insert_user()`), `BLT_Toolkit_Rename_Planner` (chains, cycles, duplicate targets, names freed within the batch, self rows), `BLT_Toolkit_Csv` (BOM, semicolons, quoted fields with commas and newlines, CRLF, blank lines, header detection).
- [ ] `HOOKS.md`: the Phase 3 hooks marked Available.

### Phase 3b: username form shortcode (D11)

- [ ] `includes/class-blt-toolkit-username-shortcode.php`: `[blt_toolkit_username_form]`, POST to `admin-post.php`, PRG back to the same URL, nothing for users without the capability, assets only when present, `style="styled|none"` (UN-23).
- [ ] `templates/username-form.php`, overridable at `your-theme/blt-toolkit/username-form.php`; form styles in `assets/css/blt-toolkit-front.css`.

### Acceptance

- [ ] With Redis or Memcached object cache active, the old username cannot log in immediately after a rename and the new one can. (UN-10, UC-1)
  - *How to verify:* build with `BLT_OBJECT_CACHE=redis bin/dev-site.sh DIR`, start `redis-server`, and run `blt-site serve` with `BLT_OBJECT_CACHE=redis` set; warm the cache by logging in as the user; rename from the profile; POST `wp-login.php` with the old login (fails) and the new login (succeeds) within seconds. Owed on staging if Redis is not available.
- [ ] The author slug changes only when it was derived from the username; the old author URL returns 301 to the new one. (UN-13, UN-13a, UC-2, D6)
  - *How to verify:* one user whose nicename was generated from the login and one with a custom nicename; rename both; `curl -I /author/old-slug/` returns 301 to the new URL for the first; the second's slug is unchanged.
- [ ] Renaming yourself keeps you signed in on the same session; renaming someone else ends their sessions when the setting is on. (UN-14, UC-10, D7)
  - *How to verify:* compare `wp_get_session_token()` (or `user session list`) before and after a self-rename and make a further authenticated request with the same cookie jar; for another user, `session_tokens` is empty afterwards.
- [ ] `jsmith` to `JSmith` succeeds; `Admin` is rejected when `admin` is disallowed; a username equal to another user's email is rejected; 61 characters is rejected; minimum length is enforced with JS disabled. (UN-1 to UN-8, UC-4, UC-5, UC-6)
  - *How to verify:* POST the profile form directly with `curl` (no JavaScript) for each case.
- [ ] No nested `<form>` in the profile HTML (validate the page markup). (UN-16, UC-8)
  - *How to verify:* fetch `profile.php` and `user-edit.php` and check with an HTML validator or `DOMDocument` that no `form` element contains another.
- [ ] Bulk CSV with `bob→carol` and `alice→bob` succeeds in the right order; `alice↔bob` is rejected as a cycle; the job survives closing the tab; cancel keeps completed rows. (UN-30, D8, JOB-4)
  - *How to verify:* upload the CSVs, read the dry-run table, start the job and stop polling (close the tab); WP-Cron or the loopback finishes it; for cancel, use a large CSV and a low `blt_toolkit_tick_budget`.

### Also check

- [ ] Phase 3b: the shortcode renders nothing signed out or without `blt_change_own_username`, enqueues its stylesheet only on pages that contain it, and works with JavaScript disabled. (UN-23)
- [ ] Self-service respects the cooldown and the password setting, and `blt_change_own_username` appears in Simple view once Phase 5 lands. (UN-20, UN-21, UN-22, X-6)

## Phase 4: Roles engine and Advanced view

**Status: not started.**

Scope (SPEC 17): RO-1 to RO-45 except export and import, US-1 to US-12.

### Build

- [ ] `includes/class-blt-toolkit-roles.php` module.
- [ ] `includes/class-blt-toolkit-role-store.php`: diff-based saves within the on-page set (RO-1), optimistic lock by render-time hash (RO-2), batch write after a snapshot (RO-3), Guard, hooks and log (RO-4), no writes on read paths (RO-5).
- [ ] `includes/class-blt-toolkit-capability-catalog.php`: sources (RO-10), orphans (RO-11), labels with `blt_toolkit_capability_labels` (RO-12), group tree with `blt_toolkit_capability_groups` (RO-13), shared capability detection (RO-14).
- [ ] `admin/class-blt-toolkit-admin-roles.php` and views: role list card with badges, Add role, role header (name, ID chip, user count linking to the filtered Users list, **Test as…** (X-3), Simple / Advanced tabs (RS-2)), sticky save bar with change count. Advanced view: layout (RO-20), matrix rows with label, raw name, source, tier badge (SEC-11) and owning Simple control (RO-21), toolbar (RO-22, `blt_toolkit_columns`), add capability (RO-23), delete orphan capability (RO-24), compare roles (RO-25), no-JS form and JS enhancements (RO-26); `assets/js/blt-toolkit-roles.js`. Critical review and typed confirmation (SEC-10). Advanced requires `blt_edit_capabilities` (SPEC 9.3).
- [ ] `includes/class-blt-toolkit-role-lifecycle.php`: add (RO-30), rename (RO-31), move users to a new role (RO-32), delete (RO-40), reassign as the `role_reassign` job (RO-41), merge (RO-42). Reset (RO-43) is built with Tools in Phase 6.
- [ ] Role metadata (SPEC 9.5): description and "Hide the toolbar on the site", applied only when every one of a user's roles has the flag; a switched user keeps the footer link (X-5).
- [ ] `includes/class-blt-toolkit-user-roles.php`: additional roles on the profile and Add New User screens (US-1), additional default roles on every registration path (US-2), bulk add and remove role on the Users list (US-3), users without a role counted on demand as the `count_no_role` job with the one-hour transient and a "No role (N)" view (US-12).
- [ ] `includes/class-blt-toolkit-user-caps.php`: per-user capabilities with Inherit, Grant and Deny (US-10); hide core's "Additional Capabilities" row (US-11).
- [ ] Settings > Roles tab (default view, show deprecated, readable names, hide administrators, additional default roles, snapshots to keep).
- [ ] `HOOKS.md`: the Phase 4 hooks marked Available, with the role lifecycle hooks' final signatures, and `blt_toolkit_risky_caps` once the tier badges (SEC-11) and the Critical review step (SEC-10) read the tiers.

### Acceptance

- [ ] Saving a filtered Advanced view does not remove capabilities that were filtered out. (RO-1)
  - *How to verify:* filter the matrix to one group (or a quick-filter term), change one capability, save; the role's capabilities outside the filter are identical before and after (compare with `BLT_Toolkit_Role_Diff`).
- [ ] Two admins editing the same role: the second save is rejected with a diff. (RO-2, URE-5)
  - *How to verify:* two cookie jars load the same role's form; both submit; the second gets "This role changed since you opened it" with a diff and nothing of its change is written.
- [ ] One save writes the roles option exactly once (assert with a query counter in a smoke test). (RO-3)
  - *How to verify:* an mu-plugin `query` filter counts `UPDATE` statements on the `{prefix}user_roles` option during the `admin-post.php` save request.
- [ ] Opening any screen changes no role (compare snapshots before and after a full click-through). (RO-5, URE-2)
  - *How to verify:* hash the roles with `BLT_Toolkit_Role_Diff::hash()` via `blt-site wp NAME eval`, request every BLT screen, tab and view (Simple and Advanced, every role, every group) plus `users.php` and a profile, then hash again: identical, and no new snapshot.
- [ ] A CPT registered with `capability_type => 'post'` appears inside the Posts capability group, not as its own node. (RO-14, URE-4)
- [ ] Capabilities registered by active post types are never offered for deletion. (RO-11, RO-24, URE-7)
- [ ] Users-without-role count is never computed on a normal Users page load. (US-12, SEC-9)
  - *How to verify:* the query-recording mu-plugin on `users.php` shows no count query until the button is pressed.

### Also check

- [ ] A non-admin with `blt_edit_roles` and `blt_create_roles` cannot copy or create a role exceeding their own, nor edit a role they hold. (SEC-3, SEC-5, URE-3)
- [ ] Granting a Critical capability to a role other than `administrator` requires the typed display name. (SEC-10)

## Phase 5: Simple view and separate permissions

**Status: not started.**

Scope (SPEC 17): RS-1 to RS-35, Appendix A.

### Build

- [ ] `includes/class-blt-toolkit-simple-controls.php`: control registry (RS-3, `blt_toolkit_simple_controls`); content rows per capability group (RS-10), types excluded from levels (RS-11), `map_meta_cap` false types (RS-12); the site and people controls in Appendix A, including "Hide the toolbar on the site" and the self-service capability (X-6); "Other plugin permissions" card.
- [ ] `includes/class-blt-toolkit-simple-mapper.php` (pure): the level map in SPEC 10.4, **Can delete** and **Manage {taxonomy}** modifiers, dedicated `assign_terms`, detection (RS-15), Custom and indeterminate states (RS-4), distinct versus shared `create_posts` (RS-13).
- [ ] Simple view UI: card order per SPEC 13.2, view preference in `blt_toolkit_editor_mode` (RS-1), switcher with unsaved-change prompt (RS-2), only changed controls submitted (RS-5), review step with JS and as its own PRG screen without it (RS-6), dependency hints with "Also allow" (RS-7, RS-17), tier badges (SEC-11), `administrator` read-only (SEC-12).
- [ ] `includes/class-blt-toolkit-readonly.php`: `map_meta_cap` for `edit_post`, `delete_post`, `publish_post` when the user holds `blt_readonly_{plural}` and lacks the type's `create_posts` (RS-13, RS-14).
- [ ] `includes/class-blt-toolkit-isolation.php`: candidates and dry run (RS-30), the `register_post_type_args` filter added in `blt-toolkit.php` at file load that returns at once when nothing is isolated, and `blt_toolkit_isolation_caps` (RS-31), revert plus separate cleanup step (RS-32), verification at `init` priority `PHP_INT_MAX` with notice and Site Health warning (RS-33), Plugins-row deactivation warning (RS-34), `blt_toolkit_isolation_excluded_types` (RS-30, RS-35), `blt_toolkit_post_type_isolated` / `_shared`.
- [ ] Unit tests: `BLT_Toolkit_Simple_Mapper` for every fixture type, RS-16 default roles, Custom detection, distinct versus shared `create_posts`.
- [ ] `HOOKS.md`: the Phase 5 hooks marked Available.

### Acceptance

- [ ] RS-16 fixture passes for Posts and Pages. (RS-16)
  - *How to verify:* the unit test, plus opening each default role in Simple view on a fresh site: Subscriber No access; Contributor Contribute with Can delete (Pages: No access); Author Author with Can delete (Pages: No access); Editor Full control; Administrator Full control, read-only.
- [ ] Round trip: for every level and modifier combination on fixtures (Posts, Pages, an isolated CPT, a CPT with its own `capability_type`, a `map_meta_cap` false CPT, an ACF-style override CPT), `detect( apply( state ) ) === state`. (RS-15, RS-11, RS-12, RS-13)
- [ ] A role edited in Advanced into an unrepresentable combination shows Custom in Simple, and saving an unrelated Simple control leaves it untouched. (RS-4, RS-5, RO-1)
- [ ] Isolating a CPT changes nobody's effective access (compare `allcaps`-derived permission checks for every role before and after on that type's items). (RS-30, RS-31)
  - *How to verify:* register a CPT with `capability_type => 'post'` in an mu-plugin; for a user of every role, record `user_can()` for `edit_post`, `delete_post`, `publish_post`, `read_post` on items owned by self and others, plus `create_posts`; isolate the type; record again on the next request; the two tables are identical.
- [ ] A View-level role can open the CPT list, sees titles as plain text, cannot open the editor, cannot create, and gets 403 from REST create and update. (RS-13, RS-14)
  - *How to verify:* over HTTP as that user: `edit.php?post_type=TYPE` returns 200 with no edit links; `post.php?post=ID&action=edit` and `post-new.php?post_type=TYPE` are refused; `POST /wp/v2/TYPE` and `POST /wp/v2/TYPE/ID` return 403.
- [ ] Deactivating BLT Toolkit with an isolated type shows the warning row; after deactivation the type shares Posts again. (RS-34, SPEC 14.6)

### Also check

- [ ] RS-35: Bricks templates (`bricks_template`) and any type whose plugin checks `edit_posts` directly. If isolation breaks the plugin's access checks, add the type to the default exclusion list and record it in `tasks/lessons.md`.
- [ ] RS-33: a type registered before BLT Toolkit loads, or overridden by another plugin, is reported by the notice and Site Health and its level selector is disabled.

## Phase 6: Tools

**Status: not started.**

Scope (SPEC 17): Snapshots UI, Export and import, Reset (both modes), Sync administrator, legacy importers (section 15).

### Build

- [ ] `admin/class-blt-toolkit-admin-tools.php`, tabs as links (SPEC 13.2):
  - [ ] Snapshots: list with time, actor, reason and the count of roles and capabilities that differ; view as diff; restore as dry-run diff, confirm, fresh snapshot, write (SNAP-4).
  - [ ] Export and import: `includes/class-blt-toolkit-role-transfer.php`, export format `blt-toolkit-roles` version 1 (RO-44); import with format check, per-role preview (New, Changed, Unchanged), per-role action, "not registered here" flags, SEC rules, snapshot first (RO-45).
  - [ ] Reset: built-in roles only (default) and full reset, each with a dry-run preview, typed `RESET`, a snapshot, then BLT capabilities re-granted to `administrator` (RO-43).
  - [ ] Sync administrator: preview of registered capabilities `administrator` lacks, grant on confirm (RO-5).
  - [ ] Import from legacy plugins (`manage_options`).
- [ ] `includes/class-blt-toolkit-legacy.php` importers (SPEC 15): Username Changer settings (allowed roles to `blt_change_own_username`, minimum length, notifications, subject, body) and `{prefix}uc_audit_log` rows into the log with `source = 'username-changer'`, resumable by highest ID; User Role Editor `other_default_roles`, hide-admin-bar flags, `{prefix}backup_user_roles` as a `legacy_ure_backup` snapshot, `ure_*` capabilities flagged "From User Role Editor" with a dry-run removal; ledger in `blt_toolkit_legacy_imports`. Option keys confirmed against the installed source versions at build time.
- [ ] Overview completed (SPEC 13.2): module cards with toggle and On / Off / Conflict badge (GEN-1), stat tiles (roles, users without a role on demand, switches and username changes in the last 30 days), setup card when legacy plugins or their data are detected, recovery-mode callout (SEC-14).

### Acceptance

- [ ] Restoring a snapshot first shows a diff and snapshots the current state. (SNAP-4, URE-6)
- [ ] Exporting roles from site A and importing into site B shows a correct per-role preview and flags capabilities not registered on B. (RO-44, RO-45)
  - *How to verify:* two sites from `blt-site new`; on A, an mu-plugin registers a CPT with its own `capability_type` and a custom role uses its capabilities; export from A, import on B; the preview marks that role New or Changed correctly and flags the CPT's capabilities "not registered here".
- [ ] Importing Username Changer's audit log twice does not duplicate rows. (SPEC 15, CONVENTIONS 11)
  - *How to verify:* seed `{prefix}uc_audit_log` in Username Changer 3.3.1's schema (or install that plugin from WordPress.org on the test site and rename a few users), import twice, and count log rows with `source = 'username-changer'`.

### Also check

- [ ] Both reset modes refuse without the typed `RESET`, list what will change first, and leave a snapshot that restores the previous state. (RO-43)
- [ ] Imports never delete or modify the source plugins' data. (SPEC 15, CONVENTIONS 11)

## Phase 7: Privacy, Site Health, docs, release

**Status: not started.**

Scope (SPEC 17): Privacy exporter and eraser, Site Health tests (legacy plugin conflicts, isolation verification failures, recovery constant set, log write failures), README per CONVENTIONS 15, `readme.txt`, `HOOKS.md`, `CHANGELOG.md`, POT.

### Build

- [ ] `includes/class-blt-toolkit-privacy.php`: personal data exporter and eraser for log rows where the user is the actor, the acting-as user or the object; the eraser blanks `actor_login` and `ip` and reports the rows as retained for security; policy text including the cookie and log text (SPEC 14.5, SW-24).
- [ ] `includes/class-blt-toolkit-site-health.php`: tests for legacy plugin conflicts (GEN-1), isolation verification failures (RS-33), the recovery constant set (SEC-14) and log write failures (LOG-3).
- [ ] Docs current: `README.md` per CONVENTIONS 15 (status line removed), `readme.txt`, `HOOKS.md` (every hook Available), `CHANGELOG.md`, `languages/blt-toolkit.pot`.
- [ ] Release: merge with `#major` to publish 1.0.0; clean install from the release zip; a second release proves the update path.

### Acceptance

- [ ] CONVENTIONS 14.5 checklist complete, including a keyboard-only pass of the Roles screen in both views and of the front-end pieces. (CONVENTIONS 14.5, 6.4)
  - [ ] Naming matrix (4.1) followed, `BLT` caps in all prose, Author lines exact.
  - [ ] Family layer registered, groups justified, nothing shared by default.
  - [ ] Update checker built with `24`, manual path intact, token precedence wired.
  - [ ] Secrets encrypted, constants override stored values, no plaintext tier.
  - [ ] No heavy work on page load; long jobs time-boxed, resumable, cancellable.
  - [ ] Destructive actions dry-run by default, audit-logged.
  - [ ] Uninstall behavior documented and tested; shared store untouched.
  - [ ] `composer lint`, `composer phpcs` and `composer test` clean.
  - [ ] README, `readme.txt`, `HOOKS.md`, `CLAUDE.md`, `tasks/todo.md` current.
  - [ ] Release zip verified: vendored update checker included, `worker/` and dev files excluded.
  - [ ] Exercised on shared-host constraints (no `exec()`, WP-Cron only).
  - [ ] Keyboard-only pass of the Roles screen (Simple and Advanced) and of the front-end pieces (switch-back link, username form).
  - *How to verify:* the keyboard pass on the dev site in a browser with the mouse unused: every control reachable in order, visible focus, the save bar and review step usable, the change counter announced (`aria-live`). Shared-host constraints and the update download are owed on staging.

## Manual smoke matrix (SPEC 18)

Run once Phase 7's build is complete, and again before a release that changes the affected area. Record the date, version and outcome in **Result**.

| Configuration | What to check | Result |
| --- | --- | --- |
| PHP 7.4 | Activation, every screen, a switch, a rename and a role save with `WP_DEBUG` on and no notices. | Not run |
| PHP 8.3 | As PHP 7.4. | Not run |
| WordPress 6.3 | As PHP 7.4; command palette entries absent or working (SW-26). | Not run |
| WordPress latest | As PHP 7.4; command palette entries present (SW-26). | Not run |
| Persistent object cache (Redis or Memcached) | Old username stops resolving and logging in immediately after a rename (UN-10); role saves visible on the next request. | Not run |
| No persistent object cache | Same checks. | Not run |
| Plain permalinks | Switch links, REST typeahead and username check through `?rest_route=`, author redirects (UN-13a). | Not run |
| Bedrock | Nothing written inside the plugin directory; `DISALLOW_FILE_MODS` honored; updates arrive by deploy. | Not run |
| Cloudflare APO | After Switch Off, the footer link appears on a cached page once the bypass rule is in place (SW-25); `CF-Connecting-IP` recorded only from Cloudflare ranges (LOG-5). | Not run |
| BLT Secure active | Custom login slug for every switch link, no two-factor prompt on switch or switch back (SW-2). | Not run |
| BLT M365 WP SSO active | Switched session not ended by SSO session checks; SSO-created users get additional default roles (US-2). | Not run |
| WooCommerce | Switch to customer from the order screen, Switch back in My Account, no cart or session leak (SW-22). | Not run |
| ACF and CPT UI post types | ACF-style types listed as "Controlled by: Change settings" (RS-11); CPT UI types grouped with Posts until isolated (RO-14); isolation round trip (RS-30). | Not run |
| Bricks templates | Isolation of `bricks_template` does not break Bricks' access checks, or the type is excluded (RS-35). | Not run |
| Gravity Forms capabilities present | Capabilities appear under Plugins (detected) in Advanced and in "Other plugin permissions" in Simple (RO-13); not flagged as orphans while Gravity Forms is active (RO-11). | Not run |
| Seeded site with 10,000 users | Users screen render time with switch and rename row actions within 10% of the plugin being inactive (SEC-9); no users-without-role count on page load (US-12). | Not run |

## Open decisions

- [ ] D4: confirm the menu placement (top-level BLT Toolkit with per-submenu capabilities, plus Users > Roles) against `DESIGN.md`, and the family's admin menu position (CONVENTIONS 17, item 16).
- [ ] D14: confirm BLT Tube's front-end token prefix so `--blt-tk-*` and `.blt-tk-*` cannot collide.
- [ ] Repository location: releases must be published at `S-FX-com/BLT-Toolkit`, where the update checker looks, or the repository transferred there (`tasks/lessons.md`).
- [ ] Add BLT Toolkit to the family list in `DESIGN.md` and port that change to every BLT repo in the same pass (CONVENTIONS 16.1 step 7). `DESIGN.md` is vendored, so it is not edited here alone.
- [ ] Family drift: BLT Events' `blt-design-system.css` and its plugin-update-checker (v5p6) differ from the canonical copies (`tasks/lessons.md`).
