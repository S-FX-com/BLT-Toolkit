# BLT Plugin Build Standard

Engineering conventions for every plugin in the S-FX.com **BLT** family. It exists so a person, or an AI coding session, can scaffold a new BLT plugin or change an existing one and land on the same decisions every time.

| | |
| --- | --- |
| Status | Draft 1.0, October 13, 2026 |
| Companion to | `DESIGN.md` (brand, admin UI, shared settings layer, update policy) |
| Reviewed for this draft | BLT Optimized, BLT Events, BLT Gallery, BLT Documents, BLT Secure, BLT Tube. READMEs, plus `DESIGN.md` and `CLAUDE.md` where a repo has them. |
| Not reviewed | BLT Popups, BLT M365 WP SSO, BLT SureCart Extensions (named in `DESIGN.md`; repos not supplied) and BLT Bookings (spec stage). Rules below that come from `DESIGN.md` apply to them; everything else should be checked against those repos before this file is called final. |

---

## 0. How to use this file

1. Keep **one canonical copy** and vendor it byte-identical into every BLT repo as `CONVENTIONS.md`, next to `DESIGN.md`, under the same rule: change it once, port the change to every repo in the same pass, never fork it per plugin.
2. **Precedence when documents overlap:** `DESIGN.md` wins for brand, admin UI, shared settings and update policy. This file wins for everything else. A repo's own `CLAUDE.md` may add plugin-specific rules but may not contradict either.
3. **Every rule carries a tag** so you can see how much weight it has.

| Tag | Meaning |
| --- | --- |
| **[S]** Stated | Already written as a rule in a BLT repo's own docs. The source is named. |
| **[P]** Pattern | Consistent across the repos reviewed but never written down. Promoted to a rule here. |
| **[R]** Recommended | The repos disagree or are silent. This is a proposed rule, not an established one. Confirm or overrule it in section 17. |

4. **Existing plugins keep their existing machine identifiers** (option keys, hooks, tables, shortcodes, namespaces). Renaming them breaks live sites. These rules govern new plugins and new code.

---

## 1. Non-negotiables

If a session reads only one section, it should be this one.

1. **"BLT" is all caps wherever a person reads it** (plugin names, admin labels, notices, readmes, commit messages). Lowercase `blt` is correct only in machine identifiers, which are never "corrected". **[S]** DESIGN 1
2. **Author is exactly** `S-FX.com` with Author URI `https://www.s-fx.com`. **[S]** DESIGN 1
3. **Core function works standalone.** Cloudflare, Workers, R2 and every third-party service are additive. When one is unreachable the plugin degrades (the image stays local, the feature stays off) instead of failing. **[P]** Secure, Optimized, Gallery
4. **Nothing is shared between BLT plugins until the site owner opts in.** A site with every local field filled behaves identically with or without the shared layer. **[S]** DESIGN 3
5. **Secrets are never stored in plaintext.** Use the family envelope (libsodium, then OpenSSL AES-GCM) and never an unauthenticated fallback. **[S]** Secure CLAUDE.md (see section 17, item 2)
6. **Every action is nonce and capability checked. Input is sanitized, output is escaped, SQL is prepared.** **[S]** Secure, Documents
7. **No heavy work on page load.** Scans, syncs, imports and remote calls run as background jobs. **[S]** Secure CLAUDE.md
8. **Long jobs are time-boxed, resumable and cancellable, with visible progress.** **[P]** Optimized, Gallery (Tube shows live progress on manual sync)
9. **Destructive actions default to dry-run**, require POST plus nonce, log who/what/when, and never delete things the plugin does not own (flag them instead). **[S]** Optimized README
10. **Updates come from GitHub Releases through the vendored `plugin-update-checker`, at most once a day.** A manual check always works immediately. **[S]** DESIGN 4
11. **Admin screens are composed from the `.blt-ui` catalog and colour tokens.** No `form-table`, no hand-rolled status spans, no literal hex values. **[S]** DESIGN 2
12. **Front-end output is self-contained, prefixed, framework-independent**, and works without JavaScript wherever a link or form can. **[S]** DESIGN 2, Documents CLAUDE.md
13. **Uninstall deletes nothing unless the owner opted in, and never touches the shared store.** **[S]** Events README, DESIGN 3
14. **The version exists in the plugin header and one constant, they always match, and CI (not a human) cuts the release.** **[S]** Secure, Documents. **[R]** family-wide, because Tube does it by hand.
15. **`DESIGN.md`, `CONVENTIONS.md` and `includes/blt-family/` are never edited in one repo alone.** **[S]** DESIGN 3

---

## 2. Parameters at a glance

| Parameter | Value | Tag and source |
| --- | --- | --- |
| Plugin header: Author | `S-FX.com` (not `S-FX.COM`, not `s-fx.com`, no personal names, no "Small Business Solutions") | **[S]** DESIGN 1 |
| Plugin header: Author URI | `https://www.s-fx.com` (no trailing slash) | **[S]** DESIGN 1 |
| Display name | `BLT <Name>` | **[S]** DESIGN 1 |
| Slug, folder, text domain | `blt-<name>` | **[S]** DESIGN 1 |
| License | GPL-2.0-or-later | **[R]** Events, Documents, Secure, Tube say this. Gallery says GPL-3.0-or-later. Optimized does not say in its README. |
| PHP and WordPress floor | Declare in the header and `readme.txt`. Default 7.4 / 6.0 for plugins that must run on shared hosting. Raise only with a stated reason. | **[R]** floors today run 7.4 to 8.1 |
| Automatic update check | At most 1 per day, anchored to 00:00 site time. Hard 24-hour floor. | **[S]** DESIGN 4 |
| `buildUpdateChecker()` `$checkPeriod` | `24`. Never `0` (registers no scheduler hooks and cannot be revived). | **[S]** DESIGN 4 |
| GitHub token precedence | wp-config constant `BLT_<NAME>_GITHUB_TOKEN`, then the plugin's own option, then the shared `github` group (opt-in) | **[S]** Secure, Gallery, Optimized |
| Settings option | `blt_<name>_settings` (one array) | **[S]** Documents CLAUDE.md |
| Schema version option | `blt_<name>_db_version` (scalar) | **[S]** Documents CLAUDE.md |
| Tables | `{prefix}blt_<name>_<thing>` | **[S]** Documents, Optimized |
| Secret encryption | libsodium secretbox, then OpenSSL AES-GCM. Key derived from the WP salts and never stored. | **[S]** Secure CLAUDE.md, DESIGN 3 |
| Secret storage | Separate option with `autoload = no` | **[S]** Secure CLAUDE.md |
| Shared store | `get_option`, `autoload = no`. Never `get_site_option()`. | **[S]** DESIGN 3 |
| Admin menu icon | `BLT_Family_Brand::menu_icon()` (reads `blt-mark-menu.svg`, 20 x 20, painted `#a7aaad`) plus `print_menu_icon_style()` on `admin_head` | **[S]** DESIGN 1 |
| Logo | `blt-mark.svg`, 100 x 100, `fill="currentColor"`, rendered at 22px in page headers | **[S]** DESIGN 1 |
| Plugin card icons | `icon-128x128.png`, `icon-256x256.png`, transparent | **[S]** DESIGN 1 |
| Admin page wrapper | `<div class="wrap blt-ui blt-<page>">` | **[S]** DESIGN 2 |
| Admin stylesheet | `assets/css/blt-design-system.css`, handle `<slug>-design-system`, enqueued on that screen's hook only | **[S]** DESIGN 2 |
| Primary / hover / tint | `#2271b1` / `#135e96` / `#f6fafd` (use the tokens, not the hexes) | **[S]** DESIGN 2 |
| Radii | `8px` card, `4px` control | **[S]** DESIGN 2 |
| Plugin-to-Worker auth | `Authorization: Bearer`, `X-BLT-Timestamp`, `X-BLT-Control`, `X-BLT-Signature` (HMAC-SHA256). Freshness window plus or minus 300 s (`MAX_SKEW`). | **[S]** Documents CLAUDE.md |
| R2 object cache header | `Cache-Control: max-age=31536000` | **[S]** Gallery README |
| Background tick budget | Seconds per tick exposed as a filter. Optimized defaults to 15. | **[S]** Optimized README |
| Release bump | CI only. Patch by default. `#minor` / `#major` in the merge commit message. Tag `vX.Y.Z`. | **[S]** Secure, Documents |
| Release asset | `blt-<name>-X.Y.Z.zip`, one top-level `blt-<name>/` folder. The updater accepts only this asset, never source archives. | **[S]** Secure README |
| Admin menu position | Documents uses 81. The `DESIGN.md` snippet shows 58 as an example. | open, see section 17 |

