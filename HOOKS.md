# Hooks

Every public action and filter in BLT Toolkit, seeded from [SPEC.md](SPEC.md) section 16. A shipped hook name is never renamed.

**Status.** BLT Toolkit is built phase by phase (SPEC 17). "Planned (Phase N)" means the hook is specified and arrives with that phase; it becomes "Available" when the phase lands. Until then, code that adds a callback to a planned hook is harmless but nothing calls it.

**Signatures.** SPEC 16 fixes the name and, for most hooks, the arguments and default. Where it fixes only the first argument, any further argument shown here is marked *proposed*: it is confirmed, and this file corrected, when the hook ships.

| Hook | Type | Requirement | Status | Purpose |
| --- | --- | --- | --- | --- |
| [`blt_toolkit_switched_to`](#blt_toolkit_switched_to) | action | SW-4 | Planned (Phase 2) | After switching to a user |
| [`blt_toolkit_switched_back`](#blt_toolkit_switched_back) | action | SW-5 | Planned (Phase 2) | After switching back |
| [`blt_toolkit_switched_off`](#blt_toolkit_switched_off) | action | SW-6 | Planned (Phase 2) | After switching off |
| [`blt_toolkit_before_username_change`](#blt_toolkit_before_username_change) | action | UN-10 | Planned (Phase 3) | Before a username is written |
| [`blt_toolkit_after_username_change`](#blt_toolkit_after_username_change) | action | UN-10 | Planned (Phase 3) | After a username changed |
| [`blt_toolkit_before_roles_save`](#blt_toolkit_before_roles_save) | action | RO-4 | Planned (Phase 4) | Before role capabilities are written |
| [`blt_toolkit_after_roles_save`](#blt_toolkit_after_roles_save) | action | RO-4 | Planned (Phase 4) | After role capabilities were written |
| [`blt_toolkit_role_created`](#blt_toolkit_role_created) | action | RO-30 | Planned (Phase 4) | After a role is added |
| [`blt_toolkit_role_deleted`](#blt_toolkit_role_deleted) | action | RO-40, RO-42 | Planned (Phase 4) | After a role is deleted |
| [`blt_toolkit_role_renamed`](#blt_toolkit_role_renamed) | action | RO-31 | Planned (Phase 4) | After a role's display name changes |
| [`blt_toolkit_post_type_isolated`](#blt_toolkit_post_type_isolated) | action | RS-30 | Planned (Phase 5) | A post type was given separate permissions |
| [`blt_toolkit_post_type_shared`](#blt_toolkit_post_type_shared) | action | RS-32 | Planned (Phase 5) | A post type shares Posts' permissions again |
| [`blt_toolkit_before_job`](#blt_toolkit_before_job) | action | JOB-1 to JOB-5 | Planned (Phase 3) | A background job starts |
| [`blt_toolkit_after_job`](#blt_toolkit_after_job) | action | JOB-1 to JOB-5 | Planned (Phase 3) | A background job ends |
| [`blt_toolkit_logged`](#blt_toolkit_logged) | action | LOG-6 | Planned (Phase 1) | After a log row is written |
| [`switch_to_user`](#user-switching-compatibility) | action | SW-19 | Planned (Phase 2) | User Switching compatibility |
| [`switch_back_user`](#user-switching-compatibility) | action | SW-19 | Planned (Phase 2) | User Switching compatibility |
| [`switch_off_user`](#user-switching-compatibility) | action | SW-19 | Planned (Phase 2) | User Switching compatibility |
| [`blt_toolkit_modules`](#blt_toolkit_modules) | filter | SPEC 4.1 | Available | The module registry |
| [`blt_toolkit_is_toolkit_admin`](#blt_toolkit_is_toolkit_admin) | filter | SPEC 5.2 | Planned (Phase 1) | Who counts as an administrator |
| [`blt_toolkit_switch_redirect`](#blt_toolkit_switch_redirect) | filter | SW-9 | Planned (Phase 2) | Where a switch lands |
| [`blt_toolkit_switch_show_footer_link`](#blt_toolkit_switch_show_footer_link) | filter | SW-17 | Planned (Phase 2) | The fixed "Switch back" link on the site |
| [`blt_toolkit_switch_send_cookies`](#blt_toolkit_switch_send_cookies) | filter | SW-3 | Planned (Phase 2) | Whether switch cookies are sent |
| [`blt_toolkit_switch_search_limit`](#blt_toolkit_switch_search_limit) | filter | SW-20 | Planned (Phase 2) | Toolbar search result cap |
| [`blt_toolkit_validate_username`](#blt_toolkit_validate_username) | filter | UN-9 | Planned (Phase 3) | Add or remove username errors |
| [`blt_toolkit_username_min_length`](#blt_toolkit_username_min_length) | filter | UN-2 | Planned (Phase 3) | Minimum username length |
| [`blt_toolkit_disallowed_usernames`](#blt_toolkit_disallowed_usernames) | filter | UN-4 | Planned (Phase 3) | Disallowed usernames |
| [`blt_toolkit_username_email`](#blt_toolkit_username_email) | filter | UN-15 | Planned (Phase 3) | The rename notification email |
| [`blt_toolkit_bulk_rename_max_rows`](#blt_toolkit_bulk_rename_max_rows) | filter | UN-30 | Planned (Phase 3) | Bulk rename CSV row cap |
| [`blt_toolkit_risky_caps`](#blt_toolkit_risky_caps) | filter | SPEC 5.4, SEC-10, SEC-11 | Planned (Phase 4) | Capability risk tiers |
| [`blt_toolkit_lockout_critical_caps`](#blt_toolkit_lockout_critical_caps) | filter | SEC-12 | Planned (Phase 1) | Capabilities administrator always keeps |
| [`blt_toolkit_capability_labels`](#blt_toolkit_capability_labels) | filter | RO-12 | Planned (Phase 4) | Plain-English capability labels |
| [`blt_toolkit_capability_groups`](#blt_toolkit_capability_groups) | filter | RO-11, RO-13 | Planned (Phase 4) | Named capability groups |
| [`blt_toolkit_simple_controls`](#blt_toolkit_simple_controls) | filter | RS-3 | Planned (Phase 5) | Simple view controls |
| [`blt_toolkit_isolation_excluded_types`](#blt_toolkit_isolation_excluded_types) | filter | RS-30, RS-35 | Planned (Phase 5) | Post types that cannot get separate permissions |
| [`blt_toolkit_isolation_caps`](#blt_toolkit_isolation_caps) | filter | RS-31 | Planned (Phase 5) | Arguments applied to an isolated post type |
| [`blt_toolkit_hide_admins_from_non_admins`](#blt_toolkit_hide_admins_from_non_admins) | filter | SEC-6 | Planned (Phase 1) | Hide administrators in the Users list and REST |
| [`blt_toolkit_snapshot_retention`](#blt_toolkit_snapshot_retention) | filter | SNAP-3 | Planned (Phase 1) | Snapshots kept besides the baseline |
| [`blt_toolkit_log_retention_days`](#blt_toolkit_log_retention_days) | filter | LOG-4 | Planned (Phase 1) | Days log rows are kept |
| [`blt_toolkit_tick_budget`](#blt_toolkit_tick_budget) | filter | JOB-2 | Planned (Phase 3) | Seconds one job tick may run |
| [`current_user_switched()`](#current_user_switched) | function | SW-19 | Planned (Phase 2) | User Switching compatibility shim |

---

## Actions

### `blt_toolkit_switched_to`

Fires after the current user has switched to another user: the auth cookies are set and the current user is the target, before the redirect (SW-4 step 6). The switch is logged at the same point.

| Argument | Type | Description |
| --- | --- | --- |
| `$target_id` | int | The user switched to. |
| `$actor_id` | int | The user who switched. |
| `$new_token` | string | Session token of the session created for the target. |
| `$old_token` | string | Session token of the actor's session, which switching back returns to. |

Requirement SW-4. Status: Planned (Phase 2).

### `blt_toolkit_switched_back`

Fires after a user has switched back to the account they came from (SW-5): the stack is popped, the original session restored and the target's switched session destroyed.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The user switched back to. |
| `$from_id` | int\|false | The user switched back from, or `false` when switching back after a Switch Off (nobody was signed in). |
| `$new_token` | string | Session token of the restored session. |
| `$old_token` | string | Session token of the session being left. |

Requirement SW-5. Status: Planned (Phase 2).

### `blt_toolkit_switched_off`

Fires after a user has switched off (SW-6): they are pushed onto the switch stack and signed out, and the login screen and site footer then offer to switch back.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The user who switched off. |
| `$token` | string | Session token of the session they switched off from. |

Requirement SW-6. Status: Planned (Phase 2).

### `blt_toolkit_before_username_change`

Fires inside the rename procedure after the change is authorized and the new name validated, and before anything is written (UN-10 step 3).

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The user being renamed. |
| `$old` | string | The current login. |
| `$new` | string | The new login. |

Requirement UN-10. Status: Planned (Phase 3).

### `blt_toolkit_after_username_change`

Fires after a username changed: the login, author slug, display name and nickname are written, both user caches are cleared, and sessions are handled (UN-10 step 9). The change is logged and the optional email sent at the same point. Fires once per user in a bulk rename.

| Argument | Type | Description |
| --- | --- | --- |
| `$user_id` | int | The renamed user. |
| `$old` | string | The previous login. |
| `$new` | string | The new login. |
| `$result` | array | What the procedure returns: `old_login`, `new_login`, `nicename_changed`, `old_nicename`, `sessions_ended`. |

Requirement UN-10. Status: Planned (Phase 3).

### `blt_toolkit_before_roles_save`

Fires after the Guard has approved a computed change set and before the roles option is written (RO-4). Every write to role capabilities made by BLT Toolkit's role store goes through it.

| Argument | Type | Description |
| --- | --- | --- |
| `$changes` | array | The change set: per role, the capabilities to add and to remove. |
| `$context` | string | Where the save came from. *Proposed values:* `simple`, `advanced`, `self_service`, `isolation`, `import`, `restore`, `reset`, `recovery`. |

Requirement RO-4. Status: Planned (Phase 4).

### `blt_toolkit_after_roles_save`

Fires after the roles option was written once (RO-3) and the change logged (RO-4).

| Argument | Type | Description |
| --- | --- | --- |
| `$changes` | array | The change set that was applied. |
| `$context` | string | As for `blt_toolkit_before_roles_save`. |
| `$snapshot_id` | int | The snapshot taken before the write (SNAP-1). When the roles already matched the newest snapshot, that snapshot's ID. |

Requirement RO-4. Status: Planned (Phase 4).

### `blt_toolkit_role_created`

Fires after a role is added (RO-30), including a copy made by "Move users to a new role" (RO-32) and a role created by an import (RO-45).

| Argument | Type | Description |
| --- | --- | --- |
| `$role_id` | string | The new role's ID. |
| `$display_name` | string | *Proposed.* Its display name. |
| `$source` | string | *Proposed.* What it started from: `blank`, or the ID of the role it copied. |

Requirement RO-30. Status: Planned (Phase 4).

### `blt_toolkit_role_deleted`

Fires after a role is deleted (RO-40), including at the end of a merge (RO-42).

| Argument | Type | Description |
| --- | --- | --- |
| `$role_id` | string | The deleted role's ID. |
| `$replacement` | string | *Proposed.* The role its users were moved to, or `''` when it had no users. |

Requirement RO-40, RO-42. Status: Planned (Phase 4).

### `blt_toolkit_role_renamed`

Fires after a role's display name changes (RO-31). A role ID never changes in place (RO-32).

| Argument | Type | Description |
| --- | --- | --- |
| `$role_id` | string | The role's ID. |
| `$old_name` | string | *Proposed.* The previous display name. |
| `$new_name` | string | *Proposed.* The new display name. |

Requirement RO-31. Status: Planned (Phase 4).

### `blt_toolkit_post_type_isolated`

Fires after a post type was given separate permissions: the seed capabilities are written in one batch and the type is stored under `roles.isolated_post_types` (RS-30 step 3). The new capabilities apply from the next request.

| Argument | Type | Description |
| --- | --- | --- |
| `$post_type` | string | The post type. |

Requirement RS-30. Status: Planned (Phase 5).

### `blt_toolkit_post_type_shared`

Fires after a post type was reverted to sharing Posts' (or Pages') permissions (RS-32). The now-unused capabilities stay on roles, flagged as orphans, until the separate cleanup step removes them.

| Argument | Type | Description |
| --- | --- | --- |
| `$post_type` | string | The post type. |

Requirement RS-32. Status: Planned (Phase 5).

### `blt_toolkit_before_job`

Fires once when a background job starts, after its settings are pinned (JOB-3) and before its first item.

| Argument | Type | Description |
| --- | --- | --- |
| `$job` | array | The job state as stored in `blt_toolkit_job_{id}`. Its type is one of `username_bulk`, `role_reassign`, `log_prune` or `count_no_role` (JOB-5). |

Requirement JOB-1 to JOB-5. Status: Planned (Phase 3).

### `blt_toolkit_after_job`

Fires once when a background job ends, whether it finished or was cancelled. Items that finished before a cancel are kept (JOB-4).

| Argument | Type | Description |
| --- | --- | --- |
| `$job` | array | The final job state, including done and failed counts. |

Requirement JOB-1 to JOB-5. Status: Planned (Phase 3).

### `blt_toolkit_logged`

Fires after each row is written to the activity log, so other logging plugins can listen (LOG-6). A failed insert does not fire it (LOG-3).

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
| `details` | array | *Proposed:* the decoded JSON details (before and after values, diff, the chain of switched users). |
| `snapshot_id` | int\|null | The snapshot taken for a role change. |
| `ip` | string | Empty when IP recording is off (LOG-5). |
| `status` | string | `success` by default. |
| `source` | string | Empty for native rows; the plugin's name for imported rows (`username-changer`). |

Requirement LOG-6. Status: Planned (Phase 1).

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

Requirement SPEC 5.2. Status: Planned (Phase 1). `BLT_Toolkit_Guard::is_toolkit_admin()` already applies the filter, but no code path calls it until the Guard's rules (SEC-1, SEC-2) and recovery (SEC-14) arrive.

### `blt_toolkit_switch_redirect`

Where a switch, switch back or switch off lands, applied after core's `login_redirect` or `logout_redirect` (SW-9). Return a non-empty URL to override; it still goes through `wp_safe_redirect()`.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$redirect_to` | string | `''` | `''` keeps the URL resolved per SW-9 (`redirect_to`, or the public URL of `redirect_to_post`, `redirect_to_term`, `redirect_to_user` or `redirect_to_comment`, otherwise the dashboard for switch to and `users.php` for switch back). |
| `$requested` | string | | *Proposed.* The URL resolved per SW-9. |
| `$user` | WP_User\|null | | *Proposed.* The user now signed in, or `null` after a switch off. |
| `$action` | string | | *Proposed.* `blt_switch_to_user`, `blt_switch_back` or `blt_switch_off`. |

Requirement SW-9. Status: Planned (Phase 2).

### `blt_toolkit_switch_show_footer_link`

Whether to print the fixed "Switch back to {Name}" link on the site (SW-17). Whatever this returns, the link is printed only while switched with the toolbar hidden, or after a Switch Off.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$show` | bool | The Settings > Switching toggle **Show a "Switch back" link on the site** (`switch.footer_link`, default `true`) | Return `false` to hide it. |

Requirement SW-17. Status: Planned (Phase 2).

### `blt_toolkit_switch_send_cookies`

Whether the switch cookies are sent with `setcookie()` (SW-3). Turn it off for tests and headless setups that carry the cookies themselves.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$send` | bool | `true` | Return `false` to suppress sending. |

Requirement SW-3. Status: Planned (Phase 2).

### `blt_toolkit_switch_search_limit`

The most results the toolbar "Switch to…" search returns (`GET blt-toolkit/v1/switch/users`).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$limit` | int | `20` | Results. |

Requirement SW-20. Status: Planned (Phase 2).

### `blt_toolkit_validate_username`

The last validation rule (UN-9), applied on every path: profile, bulk rename, the REST check and the shortcode. Add an error to refuse a name, or remove one.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$errors` | WP_Error | The errors from rules UN-1 to UN-8 (empty when the name passed) | Return it. |
| `$new` | string | | The proposed username, trimmed. |
| `$user` | WP_User | | The user being renamed. |

Requirement UN-9. Status: Planned (Phase 3).

### `blt_toolkit_username_min_length`

The minimum username length (UN-2), enforced on the server. The maximum is always 60, the `user_login` column limit.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$length` | int | The Settings > Usernames field **Minimum length** (`usernames.min_length`, default `3`) | Characters. |

Requirement UN-2. Status: Planned (Phase 3).

### `blt_toolkit_disallowed_usernames`

Usernames nobody may change to (UN-4). The result is merged with core's `illegal_user_logins` filter and compared case-insensitively on both sides.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$names` | string[] | The Settings > Usernames field **Names that cannot be used** (`usernames.disallowed`, default `admin`, `administrator`, `root`, `sysadmin`, `webmaster`) | Usernames. |

Requirement UN-4. Status: Planned (Phase 3).

### `blt_toolkit_username_email`

The plain-text email sent to a renamed user when the Settings > Usernames toggle **Email the person about the change** (`usernames.notify_user`) is on (UN-15). It is sent with `wp_mail()` and the site's defaults; no `From:` header is built, so SMTP plugins decide.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$email` | array | `to`, `subject`, `message`, `headers` (*proposed keys*, as core's `wp_new_user_notification_email`), with the tags in the subject and body already replaced | Return it. |
| `$user` | WP_User | | *Proposed.* The renamed user. |
| `$old` | string | | *Proposed.* The previous login. |
| `$new` | string | | *Proposed.* The new login. |

Requirement UN-15. Status: Planned (Phase 3).

### `blt_toolkit_bulk_rename_max_rows`

The most data rows one bulk rename CSV may contain (UN-30). A larger file is refused, not truncated.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$rows` | int | `5000` | Rows. |

Requirement UN-30. Status: Planned (Phase 3).

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

Requirement SPEC 5.4, SEC-10, SEC-11. Status: Planned (Phase 4). `BLT_Toolkit_Caps::tiers()` already applies the filter, but nothing reads the tiers until the tier badges and the Critical review step arrive with the Roles screen.

### `blt_toolkit_lockout_critical_caps`

The capabilities that can never be removed from the `administrator` role, and that recovery mode re-grants (SEC-12, SEC-14). Entries can be added; the built-in ones always stay.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$caps` | string[] | `read`, `manage_options`, `list_users`, `edit_users`, `promote_users`, `activate_plugins`, `blt_edit_roles`, `blt_edit_capabilities` | Capability names. |

Requirement SEC-12. Status: Planned (Phase 1). `BLT_Toolkit_Caps::lockout_critical()` already applies the filter, but nothing enforces the set until recovery (SEC-14) arrives in Phase 1 and the Advanced view's lockout check (SEC-12) in Phase 4. Until then, adding a capability here protects nothing.

### `blt_toolkit_capability_labels`

Plain-English labels for capabilities (RO-12). The raw name is always shown next to the label as a code chip.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$labels` | array | The built-in dictionary for core capabilities, plus labels built from each post type's and taxonomy's own labels ("Edit others' Events") | Capability => label. Capabilities with no label are humanized from their name. |

Requirement RO-12. Status: Planned (Phase 4).

### `blt_toolkit_capability_groups`

Named groups of capabilities for the Advanced view's group tree (RO-13). A capability declared here is registered, so it is never flagged as orphaned (RO-11) and never offered for deletion (RO-24).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$groups` | array | `array()` | *Proposed shape:* group key => `array( 'label' => string, 'caps' => string[] )`. |

Requirement RO-11, RO-13. Status: Planned (Phase 4).

### `blt_toolkit_simple_controls`

The controls Simple view shows (RS-3). Other BLT plugins register their own sections here (for example BLT Documents' document management or BLT Events' event management). Each control declares an id, section, label, help text, its capability universe, how to compute its state from a role, how to compute target capabilities from a chosen state, its tier, its dependencies and an `available()` predicate. Simple view saves only controls that changed, and only within each control's universe (RS-5).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$controls` | array | The built-in controls: one content row per capability group (SPEC 10.2 to 10.4) and the site and people controls in Appendix A | Control definitions. |

Requirement RS-3. Status: Planned (Phase 5).

### `blt_toolkit_isolation_excluded_types`

Post types that are never offered "Give {Type} separate permissions" (RS-30), for example a type whose plugin checks `edit_posts` directly instead of the type's own capability (RS-35).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$types` | string[] | `array()`, plus any type found to break under isolation during the RS-35 tests (recorded in `tasks/lessons.md`) | Post type names. |

Requirement RS-30, RS-35. Status: Planned (Phase 5).

### `blt_toolkit_isolation_caps`

The `register_post_type_args` changes applied to each isolated post type (RS-31). The filter that applies them is added when the main file loads, so it sees types registered before `plugins_loaded`.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$args` | array | `capability_type => array( singular, plural )`, `map_meta_cap => true`, `capabilities => array( 'create_posts' => "create_{plural}" )` | Arguments merged into the type's registration. |
| `$post_type` | string | | *Proposed.* The post type. |
| `$isolation` | array | | *Proposed.* Its stored entry: `singular`, `plural`, `since`. |

Requirement RS-31. Status: Planned (Phase 5).

### `blt_toolkit_hide_admins_from_non_admins`

Whether administrators are hidden from non-administrators in the Users list and the REST users collection (SEC-6). The role views and counts on the Users screen are adjusted to match.

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$hide` | bool | The Settings > Roles toggle **Hide administrators from everyone else** (`roles.hide_admins`, default `true`) | Return `false` to show them. |
| `$user` | WP_User | | *Proposed.* The current user. |

Requirement SEC-6. Status: Planned (Phase 1).

### `blt_toolkit_snapshot_retention`

How many role snapshots are kept, newest first, besides the baseline, which is never pruned (SNAP-2, SNAP-3).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$count` | int | The Settings > Roles field **Snapshots to keep** (`roles.snapshot_retention`, default `50`) | Snapshots. |

Requirement SNAP-3. Status: Planned (Phase 1).

### `blt_toolkit_log_retention_days`

How many days activity log rows are kept. A daily event prunes older rows in batches of 1,000 (LOG-4).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$days` | int | The Settings > Activity log field **Keep entries for** (`log.retention_days`, default `365`) | Days. |

Requirement LOG-4. Status: Planned (Phase 1).

### `blt_toolkit_tick_budget`

Seconds one background job tick may run before it saves its state and yields (JOB-2).

| Argument | Type | Default | Description |
| --- | --- | --- | --- |
| `$seconds` | int | `15` | Seconds. |

Requirement JOB-2. Status: Planned (Phase 3).

---

## User Switching compatibility

When the Settings > Switching toggle **Work with tools made for User Switching** (`switch.compat_hooks`, the "Compatibility hooks" setting of SW-19) is on (the default, D2), BLT Toolkit also fires User Switching's action names with User Switching 1.12.2's signatures, so activity loggers and debugging tools that integrate with User Switching keep working (SW-19). They fire right after the matching `blt_toolkit_*` action. Requirement SW-19. Status: Planned (Phase 2).

| Action | Arguments |
| --- | --- |
| `switch_to_user` | `int $user_id` (switched to), `int $old_user_id` (switched from), `string $new_token`, `string $old_token` |
| `switch_back_user` | `int $user_id` (switched back to), `int\|false $old_user_id` (switched back from, or `false` after a Switch Off), `string $new_token`, `string $old_token` |
| `switch_off_user` | `int $old_user_id` (the user switching off), `string $old_token` |

These are action names only. BLT Toolkit never declares the functions `switch_to_user()`, `switch_off_user()` or `user_switching_*()`, which belong to User Switching (GEN-2). While User Switching is active, BLT Toolkit's switch module does not boot (GEN-1), so the two never fire these actions side by side.

### `current_user_switched()`

```php
current_user_switched(): WP_User|false
```

Returns the user who switched into the current session (the most recent originating user), or `false` when the current user is not switched or nobody is signed in. Code written for User Switching can call it unchanged.

- Defined only when **Work with tools made for User Switching** (`switch.compat_hooks`) is on and no function of that name exists yet (SW-19, D2). It is the plugin's only global symbol without the `blt_toolkit_` prefix.
- Guard calls with `function_exists( 'current_user_switched' )`, as with User Switching.

Requirement SW-19. Status: Planned (Phase 2).
