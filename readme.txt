=== BLT Toolkit ===
Contributors: sfxdotcom
Author: S-FX.com
Author URI: https://www.s-fx.com
Tags: users, roles, capabilities, user switching, username
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Switch to another user and back, change usernames, and edit roles and capabilities in a Simple or Advanced view, with one security model and log.

== Description ==

BLT Toolkit gives site administrators three user-management tools in one place, under one security model and one activity log. It replaces User Switching, Username Changer and User Role Editor on S-FX-managed sites.

**Switch to another user and back**

* "Switch To" on the Users list and on each profile; "Switch back" in the toolbar, in notices, on the login screen and as a fixed link on the site.
* Nested switching, Switch Off, remember-me inheritance, a warning when someone else is already switched into that account, and redirects that take you back to what you were looking at.
* Switching needs its own capability (`blt_switch_users`), not just the ability to edit users, and nobody can switch into someone more powerful than themselves.
* An optional Support Agent role that can switch only into users with no more access than itself.

**Change a username**

* From the user's profile, with live validation and one set of rules everywhere: length, characters, a disallowed list, names already taken, and names that equal another user's email address.
* The change takes effect at once, including on sites with a persistent object cache. Renaming yourself keeps you signed in. Old author URLs redirect to the new ones.
* Optional self-service per role, with a cooldown and an optional password check, and a front-end form shortcode.
* Bulk rename from a CSV file, with a dry run that orders chained renames and refuses swaps, run as a background job you can close the tab on.

**Edit roles and capabilities**

* **Simple** view shows each content type (Posts, Pages and every custom post type) as one plain-English access level such as View, Author (own only) or Edit all, plus site-wide toggles grouped by area.
* **Advanced** view is the full capability matrix, with groups, filters, readable names, tier badges and role comparison.
* Give a custom post type that shares Posts' permissions its own permissions, without changing anyone's access on day one.
* Add, rename, copy, delete and merge roles; multiple roles per user; additional default roles; per-user capabilities; users without a role.
* Every save is snapshotted and can be restored from a diff. Roles can be exported from one site and imported into another.

**One security model**

* Non-administrators can never edit, delete, rename, re-role or switch into an administrator, and can never grant a capability they do not hold. The rules hold on every path: admin screens, AJAX, REST and WP-CLI.
* The administrator role cannot be locked out, and a `wp-config.php` constant restores it if it ever is.

**One activity log**

* Every switch, rename and role change is logged with who did it, including "acting as" when the change was made from a switched session.
* Filter it by module, action, who, acting as and date, see each entry's details and role diff, and export the filtered list as CSV. Entries are kept for 365 days by default.

**Tools**

* Restore any role snapshot after seeing what would change; export roles to a file and import them on another site; reset the built-in roles or every role to WordPress's defaults; give Administrator the registered capabilities it lacks.
* Import settings and history from Username Changer and User Role Editor, previewed first and never twice.

**Site Health**

* Five tests on Tools > Site Health: BLT Toolkit's database tables (with a button that creates them again), plugins BLT Toolkit replaces that are still active, content types whose separate permissions are not in effect, recovery mode, and activity log entries that could not be saved (with a button that clears the warning).

== External services ==

This plugin does not contact any external service. Updates are delivered from the plugin's GitHub releases (github.com/S-FX-com/BLT-Toolkit) through the WordPress update system, checked once a day. While the repository is private, a read-only GitHub token is needed to see updates; it is sent only to GitHub. On a site where WordPress may not change files (DISALLOW_FILE_MODS), new versions arrive with the deployment.

== Privacy ==

BLT Toolkit stores an activity log of switches, username changes and role changes on your own site: who made each change, whom they were acting as, what changed and, unless turned off under BLT Toolkit > Settings > Activity log, the IP address it came from. Entries are deleted after 365 days by default. While someone is switched to another account, the browser holds BLT Toolkit's switch cookies, which contain the original user's username; they are browser-session cookies unless that person signed in with "Remember Me", and last at most 48 hours.

* Tools > Export Personal Data includes every log entry a person made, that was made while someone had switched to their account, that is about their account, or that names them.
* Tools > Erase Personal Data keeps those entries as a security record and takes the person out of them: their usernames and name are removed from every entry, and the stored username and IP address are blanked on the entries they made.
* Settings > Privacy > Policy Guide has suggested text for your privacy policy.

== Installation ==