---

## 3. The family

| Plugin | Purpose | Cloudflare footprint | Notes |
| --- | --- | --- | --- |
| BLT Optimized | `wp-content` disk forensics, orphaned-data cleanup, DB optimization. Optional image module. | Worker in `worker/` for the image module only | Absorbed the former BLT Image Optimizer in v1.1. Core has zero external dependency. |
| BLT Events | Event registration, ticketing, checkout (Stripe, SureCart, FluentCart), online meeting rooms | None required | **Reference implementation for the admin UI.** Most complete tests, CI and docs. |
| BLT Gallery | Galleries, albums, sliders, with R2/S3 offload and Cloudflare Image Resizing | R2, Image Resizing (optional) | Formerly ZymGallery. Optional Bricks elements. |
| BLT Documents | Versioned, private document delivery for governance boards | Private R2 bucket plus HMAC-gated Worker | Three-plane architecture (section 12). |
| BLT Secure | WordPress hardening plus Cloudflare WAF deployment | Cloudflare API, scoped zone token | Local hardening works with no token. |
| BLT Tube | YouTube playlist to Custom Post Type sync, plus embed shortcode | None | Formerly ZymTube. |
| BLT Popups, BLT M365 WP SSO, BLT SureCart Extensions | Named in `DESIGN.md` | n/a | Not reviewed. |
| BLT Bookings | Calendly-style booking with M365 calendars and Teams (spec stage) | n/a | Not yet in the `DESIGN.md` family list. |

---

## 4. Naming and identity

### 4.1 Naming matrix for a new plugin

Example column uses BLT Documents. Replace `<name>` and `<Name>`.

| Thing | Pattern | Example | Tag |
| --- | --- | --- | --- |
| Display name | `BLT <Name>` | BLT Documents | **[S]** |
| Repository | `S-FX-com/BLT-<Name>` | `BLT-Documents` | **[P]** |
| Slug, folder, text domain, top-level menu slug | `blt-<name>` | `blt-documents` | **[S]** |
| Main file | `blt-<name>.php` | `blt-documents.php` | **[P]** |
| Constants | `BLT_<NAME>_VERSION`, `_FILE`, `_DIR`, `_URL`, `_BASENAME`, and `_REST_NS` if there is REST | `BLT_DOCUMENTS_VERSION` | **[S]** Documents |
| Classes | `BLT_<Name>_<Thing>`, no namespace | `BLT_Documents_Signer` | **[S]** Documents. See section 17, item 4. |
| Class files | `includes/class-blt-<name>-<thing>.php` (admin classes in `admin/`) | `class-blt-documents-signer.php` | **[S]** Documents |
| Functions, hooks, filters | `blt_<name>_*` | `blt_secure_modules` | **[S]** DESIGN 1 |
| Options | `blt_<name>_settings`, `blt_<name>_db_version` | `blt_documents_settings` | **[S]** Documents |
| Tables | `{prefix}blt_<name>_<thing>` | `wp_blt_documents_files` | **[S]** Documents |
| Capabilities | `blt_<verb>_<noun>` | `blt_manage_documents` | **[R]** Events uses `manage_blt_events`. |
| Shortcodes | `[blt_<name>]` or `[blt_<name>_<thing>]` | `[blt_documents]` | **[S]** DESIGN 1 |
| REST namespace | `blt-<name>/v1` | `blt-events/v1` | **[P]** |
| Front-end CSS classes | `.blt-<abbr>-*`, self-contained | `.blt-doc-*` | **[S]** Documents |
| Front-end CSS tokens | `--blt-<letter>-*` | `--blt-e-primary` (Events) | **[P]** |
| Admin component classes | Shared `.blt-*` under `.blt-ui`. Do not invent parallel ones. | `.blt-card` | **[S]** DESIGN 2 |
| JS localized global | camelCase | `bltDocuments` | **[S]** Documents |
| Cloudflare rule ref | `blt-<name>-*`, description prefixed `[BLT <Name>]` | `blt-secure-*` | **[S]** Secure |

### 4.2 Human-readable versus machine identifiers

Write **BLT** in caps in anything a site owner, reviewer or reader sees. These stay lowercase and must not be "corrected", because renaming them breaks live sites **[S]** DESIGN 1:

text domains, option keys, menu and page slugs, shortcodes, CSS classes, hook and filter names, function prefixes, DB tables. Class names and namespaces (`Blt_Secure_Crypto`, `BltGallery\Admin`) are internal identifiers too: leave them as each repo has them.

### 4.3 Authorship and license

```
* Author:            S-FX.com
* Author URI:        https://www.s-fx.com
```

Exactly this in every plugin header, readme and package manifest. `Plugin URI` is separate and points at the product page or GitHub repo. **[S]** DESIGN 1

License is GPL-2.0-or-later **[R]**. Ship a `LICENSE` file; Tube and Optimized do not show one at the repo root.

### 4.4 Rebrands, merges and legacy names **[P]**

- **Rebrand** (ZymGallery to BLT Gallery, ZymTube to BLT Tube): migrate legacy options and tables on first load, keep legacy shortcodes and post-meta keys working, and never ask editors to change content. Legacy prefixes (`bltgallery_`, `bltt_`) are frozen, not renamed.
- **Merge** (BLT Image Optimizer into BLT Optimized): the absorbed module is self-contained under its own namespace and directory, **off by default**, and keeps its own option key and table. Optimized deliberately keeps `blt_optimizer_settings` one letter away from `blt_optimized_settings` so the two never collide. When the module is off, no code runs and no table or option is created.
- **Hand-off safety:** artifacts a site depends on survive uninstall (Optimized keeps its optimized `.webp` files and the postmeta that points at them).

### 4.5 Language

