# BLT Toolkit

BLT Toolkit gives WordPress site administrators three user-management tools in one place, under one security model and one activity log: **switch** to another user's account and back, **change** a user's username, and **create and edit roles and capabilities**. It replaces User Switching, Username Changer and User Role Editor on S-FX-managed sites. The role editor has two views of the same data. **Simple** shows each content type (Posts, Pages and every custom post type) as one plain-English access level such as *View*, *Author (own only)* or *Edit all*, plus site-wide toggles grouped by area. **Advanced** is the full capability matrix. The three tools share one protection layer, so a delegated user manager can never switch into, rename or re-role someone more powerful than themselves, and every switch, rename and role change lands in one log that says who did it, including "acting as" when the change was made from a switched session. Everything works with no external service, no Cloudflare and no credentials.

Part of the BLT family of WordPress plugins by S-FX.com. It is deliberately narrow: two tables, no front end beyond a "Switch back" link and an optional username form, a REST namespace used only by its own admin screens, and no runtime dependencies.

> **Status: in development.** BLT Toolkit is built phase by phase per [SPEC.md](SPEC.md) section 17. **Phase 0 (scaffold) and Phase 1 (protection for administrators, recovery mode, the activity log and role snapshots) are built; Phase 2 (switch) is next.** This README describes the plugin as specified; a feature is available only once the phase that delivers it has passed its acceptance criteria. Progress is tracked in [tasks/todo.md](tasks/todo.md).

## Requirements

- WordPress 6.3 or later. The family default is 6.0; BLT Toolkit raises it for parity with the switching code it reimplements (User Switching 1.12.2 requires 6.3), which also avoids guards around `switch_to_user_locale()`, `did_filter()` and the command palette.
- PHP 7.4 or later.
- Single site only. Activation on multisite is refused with a notice (decision D1).
- No external service, account or API key.

## Install