1. Download `blt-toolkit-X.Y.Z.zip` from the plugin's latest GitHub release (the release asset, not the source code archive).
2. In WordPress go to Plugins > Add New Plugin > Upload Plugin, upload the zip and activate it. It cannot be activated on multisite.
3. Open BLT Toolkit in the admin menu.
4. While the repository is private, add a read-only GitHub token so the site can see updates: `define( 'BLT_TOOLKIT_GITHUB_TOKEN', '...' );` in `wp-config.php`, or under BLT Toolkit > Settings > Advanced, where it is stored encrypted.

If User Switching, Username Changer or User Role Editor is still active, the matching BLT Toolkit module stays off until you deactivate it. Switch back before deactivating User Switching. Settings and history from Username Changer and User Role Editor can be imported under BLT Toolkit > Tools > Import from legacy plugins. Import from User Role Editor before you delete it: deleting it (not deactivating it) removes its settings and its backup of your roles.

== Frequently Asked Questions ==

= Does it work on multisite? =

Not in this version. Activation on multisite is refused with a notice.

= Can I keep User Switching, Username Changer or User Role Editor active? =

Yes, while you move over. A BLT Toolkit module whose job an active legacy plugin already does stays off, and the Overview screen names the plugin to deactivate. The other modules work normally.

= Will I lose my roles if I uninstall User Role Editor or BLT Toolkit? =

No. Roles live in WordPress's own storage, which both plugins read and write. Deleting User Role Editor removes its own settings and capabilities, not your roles, and BLT Toolkit's uninstall never reverts role edits or deletes roles.

= I removed a capability from Administrator and now I am locked out. =

Add `define( 'BLT_TOOLKIT_RECOVERY', true );` to `wp-config.php` and load any admin screen as a user with the Administrator role. BLT Toolkit takes a snapshot, restores the capabilities the Administrator role needs, logs it, and shows a notice until you remove the constant.

= Who can switch to other users? =

Users with the `blt_switch_users` capability (Administrators by default). Unlike User Switching, the ability to edit users is not enough. A non-administrator can never switch into an administrator or into anyone more powerful than themselves.

= What happens when I delete the plugin? =

Nothing is deleted unless you turn on "Delete all plugin data on uninstall" under BLT Toolkit > Settings > Advanced. Even then, your roles and role edits are kept, and only BLT Toolkit's own capabilities are taken off roles and users.

= Does my page cache need a change? =

Only for "Switch Off": the signed-out page then offers "Switch back to {name}", which a page cache would hide. Bypass the cache for requests with a cookie starting with `wordpress_blt_sw_olduser_`; many hosts already bypass any `wordpress_*` cookie. The README has the details, including Cloudflare APO.

== Credits ==

BLT Toolkit reimplements, from a written specification, the features of three GPL-2.0-or-later plugins whose source was read while it was designed. The switch module reproduces User Switching's cookie and session mechanism, request flow, redirects and duplicate-switch check, and its WooCommerce placements and session forgetting follow User Switching 1.12.2 by John Blackbourn; each switch file names the User Switching functions it follows in its header. Any block of code ported from these plugins keeps its original copyright line in its file header.

* User Switching by John Blackbourn (https://johnblackbourn.com).
* User Role Editor by Vladimir Garagulya (https://www.role-editor.com).
* Username Changer by DigitalME (WordPress.org contributors digitalmeactivecampaign and ympno; published as TRS Plugins).

Thank you to their authors.

== Changelog ==

= Unreleased =
The first release, to be published as 1.0.0. CHANGELOG.md in the repository has the full notes.

* Protection: non-administrators can never edit, delete, rename, re-role or switch into an administrator or anyone more powerful than themselves, or grant a capability they lack, on every path (admin screens, AJAX, admin-post.php, REST, WP-CLI). Administrators are hidden from everyone else. The Administrator role cannot be locked out, and BLT_TOOLKIT_RECOVERY restores it if it ever is.
* Activity log with "acting as" attribution, CSV export and a daily prune; role snapshots before every role change, kept 50 plus the baseline.
* Switch: switch to another user and back, nested, Switch Off, the toolbar search, WooCommerce placements, User Switching compatibility hooks, an optional Support Agent role.
* Usernames: rename from the profile and the Users list with one set of rules, old author URLs redirected, optional email, self-service per role with a cooldown and an optional password check, bulk rename from CSV, and the [blt_toolkit_username_form] shortcode.
* Roles: Simple and Advanced views, separate permissions for custom post types with a read-only View level, the role lifecycle (add, rename, move users, delete, merge), additional roles, additional default roles, per-user capabilities and users without a role.
* Tools: snapshot restore, role export and import, both resets, Sync administrator, and imports from Username Changer and User Role Editor.
* Privacy exporter and eraser for the activity log, suggested policy text, and five Site Health tests.