Source strings are `en_US`. WordPress treats `en_US` as the source locale and translations (including `en_GB`) layer on top. Several READMEs currently use British spelling ("optimisation", "colour", "Customising"); write new docs and strings in American English. **[R]**

---

## 5. Repository layout and required files

```
blt-<name>/
├── blt-<name>.php              Header, constants, family bootstrap, update checker, autoloader, boot
├── uninstall.php
├── readme.txt                  WordPress-format readme
├── README.md
├── CHANGELOG.md
├── CLAUDE.md                   Repo conventions for AI sessions
├── AGENTS.md                   Operational checklist
├── DESIGN.md                   Vendored, byte-identical across repos
├── CONVENTIONS.md              This file, vendored, byte-identical across repos
├── HOOKS.md                    Every public action and filter
├── LICENSE
├── composer.json               Dev tooling only
├── phpcs.xml.dist
├── phpunit.xml.dist
├── .distignore                 Keeps dev files and worker/ out of the zip
├── blt-family-smoke.php        Present at the root of all six repos reviewed (contents not reviewed)
├── includes/
│   ├── blt-family/             Vendored shared layer. Never edit locally.
│   ├── lib/plugin-update-checker/   Vendored. Must ship in the zip.
│   └── class-blt-<name>-*.php
├── admin/                      Admin controller, views, admin-only assets
├── assets/
│   ├── css/                    blt-design-system.css lives here
│   ├── js/
│   └── img/                    blt-mark.svg, blt-mark-menu.svg, icon-128x128.png, icon-256x256.png
├── templates/                  Theme-overridable front-end HTML (only if the plugin renders any)
├── languages/                  <slug>.pot, committed
├── tests/                      PHPUnit, no WordPress install required
├── tasks/
│   ├── todo.md                 Phase checklists with acceptance criteria. Keep current.
│   └── lessons.md              Gotchas found while building. Append, never rewrite history.
├── worker/                     Only if the plugin needs a Cloudflare Worker. Not in the zip.
└── .github/workflows/release.yml
```

Layout is **[P]**, drawn mainly from Documents, Secure and Events. The `includes/lib/plugin-update-checker/` location is **[S]** in Documents and Secure but **[R]** family-wide: Optimized keeps it at the repo root and Gallery under `lib/`.

### 5.1 Plugin header and main-file skeleton **[S]** assembled from DESIGN 1, 3, 4 and Documents CLAUDE.md

```php
<?php
/**
 * Plugin Name:       BLT Thing
 * Plugin URI:        https://github.com/S-FX-com/BLT-Thing
 * Description:       One sentence a site owner understands.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            S-FX.com
 * Author URI:        https://www.s-fx.com
 * License:           GPL-2.0-or-later
 * Text Domain:       blt-thing
 * Domain Path:       /languages
 *
 * @package Blt_Thing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLT_THING_VERSION', '1.0.0' ); // Must equal the header. CI enforces this.
define( 'BLT_THING_FILE', __FILE__ );
define( 'BLT_THING_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_THING_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_THING_BASENAME', plugin_basename( __FILE__ ) );

// 1. Family layer. Register before plugins_loaded.
require_once BLT_THING_DIR . 'includes/blt-family/bootstrap.php';
blt_family_register(
	BLT_THING_FILE,
	array(
		'name'    => 'BLT Thing',
		'slug'    => 'blt-thing',
		'version' => BLT_THING_VERSION,
		'menu'    => 'blt-thing',
		'groups'  => array(), // Declare a group only when 2+ plugins really share the credential.
	)
);

// 2. Update checker. The fourth argument MUST be 24.
$checker = PucFactory::buildUpdateChecker( $repo_url, BLT_THING_FILE, 'blt-thing', 24 );
BLT_Family_Updates::apply(
	$checker,
	array(
		'basename'  => BLT_THING_BASENAME,
		'icons_url' => BLT_THING_URL . 'assets/img/',
	)
);

// 3. Computed prefix autoloader (probes includes/, then admin/).
// 4. Boot on plugins_loaded.
```

The main file owns exactly four things: header and constants, family bootstrap, update checker, autoloader and boot. Everything else lives in classes.

---

## 6. Code conventions

### 6.1 PHP structure

