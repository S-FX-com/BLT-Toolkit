# Hooks

Every public action and filter in BLT Toolkit, seeded from [SPEC.md](SPEC.md) section 16. A shipped hook name is never renamed.

**Status.** BLT Toolkit is built phase by phase (SPEC 17). "Planned (Phase N)" means the hook is specified and arrives with that phase; it becomes "Available" when the phase lands. Until then, code that adds a callback to a planned hook is harmless but nothing calls it.

**Signatures.** SPEC 16 fixes the name and, for most hooks, the arguments and default. Where it fixes only the first argument, any further argument shown here is marked *proposed*: it is confirmed, and this file corrected, when the hook ships.

| Hook | Type | Requirement | Status | Purpose |
| --- | --- | --- | --- | --- |
| [`blt_toolkit_switched_to`](#blt_toolkit_switched_to) | action | SW-4 | Available | After switching to a user |
| [`blt_toolkit_switched_back`](#blt_toolkit_switched_back) | action | SW-5 | Available | After switching back |
| [`blt_toolkit_switched_off`](#blt_toolkit_switched_off) | action | SW-6 | Available | After switching off |
| [`blt_toolkit_before_username_change`](#blt_toolkit_before_username_change) | action | UN-10 | Available | Before a username is written |
| [`blt_toolkit_after_username_change`](#blt_toolkit_after_username_change) | action | UN-10 | Available | After a username changed |
| [`blt_toolkit_before_roles_save`](#blt_toolkit_before_roles_save) | action | RO-4 | Available | Before role capabilities are written |
| [`blt_toolkit_after_roles_save`](#blt_toolkit_after_roles_save) | action | RO-4 | Available | After role capabilities were written |
| [`blt_toolkit_role_created`](#blt_toolkit_role_created) | action | RO-30 | Available | After a role is added |
| [`blt_toolkit_role_deleted`](#blt_toolkit_role_deleted) | action | RO-40, RO-42 | Available | After a role is deleted |
| [`blt_toolkit_role_renamed`](#blt_toolkit_role_renamed) | action | RO-31 | Available | After a role's display name changes |
| [`blt_toolkit_post_type_isolated`](#blt_toolkit_post_type_isolated) | action | RS-30 | Available | A post type was given separate permissions |
| [`blt_toolkit_post_type_shared`](#blt_toolkit_post_type_shared) | action | RS-32 | Available | A post type shares Posts' permissions again |
| [`blt_toolkit_before_job`](#blt_toolkit_before_job) | action | JOB-1 to JOB-5 | Available | A background job starts |
| [`blt_toolkit_after_job`](#blt_toolkit_after_job) | action | JOB-1 to JOB-5 | Available | A background job ends |
| [`blt_toolkit_logged`](#blt_toolkit_logged) | action | LOG-6 | Available | After a log row is written |
| [`switch_to_user`](#user-switching-compatibility) | action | SW-19 | Available | User Switching compatibility |
| [`switch_back_user`](#user-switching-compatibility) | action | SW-19 | Available | User Switching compatibility |
| [`switch_off_user`](#user-switching-compatibility) | action | SW-19 | Available | User Switching compatibility |
| [`blt_toolkit_modules`](#blt_toolkit_modules) | filter | SPEC 4.1 | Available | The module registry |
| [`blt_toolkit_is_toolkit_admin`](#blt_toolkit_is_toolkit_admin) | filter | SPEC 5.2 | Available | Who counts as an administrator |
| [`blt_toolkit_switch_redirect`](#blt_toolkit_switch_redirect) | filter | SW-9 | Available | Where a switch lands |
| [`blt_toolkit_switch_show_footer_link`](#blt_toolkit_switch_show_footer_link) | filter | SW-17 | Available | The fixed "Switch back" link on the site |
| [`blt_toolkit_switch_send_cookies`](#blt_toolkit_switch_send_cookies) | filter | SW-3 | Available | Whether switch cookies are sent |
| [`blt_toolkit_switch_search_limit`](#blt_toolkit_switch_search_limit) | filter | SW-20 | Available | Toolbar search result cap |
| [`blt_toolkit_validate_username`](#blt_toolkit_validate_username) | filter | UN-9 | Available | Add or remove username errors |
| [`blt_toolkit_username_min_length`](#blt_toolkit_username_min_length) | filter | UN-2 | Available | Minimum username length |
| [`blt_toolkit_disallowed_usernames`](#blt_toolkit_disallowed_usernames) | filter | UN-4 | Available | Disallowed usernames |
| [`blt_toolkit_username_email`](#blt_toolkit_username_email) | filter | UN-15 | Available | The rename notification email |
| [`blt_toolkit_bulk_rename_max_rows`](#blt_toolkit_bulk_rename_max_rows) | filter | UN-30 | Available | Bulk rename CSV row cap |
| [`shortcode_atts_blt_toolkit_username_form`](#shortcode_atts_blt_toolkit_username_form) | filter | UN-23 | Available | The username form's shortcode attributes (core's filter) |
| [`blt_toolkit_risky_caps`](#blt_toolkit_risky_caps) | filter | SPEC 5.4, SEC-10, SEC-11 | Available | Capability risk tiers |
| [`blt_toolkit_lockout_critical_caps`](#blt_toolkit_lockout_critical_caps) | filter | SEC-12 | Available | Capabilities administrator always keeps |
| [`blt_toolkit_capability_labels`](#blt_toolkit_capability_labels) | filter | RO-12 | Available | Plain-English capability labels |
| [`blt_toolkit_capability_groups`](#blt_toolkit_capability_groups) | filter | RO-11, RO-13 | Available | Named capability groups |
| [`blt_toolkit_orphan_family_labels`](#blt_toolkit_orphan_family_labels) | filter | RO-11, RO-13, SPEC 15 | Available | Labels of orphaned capability families whose plugin is known |
| [`blt_toolkit_simple_controls`](#blt_toolkit_simple_controls) | filter | RS-3 | Available | Simple view controls |
| [`blt_toolkit_isolation_excluded_types`](#blt_toolkit_isolation_excluded_types) | filter | RS-30, RS-35 | Available | Post types that cannot get separate permissions |
| [`blt_toolkit_isolation_caps`](#blt_toolkit_isolation_caps) | filter | RS-31 | Available | Arguments applied to an isolated post type |
| [`blt_toolkit_hide_admins_from_non_admins`](#blt_toolkit_hide_admins_from_non_admins) | filter | SEC-6 | Available | Hide administrators in the Users list and REST |
| [`blt_toolkit_snapshot_retention`](#blt_toolkit_snapshot_retention) | filter | SNAP-3 | Available | Snapshots kept besides the baseline |
| [`blt_toolkit_log_retention_days`](#blt_toolkit_log_retention_days) | filter | LOG-4 | Available | Days log rows are kept |
| [`blt_toolkit_tick_budget`](#blt_toolkit_tick_budget) | filter | JOB-2 | Available | Seconds one job tick may run |
| [`current_user_switched()`](#current_user_switched) | function | SW-19 | Available | User Switching compatibility shim |

---

## Actions

### `blt_toolkit_switched_to`

Fires after the current user has switched to another user: the auth cookies are set and the current user is the target, before the redirect (SW-4 step 6). The log row is written just before the identity changes, so its `acting_as_id` is the user switched from (`0` when that is the person who signed in) and its `switch_chain` ends with them (LOG-2).

| Argument | Type | Description |
| --- | --- | --- |
| `$target_id` | int | The user switched to. |
| `$actor_id` | int | The user who switched. |
| `$new_token` | string | Session token of the session created for the target. |
| `$old_token` | string | Session token of the actor's session, which switching back returns to. |

Requirement SW-4. Status: Available.

### `blt_toolkit_switched_back`

Fires after a user has switched back to the account they came from (SW-5): the stack is popped and the original session restored. The session that was made for the target is destroyed right after this action, as User Switching does, so a callback can still read it. The log row is written before the identity changes.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The user switched back to. |
| `$from_id` | int\|false | The user switched back from, or `false` when switching back after a Switch Off (nobody was signed in). |
| `$new_token` | string | Session token of the restored session. |
| `$old_token` | string | Session token of the session being left (`''` after a Switch Off). |

Requirement SW-5. Status: Available.

### `blt_toolkit_switched_off`

Fires after a user has switched off (SW-6): they are pushed onto the switch stack and signed out, and the login screen and site footer then offer to switch back. Their session is kept, so switching back returns to it. The log row is written before they are signed out.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The user who switched off. |
| `$token` | string | Session token of the session they switched off from. |

Requirement SW-6. Status: Available.

### `blt_toolkit_before_username_change`

Fires inside the rename procedure (`BLT_Toolkit_Username_Changer::change()`) after the change is authorized, the new name validated, the current password checked when UN-22 asks for it and a switched session confirmed (UN-12), and before anything is written (UN-10 step 3). Every path fires it: the profile screen, the bulk rename and the self-service form.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The user being renamed. |
| `$old` | string | The current login. |
| `$new` | string | The new login (trimmed, exactly as it will be stored). |

Requirement UN-10. Status: Available.

### `blt_toolkit_after_username_change`

Fires after a username changed: the login, author slug, display name and nickname are written, both user caches are cleared, and sessions are handled (UN-10 step 9). The change is logged and the optional email sent at the same point. Fires once per user in a bulk rename.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The renamed user. |
| `$old` | string | The previous login. |
| `$new` | string | The new login. |
| `$result` | array | What the procedure returns: `old_login`, `new_login`, `nicename_changed` (bool), `old_nicename`, `sessions_ended` (int, sessions destroyed), plus `new_nicename` and `session_kept` (bool: a self rename kept the current session, UN-14). |

The log row (`usernames` / `username_changed`) is written right after this action, then the optional email is sent.

Requirement UN-10. Status: Available.

### `blt_toolkit_before_roles_save`

Fires after the Guard has approved a computed change set (and the SEC-10 review was confirmed) and the SNAP-1 snapshot was stored, just before the roles option is written once (RO-3, RO-4). Every BLT Toolkit write to the roles option fires it: `BLT_Toolkit_Role_Store::save_caps()` and `transaction()` (which every Tools write uses: snapshot restore, both resets, Sync administrator, role import and the legacy importers), and SEC-14 recovery, which keeps its own path because it must go ahead when the snapshot fails. Uninstall's removal of BLT Toolkit's own capabilities does not fire it. A save that changes nothing, a dry run and a refused save fire nothing.

| Argument | Type | Description |
| --- | --- | --- |
| `$changes` | array | Role ID => `array( 'add' => array<string,bool>, 'remove' => string[], 'created' => bool, 'deleted' => bool, 'name' => null or array( 'from' => string, 'to' => string ) )`. `add` holds the new value of each capability added or flipped (`true` grants, `false` denies); `remove` the capabilities taken out of the role. A created role lists all its capabilities in `add`, a deleted one all of them in `remove`. |
| `$context` | string | Where the save came from: `advanced` (the Advanced matrix, and adding or deleting a capability there; the default for a one-role save), `simple` (Simple view; needs `blt_edit_roles`, while `advanced` needs `blt_edit_capabilities` too), `self_service` (Settings > Usernames, UN-20), `support_agent` (SW-21), `lifecycle` (add, rename, move to a new role, delete and merge, RO-30 to RO-42), `activation` and `sync` (the administrator grant), `recovery` (SEC-14), `isolation` (separate permissions for a post type and their cleanup, RS-30, RS-32), `import` (Tools > Export and import, RO-45: the roles of an imported file, in one write with a `before_import` snapshot), `restore` (Tools > Snapshots, SNAP-4: the whole roles array put back as the snapshot held it, with a `before_restore` snapshot), `reset` (Tools > Reset, RO-43, either mode, with a `before_reset` snapshot), `sync` (also Tools > Sync administrator, RO-5: exactly the capabilities its preview listed), `legacy_import` (Tools > Import from legacy plugins, SPEC 15: Username Changer's Allowed Roles as `blt_change_own_username`, with a `before_import` snapshot) and `legacy_cleanup` (the same tab's removal of User Role Editor's `ure_*` capabilities, with a `before_save` snapshot). A `transaction()` without one passes `roles`. A restore or reset that adds or removes whole roles reports them here (`created`, `deleted`) and does not fire `blt_toolkit_role_created` or `blt_toolkit_role_deleted`. |

Requirement RO-4. Status: Available.

### `blt_toolkit_after_roles_save`

Fires after the roles option was written once (RO-3), the role objects reloaded from it, and the change logged (RO-4). A write the database refused fires nothing here (it is logged with status `failure`).

| Argument | Type | Description |
| --- | --- | --- |
| `$changes` | array | The change set that was applied, as for `blt_toolkit_before_roles_save`. |
| `$context` | string | As for `blt_toolkit_before_roles_save`. |
| `$snapshot_id` | int | The snapshot taken before the write (SNAP-1). When the roles already matched the newest snapshot, that snapshot's ID. `0` only for SEC-14 recovery whose snapshot failed. |

Requirement RO-4. Status: Available.

### `blt_toolkit_role_created`

Fires after BLT Toolkit added a role and the roles option was written (RO-30), including the copy made by "Move users to a new role" (RO-32) and the Support Agent role (SW-21). A dry run, a refused add and an add awaiting the SEC-10 typed confirmation fire nothing. Import (RO-45) fires it for each role it creates, after the import's one roles write, with `$from` `import`.

| Argument | Type | Description |
| --- | --- | --- |
| `$role_id` | string | The new role's ID. |
| `$display_name` | string | Its display name, as stored. |
| `$from` | string | What it started from: `blank` (`read` only), `subscriber`, `contributor`, `author` or `editor` (WordPress's default capabilities for that role), `copy:` followed by the copied role's ID (a copy, and every RO-32 move), `support_agent` (SW-21), or `import` (a role created by Tools > Export and import, RO-45). |

Requirement RO-30. Status: Available.

### `blt_toolkit_role_deleted`

Fires after a role is deleted (RO-40), including at the end of a merge (RO-42). A snapshot restore or a full reset (Tools) that removes roles does not fire it; those writes list the removed roles in `blt_toolkit_after_roles_save` (`deleted`).

| Argument | Type | Description |
| --- | --- | --- |
| `$role_id` | string | The deleted role's ID. |
| `$replacement` | string | The role its holders were given instead (RO-41 and RO-42), or `''` when nobody held it. |

Fires once per role after the roles option was written, so a multi-role delete fires it for each. A role that people hold is deleted by the `role_reassign` job once nobody holds it, so for it the action fires inside the job's last batch (a page tick, a loopback or cron), not on the request that asked.

Requirement RO-40, RO-42. Status: Available.

### `blt_toolkit_role_renamed`

Fires after a role's display name changes (RO-31). A role ID never changes in place (RO-32).

| Argument | Type | Description |
| --- | --- | --- |
| `$role_id` | string | The role's ID. |
| `$old_name` | string | The previous display name, as stored. |
| `$new_name` | string | The new display name. |

Requirement RO-31. Status: Available.

### `blt_toolkit_post_type_isolated`

Fires after a post type was given separate permissions: the seed capabilities are written in one batch and the type is stored under `roles.isolated_post_types` (RS-30 step 3). The new capabilities apply from the next request.

| Argument | Type | Description |
| --- | --- | --- |
| `$post_type` | string | The post type. |

Fired by `BLT_Toolkit_Isolation::isolate()` after the roles were written in one batch through the role store (snapshot reason `before_isolation`, log action `post_type_isolated`), users with Posts or Pages capabilities of their own were updated, and the type was stored with its `since` time.

Requirement RS-30. Status: Available.

### `blt_toolkit_post_type_shared`

Fires after a post type was reverted to sharing Posts' (or Pages') permissions (RS-32). The now-unused capabilities stay on roles, flagged as orphans, until the separate cleanup step removes them.

| Argument | Type | Description |
| --- | --- | --- |
| `$post_type` | string | The post type. |

Fired by `BLT_Toolkit_Isolation::revert()` after the type was taken off `roles.isolated_post_types` (log action `post_type_shared`). No role is written; the cleanup step logs `capability_deleted` and fires no hook.

Requirement RS-32. Status: Available.

### `blt_toolkit_before_job`

Fires once when a background job starts, after its settings are pinned (JOB-3) and before its first item, and again when a "Retry failed" pass starts.

| Argument | Type | Description |
| --- | --- | --- |
| `$job` | array | The job state as stored in `blt_toolkit_job_{id}` (`id`, `type`, `mode`, `status`, `owner`, `pinned`, `cursor` and the counters). Its type is one of `username_bulk`, `role_reassign`, `log_prune` or `count_no_role` (JOB-5), or `legacy_uc_log` (copying Username Changer's audit log, SPEC 15); `log_prune` and `legacy_uc_log` are always registered, `username_bulk` while the Usernames module runs, `role_reassign` and `count_no_role` while the Roles module runs. `chain` holds the switch chain of the request that started the job (user IDs, oldest first; empty when not switched), which every log row its ticks write is attributed to (LOG-2). |

Requirement JOB-1 to JOB-5. Status: Available. The job runner landed in Phase 1 for the daily log prune (LOG-4).

### `blt_toolkit_after_job`

Fires once when a background job ends, whether it finished, was cancelled or was stopped by an error, and again at the end of each "Retry failed" pass. Items that finished before a cancel are kept (JOB-4).

| Argument | Type | Description |
| --- | --- | --- |
| `$job` | array | The final job state, including done and failed counts; `status` is `done`, `cancelled` or `failed`. |

Requirement JOB-1 to JOB-5. Status: Available.

### `blt_toolkit_logged`

Fires after each row is written to the activity log, so other logging plugins can listen (LOG-6). A failed insert does not fire it (LOG-3). One exception: the entries copied from Username Changer's audit log by **Tools > Import from legacy plugins** (`source` `username-changer`) are history, written 250 at a time in one insert, and do not fire it; the `toolkit` / `legacy_imported` summary row each import run writes does (SPEC 15).

| Argument | Type | Description |
| --- | --- | --- |
| `$row` | array | The row as written, with its `id`. Keys below. |

| Key | Type | Notes |
| --- | --- | --- |
| `id` | int | Row ID. |
| `created_at` | string | `Y-m-d H:i:s`, UTC. |
| `module` | string | The module that wrote it, for example `switch`, `usernames` or `roles`. |
| `action` | string | What happened, for example a switch, a rename or a role save. |
| `actor_id`, `actor_login` | int, string | The human who signed in: while switched, the **first** user in the switch stack (LOG-2). |
| `acting_as_id` | int | The current user while switched, otherwise `0` (LOG-2). |
| `object_type`, `object_id` | string, string | What was acted on (a user ID, a role ID, a post type). |
| `summary` | string | One line a person can read. |
| `details` | array | The details as stored, decoded (not the JSON string): what the module recorded (before and after values, a `BLT_Toolkit_Role_Diff::diff()` result). While the actor is switched, a `switch_chain` entry is added: each user's `id` and `login`, oldest first, the current user last (LOG-2). Details too large or too deeply nested to store arrive as the stored marker (`truncated`, `bytes` and the top-level `keys`), and values JSON cannot hold arrive as stored (invalid UTF-8 replaced, for example). An empty array when nothing was recorded. |
| `snapshot_id` | int\|null | The snapshot taken for a role change. |
| `ip` | string | Empty when IP recording is off (LOG-5). |
| `status` | string | `success` by default. |
| `source` | string | Empty for native rows; the plugin's name for imported rows (`username-changer`). |

A listener that throws is reported to the PHP error log and otherwise ignored: the row is already written.

Requirement LOG-6. Status: Available.

---

## Filters

### `blt_toolkit_modules`

The module registry. Each class must implement `BLT_Toolkit_Module` (`key()`, `label()`, `description()`, `boot()`) and be loadable by the autoloader or already loaded. A module still boots only when its setting is on, it is not named in `BLT_TOOLKIT_DISABLE_MODULES`, and its legacy plugin is not active (GEN-1). A class is loaded only to boot it, so the screens ask an added module for its `label()` and `description()` only while it is running, and list it by its key otherwise.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$modules` | array | `switch => BLT_Toolkit_Switch`, `usernames => BLT_Toolkit_Usernames`, `roles => BLT_Toolkit_Roles` | Module key => class name. |

Requirement SPEC 4.1. Status: Available.

### `blt_toolkit_is_toolkit_admin`

Whether a user counts as an administrator for the protection rules (SEC-1, SEC-2) and recovery (SEC-14). The default checks role membership, not capabilities, so it answers correctly after the administrator role's capabilities were damaged. Widening it widens who is protected and who bypasses the superset rules; do so with care.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$is_admin` | bool | Whether the user has the `administrator` role on this site | Return the answer. |
| `$user` | WP_User | | The user. |

The Guard calls it on every guarded capability check (`edit_user`, `delete_user`, `remove_user`, `promote_user`, `blt_switch_to_user`, `blt_change_username`), for the actor and then the target, and in `editable_roles`, SEC-6 hiding and recovery, so keep a callback cheap. Hiding (SEC-6) works by the `administrator` role: users this filter adds are protected by SEC-1 but still listed.

Requirement SPEC 5.2. Status: Available.

### `blt_toolkit_switch_redirect`

Where a switch, switch back or switch off lands, applied after core's `login_redirect` (switch to, switch back) or `logout_redirect` (switch off) (SW-9). Return a non-empty URL to override; it still goes through `wp_safe_redirect()`, so another host needs `allowed_redirect_hosts`. The flag `blt_switched`, `blt_switched_back` or `blt_switched_off` is added to the URL afterwards.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$redirect_to` | string | `''` | `''` keeps `$requested`. |
| `$requested` | string | | The URL resolved per SW-9, default included: `redirect_to`, or the public URL of `redirect_to_post`, `redirect_to_term`, `redirect_to_user` or `redirect_to_comment` (only when publicly viewable), then core's filter, otherwise the default for the action (the dashboard for switch to when the user may `read`, else the home page; `users.php` for switch back; the home page for switch off). |
| `$user` | WP_User\|null | | The user now signed in, or `null` after a switch off. |
| `$action` | string | | `blt_switch_to_user`, `blt_switch_back` or `blt_switch_off`. |

Requirement SW-9. Status: Available.

### `blt_toolkit_switch_show_footer_link`

Whether to print the fixed "Switch back to {Name}" link on the site (SW-17). Whatever this returns, the link is printed only while switched with the toolbar hidden, or after a Switch Off, and not on a page where the Meta widget already printed it (as User Switching does). Its stylesheet, `assets/css/blt-toolkit-front.css`, loads only when the link is printed, and a page that prints it for someone who is signed out (after a Switch Off) is sent with no-cache headers and `DONOTCACHEPAGE` (SW-25). Applied at most once per request, and never for a visitor without a switch cookie.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$show` | bool | The Settings > Switching toggle **Show a "Switch back" link on the site** (`switch.footer_link`, default `true`) | Return `false` to hide it. |

Requirement SW-17. Status: Available.

### `blt_toolkit_switch_send_cookies`

Whether the switch cookies are sent with `setcookie()` (SW-3). Turn it off for tests and headless setups that carry the cookies themselves.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$send` | bool | `true` | Return `false` to suppress sending. |

Requirement SW-3. Status: Available.

### `blt_toolkit_switch_search_limit`

The most results the toolbar "Switch to…" search returns (`GET blt-toolkit/v1/switch/users`).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$limit` | int | `20` | Results; clamped to 1 to 100. Only users the current user may switch to are returned, and at most 500 candidates are read per search. |

Requirement SW-20. Status: Available.

### `blt_toolkit_validate_username`

The last validation rule (UN-9), applied on every path: profile, bulk rename, the REST check and the shortcode. Add an error to refuse a name, or remove one.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$errors` | WP_Error | The errors from rules UN-1 to UN-8 (empty when the name passed) | Return it. |
| `$new` | string | | The proposed username, trimmed. |
| `$user` | WP_User | | The user being renamed. |

Requirement UN-9. Status: Available.

### `blt_toolkit_username_min_length`

The minimum username length (UN-2), enforced on the server. The maximum is always 60, the `user_login` column limit.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$length` | int | The Settings > Usernames field **Minimum length** (`usernames.min_length`, default `3`) | Characters. |

Requirement UN-2. Status: Available.

### `blt_toolkit_disallowed_usernames`

Usernames nobody may change to (UN-4). The result is merged with core's `illegal_user_logins` filter and compared case-insensitively on both sides.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$names` | string[] | The Settings > Usernames field **Names that cannot be used** (`usernames.disallowed`, default `admin`, `administrator`, `root`, `sysadmin`, `webmaster`) | Usernames. |

Requirement UN-4. Status: Available.

### `blt_toolkit_username_email`

The plain-text email sent to a renamed user when the Settings > Usernames toggle **Email the person about the change** (`usernames.notify_user`) is on (UN-15). It is sent with `wp_mail()` and the site's defaults; no `From:` header is built, so SMTP plugins decide.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$email` | array | `to` (the user's email), `subject`, `message`, `headers` (`''`), as core's `wp_new_user_notification_email`, with the tags in the subject and body already replaced and the text in the user's locale | Return it. A result that is not an array, or whose `to` is not an email address, sends nothing. |
| `$user` | WP_User | | The renamed user, as stored after the change. |
| `$old` | string | | The previous login. |
| `$new` | string | | The new login. |

The subject and body come from the Settings > Usernames fields **Email subject** and **Email message** (`usernames.email_subject`, `usernames.email_body`), or a translated default when empty. Tags: `{old_username}`, `{new_username}`, `{display_name}`, `{email}`, `{sitename}`, `{siteurl}`, `{loginurl}`, replaced in one pass.

Requirement UN-15. Status: Available.

### `blt_toolkit_bulk_rename_max_rows`

The most rows with a new username one bulk rename CSV may contain (UN-30). Rows whose `new_username` is empty are skipped and do not count, so the template of a site with more users than this uploads back with a few names filled in. A file with more is refused, not truncated, and an upload larger than this many rows times 1 KB (plus 64 KB), or than the server's upload limit, is refused before it is read. Read when the Usernames screen shows the upload form and when a file is checked.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$rows` | int | `5000` | Rows (at least 1). |

Requirement UN-30. Status: Available.

### `shortcode_atts_blt_toolkit_username_form`

Core's `shortcode_atts_{$shortcode}` filter for `[blt_toolkit_username_form]` (UN-23), applied by `shortcode_atts()` each time the shortcode renders, and also when the page's content is checked early to decide whether the stylesheet goes in the head (`wp_enqueue_scripts`). Use it to set a site-wide default, such as `style="none"` on every form, which then also keeps the stylesheet off the page.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$out` | array | `style` (`styled`), `class` (`''`), merged with what the shortcode gave | Return it. `style` is `styled` or `none` (`no-css` also means `none`; anything else reads as `styled`); `class` is a space-separated list of extra wrapper classes, each sanitized with `sanitize_html_class()`. |
| `$pairs` | array | | The defaults. |
| `$atts` | array | | The attributes as written in the shortcode. |
| `$shortcode` | string | | `blt_toolkit_username_form`. |

Requirement UN-23. Status: Available.

### `blt_toolkit_risky_caps`

The risk tiers (SPEC 5.4) behind the tier badges (SEC-11) and the Critical review step (SEC-10). Standard is everything else, so it has no list. A trailing `*` is a prefix wildcard (`edit_others_*`). A capability on both lists is Critical. The filtered value is read once per request.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$tiers` | array | `critical`: `manage_options`, `activate_plugins`, `install_plugins`, `update_plugins`, `delete_plugins`, `edit_plugins`, `install_themes`, `update_themes`, `delete_themes`, `edit_themes`, `update_core`, `unfiltered_html`, `unfiltered_upload`, `edit_users`, `create_users`, `delete_users`, `remove_users`, `promote_users` and every Critical capability in SPEC 5.1. `elevated`: `list_users`, `export`, `import`, `edit_theme_options`, `switch_themes`, `moderate_comments`, `manage_categories`, `edit_others_*`, `delete_others_*`, `blt_view_toolkit_log`. | `array( 'critical' => string[], 'elevated' => string[] )`. |

```php
add_filter(
	'blt_toolkit_risky_caps',
	function ( $tiers ) {
		$tiers['critical'][] = 'acme_manage_payments';
		return $tiers;
	}
);
```

Requirement SPEC 5.4, SEC-10, SEC-11. Status: Available. The role store's SEC-10 review (`BLT_Toolkit_Guard::requires_critical_review()`) and every capability catalog entry's `tier` (the tier badges, SEC-11) read it.

### `blt_toolkit_lockout_critical_caps`

The capabilities that can never be removed from the `administrator` role, and that recovery mode re-grants (SEC-12, SEC-14). Entries can be added; the built-in ones always stay.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$caps` | string[] | `read`, `manage_options`, `list_users`, `edit_users`, `promote_users`, `activate_plugins`, `blt_edit_roles`, `blt_edit_capabilities` | Capability names. |

Used by recovery mode (SEC-14), by snapshot restore (which always leaves `administrator` holding the set) and by `BLT_Toolkit_Guard::check_role_change()` for SEC-12, which the role editor calls from Phase 4.

Requirement SEC-12. Status: Available.

### `blt_toolkit_capability_labels`

Plain-English labels for capabilities (RO-12). The raw name is always shown next to the label as a code chip.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$labels` | array | The built-in dictionary for core capabilities, plus labels built from each post type's and taxonomy's own labels ("Edit others' Events") | Capability => label, for every capability in the catalog (core, content types, taxonomies, BLT Toolkit's own, declared groups, and any capability found on a role or user). Capabilities with no label are humanized from their name (`gravityforms_edit_forms` reads "Gravityforms edit forms"). Return the array; only non-empty strings for capabilities already in it are used. |

Applied once per request, when `BLT_Toolkit_Capability_Catalog` is first built (only on BLT roles screens and their write paths), and again for `BLT_Toolkit_Capability_Catalog::label()` before the full catalog exists.

Requirement RO-12. Status: Available.

### `blt_toolkit_capability_groups`

Named groups of capabilities for the Advanced view's group tree (RO-13). A capability declared here is registered, so it is never flagged as orphaned (RO-11) and never offered for deletion (RO-24).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$groups` | array | WooCommerce's `manage_woocommerce` and `view_woocommerce_reports` (key `woocommerce`) while WooCommerce is active, and Gravity Forms' `GFCommon::all_caps()` (key `gravityforms`) while Gravity Forms is active | Group key => `array( 'label' => string, 'caps' => string[] )`. The key becomes the tree node `plugin-{key}` (lowercase letters, digits, `_` and `-`); a missing label is the key humanized; groups without capabilities are dropped. |

A capability declared here that core, a post type or a taxonomy also registers keeps that as its source and is also listed in the group's node. Capabilities nobody declares that share a prefix with two or more others (`gravityforms_*`, `manage_woocommerce` and `view_woocommerce_reports`) are grouped by that prefix too, but stay flagged as orphaned.

```php
add_filter(
	'blt_toolkit_capability_groups',
	function ( $groups ) {
		$groups['acme'] = array(
			'label' => 'Acme Bookings',
			'caps'  => array( 'acme_manage_bookings', 'acme_view_reports' ),
		);
		return $groups;
	}
);
```

Requirement RO-11, RO-13. Status: Available.

### `blt_toolkit_orphan_family_labels`

Labels for families of orphaned capabilities whose plugin is known (RO-11, RO-13). A family is the first word of a capability's name that is not a common verb (`ure` for `ure_edit_roles`, as `BLT_Toolkit_Capability_Catalog::prefix_family()` reads it). Its orphans are grouped under Plugins with this label and that label as their source, however few they are, and they stay orphaned: nothing registers them, so RO-24 may still delete them, and Sync administrator never grants them.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$labels` | array | `array( 'ure' => 'From User Role Editor' )` (SPEC 15, `BLT_Toolkit_Legacy::orphan_families()`) | Family => label. Families are matched in lowercase and must be two or more letters or digits; empty labels are dropped. |

Applied once per request, when `BLT_Toolkit_Capability_Catalog` is built (only on BLT roles screens, Tools and their write paths).

Requirement RO-11, RO-13, SPEC 15. Status: Available.

### `blt_toolkit_simple_controls`

The controls Simple view shows (RS-3). Other BLT plugins register their own here (for example BLT Documents' document management or BLT Events' event management), in a built-in section or a new one. Simple view saves only controls that changed, and only within each control's capabilities (RS-5); the Guard applies to every save (SEC-2, SEC-3, SEC-5, SEC-10, SEC-12, SEC-13). Applied once per request, only on the Roles screen and its save handler (`BLT_Toolkit_Simple_Controls::definitions()`).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$controls` | array[] | The built-in controls: Basics (`read`, and `hide_toolbar`, the role setting of SPEC 9.5), one content row per capability group (`content-{type}`, SPEC 10.2 to 10.4), then the Appendix A controls in its order | Control definitions, a list. |

Each control is an array:

| Key | Type | Description |
| --- | --- | --- |
| `id` | string | Unique, `[a-z0-9_-]`. A duplicate ID is dropped. |
| `section` | string | `basics`, `content`, `media`, `appearance`, `plugins`, `site`, `people` or `blt`, or a new section (drawn as its own card before "Other plugin permissions"). |
| `section_label` | string | Optional. The card title of a new section (default: the section ID humanized). |
| `label`, `help` | string | What the control is called and the line under it. |
| `kind` | string | `toggle` (default) or `level`. |
| `caps` | string[] | A toggle's capabilities, turned on and off together; partly granted reads as indeterminate (RS-4). |
| `map` | array | A `level` row's type map for `BLT_Toolkit_Simple_Mapper` (see that class). |
| `tier` | string | Optional. `critical`, `elevated` or `standard`; the default is the highest tier among its capabilities (SEC-11). |
| `depends` | array[] | Optional RS-7 hints: each `array( 'control' => ID, 'text' => string, 'min' => level )` (`min` for level rows). Shown, with "Also allow", while the control is on and the one it needs is off. |
| `note` | string | Optional. An extra line under the control. |
| `available` | bool\|callable | Optional. False (or a callable returning false) leaves the control out. |

Each capability a control covers is named in the Advanced matrix as "Simple: Events → level" or "Simple: Site → Change settings" (RO-21).

Requirement RS-3. Status: Available.

### `blt_toolkit_isolation_excluded_types`

Post types that are never offered "Give {Type} separate permissions" (RS-30), for example a type whose plugin checks `edit_posts` directly instead of the type's own capability (RS-35).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$types` | string[] | `array( 'bricks_template' )` (RS-35; the reason is in `tasks/lessons.md`, Phase 5 isolation) | Post type names. |

Requirement RS-30, RS-35. Status: Available.

### `blt_toolkit_isolation_caps`

The `register_post_type_args` changes applied to each isolated post type (RS-31). The filter that applies them is added when the main file loads, so it sees types registered before `plugins_loaded`.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$args` | array | `capability_type => array( singular, plural )`, `map_meta_cap => true`, `capabilities => array( 'create_posts' => "create_{plural}" )` | Arguments merged into the type's registration. |
| `$post_type` | string | | The post type. |
| `$isolation` | array | | Its stored entry: `singular`, `plural`, `since` (`since` is 0 while a dry run computes the new capabilities). |

The dry run, the collision check, the cleanup and the RS-33 verification build the new capability strings through the same filter, so a change made here is what the preview shows and what verification expects. Return an array; anything else is ignored. A `do_not_allow` value in the type's own `capabilities` registration is kept over these arguments; any other override of Posts' (or Pages') strings stops the type from being isolated, and RS-33 reports it.

Requirement RS-31. Status: Available.

### `blt_toolkit_hide_admins_from_non_admins`

Whether administrators are hidden from non-administrators in the Users list and the REST users collection (SEC-6). The role views and counts on the Users screen are adjusted to match.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$hide` | bool | The Settings > Roles toggle **Hide administrators from everyone else** (`roles.hide_admins`, default `true`) | Return `false` to show them. |
| `$user` | WP_User | | The user who is looking: never a toolkit admin (administrators always see everyone, and the filter is not called for them), and `WP_User` 0 for an anonymous REST request. |

The REST users collection is filtered for anonymous visitors too, so the public `/wp/v2/users` no longer lists administrator authors; return `false` when `$user->ID === 0` to keep listing administrator authors to visitors.

Requirement SEC-6. Status: Available.

### `blt_toolkit_snapshot_retention`

How many role snapshots are kept, newest first, besides the baseline, which is never pruned (SNAP-2, SNAP-3).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$count` | int | The Settings > Roles field **Snapshots to keep** (`roles.snapshot_retention`, default `50`) | Snapshots. A value below 1 is read as 1: the newest snapshot is always kept. |

Applied after each new snapshot, when older ones are pruned.

Requirement SNAP-3. Status: Available.

### `blt_toolkit_log_retention_days`

How many days activity log rows are kept. A daily event prunes older rows in batches of 1,000 (LOG-4).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$days` | int | The Settings > Activity log field **Keep entries for** (`log.retention_days`, default `365`) | Days. A value below 1 keeps every row: nothing is pruned. |

Read once a day when the prune starts; the cutoff is pinned for that run (JOB-3).

Requirement LOG-4. Status: Available.

### `blt_toolkit_tick_budget`

Seconds one background job tick may run before it saves its state and yields (JOB-2).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$seconds` | int | `15` | Seconds, at least 1. Capped at 60 percent of PHP's `max_execution_time` when one is set. A value that is not a positive number means the default. |

It also bounds the log prune's inline fallback, which runs inside the cron event when the job runner cannot start a job, read the same way.

Requirement JOB-2. Status: Available.

---

## User Switching compatibility

When the Settings > Switching toggle **Work with tools made for User Switching** (`switch.compat_hooks`, the "Compatibility hooks" setting of SW-19) is on (the default, D2), BLT Toolkit also fires User Switching's action names with User Switching 1.12.2's signatures, so activity loggers and debugging tools that integrate with User Switching keep working (SW-19). They fire right after the matching `blt_toolkit_*` action. Requirement SW-19. Status: Available.

| Action | Arguments |
| --- | --- |
| `switch_to_user` | `int $user_id` (switched to), `int $old_user_id` (switched from), `string $new_token`, `string $old_token`. Unlike User Switching, which passes `''`, `$new_token` is the token of the session just made for the target. |
| `switch_back_user` | `int $user_id` (switched back to), `int\|false $old_user_id` (switched back from, or `false` after a Switch Off), `string $new_token`, `string $old_token` |
| `switch_off_user` | `int $old_user_id` (the user switching off), `string $old_token` |

These are action names only. BLT Toolkit never declares the functions `switch_to_user()`, `switch_off_user()` or `user_switching_*()`, which belong to User Switching (GEN-2). While User Switching is active, BLT Toolkit's switch module does not boot (GEN-1), so the two never fire these actions side by side.

### `current_user_switched()`

```php
current_user_switched(): WP_User|false
```

Returns the user who switched into the current session (the most recent originating user), or `false` when the current user is not switched or nobody is signed in. Code written for User Switching can call it unchanged. Like User Switching's, it reads the old-user cookie, which is sent on the site's front end and REST requests too; unlike it, the cookie counts only for the session that switch created (`blt_switched_from_id` and `blt_switched_from_session` on the current session), so switch cookies left behind by someone else in the same browser never make a signed-in user look switched.

- Defined only when **Work with tools made for User Switching** (`switch.compat_hooks`) is on and no function of that name exists yet (SW-19, D2). It is the plugin's only global symbol without the `blt_toolkit_` prefix.
- Guard calls with `function_exists( 'current_user_switched' )`, as with User Switching.

Requirement SW-19. Status: Available.
