=== BLT Toolkit ===
Contributors: sfxdotcom
Author: S-FX.com
Author URI: https://www.s-fx.com
Tags: users, roles, capabilities, user switching, username
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Switch to another user and back, change usernames, and edit roles and capabilities in a Simple or Advanced view, with one security model and log.

== Description ==

**In development.** BLT Toolkit is being built phase by phase from its specification. Phases 0 to 5 are built (protection, the activity log, switching, username changes, the roles engine with the Advanced view, and Simple view with separate permissions for custom post types); the Tools screen is next. Features are described here as specified.

BLT Toolkit gives site administrators three user-management tools in one place, under one security model and one activity log. It replaces User Switching, Username Changer and User Role Editor on S-FX-managed sites.

**Switch to another user and back**

* "Switch To" on the Users list and on each profile; "Switch back" in the toolbar, in notices, on the login screen and as a fixed link on the site.
* Nested switching, Switch Off, remember-me inheritance, a warning when someone else is already switched into that account, and redirects that take you back to what you were looking at.
* Switching needs its own capability (`blt_switch_users`), not just the ability to edit users, and nobody can switch into someone more powerful than themselves.
* An optional Support Agent role that can switch only into users less powerful than itself.

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

== External services ==

This plugin does not contact any external service. Updates are delivered from the plugin's GitHub releases through the WordPress update system, checked at most once a day.

== Installation ==

1. Download `blt-toolkit-X.Y.Z.zip` from the plugin's latest GitHub release (the release asset, not the source code archive).
2. In WordPress go to Plugins > Add New Plugin > Upload Plugin, upload the zip and activate it. It cannot be activated on multisite.
3. Open BLT Toolkit in the admin menu.

If User Switching, Username Changer or User Role Editor is still active, the matching BLT Toolkit module stays off until you deactivate it. Switch back before deactivating User Switching. Settings from Username Changer and User Role Editor can be imported under BLT Toolkit > Tools. Import from User Role Editor before you delete it: deleting it (not deactivating it) removes its settings and its backup of your roles.

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

Nothing is deleted unless you turn on "Delete all plugin data on uninstall" under Settings > Advanced. Even then, your roles and role edits are kept.

== Credits ==

BLT Toolkit reimplements, from a written specification, the features of three GPL-2.0-or-later plugins whose source was read while it was designed. The switch module reproduces User Switching's cookie and session mechanism, request flow, redirects and duplicate-switch check, and its WooCommerce placements and session forgetting follow User Switching 1.12.2 by John Blackbourn; each switch file names the User Switching functions it follows in its header. Any block of code ported from these plugins keeps its original copyright line in its file header.

* User Switching by John Blackbourn (https://johnblackbourn.com).
* User Role Editor by Vladimir Garagulya (https://www.role-editor.com).
* Username Changer by DigitalME (WordPress.org contributors digitalmeactivecampaign and ympno; published as TRS Plugins).

Thank you to their authors.

== Changelog ==

= 0.1.0 =
* In development. Phases 0 to 5: scaffold; protection, activity log and role snapshots; switching to another user and back; username changes, including bulk rename from CSV; the roles engine, the Advanced view, the role lifecycle, additional roles and per-user capabilities; Simple view, separate permissions for custom post types and read-only (View) enforcement.
