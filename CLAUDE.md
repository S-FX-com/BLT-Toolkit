# BLT Toolkit

One plugin that replaces User Switching, Username Changer and User Role Editor on S-FX-managed sites: **switch** to another user and back, **change** a username, and **edit roles and capabilities** in a Simple (per content type) or Advanced (capability matrix) view. One security model (the Guard), one activity log, no external services.

Three documents govern this repo. Read them before writing code; this file does not restate them.

| File | What it decides |
| --- | --- |
| `SPEC.md` | Every BLT Toolkit requirement, by ID (`SW-4`, `RS-12`, `SEC-2`). Phase order and acceptance criteria are in SPEC 17. |
| `CONVENTIONS.md` | Family engineering rules (vendored, byte-identical, never edit here). |
| `DESIGN.md` | Brand, admin UI catalog, shared settings layer, update policy (vendored, byte-identical, never edit here). |

Precedence: `DESIGN.md` wins on brand, admin UI, shared settings and updates; `CONVENTIONS.md` wins on everything else; `SPEC.md` adds plugin-specific rules. A conflict between SPEC and DESIGN is resolved in DESIGN's favor and written up in `tasks/lessons.md`. A **Should** that is not followed needs a written reason in `tasks/lessons.md`.

## Hard constraints

- **Single site only in v1** (SPEC D1). Activation is refused on multisite; if the plugin somehow runs on a network it boots nothing and shows a notice.
- **PHP 7.4 syntax floor, WordPress 6.3 floor.** No PHP 8 syntax (no `match`, named arguments, nullsafe `?->`, union types, constructor promotion, `str_contains`). PHPCompatibilityWP enforces it.
- **Zero runtime Composer dependencies.** `composer.json` is dev tooling only.
- **No heavy work on page load.** Anything on `init`, `map_meta_cap`, `user_has_cap`, `pre_get_users` or a list-table row gets a "what does this cost per request?" pass (SEC-9). Front-end page views by visitors who are not switched run no queries from this plugin.
- **Never write roles on a read path** (RO-5). Rendering a screen changes nothing.
- **Never declare `switch_to_user()`, `switch_off_user()` or `user_switching_*()`** (GEN-2). The only unprefixed global symbol is the `current_user_switched()` shim (SW-19), defined only when undefined.
- **Do not port the defects in SPEC 19.** Each row names the rule that prevents it.
- Reimplement from SPEC rather than copying the source plugins. A block that is genuinely ported keeps its original copyright line in the file header, and the original authors are credited in `readme.txt`.

## Architecture

Module pattern from BLT Secure (CONVENTIONS 6.1). Three modules, `switch`, `usernames` and `roles` (SPEC 4.1), sit on shared services that always load. A disabled module (setting, `BLT_TOOLKIT_DISABLE_MODULES`, or a legacy plugin conflict per GEN-1) loads no code beyond its registry entry and registers no hooks. The Guard and the Log always load, because protection must hold with every module off.

### Boot order (SPEC 4.2)

1. `blt-toolkit.php`: header, constants, family bootstrap (`groups => array( 'github' )`), update checker (`24`), computed autoloader, activation hooks, then **one** extra line: `add_filter( 'register_post_type_args', ... )` for separate CPT permissions (RS-30, RS-31). It is the only exception to "the main file owns exactly four things": some plugins register post types before `plugins_loaded`, so a filter added any later would miss them. The callback must return immediately when no type is isolated.
2. `plugins_loaded` priority 1: `BLT_Toolkit_Plugin::boot()` loads settings, boots the Guard and Log, resolves module state, boots enabled modules.
3. `init`: `BLT_Toolkit_Schema::maybe_upgrade()`, switch request handling (SW-2), recovery mode (SEC-14).
4. `init` priority `PHP_INT_MAX`: isolation verification (RS-33).

### Shared-service contract

These are the public entry points modules build on. Keep the signatures stable; extend rather than rename. Static classes unless noted.

