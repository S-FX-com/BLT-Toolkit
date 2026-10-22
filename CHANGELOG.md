# Changelog

Release notes for BLT Toolkit, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format; versions follow [semantic versioning](https://semver.org/). CI bumps the version when a change merges to `main` (patch by default, `#minor` or `#major` in the merge commit message), and the plugin header, `BLT_TOOLKIT_VERSION` and the `readme.txt` stable tag always match. Requirement IDs refer to [SPEC.md](SPEC.md).

The plugin is built phase by phase (SPEC 17). Entries under **Unreleased** have merged but not yet shipped in a release.

## [Unreleased]

### Added

- **Phase 0: scaffold** (SPEC 17).
  - Repository layout per CONVENTIONS 5 and 16.1. `DESIGN.md`, `CONVENTIONS.md`, `includes/blt-family/`, `blt-family-smoke.php`, `assets/img/`, `assets/css/blt-design-system.css` and plugin-update-checker v5p7 vendored byte-identical from BLT Activity Log and BLT Secure.
  - `blt-toolkit.php`: plugin header (WordPress 6.3, PHP 7.4, `Network: false`), the `BLT_TOOLKIT_*` constants including `BLT_TOOLKIT_REST_NS`, BLT family registration with the `github` group, the update checker built with a 24-hour check period and accepting only the `blt-toolkit-X.Y.Z.zip` release asset (a release without it, a tag or the branch is never offered as an update), the computed `BLT_Toolkit_*` autoloader, and boot on `plugins_loaded` priority 1 (SPEC 4.2).
  - Module registry with the `switch`, `usernames` and `roles` modules, the `blt_toolkit_modules` filter, `BLT_TOOLKIT_DISABLE_MODULES` (SEC-15) and detection of User Switching, Username Changer and User Role Editor, which keeps the matching module off (GEN-1).
  - Settings stored as one array merged over the SPEC 14.1 defaults and sanitized by hand, on save and again on read, so a value written outside the Settings screen (the string `false`, say) means what the screen would have saved. The optional GitHub update token is read from `BLT_TOOLKIT_GITHUB_TOKEN`, then the plugin's own option encrypted with the envelope copied from BLT Secure (libsodium, then OpenSSL AES-GCM), then the shared `github` group.
  - Schema: the `{prefix}blt_toolkit_log` and `{prefix}blt_toolkit_snapshots` tables (SPEC 14.3), versioned by `blt_toolkit_db_version` and re-checked on `init` on admin screens, WP-Cron and WP-CLI, never on a visitor's page view.
  - Capabilities (SPEC 5.1) granted to `administrator` on activation, after a baseline snapshot of the roles (SNAP-2). If the tables or the baseline cannot be stored, activation is refused and no role is changed; two activations at once keep one baseline. Risk tiers (SPEC 5.4, `blt_toolkit_risky_caps`), the lockout-critical set (SEC-12, `blt_toolkit_lockout_critical_caps`) and `blt_toolkit_is_toolkit_admin` (SPEC 5.2).
  - Role canonicalization, hashing and diffing (SNAP-5) and snapshot capture that skips a state identical to the newest snapshot (SNAP-1).
  - Empty admin screens (Overview, Roles, Usernames, Activity, Tools, Settings, and Users > Roles) on the BLT design system (SPEC 13).
  - Activation refused on multisite with a readable notice; on a network the plugin boots nothing (D1).
  - Deactivation that changes no data, and an `uninstall.php` that deletes nothing unless "Delete all plugin data on uninstall" is on (SPEC 14.6).
  - CI: syntax check on PHP 7.4 to 8.3, phpcs (WordPress-Extra, PHPCompatibilityWP), unit tests, the version-sync check, the POT drift check and a release-zip build check. `release.yml` ported from BLT Secure runs lint, phpcs, the unit tests and the POT check on the merged commit, then publishes `blt-toolkit-X.Y.Z.zip` on every merge to `main`.
  - Developer tooling: `bin/make-pot.js`, `languages/blt-toolkit.pot`, `bin/dev-site.sh`, and unit tests for capabilities, crypto, role diffs, settings and the CI version bump.
  - Documentation: `README.md`, `readme.txt`, `HOOKS.md`, `AGENTS.md`, `CHANGELOG.md`, `tasks/todo.md`, `tasks/lessons.md`.