- `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top of **every** PHP file, with a `@package Blt_<Name>` docblock. **[S]** Documents
- WordPress Coding Standards, **WordPress-Extra** ruleset, enforced through `phpcs.xml.dist`. **[S]** Documents, Secure
- Computed prefix autoloader in the main file. Adding a class means creating its file; there is no manifest. **[S]** Documents
- Admin controller is a singleton: one menu entry, one capability, page-gated asset enqueue, PRG (post/redirect/get) form handling on `admin_init`. **[S]** Documents
- Plugins with several independent features use **modules**: an interface, a registry booted on `plugins_loaded` priority 1, and a filterable list (`blt_<name>_modules`). **[S]** Secure CLAUDE.md. Adopt wherever a plugin has three or more switchable features **[P]**.
- A module that carries an external dependency ships **off by default**. When off, no code loads and no table or option is created. **[S]** Optimized README
- Syntax must not exceed the plugin's declared PHP floor, and CI lints the whole supported range. **[R]**

### 6.2 Settings and data

- **One array option** `blt_<name>_settings`, merged over defaults, hand-sanitized in `Settings::save()`. Never `register_setting`. **[S]** Documents
- `blt_<name>_db_version` is a separate scalar. Custom tables are created with `dbDelta`, gated on a stored schema version, and re-checked on `init` (`maybe_upgrade`) so that copying new files over an old install still migrates. **[S]** Documents
- **Autoload discipline.** Only values read on nearly every request are autoloaded. Tokens, secrets, deployment IDs, event logs and anything that changes at a different cadence use `autoload = no`. **[S]** Secure CLAUDE.md
- **Empty is not unset.** Settings merge over defaults of `''`, so a field an admin cleared looks identical to one never filled. Never infer "not configured, so inherit from somewhere else". **[S]** DESIGN 3
- A blank submitted secret **preserves** the stored value; clearing a secret is an explicit action. **[S]** Documents
- **Direct queries:** interpolate only the table name, bind every value through `$wpdb->prepare` with `%d` / `%s`, whitelist any dynamic `ORDER BY`, and annotate with the `phpcs:ignore WordPress.DB.*` comments. **[S]** Documents
- **Per-site storage.** Never `get_site_option()` for credentials: on multisite it would hand one tenant's secrets to every site. State in the README whether multisite is supported or deferred (Optimized: deferred). **[S]** DESIGN 3, **[P]** README rule
- Table names carry the plugin slug (`blt_<name>_<thing>`). Events' generic names (`blt_registrations`, `blt_attendees`) are legacy and will collide with any future BLT plugin that wants the same words. **[R]**

### 6.3 Security

- **Nonce and capability check on every form, AJAX handler and REST callback.** **[S]** Secure
- REST routes always declare a `permission_callback`. **[R]** (WordPress platform requirement; listed here so it is never skipped)
- `manage_options` guards settings and credential screens. Functions that an owner might delegate (managing documents, running events) get their own capability and, where useful, a role (Documents: Board Member; Events: Event Manager). **[P]**
- Escape at output with `esc_html`, `esc_attr`, `esc_url`. Use `esc_url_raw` for storage and `wp_kses_post` for pre-built markup. **[S]** Documents
- **Secrets:** a `BLT_<Name>_Crypto` class using libsodium secretbox with an OpenSSL AES-GCM fallback, key derived from the WP salts and never stored. Never store plaintext, never fall back to an unauthenticated cipher. Detect salt rotation and ask for the secret again rather than failing silently. **[S]** Secure CLAUDE.md and README
- A wp-config constant always overrides a stored secret. **[S]** Secure, Gallery
- Trust `CF-Connecting-IP` only when the request actually arrives from Cloudflare's published ranges. **[S]** Secure README
- Never expose a storage key, Worker URL or signature to the browser. **[S]** Documents CLAUDE.md
- Any feature that can lock an administrator out ships with a wp-config escape hatch (Secure: `define( 'BLT_SECURE_DISABLE_SLUG', true );`). **[R]** generalizing Secure

### 6.4 Front end

- Self-contained, prefixed CSS. **No dependency on a utility-class framework.** Front-end output renders inside the active theme, so it is deliberately not part of `.blt-ui`. **[S]** DESIGN 2
- Shortcodes **return** an escaped string (`ob_start` / `ob_get_clean`) and enqueue assets **only when the shortcode or block is present**. **[S]** Documents
- Links are real `<a>` elements and forms are real forms. Tabs are links, one URL each. Everything works without JavaScript where it can. **[S]** Documents, DESIGN 2
- **Vanilla JS, no jQuery.** **[S]** Documents, Gallery. No minified-only JS shipped. **[S]** Secure CLAUDE.md
- Load scripts deferred; put `fetchpriority="high"` on the LCP image where the plugin renders one. **[S]** Gallery README
- Accessibility target **WCAG 2.2 AA**: keyboard navigation, ARIA roles, focus traps. **[S]** Gallery README; **[R]** family-wide
- **Themeability** for any output meant to be restyled: every colour, radius, shadow and font resolves through custom properties (`--blt-<letter>-*`); front-end templates live under `templates/` and can be overridden at `your-theme/blt-<name>/<same path>`; offer **Styled**, **Skeleton** (inherits the site framework's variables, e.g. Automatic.css) and **No-CSS** (BEM markup only) modes. **[S]** Events README; **[R]** for other plugins with themeable output
- S-FX builds WordPress front ends in Bricks. Plugin output must sit cleanly inside a Bricks page (no global resets, no layout assumptions). A native Bricks element is optional, **off by default**, registered only when Bricks is active, and mirrors the shortcode attributes. **[S]** Gallery README for the element pattern; **[R]** for the clean-output rule
- Public entities (events, etc.) emit schema.org JSON-LD. **[S]** Events README

### 6.5 Shortcodes, blocks and REST

- Names follow section 4.1. Document every attribute in a table with columns **Attribute / Default or Values / Description**. **[P]** Events, Gallery, Tube
- **Boolean attributes:** accept `yes`, `no`, `1`, `0`, `true`, `false` on input; document `yes` / `no`. Events and Tube use `yes`/`no`; Gallery uses `1`/`0`. **[R]**
- Shared attributes where they apply: `id` or `slug`, `limit`, `order`, `class`. **[P]**
- Where output can be placed in a page, ship the shortcode **and** a block (Events) or builder element (Gallery), and a builder UI in Settings when attributes are numerous (Events: Settings > Shortcodes & Blocks). **[P]**
- REST namespace `blt-<name>/v1`. Register public meta deliberately. Anything that serves a protected file goes through a REST route with nonce and capability checks and **streams** the response rather than redirecting to a signed URL. **[S]** Documents CLAUDE.md

### 6.6 Hooks and extensibility

- Every public hook is documented in `HOOKS.md` (Events documents about 90). Optimized currently documents its hooks in a README table. **[R]** `HOOKS.md` everywhere
- Lifecycle pairs as actions (`before_scan` / `after_scan`, `before_cleanup` / `after_cleanup`). Filters for lists, budgets and exclusions, each with a documented default. **[P]** Optimized, Gallery, Events
- A shipped hook name is never renamed. **[S]** DESIGN 1

### 6.7 Internationalization

- Text domain equals the slug. Every user-facing string is wrapped. `languages/<slug>.pot` is committed and CI checks for POT drift. **[S]** Events README, DESIGN 5

---

## 7. Admin UI

The authoritative markup, component catalog and token values are in `DESIGN.md` section 2. This section is the working summary. If it ever disagrees with `DESIGN.md`, `DESIGN.md` wins.

**Two systems, same colours.** Custom admin pages use `.blt-ui` and `.blt-card` divs. Post-type editor screens use WordPress's native `.postbox`, tagged `.blt-card` via `postbox_classes_{screen}_{id}`, scoped by `body.post-type-<cpt>`.

**Adding a screen:**

1. Register with `add_menu_page()` / `add_submenu_page()`.
2. Enqueue `blt-design-system.css` on that hook only. Never unconditionally.
3. Wrap content in `<div class="wrap blt-ui blt-<page>">`. Without `blt-ui` nothing is scoped.
4. Compose from the catalog. Write page-specific CSS only for layout the catalog does not cover.
5. **Grep for the class name before reusing a shared component on a page with custom markup.** A page-local `.blt-field-label` colliding with the shared one is the bug this rule exists for.

**Catalog:** page header (`.blt-admin-page-header`, optional `.blt-brand-mark`), card, field row, toggle, badge, tabs, save bar, stat tiles, progress bar, selectable cards, callout, empty state, toolbar, code block, URI chip.

**Rules**

- Booleans are `.blt-toggle`, never a bare checkbox. Statuses are `.blt-badge` (`on`, `off`, `confirmed`, `pending`, `cancelled`). **[S]**
- Never `<table class="form-table">`. Use `.blt-field` rows. **[S]**
- Never hard-code a token hex. Reference the token, with a literal fallback only where the stylesheet may load without the design system: `var(--blt-fg, #1e1e1e)`. **[S]**
- Register the top-level menu with `BLT_Family_Brand::menu_icon()`, never a dashicon, and call `print_menu_icon_style()` on `admin_head`. Use `blt-mark-menu.svg` **only** for the menu; never at larger sizes. **[S]**

**Colour tokens** (mirrored from DESIGN 2)

| Token | Value | Use |
| --- | --- | --- |
| `--blt-primary` / `--blt-primary-hover` / `--blt-primary-tint` | `#2271b1` / `#135e96` / `#f6fafd` | Accent, active tab, focus ring, checked toggle / hover / selected card and callout background |
| `--blt-fg` / `--blt-muted-fg` | `#1e1e1e` / `#646970` | Primary / helper text |
| `--blt-border` | `#dcdcde` | Card and control borders |
| `--blt-surface` / `--blt-surface-muted` | `#ffffff` / `#f6f7f7` | Card background / header dividers, code chip |
| `--blt-success-bg` / `-fg` | `#edfaef` / `#005c12` | Connected, Confirmed |
| `--blt-warning-bg` / `-fg` | `#fef8ee` / `#94660c` | Pending |
| `--blt-danger-bg` / `-fg` | `#fcf0f1` / `#8a2424` | Cancelled, error text |
| `--blt-neutral-bg` / `-fg` | `#f0f0f1` / `#646970` | Not connected, Refunded |
| `--blt-radius` / `--blt-radius-sm` | `8px` / `4px` | Card / control corners |

**Admin UX patterns beyond `DESIGN.md` [P]**

- A **Test Connection** (or Validate Key) button next to every external credential. Documents, Optimized and Tube all have one.
- A **Check for Updates** action on the plugin's own settings screen. **[S]** DESIGN 4
- Background jobs show a live progress bar and survive closing the tab.
- A first-run **setup card** that lists what is missing and fixes what it can in one click (Events). **[R]** for plugins with a setup step.

---

## 8. Shared settings layer (`includes/blt-family/`)

Authoritative contract: `DESIGN.md` section 3 and `includes/blt-family/README.md`. Working summary:

- **Load it, don't own it.** Every plugin ships a copy and registers it. The newest version on the site boots once. Never fork it inside a plugin. **[S]**
- **Precedence is always:** wp-config constant, then the plugin's own option, then the shared store. The shared store is a fallback, never a replacement, and the library never writes to a plugin's own options. **[S]**
- **Nothing is shared until the site owner says so.** `BLT_Family::get()` returns the default unless 2+ BLT plugins are active, the plugin declared the group, **and** the owner enabled that group for that plugin on the BLT screen. Default off, including on upgrade. **[S]** This is the single most important rule in the layer: these credentials sit under `is_configured()`-style gates that decide whether a module boots, whether cron enqueues and what Site Health reports. A second plugin must never silently wake a dormant feature on a site whose owner never entered that credential.
- The shared screen appears **only** when 2+ BLT plugins are active. One plugin on a site sees no new menu, notice or screen. **[S]**
- No plugin's `uninstall.php` touches the shared option. **[S]**
- **Group carefully.** A group earns its place only when two or more plugins consume the *same* credential. **[S]**

| Group | Used by | Notes |
| --- | --- | --- |
| `github` | Optimized, Gallery | Update token |
| `image_worker` | Optimized | Image Worker URL and secret |
| `cloudflare`, `r2` | Named in the `DESIGN.md` example | Confirm the live group and field list in `includes/blt-family/README.md` |

**Do not share:** `worker_url` / `worker_secret` between Documents and the image module (different Workers behind identical field names); a referrer-restricted Google Maps browser key with an IP-restricted YouTube Data server key. **[S]** DESIGN 3

**Forward note [R]:** Events already creates Teams meetings and BLT Bookings will too. Do not introduce a `microsoft` group unless both genuinely use the same app registration and secret. Decide when Bookings is scoped.

---

## 9. Updates and releases

### 9.1 Update checker **[S]** DESIGN 4

- Every plugin updates from its **own GitHub Releases** through the vendored `plugin-update-checker` (v5.x).
- At most one automatic check per day, anchored to 00:00 site time. `plugin-update-checker` shortens its own interval to 60 seconds on Dashboard > Updates and 1 hour on the Plugins screen; `BLT_Family_Updates::apply()` overrides that with a hard 24-hour floor.
- **Manual checks always run immediately:** the Plugins-row **Check for Updates** link, the **Check again** button on Dashboard > Updates, and **Check for Updates** on the plugin's own settings screen.
- Construct the checker with `24` as `$checkPeriod`. A checker built with `0` registers no scheduler hooks and cannot be revived.
- Attach the BLT mark to the plugin card on update screens (`icons_url`).
- Never lower the floor below 24 hours. Never disable the manual path.
- Private repo or rate limits: token precedence is `BLT_<NAME>_GITHUB_TOKEN` constant, then the plugin's option, then the shared `github` group. When no token is present and the repo is private, show a warning on Plugins and Updates rather than letting sites quietly fall behind.

### 9.2 Release pipeline **[S]** Secure, Documents, Events

1. Every merge to `main` runs `.github/workflows/release.yml`.
2. CI bumps the version (patch by default; `#minor` or `#major` in the merge commit message, or a manual workflow dispatch), writes it to **both** the header and the constant, commits with `[skip ci]`, and tags `vX.Y.Z`.
3. CI builds the zip per `.distignore`: one stable top-level `blt-<name>/` folder, **includes** the vendored update checker internals, **excludes** `worker/` and dev files, and verifies both.
4. CI publishes a GitHub Release with `blt-<name>-X.Y.Z.zip` attached. The updater accepts **only** that asset, never source archives.
5. If branch protection is enabled on `main`, grant the GitHub Actions app a ruleset bypass (or switch the workflow to a PAT secret) so the bot's bump push is not rejected.
6. A CI check fails the build if the header version and the constant differ.

**Do not hand-edit only one of the two version strings.** Tube currently requires a manual bump on every commit and silently skips the release when the tag already exists. See section 17, item 1.

---

## 10. Background work and resilience

- **No heavy work on page load.** Anything that hooks the core lifecycle gets a "what does this cost per request?" pass. **[S]** Secure
- **Scheduler:** Action Scheduler when present (`as_enqueue_async_action`), otherwise chained WP-Cron single events, and while an admin page is open also drive progress by AJAX polling so work advances regardless. No vendored Action Scheduler copy to keep patched. **[S]** Optimized README
- **Time-boxed ticks** with the budget exposed as a filter (Optimized: `blt_optimized_tick_budget`, default 15 s). **[S]**
- **Resumable:** partial state persists between ticks so a timeout, deploy or restart costs seconds, not the run. Save progress every few items. **[S]** Optimized, Gallery
- **Survives the tab:** the job continues server-side; reopening the screen shows live progress (bar, current item, done/total, elapsed, estimate, per-item warnings). **[S]** Gallery. Tube's manual sync also shows a live progress bar. **[P]**
- **Cancellable:** cancel keeps whatever finished. **[S]** Gallery
- **Stall handling:** triggered by WP-Cron plus an immediate loopback request. If the host blocks both, detect the stall and drive the job in the foreground with a notice to keep the tab open. **[S]** Gallery
- **Pin the backend at run start** so flipping a toggle mid-run cannot split work between two destinations. **[S]** Gallery
- **Failure policy:** an item that fails is skipped with a warning and not retried forever. A button gives it another pass later. Offload and other remote work are **best effort**: an unreachable bucket leaves the item local and working. **[S]** Gallery
- **Remote deletes** go to a background queue with a short hold-off (so bulk deletes drain in one pass) and tunable delay and budget filters. No admin screen waits on thousands of bucket requests. **[S]** Gallery
- **Shared-host fallbacks:** use a fast path when available (`du -sb` via `exec()`), and a pure-PHP fallback when it is not. **[S]** Optimized
- **Cache:** objects pushed to R2 carry `Cache-Control: max-age=31536000`. **[S]** Gallery

---

## 11. Destructive and bulk operations

All **[S]** Optimized README unless noted.

- Flow is **dry-run preview, confirm, execute, log.** Dry-run is the default state everywhere.
- Every destructive action requires `manage_options` plus a nonce, and is POST only.
- Require a **backup acknowledgment** before the first bulk deletion in a session.
- Keep a full, exportable **audit log**: who, what, when, how much was reclaimed.
- **Hard exclusions** (active theme and plugin folders, `wp-config.php`, `.htaccess`) plus an admin-editable exclusion list and a filter (`blt_<name>_exclude_paths`). Backup archives are never swept into a generic bulk delete.
- **Flag, don't delete, what you don't own:** orphaned plugin folders, leftover tables from uninstalled plugins, generated image sizes. Report them; never remove them automatically.
- **Warn instead of running silently** where an operation locks or rebuilds (large InnoDB `OPTIMIZE TABLE`). Surface the engine first. Finish with a before/after summary.
- **Remote configuration ownership** (**[S]** Secure CLAUDE.md): create every remote rule with a `blt-<name>-*` ref and a `[BLT <Name>]` description. Deploys are **idempotent** (re-adopt by ref, never duplicate) and **removable per feature** from the UI. Never PUT a whole ruleset entrypoint, only POST, PATCH or DELETE individual rules, so other tools' rules are untouched.
- **Imports and migrations only read from the source.** Copy into the plugin's own storage, record where each item came from, show already-imported items as such so a second run does not duplicate, mark interrupted ones "partly imported", and make legacy-file cleanup a separate explicit step (ZIP first, then remove). **[S]** Gallery README
- **Uninstall:** nothing is deleted unless "Delete all plugin data" is enabled under Settings > General > Advanced. **[S]** Events. Artifacts a site depends on survive. **[S]** Optimized. The shared store is never touched. **[S]** DESIGN 3. Remote objects (R2, S3) are never deleted by uninstall. **[R]**

---

## 12. Cloudflare and Worker conventions

### 12.1 Principles

- **Cloudflare is optional.** Local behavior works with no token configured, and Cloudflare code never loads on front-end requests. **[S]** Secure CLAUDE.md
- **Least-privilege, zone-scoped API tokens.** The README carries the exact token recipe (each permission named). Store the token encrypted. **[S]** Secure README
- Scope a token to the specific zone, and to the account only when a feature needs it (Access). **[S]** Secure README
- A Worker that uses `cf.image` must sit on a real **zone route**, not `*.workers.dev`. **[S]** Optimized README
- Talk to R2 and S3 with **SigV4 and no SDK dependency.** **[S]** Gallery README
- Admin fields for a Worker are `worker_url` and `worker_secret`, always with a **Test Connection** button. **[P]**

### 12.2 Three-plane architecture (BLT Documents) **[S]** Documents CLAUDE.md

```
Front-end shortcode -> WP REST route (nonce + capability check)
                            | HMAC-signed, short-TTL, server-to-server
                            v
                    Cloudflare Worker -> R2 bucket (private, no public binding)
                            |
                            v
              Streamed back through WordPress to the browser
```

- **Control plane:** the WordPress plugin owns the data model, roles, admin UI and capability checks, and signs every request to the Worker.
- **Enforcement and delivery plane:** `worker/`, a TypeScript Worker that independently verifies signature and freshness before touching R2.
- **Storage plane:** a private R2 bucket. Every version is an immutable object keyed `{site-id}/{folder-slug}/{file-slug}/v{n}.{ext}`. Old versions are retained.
- The browser talks only to the WordPress route. The R2 key, Worker URL and signature never leave the server. Downloads are **proxied and streamed**, not redirected, because a signed Worker URL would leak version numbers.

### 12.3 Plugin-to-Worker signing contract **[S]** Documents CLAUDE.md

| Item | Value |
| --- | --- |
| Auth header | `Authorization: Bearer <WORKER_SECRET>` |
| Timestamp | `X-BLT-Timestamp: <unix seconds>` |
| Control | `X-BLT-Control: <base64url JSON>` containing `{op, key, sha256, content_type, site}` |
| Signature | `X-BLT-Signature: <lowercase hex HMAC-SHA256 of "{ts}.{control_b64}">` |
| Freshness | plus or minus 300 s (`MAX_SKEW`), identical on both ends |
| Replay protection | `op` and `key` are inside the signed string, so a captured request cannot be replayed against another object or operation |
| Uploads | The signature covers the file's SHA-256, not its bytes. R2 verifies content against that hash on `put`. Memory-safe. |
| PHP signer | `includes/class-blt-<name>-signer.php` |
| TS verifier | `worker/src/auth.ts`, with known-vector tests in `worker/test/auth.test.ts` |

If the canonical string changes on one side, change the other and update **both** test suites. They exist to keep the two planes byte-identical.

### 12.4 Worker repo hygiene **[S]** Documents

- `worker/` is TypeScript, ships `DEPLOY.md` (create the private bucket, set the shared secret), and is **excluded from the plugin zip** via `.distignore`.
- `cd worker && npm install && npm run typecheck && npm test`.

---

## 13. Hosting assumptions

- Target hosts include GoDaddy, SiteGround and Pressable shared plans: no shell, restricted PHP builds, WP-Cron only, `exec()` possibly disabled. **[S]** Secure CLAUDE.md, Optimized README
- **Zero runtime Composer dependencies.** `composer.json` is dev tooling, excluded from the zip. The release zip is self-contained (vendored libraries ship inside it). **[S]** Secure CLAUDE.md; **[R]** family-wide, see section 17 item 6
- **Bedrock-compatible.** Documents states it and S-FX runs Bedrock. Do not write into the plugin directory at runtime, use `wp_upload_dir()` and WordPress path functions, read configuration from wp-config constants, and honor `DISALLOW_FILE_MODS` (on such sites updates arrive through deploy, not the updater). **[R]**
- WP-Cron may be unreliable: use the loopback plus stall fallback in section 10.
- If the plugin needs pretty permalinks (Events does), say so in Requirements and in the setup card.

---

## 14. Testing, CI and definition of done

### 14.1 Philosophy **[S]** Secure CLAUDE.md

Isolate **pure logic** (crypto envelopes, signing, TOTP math, CIDR matching, rule payloads) in classes with no WordPress dependency and unit-test it against **known vectors** (RFC 6238 Appendix B, golden JSON payloads, HMAC known-vector tests). `tests/bootstrap.php` shims the small WordPress surface used. WordPress-coupled behavior is smoke-tested manually against the checklist in `tasks/todo.md`.

### 14.2 Commands (every plugin)

```
composer install      # dev deps only: phpcs, WPCS, phpunit
composer lint         # php -l on every file
composer phpcs        # WordPress-Extra
composer test         # PHPUnit, no WordPress install required

# Plugins with a Worker
cd worker && npm install && npm run typecheck && npm test

# Plugins with translations (Events: node bin/make-pot.js)
# regenerate languages/<slug>.pot
```

Run `composer lint` and `composer test` before committing, and `composer phpcs` before pushing. **[S]** Secure CLAUDE.md

### 14.3 CI baseline on every push and pull request

**[S]** Events README: PHP syntax checks across 7.4 to 8.3, phpcs, the test suite, and a POT drift check. Merges to `main` publish the release.

**[R]** add to every repo: header/constant version-sync check, and a zip-build verification (vendored update checker present, `worker/` and dev files absent).

### 14.4 Definition of done: new admin screen **[S]** DESIGN 5

- [ ] Wrapped in `<div class="wrap blt-ui ...">`
- [ ] `blt-design-system.css` enqueued on this hook only
- [ ] Composed from the catalog; no `<table class="form-table">`
- [ ] Booleans are `.blt-toggle`, statuses are `.blt-badge`
- [ ] Colours come from tokens, not literals
- [ ] Nonce and capability check on every action; input sanitized, output escaped
- [ ] Every human-readable string wrapped in the plugin's text domain
- [ ] "BLT" all caps in every string a site owner reads
- [ ] Credentials read through the shared-store fallback where a group exists

### 14.5 Definition of done: plugin or release **[R]** consolidated from this file

- [ ] Naming matrix (4.1) followed, `BLT` caps in all prose, Author lines exact
- [ ] Family layer registered, groups justified, nothing shared by default
- [ ] Update checker built with `24`, manual path intact, token precedence wired
- [ ] Secrets encrypted, constants override stored values, no plaintext tier
- [ ] No heavy work on page load; long jobs time-boxed, resumable, cancellable
- [ ] Destructive actions dry-run by default, audit-logged
- [ ] Uninstall behavior documented and tested; shared store untouched
- [ ] `composer lint`, `composer phpcs` and `composer test` clean
- [ ] README, `readme.txt`, `HOOKS.md`, `CLAUDE.md`, `tasks/todo.md` current
- [ ] Release zip verified: vendored update checker included, `worker/` and dev files excluded
- [ ] Exercised on shared-host constraints (no `exec()`, WP-Cron only)
- [ ] Keyboard-only pass on the front end

---

## 15. Documentation standard

**README.md** follows the Events shape **[P]**: what it is and why in one paragraph; Requirements; Install; **What works out of the box** (table of area and default behavior); Blocks and shortcodes (attribute tables); Customising (Settings, Templates, CSS tokens, Hooks, REST API, Roles and capabilities); Development (commands and CI); Data (tables and uninstall behavior); License.

Also include, where they apply:

- **Architecture notes and decisions** that record the choice made on an open question, not just the feature (Optimized resolves "bundle Action Scheduler or rely on WooCommerce" this way). **[P]**
- A **Roadmap** (Optimized) and a **Non-goals** section. Documents' v1 non-goals end with "Keep the plugin narrow." **[S]**
- A "do I need this key?" table for any optional external credential (Tube). **[R]**
- A token recipe for any Cloudflare or Google credential. **[S]** Secure

**Other files**

- `readme.txt` in WordPress format. **[P]** (Optimized, Documents, Secure)
- `HOOKS.md` for every public action and filter. `CHANGELOG.md` for human-readable release notes. **[R]**
- `worker/DEPLOY.md` for any plugin with a Worker. **[S]** Documents
- **`CLAUDE.md`** states what the plugin does, its architecture, conventions, constants, repository layout, packaging, testing and non-goals. It points at `DESIGN.md` and `CONVENTIONS.md` and does **not** restate them. **[S]** Documents
- **`AGENTS.md`** is the operational checklist. **[S]** Documents
- **`tasks/todo.md`** holds phase checklists with acceptance criteria and is kept current. **`tasks/lessons.md`** is append-only. **[S]** Secure CLAUDE.md

---

## 16. Starting a new BLT plugin

### 16.1 Workflow **[P]**

1. **Write the spec first:** problem, users, v1 scope, **non-goals**, integrations, data model, roles.
2. **Answer the pre-flight questions** below.
3. Create `S-FX-com/BLT-<Name>`. Scaffold per section 5. Vendor `DESIGN.md`, `CONVENTIONS.md` and `includes/blt-family/` **byte-identical** from the canonical source, plus the update checker (v5.x).
4. Write `CLAUDE.md` and `tasks/todo.md` (phases with acceptance criteria).
5. Build phase by phase. Keep `todo.md` current; append gotchas to `lessons.md`.
6. Release through CI. Install the zip on a clean test site, then ship a second release to prove the update path.
7. Add the plugin to the family list in `DESIGN.md` and port that change to every repo in the same pass.

### 16.2 Pre-flight questions

- Does the core need any external service? If yes, what does the plugin do when it is down?
- Which credentials does it hold, and does a shared group genuinely apply (the same credential used by 2+ plugins)?
- Which capabilities and roles does it need?
- What does uninstall do? Is multisite supported or deferred?
- Which jobs are long-running, and how do they resume and cancel?
- What is the public surface (shortcode, block, Bricks element, REST) and which attributes?
- What are the v1 non-goals?

### 16.3 Rules for an AI coding session in a BLT repo

1. Read `DESIGN.md`, `CONVENTIONS.md` and the repo's `CLAUDE.md` before writing code.
2. Never rename a machine identifier, and never "fix" lowercase `blt` in one.
3. Any change to `DESIGN.md`, `CONVENTIONS.md` or `includes/blt-family/`: stop, list the other repos that need the identical change, and make it in the same pass.
4. Do not add a shared group, a new Worker or a runtime dependency without asking.
5. Grep for class-name collisions before reusing a shared admin component on a page with custom markup.
6. Run `composer lint` and `composer test` before committing; `composer phpcs` before pushing.
7. Never hand-edit only one of the two version strings. Follow the repo's release policy.
8. Update `tasks/todo.md` as phases complete; append to `tasks/lessons.md`, never rewrite it.
9. When this file disagrees with what a repo already does, **follow the repo and flag the conflict** instead of silently "fixing" either side.

---

## 17. Drift found in review: decisions to confirm

Findings from the six repos as of October 13, 2026. Each **[R]** rule above that depends on one of these is listed. Resolve them, then retag the affected rules **[S]** and delete the row.

| # | Priority | Finding and evidence | Recommended resolution |
| --- | --- | --- | --- |
| 1 | **High** | **Release and version policy conflict.** Tube's CLAUDE.md requires a manual bump on every commit, in both the header and `BLTT_VERSION`, and its workflow skips the release if the tag exists, so a forgotten bump means no release. Secure's README and Documents' CLAUDE.md say bump and release are CI-only (`#minor` / `#major` in the merge message). Events publishes on merge. | CI-only everywhere. It removes the forgot-to-bump failure. Port Secure's `release.yml` to Tube and the others. |
| 2 | **High** | **Documents' crypto has a third fallback tier.** Its CLAUDE.md lists "libsodium, then OpenSSL AES-GCM, then tagged base64 fallback". Base64 is encoding, not encryption. Secure's rule is "never store a secret in plaintext; never fall back to an unauthenticated cipher". | Remove the third tier. If neither sodium nor OpenSSL is available, refuse to store the secret and show an admin notice. (PHP 7.2+ bundles libsodium and WordPress ships a polyfill, so it is rarely reachable, but the rule should still hold.) Check the other plugins' `*_Crypto` copies for the same tier. |
| 3 | Med | **AI and ops files are inconsistent.** `CLAUDE.md` is absent at the root of Events, Gallery and Optimized. `AGENTS.md` exists only in Documents. `tasks/` exists only in Documents and Secure. | Add `CLAUDE.md`, `AGENTS.md` and `tasks/` to every repo, each pointing at `DESIGN.md` and `CONVENTIONS.md`. |
| 4 | Low | **Namespaces.** Documents says "No namespace ... match the family exactly" and Secure uses prefixed classes. Gallery uses `BltGallery\` and Optimized's image module uses `BltImageOptimizer\`. | New plugins: prefixed classes, no namespace. Do not rename existing ones (`DESIGN.md` says leave them). |
| 5 | Med | **License.** Gallery is GPL-3.0-or-later. Events, Documents, Secure and Tube say GPL-2.0-or-later. Optimized's README is silent. Only Gallery has a license file at the root. | Confirm Gallery is intentional. Otherwise GPL-2.0-or-later, and add a `LICENSE` file to every repo. |
| 6 | Med | **Runtime Composer.** Gallery's README tells installers to run `composer install --no-dev --optimize-autoloader`. Secure's hard constraint is zero runtime Composer dependencies because target hosts have no shell. | Confirm Gallery's release zip bundles `vendor/` so site owners never run Composer. Make the rule family-wide. |
| 7 | Low | **`DESIGN.md` family list is stale.** It still lists BLT Image Optimizer, which Optimized's README says was merged in at v1.1, and it omits Bookings. Documents' CLAUDE.md and Secure's README also still reference the Image Optimizer. | Update the one canonical copy and port it to every repo in the same pass. |
| 8 | Low | **Stale README references.** Secure's README describes a private repo, a per-site token and the slug `sfxdotcom/BLT-Secure`, but the repo shows as public under `S-FX-com`. Gallery links `sfxdotcom/CloudflareSkills`. | Confirm which GitHub org is canonical. Refresh the READMEs, and verify that no plugin's updater `$repo_url` still points at the old slug. |
| 9 | Low | **Update-checker location.** `plugin-update-checker/` at the Optimized root, `lib/` in Gallery, `includes/lib/` in Documents and Secure. | `includes/lib/plugin-update-checker/` for new plugins. Move the others opportunistically. |
| 10 | Low | **Boolean shortcode attributes.** Events and Tube use `yes` / `no`; Gallery uses `1` / `0`. | Accept all forms, document `yes` / `no` (rule in 6.5). |
| 11 | Med | **Tooling and lifecycle gaps.** No `uninstall.php`, tests, phpcs, phpunit or `languages/` at the root of Gallery or Tube. Optimized has no phpcs, phpunit, `composer.json` or `languages/` (its lint is a `php -l` one-liner). Gallery matters most: it offloads to R2 and S3. | Bring every repo to the section 14 baseline. Decide Gallery's uninstall behavior for remote objects (default: leave them). |
| 12 | Info | **Legacy prefixes.** `bltgallery_`, `bltt_`, `BLTT_VERSION`, file prefix `class-bltt-*`. Left over from the ZymGallery and ZymTube rebrands. | Freeze. Never use for new plugins. |
| 13 | Low | **Events table names are generic** (`blt_registrations`, `blt_attendees`, `blt_fieldsets`) and could collide with BLT Bookings. | Do not rename Events' tables. New plugins use `blt_<name>_<thing>`. |
| 14 | Low | **Floors vary.** PHP 7.4 (Events, Secure, Tube), 8.0 (Optimized), 8.1 (Gallery). WordPress 6.0 to 6.5. Documents' floor is not stated in its README. Optimized claims to survive cheap shared hosting yet requires PHP 8.0. | Confirm the intended baseline (default in section 2). Record a reason wherever a plugin raises it. |
| 15 | Low | **Spelling.** Events and Gallery READMEs use British spelling; Optimized uses American. | `en_US` for source strings and new docs. |
| 16 | Low | **Admin menu position.** Documents uses 81; the `DESIGN.md` snippet shows 58. | Pick one so BLT menus cluster. Apply to new plugins only. |
| 17 | Verify | **Tube's admin styling.** Its README tree shows only `assets/css/admin.css`, but its repo carries `DESIGN.md`, so the README may be stale. | Confirm Tube uses `blt-design-system.css` and `.blt-ui`. Refresh the README tree. |
| 18 | Verify | **`DESIGN-SYSTEM.md` in Events only.** Events' README points to it for the component library, but the canonical `DESIGN.md` now carries the catalog. Not reviewed. | Confirm whether it is redundant, then fold or remove it to avoid two sources of truth. |

**How this review was done, and its limits.** Root-level file listings, READMEs, Events' and Tube's `DESIGN.md`, and the `CLAUDE.md` files of Documents, Secure and Tube. A file inside a subfolder would not appear in the matrix below. No source code was read. `DESIGN.md` was compared by size and line count only (Events and Tube both show 480 lines, 18.6 KB), not diffed. To verify byte-identity across repos:

```
for r in BLT-*/; do sha256sum "$r/DESIGN.md"; done
```

---

## Appendix A. Compliance matrix (repo root, October 13, 2026)

`yes` = present at the root. `-` = not seen at the root. `n/s` = not stated in what was read.

| Item | Optimized | Events | Gallery | Documents | Secure | Tube |
| --- | --- | --- | --- | --- | --- | --- |
| `DESIGN.md` | yes | yes | yes | yes | yes | yes |
| `CONVENTIONS.md` | - | - | - | - | - | - |
| `CLAUDE.md` | - | - | - | yes | yes | yes |
| `AGENTS.md` | - | - | - | yes | - | - |
| `tasks/` | - | - | - | yes | yes | - |
| `HOOKS.md` | - | yes | - | - | - | - |
| `CHANGELOG.md` | - | yes | - | - | - | - |
| `readme.txt` | yes | - | - | yes | yes | - |
| `uninstall.php` | yes | yes | - | yes | yes | - |
| `languages/` | - | yes | - | yes | yes | - |
| `tests/` | - | yes | - | yes | yes | - |
| `phpcs.xml.dist` | - | yes | - | yes | yes | - |
| `phpunit.xml.dist` | - | yes | - | yes | yes | - |
| `composer.json` | - | yes | yes | yes | yes | yes |
| `LICENSE` file | - | - | yes | - | - | - |
| `.distignore` | - | - | - | yes | yes | - |
| `blt-family-smoke.php` | yes | yes | yes | yes | yes | yes |
| `.github` workflows | yes | yes | yes | yes | yes | yes |
| `worker/` | yes (images) | - | - | yes | - | - |
| Declared license | n/s | GPL-2.0+ | GPL-3.0+ | GPL-2.0+ | GPL-2.0+ | GPL-2.0+ |
| PHP / WordPress floor | 8.0 / 6.0 | 7.4 / 6.2 | 8.1 / 6.3 | n/s | 7.4 / 6.0 | 7.4 / 6.5 |
| Class style | namespaced image module | n/s | namespaced `BltGallery\` | prefixed, no namespace | prefixed `Blt_Secure_*` | `bltt`-prefixed |
| Release and version | n/s | CI publish on merge | n/s | CI bump and release | CI bump and release | manual bump, CI tag |

---

## Appendix B. Sources reviewed

- https://github.com/S-FX-com/BLT-Optimized (README)
- https://github.com/S-FX-com/BLT-Events (README, `DESIGN.md`)
- https://github.com/S-FX-com/BLT-Gallery (README)
- https://github.com/S-FX-com/BLT-Documents (README, `CLAUDE.md`)
- https://github.com/S-FX-com/BLT-Secure (README, `CLAUDE.md`)
- https://github.com/S-FX-com/BLT-Tube (README, `CLAUDE.md`, `DESIGN.md` header)
- S-FX global `CLAUDE.md` conventions: plugin-update-checker default-wired into every custom WordPress plugin on a 24-hour interval; Bricks for WordPress front ends; Bedrock, Cloudflare and Microsoft 365 as the foundational stack.
