<?php
/**
 * Plugin Name:       BLT Toolkit
 * Plugin URI:        https://github.com/S-FX-com/BLT-Toolkit
 * Description:       Switch to another user and back, change usernames, and edit roles and capabilities in a Simple or Advanced view, under one security model and one activity log.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Network:           false
 * Author:            S-FX.com
 * Author URI:        https://www.s-fx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-toolkit
 * Domain Path:       /languages
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLT_TOOLKIT_VERSION', '0.1.0' ); // Must equal the header. CI enforces this.
define( 'BLT_TOOLKIT_FILE', __FILE__ );
define( 'BLT_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_TOOLKIT_BASENAME', plugin_basename( __FILE__ ) );
define( 'BLT_TOOLKIT_REST_NS', 'blt-toolkit/v1' );

// ---------- BLT family layer ----------
// Registered during load, not inside a hook, so the registry is complete before
// the library elects a copy on plugins_loaded and BLT_Family_Updates is
// available to the update-checker wiring below.
require_once BLT_TOOLKIT_DIR . 'includes/blt-family/bootstrap.php';

blt_family_register(
	BLT_TOOLKIT_FILE,
	array(
		'name'    => 'BLT Toolkit',
		'slug'    => 'blt-toolkit',
		'version' => BLT_TOOLKIT_VERSION,
		'menu'    => 'blt-toolkit',
		// The optional update token is the one credential held, and every BLT
		// plugin's update checker consumes the same one (SPEC 2).
		'groups'  => array( 'github' ),
	)
);

// ---------- Update checker ----------
// Updates come from GitHub Releases: the CI-built zip with a stable
// blt-toolkit/ top-level folder, never a source zipball. Checks run from
// wp-admin, WP-Cron or WP-CLI only, so a front-end request never loads it.
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once BLT_TOOLKIT_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

	// The fourth argument MUST be 24: a checker built with 0 registers no
	// scheduler hooks and cannot be revived.
	$blt_toolkit_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/S-FX-com/BLT-Toolkit/',
		BLT_TOOLKIT_FILE,
		'blt-toolkit',
		24
	);
	$blt_toolkit_update_checker->setBranch( 'main' );

	// Only accept the CI-built zip asset; ignore checksums and source archives.
	// REQUIRE makes a release without that asset count as no release (the
	// default would fall back to its source zipball), and keeping only the
	// latest-release strategy stops the checker from then trying the newest
	// tag's or the branch's source archive instead.
	$blt_toolkit_update_checker->getVcsApi()->enableReleaseAssets(
		'/^blt-toolkit(-[\d.]+)?\.zip$/i',
		\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);
	$blt_toolkit_update_checker->addFilter(
		'vcs_update_detection_strategies',
		static function ( $strategies ) {
			return array_intersect_key( (array) $strategies, array( 'latest_release' => true ) );
		}
	);

	// One automatic check a day, anchored to midnight site time. Manual checks
	// always run immediately.
	BLT_Family_Updates::apply(
		$blt_toolkit_update_checker,
		array(
			'basename'  => BLT_TOOLKIT_BASENAME,
			'icons_url' => BLT_TOOLKIT_URL . 'assets/img/',
		)
	);

	// Optional token: the BLT_TOOLKIT_GITHUB_TOKEN constant, then the plugin's
	// encrypted option, then the shared `github` group. The shared store only
	// exists once the family copy is elected (plugins_loaded priority 0), and
	// reading it translates strings, which before `init` would authenticate the
	// current user early (and before SSO plugins hook in). So the token is
	// resolved on `init`: still ahead of everything that asks GitHub for
	// updates (admin_init, cron events, WP-CLI commands).
	add_action(
		'init',
		static function () use ( $blt_toolkit_update_checker ) {
			$token = BLT_Toolkit_Settings::github_token();

			if ( '' !== $token ) {
				$blt_toolkit_update_checker->setAuthentication( $token );
			}
		},
		1
	);
}

// ---------- Autoloader ----------
// Resolves BLT_Toolkit_<Thing> to includes/class-blt-toolkit-<thing>.php,
// probing admin/ as a fallback. Adding a class means creating its file.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'BLT_Toolkit';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$rest = substr( $class_name, strlen( $prefix ) );

		if ( '' !== $rest && '_' !== $rest[0] ) {
			return;
		}

		$file = 'class-blt-toolkit' . strtolower( str_replace( '_', '-', $rest ) ) . '.php';

		foreach ( array( BLT_TOOLKIT_DIR . 'includes/' . $file, BLT_TOOLKIT_DIR . 'admin/' . $file ) as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// ---------- Activation / deactivation ----------
register_activation_hook( __FILE__, array( 'BLT_Toolkit_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BLT_Toolkit_Plugin', 'deactivate' ) );

// ---------- Separate permissions for post types (RS-31) ----------
// The one exception to "the main file owns exactly four things" (SPEC 4.2,
// CLAUDE.md): some plugins register post types before plugins_loaded, so a
// filter added any later would miss them. The callback returns its input at
// once when no type is isolated and reads only the autoloaded settings.
add_filter( 'register_post_type_args', array( 'BLT_Toolkit_Isolation', 'filter_args' ), 10, 2 );

// ---------- i18n ----------
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'blt-toolkit', false, dirname( BLT_TOOLKIT_BASENAME ) . '/languages' );
	}
);

// ---------- Boot ----------
add_action( 'plugins_loaded', array( 'BLT_Toolkit_Plugin', 'boot' ), 1 );
