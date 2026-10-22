<?php
/**
 * Coexistence with the three plugins BLT Toolkit replaces.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects User Switching, Username Changer and User Role Editor (GEN-1).
 *
 * When one of them is active, the BLT module that does the same job must not
 * boot: two plugins answering the same switch link, or rewriting the same
 * username, would fight each other. Detection uses the symbols each plugin
 * declares at load, so it is reliable from `plugins_loaded` onward and costs
 * no query.
 *
 * Only detection lives here for now. The read-only importers (SPEC 15) arrive
 * in Phase 6.
 */
class BLT_Toolkit_Legacy {

	/**
	 * Detection results for this request, keyed by module.
	 *
	 * @var array<string,bool>
	 */
	private static $detected = array();

	/**
	 * The legacy plugin behind each module.
	 *
	 * `name` is the plugin's own product name, shown untranslated. `basename`
	 * is where the plugin normally lives, used only to point the owner at the
	 * right row on the Plugins screen; detection never relies on it, because
	 * a plugin can be installed under any folder name.
	 *
	 * @return array<string,array{name:string,basename:string}>
	 */
	public static function plugins() {
		return array(
			'switch'    => array(
				'name'     => 'User Switching',
				'basename' => 'user-switching/user-switching.php',
			),
			'usernames' => array(
				'name'     => 'Username Changer',
				'basename' => 'username-changer/class-username-changer.php',
			),
			'roles'     => array(
				'name'     => 'User Role Editor',
				'basename' => 'user-role-editor/user-role-editor.php',
			),
		);
	}

	/**
	 * Whether the legacy plugin matching a module is active (GEN-1).
	 *
	 * @param string $module Module key.
	 * @return bool
	 */
	public static function is_active( $module ) {
		$module = (string) $module;

		if ( ! array_key_exists( $module, self::$detected ) ) {
			self::$detected[ $module ] = self::detect( $module );
		}

		return self::$detected[ $module ];
	}

	/**
	 * The display name of the legacy plugin that blocks a module, or '' when
	 * nothing does.
	 *
	 * @param string $module Module key.
	 * @return string
	 */
	public static function conflict( $module ) {
		$plugins = self::plugins();
		$module  = (string) $module;

		if ( ! isset( $plugins[ $module ] ) || ! self::is_active( $module ) ) {
			return '';
		}

		return $plugins[ $module ]['name'];
	}

	/**
	 * Every module currently blocked, mapped to the legacy plugin's name.
	 *
	 * @return array<string,string>
	 */
	public static function conflicts() {
		$out = array();

		foreach ( array_keys( self::plugins() ) as $module ) {
			$name = self::conflict( $module );

			if ( '' !== $name ) {
				$out[ $module ] = $name;
			}
		}

		return $out;
	}

	/**
	 * Forget the cached detection (tests, or after a plugin was loaded late).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$detected = array();
	}

	/**
	 * Run the GEN-1 check for one module.
	 *
	 * @param string $module Module key.
	 * @return bool
	 */
	private static function detect( $module ) {
		switch ( $module ) {
			case 'switch':
				return class_exists( 'user_switching' );

			case 'usernames':
				return function_exists( 'username_changer' );

			case 'roles':
				return class_exists( 'URE_Lib' );
		}

		return false;
	}
}
