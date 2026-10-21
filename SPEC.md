# BLT Toolkit: Build Specification

One plugin that replaces **User Switching**, **Username Changer** and **User Role Editor** on S-FX-managed sites, rebuilt to the BLT family standard, with a new **Simple / Advanced** permissions editor designed around custom post types.

| | |
| --- | --- |
| Status | Draft 1.0, October 21, 2026 |
| Display name / slug | BLT Toolkit / `blt-toolkit` |
| Repository | `S-FX-com/BLT-Toolkit` |
| Governed by | `CONVENTIONS.md` (Draft 1.0, October 13, 2026) and `DESIGN.md`. This spec adds plugin-specific rules only. Where it is silent, they decide. Where it conflicts with `DESIGN.md` on brand, admin UI, shared settings or updates, `DESIGN.md` wins and the conflict gets flagged in `tasks/lessons.md`. |
| Source plugins reviewed (code, not just readmes) | User Switching 1.12.2 (John Blackbourn), Username Changer 3.3.1, User Role Editor 4.66.2 (Vladimir Garagulya). All GPL-2.0-or-later. |

---

## 0. How to use this document

1. Commit this file to the repo root as `SPEC.md`. `CLAUDE.md` points at `SPEC.md`, `CONVENTIONS.md` and `DESIGN.md` and does not restate them.
2. Every requirement has an ID (`SW-4`, `RS-12`). Reference IDs in commit messages, `tasks/todo.md` and test names.
3. Build in the phase order in section 17. A phase is done when its acceptance criteria pass. Do not start the next phase with a failing criterion.
4. **Must** is a hard requirement. **Should** is the default unless there is a written reason in `tasks/lessons.md`. **May** is optional.
5. Reimplement from this spec rather than copying source files. The source plugins are GPL-2.0-or-later, so porting is permitted; any block that is ported keeps its original copyright line in the file header, and the original authors are credited in `readme.txt`.
6. Section 19 lists defects found while reading the source plugins. **Do not port them.**

---

## 1. What it is

BLT Toolkit gives site administrators three user-management tools in one place, under one security model and one activity log: **switch** to another user's account and back, **change** a user's username, and **create and edit roles and capabilities**. The role editor has two views of the same data. **Simple** shows each content type (Posts, Pages and every custom post type) as one plain-English access level such as *View*, *Author (own only)* or *Edit all*, plus site-wide toggles grouped by area. **Advanced** is the full capability matrix. Everything works with no external service, no Cloudflare and no credentials.

### 1.1 What works out of the box (seed for README)

| Area | Default behavior |
| --- | --- |
| Switch | Administrators can switch to any non-administrator and back. "Switch To" appears on the Users list and profiles; "Switch back" appears in the toolbar, notices, the login screen and a fixed link on the site. |
| Usernames | Administrators can change any username from the user's profile. Changes take effect immediately, including on sites with a persistent object cache. Old author URLs redirect. |
| Roles | Roles screen opens in Simple view. Every save is snapshotted and can be restored. |
| Protection | Non-administrators can never edit, delete, rename, re-role or switch into an administrator, and can never grant a capability they do not hold. |
| Activity log | Every switch, rename and role change is logged with who did it, including "acting as" when the change was made from a switched session. |
| Self-service, bulk rename, separate CPT permissions | Off until enabled. |

---

## 2. Pre-flight answers (CONVENTIONS 16.2)

| Question | Answer |
| --- | --- |
| External services? | None. The plugin is fully local. No Worker, no Cloudflare code. |
| Credentials held | Only the optional GitHub update token, handled exactly as the family does: `BLT_TOOLKIT_GITHUB_TOKEN` constant, then the plugin's own encrypted option, then the shared `github` group (opt-in). Declare `groups => array( 'github' )`. Requires a `BLT_Toolkit_Crypto` class copied from **BLT Secure's** envelope (libsodium, then OpenSSL AES-GCM). Do not copy Documents' base64 tier (CONVENTIONS 17, item 2). |
| Capabilities and roles | Section 5.1. No role is created on activation. An optional "Support Agent" role is created only when the owner clicks a button (SW-21). |
| Uninstall | Deletes nothing unless "Delete all plugin data" is enabled. Never reverts role edits the owner made. Never touches the shared store. Section 14.6. |
| Multisite | **Deferred.** v1 refuses activation on multisite with a clear notice. Username changes are network-wide by nature (shared users table) and role editing per site needs its own design. See decision D1. |
| Long-running jobs | Bulk username rename, moving users off a role before delete or merge, log pruning, counting users without a role. Section 6.3. |
| Public surface | No front-end output except the "Switch back" link while switched and an optional self-service username form shortcode. REST namespace `blt-toolkit/v1` for admin typeahead, live validation and job progress only. |
| v1 non-goals | Section 20. |

---

## 3. Identity

| Thing | Value |
| --- | --- |
| Display name | BLT Toolkit |
| Slug, folder, text domain, top-level menu slug | `blt-toolkit` |
| Main file | `blt-toolkit.php` |
| Constants | `BLT_TOOLKIT_VERSION`, `_FILE`, `_DIR`, `_URL`, `_BASENAME`, `_REST_NS` (`blt-toolkit/v1`) |
| Classes | `BLT_Toolkit_<Thing>`, no namespace, files `includes/class-blt-toolkit-<thing>.php` and `admin/class-blt-toolkit-admin-<thing>.php` |
| Functions, hooks, filters | `blt_toolkit_*` |
| Options | `blt_toolkit_settings`, `blt_toolkit_db_version`, `blt_toolkit_secrets` |
| Tables | `{prefix}blt_toolkit_log`, `{prefix}blt_toolkit_snapshots` |
| Capabilities | `blt_<verb>_<noun>` (section 5.1) |
| Front-end CSS classes / tokens | `.blt-tk-*` / `--blt-tk-*` (two letters, because a single `t` may collide with BLT Tube; see D14) |
| JS localized global | `bltToolkit` |
| Plugin header | `Requires at least: 6.3`, `Requires PHP: 7.4`, `Network: false`, Author exactly `S-FX.com`, Author URI `https://www.s-fx.com`, License `GPL-2.0-or-later` |
| Reason for raising the WordPress floor to 6.3 | Parity with the switching code being reimplemented (User Switching 1.12.2 requires 6.3). Avoids guards around `switch_to_user_locale()` (6.2), `did_filter()` (6.1) and the command palette (6.3). |

---

## 4. Architecture

### 4.1 Modules and shared services

The plugin uses the module pattern from BLT Secure (CONVENTIONS 6.1): an interface `BLT_Toolkit_Module`, a registry booted on `plugins_loaded` priority 1, and a filterable list `blt_toolkit_modules`.

| Module | Key | Default | Depends on |
| --- | --- | --- | --- |
| Switch | `switch` | On | Guard, Log |
| Usernames | `usernames` | On | Guard, Log, Jobs |
| Roles | `roles` | On | Guard, Log, Snapshots, Jobs |

Shared services that every module uses and no module owns:

| Service | Class | Purpose |
| --- | --- | --- |
| Settings | `BLT_Toolkit_Settings` | One array option merged over defaults, hand-sanitized in `save()`. Never `register_setting`. |
| Schema | `BLT_Toolkit_Schema` | `dbDelta`, gated on `blt_toolkit_db_version`, re-checked on `init` (`maybe_upgrade`). |
| Capabilities | `BLT_Toolkit_Caps` | The plugin's own capabilities, activation grants, risk tiers, lockout-critical set. |
| Guard | `BLT_Toolkit_Guard` | Every authorization rule in section 5.3, in one place. |
| Superset | `BLT_Toolkit_Superset` | Pure logic: "does user A hold every capability user or role B holds?" |
| Activity log | `BLT_Toolkit_Log` | Section 6.1. |
| Snapshots | `BLT_Toolkit_Snapshots` + `BLT_Toolkit_Role_Diff` (pure) | Section 6.2. |
| Jobs | `BLT_Toolkit_Jobs` | Section 6.3. |
| Legacy | `BLT_Toolkit_Legacy` | Coexistence detection and read-only importers (section 15). |

A disabled module loads no code beyond its registry entry, registers no hooks and creates nothing. The Guard and Log always load, because protection must hold even with every module off.

### 4.2 Boot order

1. Main file: header, constants, family bootstrap (`blt_family_register` with `groups => array( 'github' )`), update checker (`$checkPeriod` = `24`), computed prefix autoloader, then `add_filter( 'register_post_type_args', ... )` for post type isolation (RS-30). That one filter is added at file load, not on `plugins_loaded`, because some plugins register post types early. It is the only exception to "the main file owns exactly four things", and the reason goes in `CLAUDE.md`.
2. `plugins_loaded` priority 1: registry reads `blt_toolkit_settings['modules']`, applies `BLT_TOOLKIT_DISABLE_MODULES` (section 5.5), checks legacy plugins (section 4.4), boots enabled modules.
3. `init`: `maybe_upgrade`, switch request handling (SW-2), recovery mode check (SEC-14).
4. `init` priority `PHP_INT_MAX`: isolation verification (RS-33).

### 4.3 Repository layout

The standard layout from CONVENTIONS 5 applies, plus `SPEC.md`. Plugin-specific files (flat, so the computed autoloader that probes `includes/` then `admin/` keeps working; the interface lives in `class-blt-toolkit-module.php`):

```
includes/
  class-blt-toolkit-plugin.php              Registry and boot
  class-blt-toolkit-module.php              Interface
  class-blt-toolkit-settings.php
  class-blt-toolkit-schema.php
  class-blt-toolkit-crypto.php              Copied from BLT Secure
  class-blt-toolkit-caps.php
  class-blt-toolkit-guard.php
  class-blt-toolkit-superset.php            Pure
  class-blt-toolkit-log.php
  class-blt-toolkit-snapshots.php
  class-blt-toolkit-role-diff.php           Pure
  class-blt-toolkit-jobs.php
  class-blt-toolkit-legacy.php
  class-blt-toolkit-rest.php
  class-blt-toolkit-privacy.php
  class-blt-toolkit-site-health.php
  class-blt-toolkit-switch.php              Module
  class-blt-toolkit-switch-cookies.php      Cookie stack (stack ops pure, I/O separate)
  class-blt-toolkit-switch-redirects.php
  class-blt-toolkit-switch-ui.php           Row actions, profile, toolbar, notices, footer link, login message, wp_die
  class-blt-toolkit-switch-woocommerce.php  Loaded only when WooCommerce is active
  class-blt-toolkit-usernames.php           Module
  class-blt-toolkit-username-validator.php  Rules pure, lookups injected
  class-blt-toolkit-username-changer.php    The rename procedure (UN-10)
  class-blt-toolkit-nicename.php            Pure, mirrors wp_insert_user()
  class-blt-toolkit-rename-planner.php      Pure, bulk ordering and conflicts
  class-blt-toolkit-csv.php                 Pure
  class-blt-toolkit-username-profile.php    Profile screen integration
  class-blt-toolkit-author-redirects.php
  class-blt-toolkit-username-shortcode.php
  class-blt-toolkit-roles.php               Module
  class-blt-toolkit-role-store.php          Read, lock, batch-write roles
  class-blt-toolkit-capability-catalog.php  Discover caps, sources, groups, labels
  class-blt-toolkit-simple-controls.php     Control registry for Simple view
  class-blt-toolkit-simple-mapper.php       Pure: caps <-> levels
  class-blt-toolkit-isolation.php           Separate permissions for CPTs
  class-blt-toolkit-readonly.php            View-level enforcement
  class-blt-toolkit-user-roles.php          Multi-role, defaults, users without a role
  class-blt-toolkit-user-caps.php           Per-user capability overrides
  class-blt-toolkit-role-lifecycle.php      Add, rename, delete, merge, reset
  class-blt-toolkit-role-transfer.php       Export and import
admin/
  class-blt-toolkit-admin.php               Menu, page-gated enqueue, PRG router
  class-blt-toolkit-admin-overview.php
  class-blt-toolkit-admin-roles.php
  class-blt-toolkit-admin-usernames.php
  class-blt-toolkit-admin-activity.php
  class-blt-toolkit-admin-settings.php
  class-blt-toolkit-admin-tools.php
  views/
assets/css/blt-design-system.css            Vendored, byte-identical
assets/css/blt-toolkit-admin.css            Layout the catalog does not cover, only
assets/css/blt-toolkit-front.css            Switch-back link and username form
assets/js/blt-toolkit-roles.js              Vanilla JS, unminified source shipped
assets/js/blt-toolkit-usernames.js
assets/js/blt-toolkit-switch-search.js
assets/js/blt-toolkit-command-palette.js
assets/js/blt-toolkit-jobs.js
templates/username-form.php                 Theme-overridable at your-theme/blt-toolkit/username-form.php
```