1. Download `blt-toolkit-X.Y.Z.zip` from the latest release at [github.com/S-FX-com/BLT-Toolkit](https://github.com/S-FX-com/BLT-Toolkit/releases/latest). Use the release asset, not the "Source code" archive. (No release has been published yet; see [tasks/lessons.md](tasks/lessons.md) for where releases will live.)
2. In WordPress go to **Plugins > Add New Plugin > Upload Plugin**, upload the zip and activate it.
3. Open **BLT Toolkit** in the admin menu.

Updates arrive through the normal Plugins screen. The plugin checks GitHub at most once a day, and **BLT Toolkit > Settings > Advanced > Check for Updates** checks on demand.

### Moving from User Switching, Username Changer and User Role Editor

- BLT Toolkit can be activated while the old plugins are still active. A BLT module whose job an active legacy plugin already does stays off, and its card on **Overview** shows a **Conflict** badge naming the plugin to deactivate (GEN-1). The other modules work normally.
- **User Switching** keeps nothing on the server. Switch back before deactivating it: its cookies mean nothing to BLT Toolkit.
- **User Role Editor** keeps roles in WordPress's own roles option, so deactivating it loses no role data (GEN-3). **Tools > Import from legacy plugins** brings over its "other default roles", its per-role "hide admin bar" flags and its first-activation backup (as a snapshot), and flags its `ure_*` capabilities for optional removal. Run that import before you delete User Role Editor: deleting it (as opposed to deactivating it) removes those settings and the backup.
- **Username Changer**: the same Tools tab turns its allowed roles into the self-service capability, copies the minimum length and email settings, and copies its audit log into the activity log. Custom button labels and messages are not carried over; use translations.
- Every import is previewed first, only reads from the source, records what it imported so a second run duplicates nothing, and never deletes the source's data (SPEC 15).

## What works out of the box

Default behavior as specified (SPEC 1.1). Each row becomes available when its phase lands.

| Area | Default behavior |
| --- | --- |
| Switch | Administrators can switch to any non-administrator and back. "Switch To" appears on the Users list and profiles; "Switch back" appears in the toolbar, notices, the login screen and a fixed link on the site. |
| Usernames | Administrators can change any username from the user's profile. Changes take effect immediately, including on sites with a persistent object cache. Old author URLs redirect. |
| Roles | Roles screen opens in Simple view. Every save is snapshotted and can be restored. |
| Protection | Non-administrators can never edit, delete, rename, re-role or switch into an administrator, and can never grant a capability they do not hold. |
| Activity log | Every switch, rename and role change is logged with who did it, including "acting as" when the change was made from a switched session. |
| Self-service, bulk rename, separate CPT permissions | Off until enabled. |

### Where to find it

Each screen carries its own capability, so the tools can be delegated separately. WordPress shows the **BLT Toolkit** menu to anyone who can reach at least one of them.

| Screen | Menu | Capability |
| --- | --- | --- |
| Overview | BLT Toolkit | `manage_options` |
| Roles | BLT Toolkit > Roles, and **Users > Roles** | `blt_edit_roles` |
| Usernames | BLT Toolkit > Usernames | `blt_change_usernames` |
| Activity | BLT Toolkit > Activity | `blt_view_toolkit_log` |
| Tools | BLT Toolkit > Tools | `blt_reset_roles` (the legacy import tab needs `manage_options`) |
| Settings | BLT Toolkit > Settings | `manage_options` |

Switching and username changes also appear where people already work: row actions on the Users list, a button and a field on the user's profile, and the toolbar.

## Shortcodes

### `[blt_toolkit_username_form]`

Front-end form that lets signed-in users change their own username (UN-23, scheduled for Phase 3b). It is a real form that posts to `admin-post.php` and redirects back to the same page with the result. It renders nothing for visitors and for users without `blt_change_own_username`, and its stylesheet loads only on pages that contain it. The same validation, cooldown and password rule apply as on the profile screen.

| Attribute | Default or Values | Description |
| --- | --- | --- |
| `style` | `styled` (default), `none` | `styled` loads `blt-toolkit-front.css`. `none` outputs the same markup with its `.blt-tk-*` classes and no stylesheet, for a theme that styles it itself. |

No block or Bricks element is planned for v1.

## Customizing

### Settings

**BLT Toolkit > Settings** (needs `manage_options`). Each tab is a link with its own URL.

| Tab | Setting | Default | Notes |
| --- | --- | --- | --- |
| General | Switch, Usernames and Roles modules | On | A module that is off loads no code beyond its registry entry. A module also stays off while its legacy plugin is active (GEN-1) or when it is named in `BLT_TOOLKIT_DISABLE_MODULES`. Protection and the log always run. |
| Switching | Show a "Switch back" link on the site | On | A fixed link, shown while switched when the toolbar is not showing, and after Switch Off (SW-6, SW-17). |
| Switching | Offer "Switch Off" in the toolbar | On | For users with `blt_switch_users` (SW-14). |
| Switching | Search for a user to switch to from the toolbar | On | Typeahead; without JavaScript it links to the Users screen (SW-20). |
| Switching | Work with tools made for User Switching | On | The "Compatibility hooks" setting of SW-19 (`switch.compat_hooks`). Also fires User Switching's actions and defines `current_user_switched()` (SW-19, D2). See [HOOKS.md](HOOKS.md). |
| Switching | WooCommerce | On | Only when WooCommerce is active (SW-22). |
| Switching | Support Agent role | Not created | A button that opens a preview first: the role's three capabilities with their risk tier, and which of the site's roles a Support Agent could and could not switch into (administrators never, SEC-1; roles with a capability it lacks never, SEC-2). Because `blt_switch_users` is Critical, the role is created only after you type its name (SEC-10). Needs `manage_options` and **Create roles** (`blt_create_roles`). Creating it takes a role snapshot first and is logged (SW-21). The button is gone once the role exists. |
| Usernames | Minimum length | 3 | The maximum is always 60, the database column's limit (UN-2). |
| Usernames | Names that cannot be used | `admin`, `administrator`, `root`, `sysadmin`, `webmaster` | Compared case-insensitively and merged with WordPress's own list. Affects new names only (UN-4, D12). |
| Usernames | Allow `@` in usernames | On | Email-style usernames are common with SSO; a name that equals another user's email is always refused (UN-6, UN-7, D13). |
| Usernames | Author URL | Only when it was generated from the username | Or Always, or Never (UN-13, D6). |
| Usernames | Redirect old author URLs | On | 301 to the current author URL (UN-13a). |
| Usernames | Sign the person out everywhere | On | When renaming someone else; their old cookies stop working anyway (UN-14, D7). |
| Usernames | Email the person about the change | Off | Plain text through `wp_mail()`. Subject and body are editable; tags `{old_username}`, `{new_username}`, `{display_name}`, `{email}`, `{sitename}`, `{siteurl}`, `{loginurl}` (UN-15). |
| Usernames | Can change their own username | No role | One checkbox per role. Saving grants or removes `blt_change_own_username` on that role, so Simple view shows it too (UN-20). |
| Usernames | Wait between changes | 0 days | Days between self-service changes (UN-21). |
| Usernames | Ask for their current password | Off | Users who sign in through SSO may not know a WordPress password (UN-22). |
| Roles | Opens in | Simple | Each user's last choice is remembered (RS-1). |
| Roles | Show deprecated capabilities | Off | `level_0` to `level_10`, `edit_files` (RO-22). |
| Roles | Readable capability names | On | Plain-English labels; the raw name is always shown too (RO-12). |
| Roles | Hide administrators from everyone else | On | In the Users list and the REST users collection (SEC-6, D5). |
| Roles | Additional default roles | None | Roles every new user receives besides WordPress's default role, on every registration path (US-2). |
| Roles | Snapshots to keep | 50 | Plus the baseline, which is never pruned (SNAP-3). |
| Activity log | Keep entries for | 365 days | Pruned daily in batches (LOG-4, D9). |
| Activity log | Record IP addresses | On | From `REMOTE_ADDR`. `CF-Connecting-IP` is used only for requests that arrive from Cloudflare's published ranges, checked with a copy of the BLT family's range check (LOG-5). |
| Advanced | Delete all plugin data on uninstall | Off | See [Data](#data). |
| Advanced | GitHub token | None | Stored encrypted, with **Test Connection**. |
| Advanced | Check for Updates | | Asks GitHub immediately. |
| Advanced | Recovery instructions | | How to use `BLT_TOOLKIT_RECOVERY`, shown as a code block. |

A role's description, its "Hide the toolbar on the site" flag and separate permissions for a custom post type are set on the Roles screen, not here.

### Constants

Define these in `wp-config.php`.

| Constant | Effect |
| --- | --- |
| `BLT_TOOLKIT_RECOVERY` | `true` restores an administrator role that lost the ability to manage the site. On the next admin request by a user who has the `administrator` role, BLT Toolkit takes a snapshot, re-grants the lockout-critical capabilities and every BLT Toolkit capability to `administrator`, logs it, and shows a notice until the constant is removed. It changes nothing else (SEC-14). |
| `BLT_TOOLKIT_DISABLE_MODULES` | Comma-separated module keys (`switch`, `usernames`, `roles`) to turn off regardless of settings, for incident response. Protection and the log still load (SEC-15). |
| `BLT_TOOLKIT_GITHUB_TOKEN` | A read-only GitHub token for the updater. Overrides the token stored in Settings, which in turn overrides the shared BLT `github` group (opt-in). Needed only if the repository is private or GitHub rate-limits the site. |
| `BLT_TOOLKIT_SWITCH_COOKIE`, `BLT_TOOLKIT_SWITCH_SECURE_COOKIE`, `BLT_TOOLKIT_SWITCH_OLDUSER_COOKIE` | Override the switch cookie names (SW-3), as User Switching allows for its own. |

### Templates

`templates/username-form.php` renders the self-service form. Copy it to `your-theme/blt-toolkit/username-form.php` to change the markup.

### CSS tokens

BLT Toolkit puts two things on the front end: the "Switch back" link (SW-17) and the username form (UN-23). Both are styled by `assets/css/blt-toolkit-front.css` with `.blt-tk-*` classes and `--blt-tk-*` custom properties: no global resets, no framework, safe inside a Bricks page, and nothing loaded for visitors who are not switched or not on a page with the shortcode. The `tk` prefix is pending confirmation that it does not collide with BLT Tube's (D14).

The stylesheet only reads the tokens, never declares them, so a theme can set any of them on `:root`, `body` or a wrapper. Each falls back to the BLT design token where there is one, then to the literal value. The list is also in the stylesheet's header; the username form's tokens are added in Phase 3b.

| Token | Used for | Fallback |
| --- | --- | --- |
| `--blt-tk-accent` | "Switch back" link background | `--blt-primary`, then `#2271b1` |
| `--blt-tk-accent-hover` | Background on hover and focus | `--blt-primary-hover`, then `#135e96` |
| `--blt-tk-on-accent` | Link text | `--blt-surface`, then `#ffffff` |
| `--blt-tk-focus` | Focus ring | `--blt-fg`, then `#1e1e1e` |
| `--blt-tk-radius` | Corner radius | `--blt-radius-sm`, then `4px` |
| `--blt-tk-font-size` | Text size | `13px` |
| `--blt-tk-offset` | Distance from the bottom-left corner | `16px` |
| `--blt-tk-z` | Stacking order | `99999` |
| `--blt-tk-shadow` | Shadow | `0 2px 8px rgba(0, 0, 0, 0.25)` |

The toolbar's "Viewing as" node uses `var(--blt-primary, #2271b1)`. Admin screens use the family design system (`blt-design-system.css`) and its tokens.

### Hooks

Every public action and filter, with arguments, defaults and the phase that ships it, is documented in [HOOKS.md](HOOKS.md). That includes User Switching's action names (`switch_to_user`, `switch_back_user`, `switch_off_user`), which BLT Toolkit fires with User Switching's signatures when **Work with tools made for User Switching** (Settings > Switching) is on, and the `current_user_switched()` shim.

### REST API

Namespace `blt-toolkit/v1`. The routes serve BLT Toolkit's own admin screens; every one declares a `permission_callback` and answers only with what the current user may act on.

| Route | Method | Purpose |
| --- | --- | --- |
| `/switch/users?search=` | GET | Toolbar "Switch to…" typeahead. At least 2 characters, at most 20 results (`blt_toolkit_switch_search_limit`), only users the current user may switch to, each with a nonced switch URL generated on the server (SW-20). |
| `/usernames/check` | GET | Live, advisory validation of a new username while typing (UN-16). The server validates again when the form is saved. |
| `/jobs/{id}` | GET | A background job's progress: status, done and total, elapsed time, estimate, current item, failed items and warnings (JOB-4). |
| `/jobs/{id}/tick` | POST | Drives a background job while its screen is open (JOB-1). |
| `/jobs/{id}/cancel` | POST | Cancels a job; items that finished stay finished (JOB-4). |
| `/jobs/{id}/retry` | POST | Starts another pass over a finished job's failed items (JOB-4). |

The four job routes need the capability of the job's type (`BLT_Toolkit_Jobs::current_user_can_use()`: `manage_options` for the log prune) and a REST nonce; a job whose module is off answers to nobody.

BLT Toolkit also adds a `blt_toolkit_switch_url` field to core's `user` REST object: the switch URL, or `null` unless the current user may switch to that user (SW-27). Core's own users endpoint is covered by the protection rules: the role a non-administrator may assign is limited (SEC-4), and administrators are hidden from non-administrators (SEC-6).

### Roles and capabilities

Granted to the `administrator` role on activation, and to nobody else unless the owner does it. No role is created on activation.

| Capability | Lets the holder | Tier |
| --- | --- | --- |
| `blt_switch_users` | Switch to users (subject to the protection rules) and switch off | Critical |
| `blt_change_usernames` | Change other users' usernames | Critical |
| `blt_change_own_username` | Change their own username (self-service) | Standard |
| `blt_edit_roles` | Open the Roles screen and edit roles in **Simple** view | Critical |
| `blt_edit_capabilities` | Use **Advanced** view, add and delete capabilities, edit per-user capabilities | Critical |
| `blt_create_roles` | Add roles | Critical |
| `blt_delete_roles` | Delete and merge roles | Critical |
| `blt_reset_roles` | Reset to defaults, restore snapshots, import roles | Critical |
| `blt_view_toolkit_log` | View and export the activity log | Elevated |

Settings, module toggles and legacy imports need `manage_options`.

Two meta capabilities are checked against a target user and never granted directly:

| Meta capability | Maps to |
| --- | --- |
| `blt_switch_to_user` | `blt_switch_users`, plus the protection rules. Never allowed for yourself or a user who does not exist. |
| `blt_change_username` | For yourself: `blt_change_own_username` plus the cooldown. For others: `blt_change_usernames` plus the protection rules. |

**Deliberate difference from User Switching.** User Switching lets anyone who can edit a user switch into them. BLT Toolkit requires the explicit `blt_switch_users` capability, so a role that gains `edit_users` through some unrelated customization does not silently gain impersonation (D3).

**Protection rules** (SPEC 5.3), enforced for admin screens, `admin-ajax.php`, `admin-post.php`, REST and WP-CLI alike:

- A non-administrator can never edit, delete, remove, re-role, switch into or rename an administrator (SEC-1).
- A non-administrator can never act on a user more powerful than themselves, never grant a capability they lack, and can only assign roles whose capabilities they hold (SEC-2, SEC-3, SEC-4).
- A non-administrator can never change a role they hold, or their own roles (SEC-5).
- Granting a Critical capability to any role other than `administrator`, or to any user, needs a review step and the role's name typed to confirm (SEC-10). Tier badges appear next to every control (SEC-11).
- The `administrator` role is read-only in Simple view, cannot be deleted, keeps its ID, and keeps its lockout-critical capabilities (`read`, `manage_options`, `list_users`, `edit_users`, `promote_users`, `activate_plugins`, `blt_edit_roles`, `blt_edit_capabilities`) in Advanced view (SEC-12). A save that would leave you without `blt_edit_roles` is refused unless another administrator exists and you confirm (SEC-13).

**Optional role.** **Support Agent** (`blt_support_agent`: `read`, `list_users`, `blt_switch_users`) is created only when the owner clicks the button under **Settings > Switching**, checks the preview and types the role's name to confirm. The protection rules keep it from switching into anyone more powerful than itself (SW-21): on a default site it can switch into Subscribers, never into Contributors, Authors, Editors or Administrators. The deprecated `level_0` to `level_10` capabilities are left out of that comparison (SEC-8).

## Development

```
composer install      # dev tools only: phpcs, WPCS, PHPCompatibility, PHPUnit
composer lint         # php -l on every file
composer phpcs        # WordPress-Extra, PHPCompatibilityWP 7.4+
composer test         # PHPUnit, no WordPress install required
node bin/make-pot.js  # regenerate languages/blt-toolkit.pot (CI fails on drift)
bin/dev-site.sh DIR   # disposable WordPress on SQLite for smoke tests (see the script header)
```

Pure logic (the superset rule, role diffs and hashes, the Simple view mapper, author slugs, username rules, bulk rename planning, CSV parsing and the switch cookie stack) lives in classes that take plain arrays and strings, and is tested against known vectors. WordPress-coupled behavior is exercised against a real WordPress install; the acceptance criteria and the manual smoke matrix are in [tasks/todo.md](tasks/todo.md).

CI (`.github/workflows/ci.yml`) runs on every push and pull request, `main` included: the syntax check on PHP 7.4 to 8.3, phpcs, the unit tests on PHP 7.4, 8.2 and 8.3, the version-sync check, the POT drift check, and a release-zip build and verification. A merge to `main` publishes the release (`.github/workflows/release.yml`, ported from BLT Secure): lint, phpcs, the unit tests and the POT drift check run again on that commit, and only if they pass is the version bumped (patch by default, `#minor` or `#major` in the merge commit message) in the plugin header, `BLT_TOOLKIT_VERSION` and the `readme.txt` stable tag, tagged `vX.Y.Z`, and `blt-toolkit-X.Y.Z.zip` is built with one top-level `blt-toolkit/` folder and the update checker inside.

Requirements and phases are in [SPEC.md](SPEC.md), repository conventions for contributors and AI sessions in [CLAUDE.md](CLAUDE.md), the operational checklist in [AGENTS.md](AGENTS.md), and the family rules in [CONVENTIONS.md](CONVENTIONS.md) and [DESIGN.md](DESIGN.md).

## Data

- **Tables.** `{prefix}blt_toolkit_log`: one row per switch, rename or role change, with module, action, actor, "acting as", object, a one-line summary, a JSON details blob (before and after, diff, the chain of switched users), an optional snapshot ID, IP, status and source (empty for native rows, the plugin name for imported ones). `{prefix}blt_toolkit_snapshots`: the complete roles array as canonical JSON with its SHA-256, the actor, the reason and a baseline flag. Times are UTC.
- **Options.** `blt_toolkit_settings` and `blt_toolkit_db_version` (autoloaded; the Guard reads the settings on most requests), `blt_toolkit_secrets` (the encrypted GitHub token), `blt_toolkit_job_{id}` (state of a background job), `blt_toolkit_legacy_imports` (what was imported from the legacy plugins, and when). Transients: `blt_toolkit_no_role_count` caches the "users without a role" count for an hour, `blt_toolkit_log_menus` the Activity screen's filter menus for up to a week (refreshed from new rows on each view, dropped by the prune), and `blt_toolkit_recovery_in_code` notes for a day that recovery mode repaired roles defined in code, so that is logged once a day rather than on every request.
- **User meta.** `blt_toolkit_editor_mode` (Simple or Advanced), `blt_toolkit_columns` (Advanced matrix columns), `blt_toolkit_previous_nicenames` (old author slugs, for redirects), `blt_toolkit_username_changed_at` (cooldown).
- **Session data** in WordPress's `session_tokens`: `blt_switched_from_id`, `blt_switched_from_session` (the verifier of the session switched from, never a token) and `blt_switched_remember` on the session created for a switch target; `blt_switched_into` and `blt_switched_at` on the session of the person who switched. A switch target's session lasts at most 48 hours.
- **Cookies.** `wordpress_blt_sw_{COOKIEHASH}` and `wordpress_blt_sw_secure_{COOKIEHASH}` hold the stack of the originating users' auth cookies; `wordpress_blt_sw_olduser_{COOKIEHASH}` holds a logged-in cookie for the most recent one. All `HttpOnly` and `SameSite=Lax`, and `Secure` when WordPress's own auth cookies are. Like core's auth cookies, they contain the originating user's username.
- **Roles and capabilities** stay in WordPress's own storage: the `{prefix}user_roles` option and the `{prefix}capabilities` user meta. There is no parallel copy. BLT Toolkit adds its nine capabilities to `administrator` on activation, and `blt_readonly_{plural}` marker capabilities to roles given View access to a content type with separate permissions.
- **Cron.** A daily event prunes the log, and single events drive background jobs (Action Scheduler actions in the `blt-toolkit` group instead, on sites that have it). All are named `blt_toolkit_*`, and deactivation clears them.
- **Privacy.** A suggested privacy policy paragraph, a personal data exporter for log rows where the person is the actor, the "acting as" user or the object, and an eraser that blanks the stored username and IP on those rows and reports them as retained for security.
- **Deactivation** changes no data. Role edits persist, because they are WordPress's data. Content types given separate permissions go back to sharing Posts' permissions on the next request (the Plugins screen warns before this). Read-only enforcement stops: a role at View keeps `edit_{plural}` without create, so its users still cannot add items or edit others' items, and can edit only items an administrator assigned to them. A switched session becomes an ordinary session as the target user; the original user signs in again normally.
- **Uninstall** deletes nothing unless **Delete all plugin data on uninstall** is on. When it is, both tables, the plugin's options, transients, user meta and job state, its scheduled events and Action Scheduler actions, and its update-checker state are removed, along with BLT Toolkit's own nine capabilities and every `blt_readonly_*` marker from all roles and users. It never restores a snapshot, never reverts the owner's role edits, never deletes a role, never removes another BLT plugin's capabilities, and never touches the shared BLT family store.
- **Multisite:** deferred. Activation is refused.

## Architecture notes and decisions

- **Modules on shared services.** Three modules (`switch`, `usernames`, `roles`) sit on services that always load, using BLT Secure's module pattern. A module that is off, disabled by constant or in conflict with its legacy plugin registers no hooks. The Guard and the log always load, because protection must hold with every module off.
- **One Guard, enforced where WordPress decides.** Every authorization rule runs through `map_meta_cap`, the `editable_roles` filter or the Guard's checks inside BLT Toolkit's own write paths, never by reading `$_GET` on particular screens. That is what makes the rules hold for `admin-ajax.php`, `admin-post.php`, REST and WP-CLI, not only for `users.php` and `user-edit.php`.
- **Role saves are diffs, locked, snapshotted and written once.** A form submits which capabilities were on the page and which are checked, so capabilities that were filtered out or not shown are never touched. The role's hash from render time is compared at save, so two admins cannot silently overwrite each other. A snapshot is taken, every change is applied in memory, and the roles option is written once.
- **Rendering never writes roles.** Opening a screen changes nothing. Granting registered capabilities that `administrator` lacks is an explicit **Sync administrator** action on Tools, with a preview.
- **Simple view is honest.** Its controls are declared as data and mapped to capabilities by a pure, unit-tested class. A role whose capabilities match no level shows **Custom** and is never changed unless the admin picks a level, and then the review step lists exactly what is removed.
- **Separate permissions for a custom post type** re-register a type that shares Posts' or Pages' capabilities with its own, seeded so nobody's effective access changes on day one. It works through one `register_post_type_args` filter added when the main file loads, because some plugins register post types before `plugins_loaded`. That is the one exception to "the main file owns exactly four things", and the callback returns at once when no type is isolated. The View level's read-only dashboard access uses a marker capability enforced in `map_meta_cap`, and only when the user lacks the type's create capability, so capabilities stay additive across roles.
- **Switching keeps User Switching's mechanism.** A JSON stack of the originating users' auth cookies plus a logged-in "old user" cookie, all generated and validated by WordPress's own cookie functions. What changes is naming, the permission model, styling, logging and a few additions. The cookie names start with `wordpress_` because many hosts and page caches already bypass on those.
- **A switch never outlives the person who started it.** The session made for the person you switch to remembers the session you switched from; when that one ends (you sign out elsewhere, someone uses "Log out everywhere", your password is reset), the switched session is signed out on its next request. Signing in or out in a switched browser ends every session the switch could return to, also while the Switch module is off. A switch lasts at most 48 hours, and its cookies are browser-session cookies unless you signed in with "Remember Me".
- **Renames work by ID and clear both caches.** The new login is written by user ID; the user cache is cleared with the old user object (so the old login and old author slug stop resolving at once on sites with a persistent object cache) and then by ID. Renaming yourself re-issues your cookie with the same session token, so you stay signed in.
- **"Acting as" attribution.** While switched, the log's actor is the person who actually signed in (the first user in the switch stack) and "acting as" is the current user; the full chain goes in the details.
- **Cost per request.** The `map_meta_cap` callback returns at once for capabilities outside a small set. On the Users list, permission results are cached per role set within the request. The count of users without a role is computed only on demand. A front-end page view by a visitor who is not switched runs no queries from this plugin.
- **One credential, handled the family way.** The optional GitHub update token: the constant, then the plugin's own option encrypted with the envelope copied from BLT Secure (libsodium, then OpenSSL AES-GCM, never an unencrypted fallback), then the shared BLT `github` group if the owner opts in.
- **Uninstall removes only what BLT Toolkit owns.** SPEC 14.6 says "every `blt_*` capability"; read literally that would strip other BLT plugins' capabilities, so uninstall removes only the nine capabilities in the table above and the `blt_readonly_*` markers (recorded in [tasks/lessons.md](tasks/lessons.md)).
- **Defects in the source plugins are not ported.** SPEC 19 lists what reading their code found, each with the rule that prevents it: for example renames that never clear the user cache (UC-1), username changes authorized by `edit_users` alone (UC-3), administrator protection that reads only `$_GET` on two screens (URE-1), a capability list that writes roles while rendering (URE-2), saves that overwrite a concurrent change to another role (URE-5), and switching granted to anyone who can edit the target (US-A).

**Decisions** (SPEC 21). Adopted as recommended unless the owner overrides; D4 and D14 still need confirmation.

| # | Decision | Adopted |
| --- | --- | --- |
| D1 | Multisite scope | Refuse multisite activation in v1. |
| D2 | Compatibility hooks and the `current_user_switched()` shim | Fire User Switching's action names and define the shim only when undefined, behind a setting that defaults on. |
| D3 | Switch permission model | Explicit `blt_switch_users` capability plus SEC-1 and SEC-2. |
| D4 | Menu placement | Top-level BLT Toolkit with per-submenu capabilities, plus Users > Roles. **Pending confirmation** against `DESIGN.md`, and the family's menu position is still open (CONVENTIONS 17, item 16). |
| D5 | Hide administrators from non-administrators in the Users list | On. |
| D6 | Author slug policy | Change only when derived from the username, with 301 redirects on. |
| D7 | Sign out a renamed user's sessions | On. |
| D8 | Bulk rename cycles | Rejected in v1 ("Swap detected: rename one of these to a temporary name first"). |
| D9 | Log retention and IP recording | 365 days, IP on. |
| D10 | WordPress floor | 6.3. |
| D11 | Username shortcode timing | Phase 3b. |
| D12 | Default disallowed usernames | `admin`, `administrator`, `root`, `sysadmin`, `webmaster`. |
| D13 | `@` in usernames | Allowed by default. |
| D14 | Front-end prefix | `.blt-tk-*` and `--blt-tk-*`. **Pending confirmation** of BLT Tube's prefix. |

## Page caches

After **Switch Off**, the person is signed out but can still switch back: the login screen and a fixed link on the site offer "Switch back to {name}". A page cache serves signed-out visitors the same cached page, so that link is missing on cached pages unless the cache is bypassed for them (SW-25).

- Configure the cache to bypass any request whose `Cookie` header contains a cookie starting with `wordpress_blt_sw_olduser_`.
- Many hosts and caching plugins already bypass on any `wordpress_*` cookie, which is why the cookie names use that prefix. Check that yours does.
- **Cloudflare APO** and Cloudflare cache rules: add a bypass for requests where the cookie contains `wordpress_blt_sw_olduser_`, then confirm on the site that the footer link appears on a page that was cached.
- The switch, switch back and switch off links all go through `wp-login.php`, which page caches do not cache, and a user who is switched in is signed in, so the usual signed-in bypass already applies to them.
- The reverse leak is handled by the plugin: a page that shows the footer link to someone who is signed out is sent with WordPress's no-cache headers and `DONOTCACHEPAGE`, so a cache that bypasses only signed-in visitors (WP Super Cache's "known users" rule, for example) does not store "Switch back to {name}" and serve it to everyone. A cache that ignores both still needs the bypass above.
- A switch cookie whose session has ended is expired on the next request, so it does not keep a visitor out of the cache for days.

## Roadmap

- Previewing a role without a real user (today, "Test as…" switches to a user who holds the role).
- Time-limited switches, and notifying users that someone switched into their account.
- WP-CLI commands.
- SureCart "switch to customer", after confirming how SureCart links customers to users.
- Multisite, once it has its own design (D1).

## Non-goals (v1)

- Multisite and network administration (D1).
- BuddyPress and bbPress switch buttons and bbPress roles.
- Co-Authors Plus attribution updates on rename.
- User Role Editor Pro's admin-menu, widget, meta-box, front-end menu, per-post and content-view restrictions. Protected content belongs to BLT Documents; admin customization is a different product.
- Separate permissions for taxonomies.
- Previewing a role without a real user (roadmap).
- Time-limited switches and notifying users that someone switched into their account (roadmap).
- WP-CLI commands (roadmap; target hosts often have no shell).
- SureCart "switch to customer" (roadmap).
- Custom UI strings for buttons and messages. Use translations.

Keep the plugin narrow.

## License

GPL-2.0-or-later. Copyright S-FX.com. See [LICENSE](LICENSE).

BLT Toolkit reimplements the features of User Switching (John Blackbourn), User Role Editor (Vladimir Garagulya) and Username Changer (DigitalME), all GPL-2.0-or-later, from a written specification. Their authors are credited in [readme.txt](readme.txt).
