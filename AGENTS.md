# AGENTS.md

Operational checklist for working in this repository. What the plugin is, how it is built and why is in [CLAUDE.md](CLAUDE.md); every requirement, by ID, is in [SPEC.md](SPEC.md); the family rules are in [CONVENTIONS.md](CONVENTIONS.md) and [DESIGN.md](DESIGN.md). Do not restate them here.

## Before you start

- Read `CLAUDE.md`, then the `SPEC.md` sections for the phase you are working on, then `CONVENTIONS.md` and `DESIGN.md`. Precedence: `DESIGN.md` wins on brand, admin UI, shared settings and updates; `CONVENTIONS.md` on everything else; `SPEC.md` adds plugin-specific rules. A SPEC and DESIGN conflict is resolved in DESIGN's favor and written up in `tasks/lessons.md`.
- Check `tasks/todo.md` for the current phase and what is open, and `tasks/lessons.md` for decisions already made and gotchas that have already cost time.
- Build in phase order (SPEC 17). Do not start the next phase while one of the current phase's acceptance criteria fails.
- `composer install` (dev tools only: phpcs, WPCS, PHPCompatibility, PHPUnit). The plugin has no runtime Composer dependencies. Running as root (the cloud dev container), prefix every Composer command with `COMPOSER_ALLOW_SUPERUSER=1`, or Composer disables its plugins and phpcs never learns about WPCS (`CLAUDE.md` "Commands" has the repair step).
- For smoke tests, `bin/dev-site.sh DIR [WP_VERSION]` builds a disposable WordPress on SQLite with this repo symlinked in. Use your own site name and port so you never disturb someone else's.
- The three source plugins (User Switching 1.12.2, User Role Editor 4.66.2, Username Changer 3.3.1) are reference reading only. Reimplement from SPEC; never include or execute their code. A block that is genuinely ported keeps its original copyright line in the file header, and the authors are credited in `readme.txt`.

## Never

- Edit the vendored files in this repo alone: `DESIGN.md`, `CONVENTIONS.md`, `SPEC.md`, `LICENSE`, `blt-family-smoke.php`, `includes/blt-family/`, `includes/lib/` (plugin-update-checker), `assets/img/`, `assets/css/blt-design-system.css`. The family files are byte-identical across the BLT repos; a change is made in every repo in the same pass (CONVENTIONS 16.3).
- Declare `switch_to_user()`, `switch_off_user()` or any `user_switching_*()` function (GEN-2). Those names belong to User Switching. The only unprefixed global symbol is the `current_user_switched()` shim, defined only when no function of that name exists (SW-19).
- Write roles on a read path (RO-5). Rendering any screen, building the capability catalog or computing a Simple view state changes nothing. Granting missing capabilities to `administrator` is the explicit **Sync administrator** action on Tools.
- Port a defect listed in SPEC 19. Each row names the rule that prevents it. In particular:
  - Authorize by inspecting `$_GET` on particular screens (URE-1). Every decision goes through `map_meta_cap`, `editable_roles` or the Guard, so it holds for `admin-ajax.php`, `admin-post.php`, REST and WP-CLI too.
  - Let `edit_users` (or `edit_user` on the target) imply switching or renaming (US-A, UC-3). Use the `blt_switch_to_user` and `blt_change_username` meta capabilities.
  - Rename with `WHERE user_login = …`, skip `clean_user_cache()` on the old object, skip the 60-character limit, or validate only in JavaScript (UC-1, UC-2, UC-4, UC-6, UC-9). There is one validator for every path.
  - Nest a `<form>` inside the profile form (UC-8).
  - Save a whole roles array loaded at the start of the request, or send a full capability list from the browser (URE-5, RO-1). Saves are diffs within the on-page set, checked against the render-time hash, and written once.
  - Copy or import a role without checking that the actor holds every capability in it (URE-3, SEC-3).
  - Show a custom post type that shares Posts' capabilities as an independent group (URE-4, RO-14).
  - Offer to delete a capability that core, an active post type or taxonomy, or BLT Toolkit registers (URE-7, RO-24).
- Delete what the plugin does not own. Orphaned capabilities are flagged, never removed automatically (RO-11). Uninstall removes only BLT Toolkit's own SPEC 5.1 capabilities and `blt_readonly_*` markers, never another BLT plugin's `blt_*` capabilities, never a role, never a role edit, and never the shared store.
- Run queries on a front-end page view by a visitor who is not switched, or do per-row work from scratch on the Users list (SEC-9). Anything on `init`, `map_meta_cap`, `user_has_cap`, `pre_get_users` or a list-table row gets a "what does this cost per request?" pass.
- Add `add_filter( 'register_post_type_args', ... )` anywhere but the main file, or let its callback do work when no type is isolated (RS-31).
- Use PHP 8 syntax (`match`, named arguments, `?->`, union types, constructor promotion, `str_contains()`). The floor is PHP 7.4 and WordPress 6.3.
- Run, enable or test anything on multisite as if it were supported. Activation is refused there (D1); credentials never use `get_site_option()`.
- Rename a machine identifier that has shipped (option, hook, filter, capability, table, cron hook, constant, cookie, REST route).
- Bump the version by hand. CI bumps it (plugin header, `BLT_TOOLKIT_VERSION`, `readme.txt` stable tag) when a change merges to `main`.
- Add a runtime dependency, a Worker, an external service or a shared-store group without asking.
- Write "blt" in lowercase in text a person reads. "BLT" is all caps; lowercase is for machine identifiers only.