### 4.4 Coexistence with the source plugins

- **GEN-1** If User Switching (`class_exists( 'user_switching' )`), Username Changer (`function_exists( 'username_changer' )`) or User Role Editor (`class_exists( 'URE_Lib' )`) is active, the matching BLT module **must not boot**. Its Overview card shows a "Conflict" badge and a sentence naming the plugin to deactivate, with a link to Plugins. The other modules boot normally.
- **GEN-2** BLT Toolkit must never declare `switch_to_user()`, `switch_off_user()` or `user_switching_*()`. Those global names belong to User Switching. See SW-19 for the one compatibility shim.
- **GEN-3** Roles live in WordPress's own `{prefix}user_roles` option, so moving from User Role Editor to BLT Toolkit loses no role data. Section 15 covers the settings that do need importing.

---

## 5. Security model

### 5.1 Capabilities

Granted to the `administrator` role on activation. Granted to nobody else unless the owner does it.

| Capability | Lets the holder | Tier |
| --- | --- | --- |
| `blt_switch_users` | Switch to users (subject to SEC rules) and switch off | Critical |
| `blt_change_usernames` | Change other users' usernames | Critical |
| `blt_change_own_username` | Change their own username (self-service) | Standard |
| `blt_edit_roles` | Open the Roles screen and edit roles in **Simple** view | Critical |
| `blt_edit_capabilities` | Use **Advanced** view, add and delete capabilities, edit per-user capabilities | Critical |
| `blt_create_roles` | Add roles | Critical |
| `blt_delete_roles` | Delete and merge roles | Critical |
| `blt_reset_roles` | Reset to defaults, restore snapshots, import roles | Critical |
| `blt_view_toolkit_log` | View and export the activity log | Elevated |

Settings, module toggles and legacy imports require `manage_options` (CONVENTIONS 6.3).

Meta capabilities, mapped in `map_meta_cap` and never granted directly:

| Meta capability | Arg | Maps to |
| --- | --- | --- |
| `blt_switch_to_user` | target user ID | `blt_switch_users`, plus SEC-1 to SEC-5. `do_not_allow` when the target is the current user or does not exist. |
| `blt_change_username` | target user ID | Self: `blt_change_own_username` plus the cooldown (UN-22). Others: `blt_change_usernames` plus SEC-1 to SEC-5. |

**Deliberate difference from User Switching:** User Switching lets anyone who can `edit_user` the target switch into them. BLT Toolkit requires the explicit `blt_switch_users` capability. A role that gains `edit_users` through some unrelated customization must not silently gain impersonation. See D3.

### 5.2 Who counts as an administrator

`BLT_Toolkit_Guard::is_toolkit_admin( WP_User $user )` returns true when the user has the `administrator` role on this site. Filterable through `blt_toolkit_is_toolkit_admin`. It checks role membership, not capabilities, so it still answers correctly after someone damages the administrator role's capabilities (SEC-14 relies on this).

### 5.3 Guard rules

These apply to every entry point: admin screens, `admin-ajax.php`, `admin-post.php`, REST (core and BLT), WP-CLI and anything calling `current_user_can()`. They are enforced through `map_meta_cap`, `editable_roles` and the Guard's own checks inside BLT write paths, never by inspecting `$_GET` on particular screens (see section 19, URE-1).

| ID | Rule |
| --- | --- |
| SEC-1 | **Protect administrators.** If the target user is a toolkit admin and the actor is not, `map_meta_cap` adds `do_not_allow` for `edit_user`, `delete_user`, `remove_user`, `promote_user`, `blt_switch_to_user` and `blt_change_username`. |
| SEC-2 | **Never act with more power than you have.** A non-admin actor may not switch into, rename, or edit the roles or capabilities of a user whose effective capabilities are not a subset of the actor's. Checked with `BLT_Toolkit_Superset` (SEC-8). |
| SEC-3 | **Never grant what you don't hold.** A non-admin actor may not add a capability to any role or user that the actor lacks, may not copy a role whose capabilities exceed theirs, and may not import a role that would do either. |
| SEC-4 | **Assignable roles are subsets.** For non-admin actors, filter `editable_roles` to remove every role whose capabilities are not a subset of the actor's. The filter only ever removes roles, never adds them. Core uses this list for the role dropdowns, the Users bulk "Change role to", user creation and the REST users endpoint (`check_role_update()`), so one filter covers them all. It also covers BLT's own add-role and grant-role actions. |
| SEC-5 | **No editing your own role.** A non-admin actor may not change the capabilities of any role they hold, and may not add or remove their own roles. |
| SEC-6 | **Hide administrators from non-administrators** in the Users list and the REST users collection (setting, default on, URE parity; D5). Implement with `pre_get_users` scoped to the Users list table and `rest_user_query`, and adjust the role views and counts in `views_users`. |
| SEC-7 | **Nonce and capability on every action.** Writes are POST to `admin-post.php` with PRG, or REST with `permission_callback`. The switch links (SW-2) are the only GET actions and carry per-target nonces, like core's logout link. |
| SEC-8 | **Superset semantics.** Compare effective capabilities (`WP_User::$allcaps`, which merges role and user capabilities) where the value is `true`. Ignore keys that are role names. Ignore `do_not_allow`. A capability explicitly set `false` on the target does not count as held. For roles, compare the role's capability array the same way. |
| SEC-9 | **Performance.** The `map_meta_cap` callback must return immediately for any capability outside a small hash set. On the Users list, cache superset results per (role set + per-user caps hash) within the request; never compute per row from scratch. |

### 5.4 Risk tiers

`BLT_Toolkit_Caps::tiers()`, filterable through `blt_toolkit_risky_caps`.

| Tier | Capabilities |
| --- | --- |
| Critical (can take over the site or its users) | `manage_options`, `activate_plugins`, `install_plugins`, `update_plugins`, `delete_plugins`, `edit_plugins`, `install_themes`, `update_themes`, `delete_themes`, `edit_themes`, `update_core`, `unfiltered_html`, `unfiltered_upload`, `edit_users`, `create_users`, `delete_users`, `remove_users`, `promote_users`, and every Critical `blt_*` capability in 5.1 |
| Elevated | `list_users`, `export`, `import`, `edit_theme_options`, `switch_themes`, `moderate_comments`, `manage_categories`, any `edit_others_*`, `delete_others_*`, `blt_view_toolkit_log` |
| Standard | Everything else |

- **SEC-10** Granting a Critical capability to any role other than `administrator`, or to any user, requires a review step with the word "Critical" and the plain-English consequence, plus a typed confirmation of the role's display name.
- **SEC-11** Tier badges appear next to every control in both Simple and Advanced views.

### 5.5 Lockout protection and recovery

- **SEC-12** The `administrator` role is read-only in Simple view. In Advanced view the lockout-critical set cannot be removed from it: `read`, `manage_options`, `list_users`, `edit_users`, `promote_users`, `activate_plugins`, `blt_edit_roles`, `blt_edit_capabilities` (filter `blt_toolkit_lockout_critical_caps`). The `administrator` role cannot be deleted and its ID cannot change.
- **SEC-13** Self-lockout check: a save is rejected if it would leave the actor without `blt_edit_roles`, unless another toolkit admin exists and the actor confirms.
- **SEC-14** Escape hatch (CONVENTIONS 6.3): `define( 'BLT_TOOLKIT_RECOVERY', true );` in `wp-config.php`. On the next admin request by a user who has the `administrator` role, take a snapshot, re-grant the lockout-critical set and every `blt_*` capability to `administrator`, log it, and show a persistent notice until the constant is removed. It changes nothing else.
- **SEC-15** `define( 'BLT_TOOLKIT_DISABLE_MODULES', 'switch,usernames' );` disables the named modules regardless of settings (incident response). The Guard and Log still load.

---

## 6. Shared services

### 6.1 Activity log

- **LOG-1** One table for all modules (section 14.3). Each row records module, action, actor, "acting as", object, a one-line human summary, a JSON details blob (before/after, diff, chain of switched users), an optional snapshot ID, IP and status.
- **LOG-2** Actor attribution while switched: `actor_id` is the **first** user in the switch stack (the human who actually logged in). `acting_as_id` is the current user. The full chain goes in details. When not switched, `acting_as_id` is 0.
- **LOG-3** Writes never block the action they record. A failed insert is reported through `error_log()` and a Site Health warning, not to the user mid-action.
- **LOG-4** Retention: 365 days (setting; filter `blt_toolkit_log_retention_days`). A daily WP-Cron event prunes in batches of 1,000 rows as a job (6.3).
- **LOG-5** IP recording is a setting (default on). IPs come from `REMOTE_ADDR`. Use `CF-Connecting-IP` only when the request arrives from Cloudflare's published ranges (CONVENTIONS 6.3), reusing BLT Secure's range check if it can be vendored without forking the family layer; otherwise `REMOTE_ADDR` only.
- **LOG-6** Fire `do_action( 'blt_toolkit_logged', $row )` after each insert so other logging plugins can listen.

### 6.2 Role snapshots

- **SNAP-1** Before every write to the roles option, store the complete `$wp_roles->roles` array as canonical JSON (keys sorted) with its SHA-256. Skip the insert when the hash equals the newest snapshot's.
- **SNAP-2** On first activation, store a **baseline** snapshot. The baseline is never pruned.
- **SNAP-3** Retention: newest 50 plus the baseline (filter `blt_toolkit_snapshot_retention`).
- **SNAP-4** Tools screen lists snapshots with time, actor, reason and the number of roles and capabilities that differ from the current state. Each can be viewed as a diff and restored. Restore is a dry-run diff, then confirm, then a fresh snapshot of the current state, then the write.
- **SNAP-5** `BLT_Toolkit_Role_Diff` is pure: `canonicalize()`, `hash()`, `diff( $a, $b )` returning roles added, removed and changed, and per role the capabilities added, removed and flipped (`true` to `false`), plus display name changes.

### 6.3 Jobs

Per CONVENTIONS 10.

- **JOB-1** Runner: Action Scheduler when `as_enqueue_async_action` exists, otherwise chained single WP-Cron events plus an immediate loopback request. While the job's screen is open, `POST blt-toolkit/v1/jobs/{id}/tick` also drives ticks.
- **JOB-2** Ticks are time-boxed by `blt_toolkit_tick_budget` (default 15 seconds). State persists in `blt_toolkit_job_{id}` (`autoload = no`) every few items.
- **JOB-3** Pin settings at start (for example "email the user" and "sign out sessions" for a bulk rename) so changing a toggle mid-run cannot split behavior.
- **JOB-4** Progress UI: bar, current item, done/total, elapsed, estimate, per-item warnings. Survives closing the tab. Cancel keeps what finished. Failed items are skipped with a reason and offered a "Retry failed" pass. Stall detection falls back to foreground ticking with a "keep this tab open" notice.
- **JOB-5** Job types: `username_bulk` (UN-30), `role_reassign` (RO-41), `log_prune` (LOG-4), `count_no_role` (US-12).