| Class | Entry points |
| --- | --- |
| `BLT_Toolkit_Plugin` | `boot()`, `activate( $network_wide )`, `deactivate()`, `modules()` (key => class, filter `blt_toolkit_modules`), `module_status( $key )` returning `on`, `off`, `conflict` or `disabled` (by constant), `is_active( $key )`, `module( $key )` (booted instance or null) |
| `BLT_Toolkit_Module` (interface) | `key()`, `label()`, `description()`, `boot()` |
| `BLT_Toolkit_Settings` | `defaults()` (SPEC 14.1 exactly), `all()`, `get( $group, $key = null )`, `save_tab( $tab, array $raw )` (hand-sanitized, returns the sanitized group), `set( $group, $key, $value )` (internal writes such as `role_meta`, sanitized per key), `github_token()` (constant, then encrypted `blt_toolkit_secrets`, then the shared `github` group), `set_github_token( $token )` |
| `BLT_Toolkit_Schema` | `VERSION`, `install()`, `maybe_upgrade()`, `table( 'log' or 'snapshots' )` |
| `BLT_Toolkit_Crypto` | Instance: `is_available()`, `encrypt( $plaintext )`, `decrypt( $envelope )` (`WP_Error` on tamper). Copied from BLT Secure's envelope: libsodium, then OpenSSL AES-GCM, never a base64 tier. |
| `BLT_Toolkit_Caps` | `own()` (cap => tier, SPEC 5.1), `grant_admin()`, `tiers()` (filter `blt_toolkit_risky_caps`), `tier( $cap )` returning `critical`, `elevated` or `standard`, `lockout_critical()` (filter `blt_toolkit_lockout_critical_caps`), `meta()` |
| `BLT_Toolkit_Guard` | `register()`, `is_toolkit_admin( WP_User $user )`, plus the SEC checks used by write paths (Phase 1) |
| `BLT_Toolkit_Superset` (pure) | SEC-8 subset logic (Phase 1) |
| `BLT_Toolkit_Role_Diff` (pure) | `canonicalize( array $roles )`, `hash( array $roles )`, `diff( array $a, array $b )` |
| `BLT_Toolkit_Snapshots` | `take( $reason )` (skips when the hash equals the newest, returns that ID), `baseline()`; list, restore and prune arrive in Phase 1 and 6 |
| `BLT_Toolkit_Log` | `record( array $row )` (Phase 1) |
| `BLT_Toolkit_Jobs` | Job runner per SPEC 6.3 (Phase 3) |

### Pure classes

`BLT_Toolkit_Superset`, `BLT_Toolkit_Role_Diff`, `BLT_Toolkit_Simple_Mapper`, `BLT_Toolkit_Nicename`, `BLT_Toolkit_Rename_Planner`, `BLT_Toolkit_Csv`, the username validator's rules and the switch cookie stack operations take plain arrays and strings and call no WordPress function that touches the database, cookies or globals. Lookups (does this username exist, what does this post type's `cap` object hold) are injected. These are the classes `tests/` covers with known vectors (SPEC 18).

## Conventions specific to this repo

- Classes `BLT_Toolkit_<Thing>` in `includes/class-blt-toolkit-<thing>.php`, admin classes in `admin/class-blt-toolkit-admin-<thing>.php`, flat (the autoloader probes `includes/` then `admin/`). Views in `admin/views/`.
- Machine identifiers are exactly as SPEC 3 lists them: `blt_toolkit_*` for functions, hooks, options and user meta; `blt_<verb>_<noun>` for capabilities; `.blt-tk-*` and `--blt-tk-*` on the front end; `bltToolkit` for the localized JS global; REST namespace `blt-toolkit/v1` (`BLT_TOOLKIT_REST_NS`).
- Writes are POST to `admin-post.php` with nonce, capability check and PRG, or REST with a `permission_callback`. The switch links are the only GET actions (SEC-7).
- Every authorization decision goes through `map_meta_cap`, `editable_roles` or the Guard, never through inspecting `$_GET` on a particular screen (SPEC 19, URE-1).
- Reference requirement IDs in commit messages, `tasks/todo.md` and test names.

## Commands

```
composer install      # dev deps only: phpcs, WPCS, PHPCompatibility, PHPUnit
composer lint         # php -l on every file
composer phpcs        # WordPress-Extra + PHPCompatibilityWP
composer test         # PHPUnit, no WordPress install
node bin/make-pot.js  # regenerate languages/blt-toolkit.pot (CI fails on drift)
bin/dev-site.sh       # disposable WordPress on SQLite for smoke tests (see the script header)
```

Run `composer lint` and `composer test` before committing, `composer phpcs` before pushing.

## Testing

Pure logic is unit-tested against known vectors with `tests/bootstrap.php` shimming the small WordPress surface used. WordPress-coupled behavior is smoke-tested against a real install (`bin/dev-site.sh`) and recorded in `tasks/todo.md`, which holds the SPEC 17 acceptance criteria and the SPEC 18 smoke matrix.

## Files

- `tasks/todo.md`: phase checklists with acceptance criteria. Keep it current.
- `tasks/lessons.md`: gotchas and recorded decisions. Append; never rewrite history.
- `HOOKS.md`: every public action and filter, seeded from SPEC 16.

## Non-goals

SPEC 20. Keep the plugin narrow.