## Every change

1. `composer lint`
2. `composer test`
3. `composer phpcs` (before pushing)
4. Every PHP file starts with the `@package Blt_Toolkit` file docblock, then `if ( ! defined( 'ABSPATH' ) ) { exit; }` (`uninstall.php` checks `WP_UNINSTALL_PLUGIN`). Every function, method and class has a docblock.
5. A new user-facing string is wrapped in the `blt-toolkit` text domain, in American English; then `node bin/make-pot.js` and commit `languages/blt-toolkit.pot` (CI fails on POT drift).
6. Writes are POST to `admin-post.php` with a nonce, a capability check and a redirect (PRG), or REST with a `permission_callback`. The switch links are the only GET actions, with per-target nonces (SEC-7). Sanitize input, escape output, prepare SQL.
7. A new or changed public hook is added to `HOOKS.md` (signature, default, requirement ID, status) and, if it changes behavior, to `CHANGELOG.md`.
8. A new pure rule gets a known-vector test in `tests/` whose name carries the requirement ID. A new WordPress-coupled behavior is added to `tasks/todo.md` and exercised against a real WordPress install.
9. Reference requirement IDs in commit messages and in `tasks/todo.md`.
10. A **Should** that is not followed gets a written reason in `tasks/lessons.md`. A gotcha that cost you time goes at the end of `tasks/lessons.md`. Append; never rewrite history.

## Changing roles or capabilities

- Write through the role store only: per-role adds and removes within the on-page set (RO-1), the optimistic lock (RO-2), a snapshot then one `update_option()` with `use_db` off for the batch (RO-3, SNAP-1), the Guard on the computed change set (RO-4), `blt_toolkit_before_roles_save` and `blt_toolkit_after_roles_save`, and a log row with the diff and snapshot ID.
- Run the Guard rules that apply: SEC-2, SEC-3 and SEC-5 for non-administrators, SEC-10 for Critical grants, SEC-12 for `administrator`, SEC-13 for self-lockout.
- Compare roles with `BLT_Toolkit_Role_Diff`, never by hand.
- In Simple view, never change a control the admin did not change, and never change a Custom control without the review step listing what is removed (RS-4, RS-5).

## Admin screens

- Wrapper `<div class="wrap blt-ui blt-<page>">`; `blt-design-system.css` enqueued on that screen's hook only; composed from the `DESIGN.md` catalog; booleans are `.blt-toggle`, statuses `.blt-badge`; tokens, not hex; no `form-table`. Write page CSS (`assets/css/blt-toolkit-admin.css`) only for layout the catalog does not cover, and grep for a class name before reusing a shared component on a page with custom markup.
- Each submenu carries the capability in SPEC 13.1. Tabs are links, one URL each.
- The page works with JavaScript disabled; JavaScript is vanilla, unminified source is shipped, and nothing uses jQuery.
- Check the screen at 420 px wide, with JavaScript disabled, and keyboard only.
- Run the CONVENTIONS 14.4 checklist before calling a screen done.

## Front end

- Only the "Switch back" link (SW-17) and the username form (UN-23), styled by `assets/css/blt-toolkit-front.css` with `.blt-tk-*` classes and `--blt-tk-*` tokens. No global resets; it must sit cleanly inside a Bricks page.
- Nothing loads for visitors who are not switched, or on pages without the shortcode.

## Release

- Merging to `main` publishes the release. `.github/scripts/verify-zip.sh` checks the zip: one top-level `blt-toolkit/` folder, the update checker and the family layer inside, no dev files, and the same version in the header, the constant and the readme.
- The repository version stays 0.1.0 while phases are built; the first CI release after a merge is 0.1.1. Put `#major` in the merge that completes Phase 7 to publish 1.0.0.
- The update checker reads releases from `S-FX-com/BLT-Toolkit` (SPEC 3). Until the owner confirms where the repository lives, releases published anywhere else are invisible to installed sites (see `tasks/lessons.md`).
- Install the zip on a clean test site, then ship a second release to prove the update path.
- Whenever data storage changes, test uninstall: by default nothing is removed; with **Delete all plugin data on uninstall** on, the two tables, the options, transient, user meta, job options, cron events, update-checker state, BLT Toolkit's own capabilities and the `blt_readonly_*` markers are removed, while roles, role edits, other BLT plugins' capabilities, `blt_family_shared` and `blt_family_opt_in` are left alone.