### 6.4 Settings

The full schema is in section 14.1. Rules from CONVENTIONS 6.2 apply: one array, merged over defaults, empty is not unset, sanitized by hand in `save()`. Constants override options where a constant exists.

---

## 7. Switch module

Parity target: User Switching 1.12.2. The mechanism (a JSON stack of auth cookies plus a logged-in-scheme "old user" cookie, all generated and validated with core's `wp_generate_auth_cookie()` and `wp_validate_auth_cookie()`) is sound and should be reproduced faithfully. What changes is naming, the permission model, styling, logging and a few additions.

### 7.1 Requests and cookies

- **SW-1** Permission: `current_user_can( 'blt_switch_to_user', $target_id )` (5.1). Switch off requires `blt_switch_users`.
- **SW-2** All three actions route through `wp_login_url()` so they work when logged out (after switch off), are never page-cached, and respect login URL filters (for example a custom login slug from BLT Secure). Handled on `init`:

| Action param | Other params | Nonce action |
| --- | --- | --- |
| `blt_switch_to_user` | `user_id`, optional redirect params | `blt_switch_to_user_{user_id}` |
| `blt_switch_back` | optional redirect params | `blt_switch_back_{original_user_id}` |
| `blt_switch_off` | optional redirect params | `blt_switch_off` |

- **SW-3** Cookies (names overridable through constants for parity with User Switching's):

| Cookie | Constant | Content | Path |
| --- | --- | --- | --- |
| `wordpress_blt_sw_{COOKIEHASH}` | `BLT_TOOLKIT_SWITCH_COOKIE` | JSON array (stack) of `auth`-scheme cookies for each originating user | `SITECOOKIEPATH` |
| `wordpress_blt_sw_secure_{COOKIEHASH}` | `BLT_TOOLKIT_SWITCH_SECURE_COOKIE` | Same, `secure_auth` scheme, used when `secure_auth_cookie` applies | `SITECOOKIEPATH` |
| `wordpress_blt_sw_olduser_{COOKIEHASH}` | `BLT_TOOLKIT_SWITCH_OLDUSER_COOKIE` | `logged_in`-scheme cookie for the most recent originating user | `COOKIEPATH` |

All `HttpOnly`, `SameSite=Lax` (setcookie options array), `Secure` following core's `secure_auth_cookie` and `secure_logged_in_cookie` filters. The `wordpress_` prefix is deliberate: many hosts and page caches already bypass on `wordpress_*` cookies. Filter `blt_toolkit_switch_send_cookies` (default `true`) suppresses sending, for tests and headless setups.

### 7.2 Flows

- **SW-4 Switch to:**
  1. Check SW-1 and the nonce. Load the target; 404 if missing.
  2. Duplicate check (SW-8). Unless `force=1`, show a 409 confirmation page.
  3. Capture the actor's ID and session token. Push an `auth` cookie for the actor onto the stack and set the old-user cookie.
  4. Attach `blt_switched_from_id` and `blt_switched_from_session` to the target's new session through `attach_session_information` (added and removed around the call), and update the actor's own session with `blt_switched_into` and `blt_switched_at` through `WP_Session_Tokens::update()`.
  5. `wp_clear_auth_cookie()`, `wp_set_auth_cookie( $target_id, $remember )`, `wp_set_current_user( $target_id )`.
  6. Fire `blt_toolkit_switched_to` (and compat, SW-19). Log.
  7. Redirect (SW-9) with `blt_switched=1`; default `admin_url()` if the target can `read`, otherwise `home_url()`.
- **SW-5 Switch back:** validate the old-user cookie, then authenticate it against the newest stack entry (both must validate, as in User Switching's `authenticate_old_user()`). Pop the stack. Restore the original session token so the actor returns to the same session. Destroy the session that was created for the target. Clear `blt_switched_into` from the actor's session. Fire, log, redirect (default `users.php`).
- **SW-6 Switch off:** push the actor onto the stack, clear auth cookies, set current user to 0. The login screen and the site footer then offer "Switch back to {name}".
- **SW-7 Remember me:** inherit persistence from the actor's current cookie (compare its remaining lifetime with the default `auth_cookie_expiration`), as User Switching's `remember()` does.
- **SW-8 Duplicate switch:** if any of the target's sessions carries `blt_switched_from_id` for a different actor, warn: "{Name} is currently switched to {Target}. They switched {time} ago. Continue?"
- **SW-9 Redirects:** support `redirect_to` (passed through `wp_safe_redirect`), and `redirect_to_post`, `redirect_to_term`, `redirect_to_user`, `redirect_to_comment` resolved to public URLs only when publicly viewable. Apply core's `login_redirect` / `logout_redirect`, then `blt_toolkit_switch_redirect`. When switching off from an admin edit screen, carry the item being edited as context, as User Switching does.
- **SW-10** On `wp_login` and `wp_logout`, clear all switch cookies. On `clear_auth_cookie`, `unset( $_COOKIE[ LOGGED_IN_COOKIE ] )` so later code in the same request does not read a dead session token (a subtle fix User Switching 1.12 carries; keep it).
- **SW-11** Messages addressed to the original user ("Switch back to Shane") render in that user's locale through `switch_to_user_locale()` and `restore_previous_locale()`.

### 7.3 Where switching appears

| ID | Placement | Notes |
| --- | --- | --- |
| SW-12 | Users list row action "Switch To" | Only when `blt_switch_to_user` passes for that row (cached per SEC-9). |
| SW-13 | Profile screen (`personal_options`) | "Switch To" button with an `aria-label` naming the user. |
| SW-14 | Toolbar while switched | Top-level node "Viewing as {Name}" styled with `var(--blt-primary, #2271b1)` (literal fallback allowed because the toolbar renders outside `.blt-ui`), child "Switch back to {Original}". "Switch Off" node for users with `blt_switch_users` (setting, default on). |
| SW-15 | Author archive toolbar | "Switch to {Author}" on author archives, as User Switching does. |
| SW-16 | Admin notice after switching | "Switched to {Name}." with a "Switch back" link; on screens where the switched user is denied (`admin_page_access_denied` or a `wp_die` page), print the switch-back link into the die message. |
| SW-17 | Site footer link | Fixed link "Switch back to {Name}" when the toolbar is not showing, in `blt-toolkit-front.css` (prefixed, tokens only, no JS), enqueued only for switched requests. Setting and filter `blt_toolkit_switch_show_footer_link`. |
| SW-18 | Login screen | `login_message` shows "Switch back to {Name}" when an old-user cookie validates. |
| SW-26 | Command palette | When `wp-commands` is enqueued: "Switch back", "Switch off". |
| SW-27 | REST field | `blt_toolkit_switch_url` on the `user` object, `null` unless the current user may switch to that user. |
| SW-20 | Toolbar search "Switch to…" | Setting (default on). Typeahead calls `GET blt-toolkit/v1/switch/users?search=` (min 2 chars, max 20 results, only users the actor may switch to, each with a server-generated nonced URL). Without JS the node links to the Users screen. |
| SW-28 | Roles screen "Test as…" | Section 12. |

### 7.4 Integrations and compatibility

- **SW-19 Compatibility with plugins that support User Switching** (setting "Compatibility hooks", default on; D2). When on, also fire User Switching's action names with User Switching's signatures: `switch_to_user`, `switch_back_user`, `switch_off_user`, so activity loggers and debugging tools that integrate with User Switching keep working. Define `current_user_switched()` only if no function of that name exists, returning the original `WP_User` or `false`. That shim is the plugin's only unprefixed global symbol; document it in `HOOKS.md`.
- **SW-21 Support Agent role** (button on Settings > Switching, preview first): creates `blt_support_agent` with `read`, `list_users`, `blt_switch_users`. SEC-1 and SEC-2 keep it from switching into anyone more powerful than itself.
- **SW-22 WooCommerce** (loaded only when WooCommerce is active, setting default on): "Switch to customer" on the order screen, "Switch back" in My Account navigation, and forget the WooCommerce session on switch and switch back.
- **SW-23** Call `do_action( 'qm/cease' )` before the duplicate-switch `wp_die()` so Query Monitor does not print a stack trace.
- **SW-24** Register privacy policy text (`wp_add_privacy_policy_content`) stating that switch cookies contain the originating user's username, as core auth cookies do.
- **SW-25** Document for page caches (Cloudflare APO, host caches) that pages must bypass cache when `wordpress_blt_sw_olduser_*` is present, or the footer switch-back link will be missing on cached pages after switch off.

---

## 8. Usernames module

Parity target: Username Changer 3.3.1 including its Pro-gated bulk tool and audit log. The rename procedure is rewritten; see section 19 for why.

### 8.1 Validation

`BLT_Toolkit_Username_Validator::validate( string $new, WP_User $user ): WP_Error`. One validator for every path (profile, bulk, REST check, shortcode). Rules in order, each with its own message:

| ID | Rule |
| --- | --- |
| UN-1 | Trim. If `sanitize_user( $new, true ) !== $new`, reject and show the sanitized suggestion. |
| UN-2 | Length between the minimum (setting, default 3, filter `blt_toolkit_username_min_length`) and 60 (the `user_login` column limit, which core enforces in `wp_insert_user()`). Enforced on the server. |
| UN-3 | `validate_username( $new )`. |
| UN-4 | Not disallowed: setting list merged with core's `illegal_user_logins` filter and `blt_toolkit_disallowed_usernames`. Compare case-insensitively on **both** sides. |
| UN-5 | Not taken: `username_exists( $new )` returns false, or returns this user's own ID (so a case-only change such as `jsmith` to `JSmith` is allowed). |
| UN-6 | Not equal to another user's email address, because WordPress also accepts email at login and the two would be ambiguous. |
| UN-7 | `@` allowed by default (some SSO and membership setups use email-style usernames); setting to disallow it. |
| UN-8 | Not identical to the current username. |
| UN-9 | `apply_filters( 'blt_toolkit_validate_username', $errors, $new, $user )`. |

### 8.2 The rename procedure

- **UN-10** `BLT_Toolkit_Username_Changer::change( int $user_id, string $new, array $options ): array|WP_Error`, in this order:
  1. Authorize with `blt_change_username` for `$user_id` (5.1, SEC rules).
  2. Load and clone the user **before** any write: `$old = get_userdata( $user_id )`. Validate (8.1).
  3. Fire `blt_toolkit_before_username_change`.
  4. `$wpdb->update( $wpdb->users, array( 'user_login' => $new ), array( 'ID' => $user_id ), array( '%s' ), array( '%d' ) )`. Update **by ID**, never by login. Require exactly one affected row.
  5. Author slug (`user_nicename`) per setting UN-13.
  6. `display_name` and the `nickname` meta change only when each exactly equals the old login.
  7. `clean_user_cache( $old )` using the **old** object, so the old login's `userlogins` key and the old nicename's `userslugs` key are purged. Then `clean_user_cache( $user_id )`. Skipping this lets the old username keep resolving, and keep logging in, on any site with a persistent object cache until the cache expires.
  8. Sessions per UN-14.
  9. Fire `blt_toolkit_after_username_change`. Log with old and new values. Email per UN-15.
  10. Return `old_login`, `new_login`, `nicename_changed`, `old_nicename`, `sessions_ended`.
- **UN-11** Multisite is deferred, but write step 5a now so it is not forgotten: if the user's old login is in `site_admins`, replace it in place with the new login and save. Never add-then-leave-old (section 19, UC-7).
- **UN-12** Before the change, warn (and require confirmation) when the target has a session with `blt_switched_from_id` (someone is switched into them) or `blt_switched_into` (they are switched into someone): renaming ends that switched session.

### 8.3 Side effects

- **UN-13 Author slug policy** (setting): *Only when it was generated from the username* (default), *Always*, or *Never*. "Generated from the username" means the current nicename equals what `wp_insert_user()` would have produced from the old login: `sanitize_title()`, truncated to 50 characters, optionally followed by `-2`, `-3`, and so on. The new nicename uses the same algorithm, including the uniqueness suffix. `BLT_Toolkit_Nicename` is pure and unit-tested against core's outputs.
- **UN-13a Old author URLs redirect** (setting, default on). Store prior nicenames in user meta `blt_toolkit_previous_nicenames`. On `template_redirect`, when an author archive request would 404 and its slug matches a stored previous nicename, issue a 301 to the current author URL. A live user's slug always wins over a stored previous one.
- **UN-14 Sessions.** If the target is the current user, re-issue the auth cookie with the **same session token**: `wp_set_auth_cookie( $user_id, $remember, '', wp_get_session_token() )`. The cookie embeds the username, so without this the user is logged out; with it the session continues. For any other user, existing cookies stop validating anyway; setting "Sign the user out everywhere" (default on) calls `WP_Session_Tokens::get_instance( $id )->destroy_all()` so dead sessions don't linger.
- **UN-15 Email** (setting, default off): plain text to the user through `wp_mail()` with site defaults (no hand-built `From:` headers, so SMTP plugins decide). Tags: `{old_username}`, `{new_username}`, `{display_name}`, `{email}`, `{sitename}`, `{siteurl}`, `{loginurl}`. Filter `blt_toolkit_username_email`.

### 8.4 Profile screen integration

No nested forms. The change rides on the profile form's own submit.

- **UN-16** On `show_user_profile` and `edit_user_profile`, when `blt_change_username` passes, render a "Username" section with a "New username" field (`blt_tk_new_user_login`, empty means keep) and a BLT nonce. With JS, move the field into the core Username row behind a "Change" button and run live validation through `GET blt-toolkit/v1/usernames/check` (advisory, debounced). Without JS, the section works as is.
- **UN-17** On `user_profile_update_errors`: if the field is non-empty, verify the nonce, authorize, validate. Add errors to core's `WP_Error` (core then saves nothing and re-renders with the message). On success, stash `user_id => new` in a static property.
- **UN-18** On `profile_update` (after core's save), if the stash has this user, run UN-10 and queue a result notice for the next screen. Clear the stash.
- **UN-19** Users list row action "Change username" links to `user-edit.php?user_id={id}#blt-tk-username`.

### 8.5 Self-service

- **UN-20** Settings > Usernames shows a checkbox per role, "Can change their own username". Saving writes `blt_change_own_username` to those roles (snapshotted and logged like any role change). There is no separate allowed-roles list; the capability is the single source of truth, so Simple view shows it too.
- **UN-21** Cooldown in days (default 0). Last change stored in user meta `blt_toolkit_username_changed_at`.
- **UN-22** "Require current password for self-service" (default off). Note in the setting's help text that users who sign in through SSO may not know a WordPress password.
- **UN-23** Shortcode `[blt_toolkit_username_form]` for front-end self-service: real form, POST to `admin-post.php`, PRG back to the same URL with a result, renders nothing for users without the capability, assets enqueued only when present, template overridable, `Styled` and `No CSS` modes (attribute `style="styled|none"`). Phase 3b.

### 8.6 Bulk rename

- **UN-30** Usernames screen card "Bulk rename from CSV":
  1. **Download template** streams `user_id,username,new_username` for every user, in batches of 500, as a POST with nonce.
  2. **Upload** a CSV: UTF-8 with or without BOM, comma or semicolon delimiter (auto-detected), CRLF or LF. Columns `user_id,new_username` (preferred) or `username,new_username`. Max rows filter `blt_toolkit_bulk_rename_max_rows` (default 5,000).
  3. **Plan** with `BLT_Toolkit_Rename_Planner` (pure): resolve each row to a user ID; validate each new name against the **projected** state, so a name freed by another row becomes available and a name claimed by two rows conflicts; order chains topologically (`bob→carol` runs before `alice→bob`); detect cycles (`alice↔bob`) and **reject** every row in a cycle with "Swap detected: rename one of these to a temporary name first" (D8); apply SEC rules per row.
  4. **Dry run** table: row, user, old → new, status (Ready, Error with reason, No change), warnings (active switched session, author URL will change, this is you).
  5. **Confirm** with a backup acknowledgment checkbox the first time in a session (CONVENTIONS 11).
  6. **Execute** as a `username_bulk` job (6.3). Each row calls UN-10 and logs individually.

---

## 9. Roles module: engine and Advanced view

### 9.1 Role store (shared by both views)

- **RO-1 Diff-based saves only.** The browser never sends a full capability list. Each form submits, per role, the set of capabilities that were **on the page** and the set that are **checked**. The server computes adds and removes within the on-page set only. Capabilities not on the page (hidden by a filter, outside the current group, or not represented in Simple view) are never touched.
- **RO-2 Optimistic lock.** The form carries the SHA-256 of the role's canonical capabilities at render time. If the stored role's hash differs at save, reject with "This role changed since you opened it" and a diff. This prevents the lost-update problem in section 19, URE-5.
- **RO-3 Batch write.** Re-read roles inside the save. Take a snapshot (SNAP-1). Set `$wp_roles->use_db = false`, apply every `add_cap` and `remove_cap`, restore `use_db`, then write once: `update_option( $wp_roles->role_key, $wp_roles->roles )`.
- **RO-4** Run the Guard (SEC-2, SEC-3, SEC-5, SEC-10, SEC-12, SEC-13) on the computed change set before writing. Fire `blt_toolkit_before_roles_save` and `blt_toolkit_after_roles_save`. Log with the diff and snapshot ID.
- **RO-5 No writes on read paths.** Rendering any screen must not change any role. (User Role Editor grants capabilities to `administrator` while building its list; see URE-2.) Offer an explicit **Sync administrator** action on Tools that previews which registered capabilities `administrator` lacks and grants them on confirm.

### 9.2 Capability catalog

`BLT_Toolkit_Capability_Catalog` discovers every capability and records where it comes from.

- **RO-10 Sources:** core (a maintained list with WordPress-version notes, including multisite caps for completeness), content types (from each post type's `cap` object, including meta caps for types with `map_meta_cap` false), taxonomies (`cap->manage_terms`, `edit_terms`, `delete_terms`, `assign_terms`), BLT Toolkit's own, any capability present on any role, any capability present on any user (one indexed query on the capabilities meta key, cached for the request).
- **RO-11 Orphaned** = present on a role or user, but not core, not registered by an active post type or taxonomy, not BLT's own, and not declared through `blt_toolkit_capability_groups`. Orphans are flagged, never auto-deleted (CONVENTIONS 11).
- **RO-12 Labels:** a built-in dictionary of plain-English labels for core capabilities; content type and taxonomy capabilities labeled from their objects ("Edit others' Events"); unknown capabilities humanized from their name. Filter `blt_toolkit_capability_labels`. The raw name is always shown as a code chip.
- **RO-13 Groups (tree):** All; Core > General, Appearance, Posts, Pages, Media, Plugins, Users, Deprecated (`level_0` to `level_10`, `edit_files`); Content types > one node per **capability group** (RS-5), labeled with every member type; Taxonomies; Plugins (detected) > best-effort grouping by shared prefix when three or more capabilities share it, plus named groups from `blt_toolkit_capability_groups`; BLT Toolkit; Orphaned.
- **RO-14 Shared capability detection.** Group content types by identical capability strings. A custom post type registered with `capability_type => 'post'` lands in the same group as Posts and must be shown as sharing Posts' permissions, never as an independent node. (URE shows such types as if separate; editing one silently edits Posts.)

### 9.3 Advanced view

Requires `blt_edit_capabilities`. Users with only `blt_edit_roles` see Simple view only, and the view switcher is hidden for them.

- **RO-20** Layout: role list (left), group tree (left, below), capability matrix (main), sticky save bar.
- **RO-21** Matrix row: checkbox, label, raw name chip, source chip (Core, content type name, taxonomy name, plugin group, BLT, Orphaned), tier badge, and the Simple control it belongs to, if any ("Simple: Events → level"), so admins learn how the two views relate.
- **RO-22** Toolbar: quick filter (matches label and raw name), "Granted only", "Show deprecated" (setting default off), "Readable names" (setting default on), column count 1 to 3 (stored per user).
- **RO-23 Add capability:** lowercase letters, digits, underscore and hyphen; must contain a letter; max 191 characters; must not exist; must not be a meta capability name (`edit_post`, `read_post`, `delete_post`, `edit_user`, `delete_user`, `promote_user`, `remove_user`, `edit_comment`, `edit_term`, `delete_term`, `assign_term`, `edit_post_meta` style names, and BLT's own meta caps), because granting a meta capability as a primitive does nothing useful. Choose which roles receive it.
- **RO-24 Delete capability:** offered only for orphans (RO-11). The dialog lists every role and user holding it. Dry run, snapshot, confirm. Registered and core capabilities are never deletable.
- **RO-25 Compare roles:** pick two roles; show a side-by-side diff by group. Read only.
- **RO-26** Without JS the matrix is a plain form; the group tree is a list of links (one URL per group, CONVENTIONS 6.4); the quick filter is a GET field. With JS: instant filter, change counter in the save bar (`aria-live="polite"`), `beforeunload` guard for unsaved changes.

### 9.4 Role lifecycle

- **RO-30 Add role** (`blt_create_roles`): display name (required), role ID auto-suggested with `sanitize_key()` and editable (rules as RO-23 but max 64), start from: Blank (`read` only), Subscriber, Contributor, Author, Editor, or a copy of any role whose capabilities are a subset of the actor's (SEC-3). Opens the new role in the actor's current view.
- **RO-31 Rename:** display name only. The `administrator` display name may change; its ID may not.
- **RO-32 Change role ID** is offered as **Move users to a new role**: create the new role as a copy, move users (job RO-41), then offer to delete the old one. Never edit an ID in place, because user assignments reference it.
- **RO-40 Delete roles** (`blt_delete_roles`): multi-select table of deletable roles (not built in, not `administrator`, not the default role, not one of the "additional default roles"). Roles with users require a replacement step.
- **RO-41 Reassign users** (job `role_reassign`): for each user holding the role, replace it with the chosen role, or simply remove it if the user has other roles. Dry run shows counts. Each user change is logged in one aggregate row per batch plus details.
- **RO-42 Merge role into…**: reassign (RO-41) then delete, as one flow.
- **RO-43 Reset** (`blt_reset_roles`), two options with dry-run previews: **Reset built-in roles' capabilities** to WordPress defaults and keep custom roles (default); **Full reset**, which deletes the roles option and runs `populate_roles()` like a fresh install, listing every custom role that will disappear with its user count. Both require typed confirmation of `RESET`, take a snapshot, then re-grant BLT capabilities to `administrator`.
- **RO-44 Export:** JSON of selected roles: `{ "format": "blt-toolkit-roles", "version": 1, "exported_at", "site", "roles": { id: { name, capabilities } }, "role_meta": {...}, "isolated_post_types": {...} }`.
- **RO-45 Import:** validate the format and version, then a per-role diff preview (New, Changed, Unchanged) with an action per role (Create, Merge capabilities, Replace capabilities, Skip). Capabilities registered by content types that don't exist on this site are flagged "not registered here". SEC rules apply. Snapshot first. This is how S-FX reuses a role blueprint across client sites.

### 9.5 Role metadata

Stored in settings (`roles.role_meta`), keyed by role ID: an optional description shown in the role list, and **Hide the toolbar on the site** (URE parity). The toolbar is hidden only when **every** one of the user's roles has the flag, because capabilities are additive and the most permissive role wins. A switched user whose toolbar is hidden still gets the footer switch-back link (SW-17).

---

## 10. Roles module: Simple view

This is the new part. Simple view answers "what can this role do with Events?" in one choice, and it never damages what Advanced view or a third-party plugin set.

### 10.1 Rules for the whole view

- **RS-1 Same data, two views.** Simple and Advanced read and write the same role capabilities through the same store (9.1). The switcher is a per-user view preference (user meta `blt_toolkit_editor_mode`, default from settings), never a property of the role.
- **RS-2 Switcher** in the role header: two tabs, **Simple** and **Advanced**, as links (`&view=simple`, `&view=advanced`). Switching with unsaved changes asks to save or discard first.
- **RS-3 Data-driven controls.** Every Simple control is declared in `BLT_Toolkit_Simple_Controls` with: id, section, label, help text, capability universe (resolved at runtime), how to compute its state from the role, how to compute target capabilities from a chosen state, tier, dependencies on other controls, and an `available()` predicate. Filter `blt_toolkit_simple_controls` lets other BLT plugins register theirs. The mapping logic lives in `BLT_Toolkit_Simple_Mapper` (pure, unit-tested).
- **RS-4 Honest state.** A control whose capabilities don't exactly match any of its states shows **Custom** (for levels) or an indeterminate toggle (for multi-capability toggles), with "Edit in Advanced" linking to Advanced filtered to that control's capabilities. Simple never changes a Custom control unless the admin picks a state, and then the review step lists exactly which capabilities will be removed.
- **RS-5** Saving in Simple submits only the controls that changed. For each, the server computes target capabilities, intersects with that control's universe, and passes adds and removes to the store (RO-1). Unchanged controls produce no operations.
- **RS-6 Review step.** Before writing, show a plain-English summary ("Events: Author (own only) → Edit all. Adds: edit others' Events, edit private Events, read private Events. Removes: publish Events."), with raw names in a disclosure. Shown always when the change set includes a Critical capability (SEC-10), and otherwise when more than 10 capabilities change. Without JS the review is its own screen in the PRG flow.
- **RS-7 Dependencies are explained, not hidden.** When a choice needs another capability (for example `read` to reach the dashboard, `list_users` to reach the Users screen for switching, or Media upload for authors who add images), show an inline hint with a one-click "Also allow" button. Do not add it silently.

### 10.2 Content section: one row per content type

- **RS-10 Which types appear:** every post type with `show_ui` true that is not internal (`_builtin` types other than `post` and `page`; attachments are handled in Media). One row per capability group (RO-14). A group's row is labeled with every member, for example "Posts (also used by: Events, Testimonials)".
- **RS-11 Types excluded from levels:** a type whose capabilities resolve to administrative capabilities, for example a type that maps `edit_posts` to `manage_options` (ACF field groups do this), is listed read-only as "Controlled by: Change settings" with a link to that control. Rule: every capability in the type's universe must be either dedicated to content (contains its own `capability_type`) or exactly shared with Posts or Pages; anything else excludes the type.
- **RS-12 Types with `map_meta_cap` false:** shown with the badge "Custom ownership rules" and no level selector, because Own versus Everyone's cannot be enforced; managed in Advanced.

### 10.3 Access levels

Each row is a level selector. Levels and their exact help text:

| Level | Help text (where `{Type}` is the plural label) |
| --- | --- |
| No access | Can't see or change {Type} in the dashboard. |
| View | Can see all {Type}, including private ones, but can't change anything. |
| Contribute | Can write {Type} and submit them for review. Can't publish. |
| Author (own only) | Can create, publish and manage their own {Type}. Can't change anyone else's. |
| Edit all | Can edit everyone's {Type}, including published and private ones. Can't publish new ones. Edits to published items go live immediately. |
| Full control | Can create, publish, edit and delete any {Type}, and manage their categories and tags. |
| Custom | This role has a mix of permissions Simple view can't show. Pick a level to replace it, or edit in Advanced. |

Two modifiers sit under the selector:

- **Can delete** toggle: shown for Contribute, Author and Edit all. Defaults when a level is picked: on, on, off. Full control always includes deletion.
- **Manage {taxonomy}** toggle, one per attached taxonomy whose `manage_terms`, `edit_terms` and `delete_terms` are dedicated (not `manage_categories`). Available at Contribute and above; on by default only for Full control. A dedicated `assign_terms` is granted automatically at Contribute and above, since authors need it to tag their own items. Taxonomies that use the default `manage_categories` are controlled site-wide in Appendix A.

### 10.4 Level-to-capability map

`P` is the post type's `cap` object. `create_posts` is listed only when its string differs from `edit_posts` (it defaults to the same string, in which case it adds nothing). `(d)` means included only when **Can delete** is on.

| Capability (`P->…`) | No access | View | Contribute | Author | Edit all | Full |
| --- | --- | --- | --- | --- | --- | --- |
| `read_private_posts` | | ✓ | | | ✓ | ✓ |
| `edit_posts` | | ✓ † | ✓ | ✓ | ✓ | ✓ |
| `create_posts` (if distinct) | | | ✓ | ✓ | ✓ | ✓ |
| `edit_published_posts` | | | | ✓ | ✓ | ✓ |
| `publish_posts` | | | | ✓ | | ✓ |
| `edit_others_posts` | | | | | ✓ | ✓ |
| `edit_private_posts` | | | | | ✓ | ✓ |
| `delete_posts` | | | (d) | (d) | (d) | ✓ |
| `delete_published_posts` | | | | (d) | (d) | ✓ |
| `delete_others_posts` | | | | | (d) | ✓ |
| `delete_private_posts` | | | | | (d) | ✓ |
| `blt_readonly_{plural}` marker | | ✓ † | | | | |

† **View in the dashboard** needs `edit_posts` (core requires it for the list screen and menu) **without** the ability to create or edit. WordPress has no native way to express that, because for most types `create_posts` is the same string as `edit_posts`. So:

- **RS-13** When the type's `create_posts` is distinct (natively, or because it has separate permissions, RS-30), View grants `read_private_posts`, `edit_posts` and the marker `blt_readonly_{plural}`, and BLT enforces read-only (RS-14). The row's badge reads "Enforced by BLT Toolkit".
- When `create_posts` is not distinct (for example Posts, Pages and any type sharing them), View grants only `read_private_posts`, and its help text adds: "On the site only. To browse {Type} read-only in the dashboard, give {Type} separate permissions." with a link to RS-30. Posts and Pages themselves cannot be isolated, so for them View means site-only.
- **RS-14 Read-only enforcement** (`BLT_Toolkit_Readonly`): in `map_meta_cap`, for `edit_post`, `delete_post` and `publish_post` on a post whose type has a distinct `create_posts`, add `do_not_allow` when the user holds that type's `blt_readonly_{plural}` marker **and lacks** its `create_posts`. Because the test is "has marker and lacks create", a user who also holds a higher level through another role is not restricted; capabilities stay additive. List-table titles become plain text and row actions disappear automatically because `edit_post` fails. If BLT Toolkit is deactivated, such a user keeps `edit_{plural}` without create: they still cannot add items or edit others', and could edit only items an admin has assigned to them. Document this in the deactivation warning (section 14.6).
- **RS-15 Detection** (state from capabilities): within the row's universe, compute the granted set; compare with each level's set for both values of **Can delete** and each combination of **Manage** toggles. Exactly one match gives that level and modifiers. No match gives Custom. Duplicate strings (for example `create_posts` equal to `edit_posts`) collapse naturally because comparisons use string sets.
- **RS-16 Acceptance fixture:** WordPress's default roles must read as follows for both Posts and Pages. Subscriber: No access. Contributor: Contribute with Can delete (Pages: No access). Author: Author with Can delete (Pages: No access). Editor: Full control. Administrator: Full control (read-only, SEC-12).
- **RS-17** When a level at Contribute or above is picked and the role lacks Media upload, show the RS-7 hint: "People with this role usually also need to upload images."

### 10.5 Separate permissions for a custom post type ("isolation")

Most plugin-registered custom post types use `capability_type => 'post'`, so they share every capability with Posts. Per-type access is impossible until the type has its own capabilities. This is the feature that makes Simple view useful for CPTs.

- **RS-30 Give {Type} separate permissions** (button on the row; `blt_edit_capabilities` and `manage_options`). Candidates: types whose `capability_type` is `post` or `page`, whose capability strings equal Posts' or Pages' exactly (no `capabilities` overrides), that are not core types, and not in `blt_toolkit_isolation_excluded_types`.
  1. Admin confirms the singular and plural (default: the post type name and name + `s`; editable; validated so the resulting strings collide with no existing capability of another type).
  2. **Dry run:** for each role, and each user with individual Posts or Pages capabilities, show which new capabilities they will receive so that **effective access is unchanged on day one**: every `*_posts` capability they hold maps to the matching `*_{plural}` capability, and holding `edit_posts` grants `create_{plural}`.
  3. On confirm: snapshot, seed those capabilities in one batch (RO-3), store the type under `roles.isolated_post_types`, log.
- **RS-31 How it applies:** the `register_post_type_args` filter (added at file load, 4.2) sets `capability_type => array( singular, plural )`, `map_meta_cap => true`, and `capabilities => array( 'create_posts' => "create_{plural}" )` for each isolated type. Filter `blt_toolkit_isolation_caps` may adjust the arguments.
- **RS-32 Revert** (button): remove the type from the list; the type shares Posts again on the next request. Offer, as a separate dry-run step, to remove the now-unused `*_{plural}` and marker capabilities from roles. They are flagged as orphans (RO-11) until then.
- **RS-33 Verify:** at `init` priority `PHP_INT_MAX`, check that each isolated type's `cap->edit_posts` equals `edit_{plural}`. If not (the type registered before BLT Toolkit loaded, or another plugin overrode it), show an admin notice and a Site Health warning naming the type, and disable its level selector.
- **RS-34 Deactivation warning:** while any type is isolated, show a row under BLT Toolkit on the Plugins screen (`after_plugin_row_{basename}`): "Deactivating BLT Toolkit returns {N} content types to sharing Posts permissions. People with Posts access will regain access to them."
- **RS-35 Test matrix item:** Bricks templates (`bricks_template`) and any type whose plugin checks `edit_posts` directly instead of the type's own capability. If isolation breaks the plugin's access checks, add the type to the default exclusion list and record it in `tasks/lessons.md`.

### 10.6 Site and people sections

Appendix A lists every site-wide Simple control with its capabilities and tier. Controls that map to several capabilities (for example "Install, update or delete plugins") show an indeterminate state when only some are granted (RS-4).

### 10.7 Who may use Simple view

`blt_edit_roles`. Simple view is the only view for users without `blt_edit_capabilities` (9.3). Non-admin holders are still bound by SEC-2, SEC-3 and SEC-5, so a delegated "role manager" can adjust content access for roles below them without being able to escalate.

---

## 11. User-level features

| ID | Feature | Detail |
| --- | --- | --- |
| US-1 | Additional roles | Checkbox list "Additional roles" on the profile (`edit_user_profile`) and Add New User (`user_new_form`) screens, below core's Role dropdown, which remains the primary role. Saved on `profile_update` and `user_register`, limited to filtered `editable_roles` (SEC-4) and SEC-5. No jQuery multiselect. |
| US-2 | Additional default roles | Settings > Roles: roles every new user receives in addition to core's default role. Applied on `user_register` for every registration path, including SSO-created users. |
| US-3 | Bulk add or remove a role | On the Users list via `restrict_manage_users`: "Add role…" and "Remove role…" selects with an Apply button, next to core's "Change role to…". POST with nonce, SEC rules per user, one aggregate log row with details. |
| US-10 | Per-user capabilities | "Capabilities" link on the profile (requires `blt_edit_capabilities`) opens the Roles screen in user mode: the same matrix with a three-state control per capability (Inherit, Grant, Deny) and a source column (from role X, granted to user, denied for user). Save writes the user's capabilities meta once, preserving the role keys stored in the same array, then `update_user_level_from_caps()`. Advanced only. |
| US-11 | Hide the core "Additional Capabilities" profile row | Replaced by the link in US-10 (URE parity, `additional_capabilities_display`). |
| US-12 | Users without a role | Users whose capabilities meta is missing or empty for this site. Counted **on demand** (button on the Users screen and Overview), cached in a one-hour transient, run as the `count_no_role` job on large sites. A "No role (N)" view filters the list. Core's "Change role to…" then assigns them. Never counted on every Users page load. |

---

## 12. Cross-module behavior

The reason to combine three plugins is that these pieces should know about each other.

- **X-1 One protection layer.** SEC rules govern switching, renaming and role editing identically.
- **X-2 One activity log** with "acting as" attribution (LOG-2), so a change made while switched is traceable to the human.
- **X-3 Test a role.** The Roles screen header has "Test as…": a select of up to 20 users holding the role whom the actor may switch to (plus typeahead from SW-20). Choosing one switches with `redirect_to` set to the dashboard. If no user holds the role, the control says so. (Previewing a role without a user is a roadmap item.)
- **X-4 Rename and switch interact.** Renaming invalidates cookies, so UN-12 warns before renaming anyone in a switched session, and UN-14 keeps a self-renaming user signed in.
- **X-5 Switching respects role metadata.** A hidden toolbar (9.5) never removes the way back (SW-17).
- **X-6 Self-service is a capability.** UN-20 writes `blt_change_own_username`, which appears in Simple view under People, so there is one place to see what a role can do.

---

## 13. Admin UI

All screens follow `DESIGN.md` and CONVENTIONS 7 and the definition of done in CONVENTIONS 14.4: `<div class="wrap blt-ui blt-<page>">`, `blt-design-system.css` on that screen's hook only, composed from the catalog, booleans as `.blt-toggle`, statuses as `.blt-badge`, tokens not hex, no `form-table`.

### 13.1 Menu

Top-level **BLT Toolkit** with `BLT_Family_Brand::menu_icon()` and `print_menu_icon_style()` on `admin_head`. Submenus each carry their own capability; WordPress shows the top-level item to anyone who can reach at least one. This extends the "one menu entry, one capability" admin-controller pattern because the modules are delegated separately; confirm against `DESIGN.md` (D4).

| Submenu | Slug | Capability |
| --- | --- | --- |
| Overview | `blt-toolkit` | `manage_options` |
| Roles | `blt-toolkit-roles` | `blt_edit_roles` |
| Usernames | `blt-toolkit-usernames` | `blt_change_usernames` |
| Activity | `blt-toolkit-activity` | `blt_view_toolkit_log` |
| Tools | `blt-toolkit-tools` | `blt_reset_roles` (legacy import tab: `manage_options`) |
| Settings | `blt-toolkit-settings` | `manage_options` |

Also add **Users > Roles** pointing at the Roles screen, because that is where people look for it (D4).

### 13.2 Screens

| Screen | Composition |
| --- | --- |
| Overview | Page header with BLT mark. Selectable cards for the three modules (toggle, On / Off / Conflict badge, one-line description). Stat tiles: roles, users without a role (on-demand count), switches and username changes in the last 30 days. Setup card when legacy plugins or their data are detected ("Import settings from Username Changer"). Recovery-mode callout when SEC-14 is active. |
| Roles | Left column: role list card (name, user count, badges Built-in, Default, Has Critical caps) and Add role button; group tree in Advanced. Main: role header (name, ID chip, user count linking to the filtered Users list, Test as… select, Simple / Advanced tabs), then the view. Sticky save bar with change count, Review and save, Discard. |
| Roles, Simple | Cards in order: Basics, Content (table of rows per 10.2), Media and comments, Appearance, Plugins, Site, People, BLT Toolkit, Other plugin permissions ("N capabilities from other plugins. Manage in Advanced."). |
| Usernames | Card: Bulk rename from CSV (8.6) with job progress. Card: Recent changes (log filtered to usernames). Link to settings. |
| Activity | Filter toolbar (module, action, actor, acting as, date range), paginated table inside a card, row disclosure for details and diff, Export CSV (POST with nonce). `WP_List_Table` is acceptable inside `.blt-ui`; match how BLT Events builds its list screens. |
| Tools | Tabs as links: Snapshots, Export and import, Reset, Sync administrator, Import from legacy plugins. |
| Settings | Tabs as links: General (modules), Switching, Usernames, Roles, Activity log, Advanced (delete data on uninstall, recovery instructions shown as a code block, GitHub token with Test Connection, Check for Updates). |

### 13.3 Front end

Only two pieces, both in `blt-toolkit-front.css` with `--blt-tk-*` tokens, no global resets, safe inside Bricks pages: the switch-back link (SW-17) and the username form (UN-23). Nothing loads for visitors who are not switched or not on a page with the shortcode.

---

## 14. Data model

### 14.1 `blt_toolkit_settings` (autoload yes; read on most requests by the Guard and Readonly enforcement)

```php
array(
	'modules'   => array( 'switch' => true, 'usernames' => true, 'roles' => true ),
	'switch'    => array(
		'footer_link'  => true,
		'switch_off'   => true,
		'search'       => true,
		'compat_hooks' => true,
		'woocommerce'  => true,
	),
	'usernames' => array(
		'min_length'         => 3,
		'disallowed'         => array( 'admin', 'administrator', 'root', 'sysadmin', 'webmaster' ),
		'allow_at_sign'      => true,
		'nicename_policy'    => 'if_derived', // if_derived | always | never
		'redirect_old_slugs' => true,
		'sign_out_others'    => true,
		'notify_user'        => false,
		'email_subject'      => '',           // '' = translated default
		'email_body'         => '',
		'cooldown_days'      => 0,
		'require_password'   => false,
	),
	'roles'     => array(
		'default_view'          => 'simple',
		'show_deprecated'       => false,
		'readable_labels'       => true,
		'hide_admins'           => true,
		'extra_default_roles'   => array(),
		'role_meta'             => array(), // role_id => array( 'description' => '', 'hide_toolbar' => false )
		'isolated_post_types'   => array(), // type => array( 'singular' => '', 'plural' => '', 'since' => 0 )
		'snapshot_retention'    => 50,
	),
	'log'       => array( 'retention_days' => 365, 'record_ip' => true ),
	'advanced'  => array( 'delete_data_on_uninstall' => false ),
)
```

### 14.2 Other options

| Option | Autoload | Content |
| --- | --- | --- |
| `blt_toolkit_db_version` | yes | Schema version |
| `blt_toolkit_secrets` | no | Encrypted GitHub token |
| `blt_toolkit_job_{id}` | no | Job state |
| `blt_toolkit_legacy_imports` | no | Import ledger (what was imported, when, highest source row ID) |
| Transient `blt_toolkit_no_role_count` | n/a | One-hour cache for US-12 |

### 14.3 Tables

Follow `dbDelta` formatting rules (two spaces after `PRIMARY KEY`, one field per line).

```sql
CREATE TABLE {prefix}blt_toolkit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  module varchar(20) NOT NULL,
  action varchar(40) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_login varchar(60) NOT NULL DEFAULT '',
  acting_as_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id varchar(191) NOT NULL DEFAULT '',
  summary text NOT NULL,
  details longtext NULL,
  snapshot_id bigint(20) unsigned NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'success',
  source varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY module_action (module,action),
  KEY actor_id (actor_id),
  KEY object_lookup (object_type,object_id(40))
) {charset_collate};

CREATE TABLE {prefix}blt_toolkit_snapshots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason varchar(40) NOT NULL,
  roles longtext NOT NULL,
  hash char(64) NOT NULL,
  is_baseline tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {charset_collate};
```

`created_at` is UTC. `source` is empty for native rows and names the plugin for imported rows (`username-changer`). `reason` values: `baseline`, `before_save`, `before_reset`, `before_restore`, `before_import`, `before_isolation`, `recovery`, `legacy_ure_backup`.

### 14.4 User meta, session data and cookies

| Key | Where | Purpose |
| --- | --- | --- |
| `blt_toolkit_editor_mode` | user meta | `simple` or `advanced` |
| `blt_toolkit_columns` | user meta | Advanced matrix columns |
| `blt_toolkit_previous_nicenames` | user meta | Old author slugs for 301s |
| `blt_toolkit_username_changed_at` | user meta | Cooldown |
| `blt_switched_from_id`, `blt_switched_from_session` | target's session (`session_tokens`) | Who switched in |
| `blt_switched_into`, `blt_switched_at` | actor's session | Who they are switched into |
| Switch cookies | browser | SW-3 |

Multiple roles and per-user capabilities use WordPress's own `{prefix}capabilities` user meta. No parallel storage.

### 14.5 Privacy

Register a personal data exporter for log rows where the user is the actor, the acting-as user or the object. Register an eraser that blanks `actor_login` and `ip` on those rows and reports them as retained for security. Include the cookie and log text from SW-24 in the privacy policy content.

### 14.6 Deactivation and uninstall

- **Deactivation** changes no data. Role edits persist (they are WordPress's data). Isolated types return to sharing Posts on the next request (RS-34 warns). Read-only enforcement stops (RS-14 describes the outcome). Switched sessions become ordinary sessions as the target user; the original user signs in again normally.
- **Uninstall** with "Delete all plugin data" off: deletes nothing. With it on: drops both tables, deletes the plugin's options, transients, user meta keys and job options, removes every `blt_*` capability and `blt_readonly_*` marker from all roles and users, and clears the cron events. It never restores a snapshot, never reverts the owner's role edits, never deletes roles, and never touches the shared store.

---

## 15. Migration from the source plugins

Imports are read-only against the source, idempotent (recorded in `blt_toolkit_legacy_imports`), previewed before they run, and never delete the source's data (CONVENTIONS 11). Confirm option keys against the installed source version at build time; the ones below were read from the versions reviewed.

| Source | What exists | What BLT Toolkit does |
| --- | --- | --- |
| User Switching | Nothing server-side; only browser cookies. | Nothing to import. Tell the owner to switch back before deactivating User Switching, because its cookies mean nothing to BLT Toolkit. |
| Username Changer | Option `username_changer_settings` (`allowed_roles`, `minimum_length`, `enable_notifications`, `email_subject`, `email_message`, button labels and messages). Table `{prefix}uc_audit_log`. | Allowed roles become `blt_change_own_username` on those roles (previewed, snapshotted). Minimum length, notification toggle, subject and body map directly; the template tags share names. Custom button labels and messages are dropped (use translations). Audit rows are copied into the activity log with `source = 'username-changer'`, resumable by highest imported ID. |
| User Role Editor | Roles in core storage (nothing to migrate). Option `user_role_editor` (includes `other_default_roles`, display preferences). Option `ure_role_additional_options_values` (per-role "hide admin bar"). Option `{prefix}backup_user_roles` (roles at URE's first activation). `ure_*` capabilities on `administrator`. | `other_default_roles` becomes `roles.extra_default_roles`. Hide-admin-bar flags become `role_meta[*].hide_toolbar`. The backup becomes a snapshot with reason `legacy_ure_backup`. `ure_*` capabilities are flagged as orphans labeled "From User Role Editor" with a one-click dry-run removal. |

---

## 16. Hooks (seed for `HOOKS.md`)

**Actions**

| Hook | Arguments |
| --- | --- |
| `blt_toolkit_switched_to` | `int $target_id, int $actor_id, string $new_token, string $old_token` |
| `blt_toolkit_switched_back` | `int $user_id, int\|false $from_id, string $new_token, string $old_token` |
| `blt_toolkit_switched_off` | `int $user_id, string $token` |
| `blt_toolkit_before_username_change` / `blt_toolkit_after_username_change` | `int $user_id, string $old, string $new` (after also gets `array $result`) |
| `blt_toolkit_before_roles_save` / `blt_toolkit_after_roles_save` | `array $changes, string $context` (after also gets `int $snapshot_id`) |
| `blt_toolkit_role_created`, `blt_toolkit_role_deleted`, `blt_toolkit_role_renamed` | role ID plus relevant values |
| `blt_toolkit_post_type_isolated`, `blt_toolkit_post_type_shared` | `string $post_type` |
| `blt_toolkit_before_job` / `blt_toolkit_after_job` | `array $job` |
| `blt_toolkit_logged` | `array $row` |
| Compat (SW-19, setting) | `switch_to_user`, `switch_back_user`, `switch_off_user` with User Switching's signatures |

**Filters** (default in parentheses)

`blt_toolkit_modules` (three modules) · `blt_toolkit_is_toolkit_admin` (has `administrator` role) · `blt_toolkit_switch_redirect` ('') · `blt_toolkit_switch_show_footer_link` (setting) · `blt_toolkit_switch_send_cookies` (true) · `blt_toolkit_switch_search_limit` (20) · `blt_toolkit_validate_username` (`WP_Error`) · `blt_toolkit_username_min_length` (setting) · `blt_toolkit_disallowed_usernames` (setting) · `blt_toolkit_username_email` (array) · `blt_toolkit_bulk_rename_max_rows` (5000) · `blt_toolkit_risky_caps` (5.4) · `blt_toolkit_lockout_critical_caps` (SEC-12) · `blt_toolkit_capability_labels` · `blt_toolkit_capability_groups` · `blt_toolkit_simple_controls` · `blt_toolkit_isolation_excluded_types` (array) · `blt_toolkit_isolation_caps` · `blt_toolkit_hide_admins_from_non_admins` (setting) · `blt_toolkit_snapshot_retention` (50) · `blt_toolkit_log_retention_days` (365) · `blt_toolkit_tick_budget` (15)

**Global function:** `current_user_switched()` shim, only when undefined (SW-19).

---

## 17. Phases and acceptance criteria (seed for `tasks/todo.md`)

### Phase 0: Scaffold

Repo per CONVENTIONS 5 and 16.1; family layer registered with the `github` group; update checker with `24`; CI `release.yml` ported from BLT Secure; module registry; settings; schema with both tables; activation grants (5.1) and baseline snapshot; empty admin screens on the design system; multisite activation refused.

- [ ] Activates cleanly on PHP 7.4 / WordPress 6.3 and PHP 8.3 / latest WordPress with `WP_DEBUG` on and no notices.
- [ ] `composer lint`, `composer phpcs`, `composer test` clean. Header and constant versions match (CI check).
- [ ] Release zip has one top-level `blt-toolkit/`, includes the update checker, excludes dev files. A second release updates a test site through the updater.
- [ ] Activation on multisite is refused with a readable notice.

### Phase 1: Guard, log, snapshots

SEC-1 to SEC-15, LOG-1 to LOG-6, SNAP-1 to SNAP-5, Activity screen.

- [ ] A non-admin with `edit_users` and `promote_users` cannot edit, delete, change the role of, or switch to an administrator through `users.php`, `user-edit.php`, `admin-ajax.php` or `POST /wp/v2/users/{id}`.
- [ ] The same user cannot assign a role whose capabilities exceed theirs through the profile dropdown, the Users bulk action or REST.
- [ ] With `BLT_TOOLKIT_RECOVERY` defined, an administrator whose role lost `manage_options` regains it on the next admin request, and a notice persists until the constant is removed.
- [ ] Unit tests: superset (SEC-8 cases including `false` caps and role-name keys), diff and hash canonicalization.

### Phase 2: Switch

SW-1 to SW-28.

- [ ] Switch three levels deep, then switch back one level at a time to the original user and original session.
- [ ] Switch off: logged out; login screen and site footer offer switch back; switching back restores the original session.
- [ ] Duplicate-switch warning appears for a second admin; `force` continues.
- [ ] Switch back destroys the target's switched session (verify in `session_tokens`).
- [ ] A Support Agent can switch to Subscribers but not to Editors or Administrators.
- [ ] Every switch, switch back and switch off is logged with actor and acting-as.
- [ ] With compat hooks on, a listener on `switch_to_user` fires with User Switching's arguments.
- [ ] Works with BLT Secure's custom login slug and its two-factor flow (switching does not prompt; switching back does not prompt); works with BLT M365 WP SSO (switched session is not kicked out by SSO session checks).
- [ ] WooCommerce: the cart and session from the admin do not leak into the customer session.

### Phase 3: Usernames

UN-1 to UN-30 (UN-23 shortcode may slip to 3b).

- [ ] With Redis or Memcached object cache active, the old username cannot log in immediately after a rename and the new one can.
- [ ] The author slug changes only when it was derived from the username; the old author URL returns 301 to the new one.
- [ ] Renaming yourself keeps you signed in on the same session; renaming someone else ends their sessions when the setting is on.
- [ ] `jsmith` to `JSmith` succeeds; `Admin` is rejected when `admin` is disallowed; a username equal to another user's email is rejected; 61 characters is rejected; minimum length is enforced with JS disabled.
- [ ] No nested `<form>` in the profile HTML (validate the page markup).
- [ ] Bulk CSV with `bob→carol` and `alice→bob` succeeds in the right order; `alice↔bob` is rejected as a cycle; the job survives closing the tab; cancel keeps completed rows.

### Phase 4: Roles engine and Advanced view

RO-1 to RO-45 except export and import, US-1 to US-12.

- [ ] Saving a filtered Advanced view does not remove capabilities that were filtered out.
- [ ] Two admins editing the same role: the second save is rejected with a diff.
- [ ] One save writes the roles option exactly once (assert with a query counter in a smoke test).
- [ ] Opening any screen changes no role (compare snapshots before and after a full click-through).
- [ ] A CPT registered with `capability_type => 'post'` appears inside the Posts capability group, not as its own node.
- [ ] Capabilities registered by active post types are never offered for deletion.
- [ ] Users-without-role count is never computed on a normal Users page load.

### Phase 5: Simple view and separate permissions

RS-1 to RS-35, Appendix A.

- [ ] RS-16 fixture passes for Posts and Pages.
- [ ] Round trip: for every level and modifier combination on fixtures (Posts, Pages, an isolated CPT, a CPT with its own `capability_type`, a `map_meta_cap` false CPT, an ACF-style override CPT), `detect( apply( state ) ) === state`.
- [ ] A role edited in Advanced into an unrepresentable combination shows Custom in Simple, and saving an unrelated Simple control leaves it untouched.
- [ ] Isolating a CPT changes nobody's effective access (compare `allcaps`-derived permission checks for every role before and after on that type's items).
- [ ] A View-level role can open the CPT list, sees titles as plain text, cannot open the editor, cannot create, and gets 403 from REST create and update.
- [ ] Deactivating BLT Toolkit with an isolated type shows the warning row; after deactivation the type shares Posts again.

### Phase 6: Tools

Snapshots UI, Export and import, Reset (both modes), Sync administrator, legacy importers (section 15).

- [ ] Restoring a snapshot first shows a diff and snapshots the current state.
- [ ] Exporting roles from site A and importing into site B shows a correct per-role preview and flags capabilities not registered on B.
- [ ] Importing Username Changer's audit log twice does not duplicate rows.

### Phase 7: Privacy, Site Health, docs, release

Privacy exporter and eraser, Site Health tests (legacy plugin conflicts, isolation verification failures, recovery constant set, log write failures), README per CONVENTIONS 15, `readme.txt`, `HOOKS.md`, `CHANGELOG.md`, POT.

- [ ] CONVENTIONS 14.5 checklist complete, including a keyboard-only pass of the Roles screen in both views and of the front-end pieces.

---

## 18. Testing

**Unit (PHPUnit, no WordPress install, CONVENTIONS 14.1).** Pure classes with fixtures and known vectors:

| Class | What to test |
| --- | --- |
| `BLT_Toolkit_Simple_Mapper` | Level and modifier ↔ capability sets for each fixture type in Phase 5; RS-16 default roles; Custom detection; distinct versus shared `create_posts`. |
| `BLT_Toolkit_Role_Diff` | Canonicalization order independence, hash stability, `true`/`false` flips, display name changes. |
| `BLT_Toolkit_Superset` | SEC-8 cases. |
| `BLT_Toolkit_Username_Validator` | Every UN rule with lookups injected. |
| `BLT_Toolkit_Nicename` | Outputs match `wp_insert_user()` for a table of inputs (accents, spaces, `@`, 60-character logins, collisions needing `-2` and `-3`). |
| `BLT_Toolkit_Rename_Planner` | Chains, cycles, duplicate targets, names freed within the batch, self rows. |
| `BLT_Toolkit_Csv` | BOM, semicolons, quoted fields with commas and newlines, CRLF, blank lines, header detection. |
| Switch cookie stack | Push, pop, nested depth, malformed JSON yields an empty stack. |

**Manual smoke matrix** (record results in `tasks/todo.md`): PHP 7.4 and 8.3; WordPress 6.3 and latest; with and without a persistent object cache; plain permalinks; Bedrock; Cloudflare APO; BLT Secure active; BLT M365 WP SSO active; WooCommerce; ACF and CPT UI post types; Bricks templates; Gravity Forms capabilities present; a seeded site with 10,000 users (Users screen render time with switch and rename row actions must stay within 10% of the plugin being inactive).

---

## 19. Defects found in the source plugins (do not port)

Found by reading the source of the versions listed. Each has a rule above that prevents it.

| # | Plugin | Finding | Consequence | BLT rule |
| --- | --- | --- | --- | --- |
| UC-1 | Username Changer | Renames with a direct `UPDATE` and never calls `clean_user_cache()`. | On sites with a persistent object cache, the old login keeps resolving to the user (and can keep authenticating) until the cache expires. | UN-10 step 7 |
| UC-2 | Username Changer | When the nicename equals the old login, overwrites it with the raw new username: not `sanitize_title()`d, not truncated to 50, not checked for uniqueness. | Broken or colliding author URLs. | UN-13 |
| UC-3 | Username Changer | Authorizes with `current_user_can( 'edit_users' )`, not `edit_user` for the target. | Anyone holding `edit_users` can rename any account, administrators included. | 5.1, SEC-1, SEC-2 |
| UC-4 | Username Changer | Minimum length is checked only in JavaScript. | Bypassed by any direct request. | UN-2 |
| UC-5 | Username Changer | Single-change path compares the new name against a lowercased illegal list without lowercasing the name (the bulk path does it right). | `Admin` passes when `admin` is illegal. | UN-4, one validator |
| UC-6 | Username Changer | No maximum length check. | Truncation or a database error past 60 characters. | UN-2 |
| UC-7 | Username Changer | Multisite: calls `grant_super_admin()` after the rename but never removes the old login from `site_admins`; with the user cache not cleared, it may not add the new login either. | The user can lose super admin, and anyone who later registers the old username becomes a super admin. | UN-11 |
| UC-8 | Username Changer | Injects a `<form>` inside the profile form. | Invalid HTML; behavior depends on the browser. | UN-16 to UN-18 |
| UC-9 | Username Changer | Updates `WHERE user_login = %s`. | Fragile; IDs are the key. | UN-10 step 4 |
| UC-10 | Username Changer | Self-rename logs the user out (the cookie embeds the login). | Forced re-login. | UN-14 |
| UC-11 | Username Changer | Admin assets on every admin screen, front-end CSS on every page; telemetry modal sending site data to a third party; Pro license gating of the audit log and bulk tool. | Page weight and privacy. | CONVENTIONS 6.4, 7; not carried over |
| URE-1 | User Role Editor | Administrator protection (`not_edit_admin`) reads the target ID only from `$_GET` and only on `users.php` and `user-edit.php`. Its own 4.66.2 changelog fixed an AJAX bypass of a related check. | POST, AJAX and REST entry points are not covered by that check. | SEC-1 via `map_meta_cap` |
| URE-2 | User Role Editor | Building the capability list grants missing content-type and taxonomy capabilities (and, on single site, every listed capability) to `administrator`. | A read path that writes roles. | RO-5 |
| URE-3 | User Role Editor | "Add role" copying from another role does not check that the actor holds the copied capabilities, and only `administrator` is removed from assignable roles. | A non-admin with role-creation and `promote_users` can build and assign a role more powerful than their own. | SEC-3, SEC-4 |
| URE-4 | User Role Editor | Custom post types that share Posts' capabilities are listed as separate groups. | Editing "Events" silently edits Posts. | RO-14, RS-10 |
| URE-5 | User Role Editor | Save writes the whole roles array loaded at the start of the request. | A concurrent change to another role is silently overwritten. | RO-2, RO-3 |
| URE-6 | User Role Editor | Reset has no undo beyond a one-time backup taken at first activation. | Irreversible mistakes. | SNAP-1 to SNAP-4, RO-43 |
| URE-7 | User Role Editor | Capability deletion is offered for anything no non-admin role uses, including capabilities registered by active post types. | Deleting a live capability. | RO-11, RO-24 |
| US-A | User Switching | Switching is allowed to anyone who can `edit_user` the target. | A role that gains `edit_users` gains impersonation, including of users more powerful than itself that it can edit. | 5.1, SEC-2 (D3) |
| US-B | User Switching | Global functions `switch_to_user()`, `current_user_switched()`. | Name collisions if redeclared. | GEN-2, SW-19 |

User Switching's cookie and session handling is otherwise careful and is the model for section 7.

---

## 20. Non-goals (v1)

- Multisite and network administration (D1).
- BuddyPress and bbPress switch buttons and bbPress roles.
- Co-Authors Plus attribution updates on rename.
- User Role Editor Pro's admin-menu, widget, meta-box, front-end menu, per-post and content-view restrictions. Protected content belongs to BLT Documents; admin customization is a different product.
- Separate permissions for taxonomies.
- Previewing a role without a real user (roadmap).
- Time-limited switches and notifying users that someone switched into their account (roadmap).
- WP-CLI commands (roadmap; target hosts often have no shell).
- SureCart "switch to customer" (roadmap, after confirming how SureCart links customers to users).
- Custom UI strings for buttons and messages. Use translations.

Keep the plugin narrow.

---

## 21. Open decisions

| # | Decision | Recommendation | Why |
| --- | --- | --- | --- |
| D1 | Multisite scope | Refuse multisite activation in v1. | Usernames are network-wide; role editing per site and "apply to all sites" need their own design and tests. |
| D2 | Compat hooks and the `current_user_switched()` shim | Fire User Switching's action names and define the shim only when undefined, behind a setting that defaults on. | Activity loggers and dev tools that already support User Switching keep working with zero configuration. |
| D3 | Switch permission model | Explicit `blt_switch_users` capability plus SEC-1 and SEC-2. | Impersonation is too powerful to inherit from `edit_users`. |
| D4 | Menu placement | Top-level BLT Toolkit with per-submenu capabilities, plus Users > Roles. Menu position per the family's pending choice (CONVENTIONS 17, item 16). | Delegation needs per-screen capabilities; Users is where people look for roles. Confirm against `DESIGN.md`. |
| D5 | Hide administrators from non-administrators in the Users list | On. | URE parity; reduces exposure of admin accounts to delegated user managers. |
| D6 | Author slug policy | Change only when derived from the username, with 301 redirects on. | Protects SEO and inbound links. |
| D7 | Sign out a renamed user's sessions | On. | Their cookies are already invalid; this cleans up instead of leaving dead sessions. |
| D8 | Bulk rename cycles | Reject in v1. | Automatic temporary names add failure modes for a rare case. |
| D9 | Log retention and IP recording | 365 days, IP on. | Security value; privacy exporter and eraser cover requests. |
| D10 | WordPress floor 6.3 | Accept. | Reason stated in section 3. |
| D11 | Username shortcode timing | Phase 3b. | Core rename path first. |
| D12 | Default disallowed usernames | `admin`, `administrator`, `root`, `sysadmin`, `webmaster`. | Only affects new names; existing accounts are untouched. |
| D13 | `@` in usernames | Allowed by default. | Email-style usernames are common with SSO and membership sites; UN-6 handles the ambiguity. |
| D14 | Front-end token prefix | `--blt-tk-*` and `.blt-tk-*`. | Avoids a likely collision with BLT Tube's single-letter `t`. Confirm Tube's prefix. |

---

## Appendix A. Simple view: site and people controls

| Section | Control | Capabilities | Tier |
| --- | --- | --- | --- |
| Basics | Can sign in to the dashboard | `read` | Standard |
| Basics | Hide the toolbar on the site | role metadata (9.5), not a capability | n/a |
| Media and comments | Upload files | `upload_files` | Standard |
| Media and comments | Moderate comments | `moderate_comments` | Elevated |
| Media and comments | Manage categories and tags | `manage_categories` | Elevated |
| Appearance | Customize menus, widgets and the site editor | `edit_theme_options` | Elevated |
| Appearance | Change the active theme | `switch_themes` | Elevated |
| Appearance | Install, update or delete themes | `install_themes`, `update_themes`, `delete_themes` | Critical |
| Appearance | Edit theme files | `edit_themes` | Critical |
| Plugins | Activate and deactivate plugins | `activate_plugins` | Critical |
| Plugins | Install, update or delete plugins | `install_plugins`, `update_plugins`, `delete_plugins` | Critical |
| Plugins | Edit plugin files | `edit_plugins` | Critical |
| Site | Change settings | `manage_options` | Critical |
| Site | Update WordPress | `update_core` | Critical |
| Site | Import content | `import` | Elevated |
| Site | Export content | `export` | Elevated |
| Site | View Site Health | `view_site_health_checks` | Standard |
| Site | Publish unfiltered HTML (scripts) | `unfiltered_html` | Critical |
| Site | Upload any file type | `unfiltered_upload` | Critical |
| People | See the list of users | `list_users` | Elevated |
| People | Add users | `create_users` | Critical |
| People | Edit users | `edit_users` | Critical |
| People | Delete users | `delete_users`, `remove_users` | Critical |
| People | Change users' roles | `promote_users` | Critical |
| People | Switch to other users | `blt_switch_users` (hint: needs "See the list of users") | Critical |
| People | Change other users' usernames | `blt_change_usernames` | Critical |
| People | Change their own username | `blt_change_own_username` | Standard |
| BLT Toolkit | Edit roles (Simple) | `blt_edit_roles` | Critical |
| BLT Toolkit | Edit capabilities (Advanced, per-user) | `blt_edit_capabilities` | Critical |
| BLT Toolkit | Create roles | `blt_create_roles` | Critical |
| BLT Toolkit | Delete roles | `blt_delete_roles` | Critical |
| BLT Toolkit | Reset, restore and import roles | `blt_reset_roles` | Critical |
| BLT Toolkit | View Toolkit activity | `blt_view_toolkit_log` | Elevated |

Other BLT plugins may register their own Simple sections through `blt_toolkit_simple_controls` (for example BLT Documents' document management or BLT Events' event management). Third-party groups (for example Gravity Forms) may be added the same way once their capability names are verified against that plugin's documentation; until then their capabilities appear under "Other plugin permissions" and in Advanced.

---

## Appendix B. Parity checklist

| Source feature | Source | BLT |
| --- | --- | --- |
| Switch from Users list, profile, author archive toolbar | User Switching | Keep (SW-12, SW-13, SW-15) |
| Switch back from toolbar, notice, footer, login screen, `wp_die` pages, Meta widget | User Switching | Keep (SW-14, SW-16, SW-17, SW-18); Meta widget link kept |
| Switch off | User Switching | Keep (SW-6) |
| Nested switching | User Switching | Keep (SW-4, SW-5) |
| Remember-me inheritance | User Switching | Keep (SW-7) |
| Duplicate-switch warning | User Switching | Keep (SW-8) |
| Context-aware redirects | User Switching | Keep (SW-9) |
| REST field, command palette, locale switching | User Switching | Keep (SW-26, SW-27, SW-11) |
| WooCommerce integration | User Switching | Keep (SW-22) |
| BuddyPress and bbPress buttons | User Switching | Drop (v1) |
| `switch_users` grant and deny semantics | User Switching | Change (`blt_switch_users`, D3) |
| Change username on profile | Username Changer | Keep, rebuilt (UN-16 to UN-18) |
| Self-service by role | Username Changer | Keep as a capability (UN-20) |
| Minimum length | Username Changer | Keep, server-side (UN-2) |
| Email notification with tags | Username Changer | Keep (UN-15) |
| Display name and nickname follow the login | Username Changer | Keep (UN-10 step 6) |
| Audit log and CSV export (Pro) | Username Changer | Keep in the unified log (LOG) |
| Bulk rename and CSV (Pro) | Username Changer | Keep, with planner and job (UN-30) |
| Co-Authors Plus | Username Changer | Drop (v1) |
| Custom button labels and messages | Username Changer | Drop (translations) |
| Telemetry, licensing | Username Changer | Drop |
| Role capability editor with groups, filter, readable names, deprecated toggle, columns | User Role Editor | Keep (Advanced, 9.3) |
| Add role, copy from role | User Role Editor | Keep with SEC-3 (RO-30) |
| Rename role | User Role Editor | Keep (RO-31) |
| Delete unused roles, multi-select | User Role Editor | Keep with reassign and merge (RO-40 to RO-42) |
| Add and delete capabilities | User Role Editor | Keep with orphan rules (RO-23, RO-24) |
| Default role and other default roles | User Role Editor | Keep (US-2) |
| Multiple roles per user | User Role Editor | Keep (US-1) |
| Grant, add and revoke roles in bulk | User Role Editor | Keep (US-3) |
| Users without a role | User Role Editor | Keep, on demand (US-12) |
| Per-user capabilities | User Role Editor | Keep (US-10) |
| Hide admin bar per role | User Role Editor | Keep (9.5) |
| Protect administrators | User Role Editor | Keep, rebuilt (SEC-1, SEC-6) |
| Reset roles | User Role Editor | Keep with snapshots and partial reset (RO-43) |
| Backup at first activation | User Role Editor | Keep as baseline snapshot (SNAP-2) |
| Export and import roles (Pro) | User Role Editor | Keep (RO-44, RO-45) |
| WooCommerce capability groups | User Role Editor | Keep via catalog groups (RO-13) |
| Multisite network update | User Role Editor | Defer (D1) |
| Pro access-restriction modules | User Role Editor | Drop (section 20) |

---

## Appendix C. Glossary

| Term | Meaning |
| --- | --- |
| Primitive capability | A capability stored on roles or users, such as `edit_posts`. |
| Meta capability | A capability checked against an object, such as `edit_post` with a post ID, which `map_meta_cap()` translates into primitives. Never stored. |
| Capability group | Content types whose capability strings are identical. They share permissions whether or not anyone intended it. |
| Separate permissions (isolation) | Re-registering a CPT that shares Posts' capabilities with its own `capability_type`, seeded so nobody's access changes on day one. |
| Marker capability | `blt_readonly_{plural}`. Grants nothing by itself; tells BLT Toolkit to enforce read-only on that type. |
| Effective capabilities | `WP_User::$allcaps`: role capabilities merged with per-user grants and denials. |
| Superset rule | SEC-2 and SEC-3: a non-admin can never act through, assign or grant more than they hold. |
| Snapshot | A full copy of the roles option taken before a write, restorable with a diff preview. |
| Acting as | The user a change was made as, when the human who made it had switched into that account. |
