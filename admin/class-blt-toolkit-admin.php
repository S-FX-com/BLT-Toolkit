<?php
/**
 * Admin controller: menu, page-gated assets, the admin-post.php router and
 * the notices that come back from it.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the wp-admin experience (SPEC 13).
 *
 * - One top-level BLT Toolkit menu whose submenus each carry their own
 *   capability (SPEC 13.1), so a delegated role manager sees the menu with
 *   only the screens they can use. WordPress shows the top level to anyone
 *   who can reach at least one submenu and points it at the first of those.
 * - Users > Roles as a second way into the Roles screen (D4). It is a plain
 *   link, not a second registration of the page, so the BLT Toolkit menu stays
 *   the page's parent and keeps its highlighting.
 * - The design system and this plugin's admin stylesheet on these screens
 *   only (DESIGN 2).
 * - Every write is a POST to admin-post.php that checks the method, a
 *   capability and a nonce, then redirects back with a notice code (post /
 *   redirect / get, SEC-7). Rendering a screen never writes (RO-5).
 *
 * Screens are separate classes (BLT_Toolkit_Admin_<Screen>), built only when
 * their page renders or one of their forms is posted.
 */
class BLT_Toolkit_Admin {

	/**
	 * Top-level menu slug, also the Overview screen's slug (SPEC 3).
	 */
	const MENU_SLUG = 'blt-toolkit';

	/**
	 * Menu position. BLT Secure uses 81 and the shared BLT screen in the
	 * family layer uses 80, so the family's menus sit together below
	 * Settings. WordPress nudges an occupied position by a fraction, so two
	 * plugins on 81 never replace each other.
	 */
	const MENU_POSITION = 81;

	/**
	 * Name of the nonce field every form posts. The nonce action is the
	 * admin-post.php action itself.
	 */
	const NONCE_FIELD = 'blt_toolkit_nonce';

	/**
	 * Query argument that carries a notice code back after a redirect.
	 */
	const NOTICE_ARG = 'blt_notice';

	/**
	 * Query argument that carries the nonce vouching for the values of a Test
	 * connection result (see result_args()).
	 */
	const RESULT_SIG_ARG = 'blt_sig';

	/**
	 * Singleton instance.
	 *
	 * @var BLT_Toolkit_Admin|null
	 */
	private static $instance = null;

	/**
	 * Hook suffix => screen key, filled as the menu registers, for asset
	 * gating.
	 *
	 * @var array<string,string>
	 */
	private $page_hooks = array();

	/**
	 * Screen objects built so far this request, keyed by screen key.
	 *
	 * @var array<string,object>
	 */
	private $screens = array();

	/**
	 * The shared instance.
	 *
	 * @return BLT_Toolkit_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register admin hooks. Called from BLT_Toolkit_Plugin::boot() on admin
	 * requests, including admin-post.php.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_filter( 'plugin_action_links_' . BLT_TOOLKIT_BASENAME, array( $this, 'plugin_action_links' ) );

		foreach ( array_keys( self::post_actions() ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'route_post' ) );
		}
	}

	// ---------- Registry ----------

	/**
	 * The screens, in menu order: slug, capability (SPEC 13.1) and class.
	 *
	 * @return array<string,array{slug:string,cap:string,class:string}>
	 */
	public static function screens() {
		return array(
			'overview'  => array(
				'slug'  => self::MENU_SLUG,
				'cap'   => 'manage_options',
				'class' => 'BLT_Toolkit_Admin_Overview',
			),
			'roles'     => array(
				'slug'  => 'blt-toolkit-roles',
				'cap'   => 'blt_edit_roles',
				'class' => 'BLT_Toolkit_Admin_Roles',
			),
			'usernames' => array(
				'slug'  => 'blt-toolkit-usernames',
				'cap'   => 'blt_change_usernames',
				'class' => 'BLT_Toolkit_Admin_Usernames',
			),
			'activity'  => array(
				'slug'  => 'blt-toolkit-activity',
				'cap'   => 'blt_view_toolkit_log',
				'class' => 'BLT_Toolkit_Admin_Activity',
			),
			'tools'     => array(
				'slug'  => 'blt-toolkit-tools',
				'cap'   => 'blt_reset_roles',
				'class' => 'BLT_Toolkit_Admin_Tools',
			),
			'settings'  => array(
				'slug'  => 'blt-toolkit-settings',
				'cap'   => 'manage_options',
				'class' => 'BLT_Toolkit_Admin_Settings',
			),
		);
	}

	/**
	 * A screen's menu label. Translated, so only called from `admin_menu`
	 * onward.
	 *
	 * @param string $key Screen key.
	 * @return string
	 */
	public static function screen_title( $key ) {
		switch ( $key ) {
			case 'overview':
				return __( 'Overview', 'blt-toolkit' );
			case 'roles':
				return __( 'Roles', 'blt-toolkit' );
			case 'usernames':
				return __( 'Usernames', 'blt-toolkit' );
			case 'activity':
				return __( 'Activity', 'blt-toolkit' );
			case 'tools':
				return __( 'Tools', 'blt-toolkit' );
			case 'settings':
				return __( 'Settings', 'blt-toolkit' );
		}

		return '';
	}

	/**
	 * Every admin-post.php action this plugin handles: the screen that owns
	 * it, the handler method on that screen, and the capability it needs.
	 *
	 * Each handler runs only after route_post() has checked the method, the
	 * capability and the nonce, and it ends with a redirect.
	 *
	 * @return array<string,array{screen:string,method:string,cap:string}>
	 */
	public static function post_actions() {
		return array(
			'blt_toolkit_save_modules'         => array(
				'screen' => 'overview',
				'method' => 'handle_save_modules',
				'cap'    => 'manage_options',
			),
			'blt_toolkit_save_settings'        => array(
				'screen' => 'settings',
				'method' => 'handle_save',
				'cap'    => 'manage_options',
			),
			'blt_toolkit_remove_token'         => array(
				'screen' => 'settings',
				'method' => 'handle_remove_token',
				'cap'    => 'manage_options',
			),
			'blt_toolkit_test_connection'      => array(
				'screen' => 'settings',
				'method' => 'handle_test_connection',
				'cap'    => 'manage_options',
			),
			// SW-21. The handler also requires blt_create_roles.
			'blt_toolkit_create_support_agent' => array(
				'screen' => 'settings',
				'method' => 'handle_create_support_agent',
				'cap'    => 'manage_options',
			),
			// Streams the CSV and exits instead of redirecting (SPEC 13.2).
			'blt_toolkit_export_log'           => array(
				'screen' => 'activity',
				'method' => 'handle_export',
				'cap'    => 'blt_view_toolkit_log',
			),
			// UN-30. The template streams a CSV and exits instead of redirecting.
			'blt_toolkit_bulk_template'        => array(
				'screen' => 'usernames',
				'method' => 'handle_template',
				'cap'    => 'blt_change_usernames',
			),
			'blt_toolkit_bulk_plan'            => array(
				'screen' => 'usernames',
				'method' => 'handle_plan',
				'cap'    => 'blt_change_usernames',
			),
			'blt_toolkit_bulk_start'           => array(
				'screen' => 'usernames',
				'method' => 'handle_start',
				'cap'    => 'blt_change_usernames',
			),
			'blt_toolkit_bulk_discard'         => array(
				'screen' => 'usernames',
				'method' => 'handle_discard',
				'cap'    => 'blt_change_usernames',
			),
		);
	}

	/**
	 * The screen object for a key, built once per request.
	 *
	 * @param string $key Screen key.
	 * @return object|null
	 */
	public function screen( $key ) {
		$screens = self::screens();

		if ( ! isset( $screens[ $key ] ) ) {
			return null;
		}

		if ( ! isset( $this->screens[ $key ] ) ) {
			$class_name            = $screens[ $key ]['class'];
			$this->screens[ $key ] = new $class_name();
		}

		return $this->screens[ $key ];
	}

	// ---------- Menu and assets ----------

	/**
	 * Register the top-level menu, its submenus and Users > Roles.
	 *
	 * @return void
	 */
	public function register_menu() {
		$screens = self::screens();

		add_menu_page(
			__( 'BLT Toolkit', 'blt-toolkit' ),
			__( 'BLT Toolkit', 'blt-toolkit' ),
			$screens['overview']['cap'],
			self::MENU_SLUG,
			array( $this, 'render' ),
			BLT_Family_Brand::menu_icon( BLT_TOOLKIT_DIR, 'dashicons-groups' ),
			self::MENU_POSITION
		);

		foreach ( $screens as $key => $screen ) {
			$title = self::screen_title( $key );

			$hook = add_submenu_page(
				self::MENU_SLUG,
				/* translators: %s: screen name, such as Roles or Settings. */
				sprintf( __( 'BLT Toolkit %s', 'blt-toolkit' ), $title ),
				$title,
				$screen['cap'],
				$screen['slug'],
				array( $this, 'render' )
			);

			// False when the current user lacks the capability: nothing to gate.
			if ( is_string( $hook ) && '' !== $hook ) {
				$this->page_hooks[ $hook ] = $key;
			}
		}

		// D4: Users > Roles. A URL slug with no callback makes a plain link to
		// the Roles screen, so the page is registered once, under BLT Toolkit.
		// For someone without edit_users, WordPress files it under Profile.
		add_users_page(
			__( 'Roles', 'blt-toolkit' ),
			__( 'Roles', 'blt-toolkit' ),
			$screens['roles']['cap'],
			'admin.php?page=' . $screens['roles']['slug']
		);
	}

	/**
	 * Brighten the menu's SVG icon on hover and while the section is open,
	 * as core does for dashicons (DESIGN 1).
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		BLT_Family_Brand::print_menu_icon_style( self::MENU_SLUG );
	}

	/**
	 * Load the design system and this plugin's admin stylesheet on BLT
	 * Toolkit screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! isset( $this->page_hooks[ (string) $hook ] ) ) {
			return;
		}

		wp_enqueue_style(
			'blt-toolkit-design-system',
			BLT_TOOLKIT_URL . 'assets/css/blt-design-system.css',
			array(),
			BLT_TOOLKIT_VERSION
		);

		wp_enqueue_style(
			'blt-toolkit-admin',
			BLT_TOOLKIT_URL . 'assets/css/blt-toolkit-admin.css',
			array( 'blt-toolkit-design-system' ),
			BLT_TOOLKIT_VERSION
		);
	}

	/**
	 * "Settings" on the Plugins row, for those who can open it.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @return array<int|string,string>
	 */
	public function plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$mine = array(
			'settings' => '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'blt-toolkit' ) . '</a>',
		);

		return array_merge( $mine, (array) $links );
	}

	// ---------- Rendering ----------

	/**
	 * Render whichever BLT Toolkit screen was requested.
	 *
	 * WordPress has already refused the request if the user lacks the
	 * submenu's capability; the check is repeated here so a screen never
	 * depends on menu registration for its protection.
	 *
	 * @return void
	 */
	public function render() {
		global $plugin_page;

		$key     = self::screen_key( is_string( $plugin_page ) ? $plugin_page : '' );
		$screens = self::screens();

		if ( '' === $key || ! current_user_can( $screens[ $key ]['cap'] ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array( 'response' => 403 )
			);
		}

		$this->screen( $key )->render();
	}

	/**
	 * The screen key for a page slug, or '' when it is not ours.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function screen_key( $slug ) {
		foreach ( self::screens() as $key => $screen ) {
			if ( $screen['slug'] === $slug ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * The page header (BLT mark, title, subtitle, actions) and any notice
	 * from the last redirect. Every screen calls this first inside its
	 * `.wrap`.
	 *
	 * @param string $sub     Subtitle beside the title, usually the screen name.
	 * @param array  $actions Header buttons: each array( 'label' => '', 'url' => '', 'primary' => false ).
	 * @return void
	 */
	public static function header( $sub, array $actions = array() ) {
		$sub     = (string) $sub;
		$notice  = self::notice();
		$actions = array_filter(
			$actions,
			function ( $button ) {
				return is_array( $button ) && ! empty( $button['url'] ) && isset( $button['label'] );
			}
		);

		require BLT_TOOLKIT_DIR . 'admin/views/header.php';
	}

	/**
	 * A link-based tab bar (DESIGN 2, Tabs): real links, one URL per tab.
	 *
	 * @param string               $screen  Screen key the tabs belong to.
	 * @param array<string,string> $tabs    Tab key => label.
	 * @param string               $current Open tab.
	 * @param string               $label   Accessible name for the nav.
	 * @return void
	 */
	public static function tabs( $screen, array $tabs, $current, $label ) {
		?>
		<nav class="blt-settings-tabs" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
				<a class="blt-settings-tab<?php echo $tab_key === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::page_url( $screen, array( 'tab' => $tab_key ) ) ); ?>"<?php echo $tab_key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Print a toggle switch (DESIGN 2, Toggle switch) for a boolean.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Whether it is on.
	 * @param string $label   Label.
	 * @param string $desc    Optional description under the label.
	 * @param string $id      Optional id for the checkbox.
	 * @return void
	 */
	public static function toggle( $name, $checked, $label, $desc = '', $id = '' ) {
		?>
		<label class="blt-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo '' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?> <?php checked( (bool) $checked ); ?> />
			<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
			<span class="blt-toggle-text">
				<span class="blt-toggle-label"><?php echo esc_html( $label ); ?></span>
				<?php if ( '' !== $desc ) : ?>
					<span class="blt-toggle-desc"><?php echo esc_html( $desc ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Badge markup (DESIGN 2, Badge), escaped.
	 *
	 * @param string $type  `on`, `off`, `confirmed`, `pending` or `cancelled`.
	 * @param string $label Text.
	 * @return string
	 */
	public static function badge( $type, $label ) {
		return '<span class="blt-badge blt-badge-' . esc_attr( $type ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The fields every form needs to reach route_post(): the action and its
	 * nonce.
	 *
	 * Printed by hand rather than with wp_nonce_field(), which gives the input
	 * an id equal to its name: a screen with several forms (Settings >
	 * Advanced) would then repeat that id.
	 *
	 * @param string $action admin-post.php action.
	 * @return void
	 */
	public static function form_fields( $action ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::NONCE_FIELD ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}

	/**
	 * Where every form posts.
	 *
	 * @return string
	 */
	public static function post_url() {
		return admin_url( 'admin-post.php' );
	}

	// ---------- Modules ----------

	/**
	 * Everything a screen shows about each module: name, description, state
	 * (SPEC 4.1, GEN-1, SEC-15), the stored toggle and the legacy plugin in
	 * the way, if any.
	 *
	 * Labels are translated, so this runs only while a screen renders.
	 *
	 * Only a module that booted is asked for its name. One that did not is
	 * named from the registry (BLT_Toolkit_Plugin::labels()), so rendering a
	 * screen never loads the class of a module that is off, disabled by
	 * `BLT_TOOLKIT_DISABLE_MODULES` or in conflict (SPEC 4.1, SEC-15). A module
	 * added through `blt_toolkit_modules` that did not boot shows its key.
	 *
	 * @return array<string,array{key:string,label:string,description:string,status:string,enabled:bool,conflict:string}>
	 */
	public static function modules() {
		$toggles = BLT_Toolkit_Settings::get( 'modules' );
		$names   = BLT_Toolkit_Plugin::labels();
		$out     = array();

		foreach ( array_keys( BLT_Toolkit_Plugin::modules() ) as $key ) {
			$module = BLT_Toolkit_Plugin::module( $key );

			if ( null !== $module ) {
				$label       = $module->label();
				$description = $module->description();
			} elseif ( isset( $names[ $key ] ) ) {
				$label       = $names[ $key ]['label'];
				$description = $names[ $key ]['description'];
			} else {
				$label       = $key;
				$description = '';
			}

			$out[ $key ] = array(
				'key'         => $key,
				'label'       => $label,
				'description' => $description,
				'status'      => BLT_Toolkit_Plugin::module_status( $key ),
				'enabled'     => is_array( $toggles ) && ! empty( $toggles[ $key ] ),
				'conflict'    => BLT_Toolkit_Legacy::conflict( $key ),
			);
		}

		return $out;
	}

	/**
	 * The badge for a module state.
	 *
	 * @param string $status `on`, `off`, `conflict` or `disabled`.
	 * @return string Escaped markup.
	 */
	public static function status_badge( $status ) {
		switch ( $status ) {
			case 'on':
				return self::badge( 'on', __( 'On', 'blt-toolkit' ) );
			case 'conflict':
				return self::badge( 'cancelled', __( 'Conflict', 'blt-toolkit' ) );
			case 'disabled':
				return self::badge( 'pending', __( 'Disabled in wp-config.php', 'blt-toolkit' ) );
		}

		return self::badge( 'off', __( 'Off', 'blt-toolkit' ) );
	}

	/**
	 * Why a module is not running, as escaped HTML: a legacy plugin is active
	 * (GEN-1, with a link to Plugins), the module is named in
	 * BLT_TOOLKIT_DISABLE_MODULES (SEC-15), or it is switched off in
	 * settings (with a link to Settings > General). '' when it is running.
	 *
	 * @param array $module One entry of modules().
	 * @return string
	 */
	public static function module_reason( array $module ) {
		if ( 'conflict' === $module['status'] && '' !== $module['conflict'] ) {
			$html = sprintf(
				/* translators: 1: other plugin's name, such as User Switching, 2: module name. */
				esc_html__( '%1$s is active, so the %2$s module is not running. Deactivate %1$s to use it.', 'blt-toolkit' ),
				esc_html( $module['conflict'] ),
				esc_html( $module['label'] )
			);

			// Both can apply at once; deactivating the legacy plugin alone would
			// not bring the module back.
			if ( in_array( $module['key'], BLT_Toolkit_Plugin::disabled_by_constant(), true ) ) {
				$html .= ' ' . sprintf(
					/* translators: %s: constant name. */
					esc_html__( 'It is also switched off by %s in wp-config.php; remove it from that constant too.', 'blt-toolkit' ),
					'<code>BLT_TOOLKIT_DISABLE_MODULES</code>'
				);
			}

			if ( current_user_can( 'activate_plugins' ) ) {
				$url = add_query_arg(
					array(
						'plugin_status' => 'active',
						's'             => rawurlencode( $module['conflict'] ),
					),
					admin_url( 'plugins.php' )
				);

				$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Go to Plugins', 'blt-toolkit' ) . '</a>';
			}

			return $html;
		}

		if ( 'disabled' === $module['status'] ) {
			return sprintf(
				/* translators: 1: module name, 2: constant name. */
				esc_html__( 'The %1$s module is switched off by %2$s in wp-config.php, whatever its setting says. Remove it from that constant to use the module again.', 'blt-toolkit' ),
				esc_html( $module['label'] ),
				'<code>BLT_TOOLKIT_DISABLE_MODULES</code>'
			);
		}

		if ( 'off' === $module['status'] ) {
			$html = sprintf(
				/* translators: %s: module name. */
				esc_html__( 'The %s module is switched off, so its features are not available.', 'blt-toolkit' ),
				esc_html( $module['label'] )
			);

			if ( current_user_can( 'manage_options' ) ) {
				$html .= ' <a href="' . esc_url( self::page_url( 'settings', array( 'tab' => 'general' ) ) ) . '">' . esc_html__( 'Turn it on in Settings', 'blt-toolkit' ) . '</a>';
			}

			return $html;
		}

		return '';
	}

	/**
	 * Under a module toggle: why the module is not running although its
	 * toggle may be on (a conflict or the constant). Prints nothing otherwise,
	 * because "off" is already what the toggle shows.
	 *
	 * @param array $module One entry of modules().
	 * @return void
	 */
	public static function module_note( array $module ) {
		if ( ! in_array( $module['status'], array( 'conflict', 'disabled' ), true ) ) {
			return;
		}

		echo '<span class="blt-field-desc blt-toolkit-module-note">' . self::module_reason( $module ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in module_reason().
	}

	/**
	 * At the top of a module's screen: a callout when that module is not
	 * running, saying why and how to change it. Prints nothing when it runs.
	 *
	 * @param string $key Module key.
	 * @return void
	 */
	public static function module_callout( $key ) {
		$modules = self::modules();

		if ( ! isset( $modules[ $key ] ) || 'on' === $modules[ $key ]['status'] ) {
			return;
		}

		$module = $modules[ $key ];
		?>
		<div class="blt-callout">
			<strong>
				<?php
				printf(
					/* translators: %s: module name, such as Roles. */
					esc_html__( 'The %s module is not running', 'blt-toolkit' ),
					esc_html( $module['label'] )
				);
				?>
			</strong>
			<span><?php echo self::module_reason( $module ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in module_reason(). ?></span>
		</div>
		<?php
	}

	// ---------- POST router ----------

	/**
	 * Route an admin-post.php action to its screen's handler.
	 *
	 * Checks, in order: a POST request, the action's capability, the nonce.
	 * The handler then sanitizes its own fields and redirects back.
	 *
	 * @return void
	 */
	public function route_post() {
		$action  = substr( current_action(), strlen( 'admin_post_' ) );
		$actions = self::post_actions();

		if ( ! isset( $actions[ $action ] ) ) {
			return;
		}

		$route  = $actions[ $action ];
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			wp_die(
				esc_html__( 'This action needs a form submission.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 405,
					'back_link' => true,
				)
			);
		}

		if ( ! current_user_can( $route['cap'] ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		// UN-30 step 2: an upload over post_max_size reaches here with an
		// empty body (the upload form puts its action in the URL), so there
		// is no nonce to check. Nothing is written; say why instead of
		// failing the nonce or leaving a blank admin-post.php page.
		if ( 'usernames' === $route['screen'] && class_exists( 'BLT_Toolkit_Admin_Usernames' ) && BLT_Toolkit_Admin_Usernames::body_dropped() ) {
			self::redirect( $route['screen'], array(), 'bulk_too_large' );
		}

		check_admin_referer( $action, self::NONCE_FIELD );

		$screen = $this->screen( $route['screen'] );

		call_user_func( array( $screen, $route['method'] ) );

		// Handlers redirect and exit. Reaching this line is a bug; still end on
		// a redirect rather than a blank admin-post.php page.
		self::redirect( $route['screen'], array(), 'failed' );
	}

	/**
	 * The URL of a screen, with optional query arguments.
	 *
	 * @param string              $screen Screen key.
	 * @param array<string,mixed> $args   Extra query arguments.
	 * @return string
	 */
	public static function page_url( $screen, array $args = array() ) {
		$screens = self::screens();
		$slug    = isset( $screens[ $screen ] ) ? $screens[ $screen ]['slug'] : self::MENU_SLUG;

		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect to a screen with a notice code, and stop (post / redirect /
	 * get).
	 *
	 * @param string              $screen Screen key.
	 * @param array<string,mixed> $args   Query arguments, such as the tab.
	 * @param string              $notice Notice code, read back by notice().
	 * @return void
	 */
	public static function redirect( $screen, array $args = array(), $notice = '' ) {
		if ( '' !== $notice ) {
			$args[ self::NOTICE_ARG ] = $notice;
		}

		wp_safe_redirect( self::page_url( $screen, $args ) );
		exit;
	}

	/**
	 * Redirect arguments for a Test connection result: the HTTP status, where
	 * the token came from or the error code, and a nonce over those values.
	 *
	 * The nonce is bound to the current user and session (wp_create_nonce())
	 * and its action contains the values, so notice() shows the result only
	 * for a link this handler produced for this person. A crafted link cannot
	 * report a connection that was never tested, or put its own words into
	 * the error.
	 *
	 * @param int    $http HTTP status GitHub answered with, or 0.
	 * @param string $via  Where the token came from, or ''.
	 * @param string $err  Error code from the HTTP library, or ''.
	 * @return array<string,mixed>
	 */
	public static function result_args( $http, $via, $err ) {
		$http = absint( $http );
		$via  = sanitize_key( (string) $via );
		$err  = sanitize_key( (string) $err );
		$args = array( self::RESULT_SIG_ARG => wp_create_nonce( self::result_action( $http, $via, $err ) ) );

		if ( '' !== $err ) {
			$args['err'] = $err;
		} else {
			$args['http'] = $http;
			$args['via']  = $via;
		}

		return $args;
	}

	/**
	 * The nonce action for a Test connection result.
	 *
	 * @param int    $http HTTP status.
	 * @param string $via  Token source.
	 * @param string $err  Error code.
	 * @return string
	 */
	private static function result_action( $http, $via, $err ) {
		return 'blt_toolkit_test_result|' . $http . '|' . $via . '|' . $err;
	}

	/**
	 * The notice for the `blt_notice` query argument, if any.
	 *
	 * Only known codes produce text, and the URL never carries a message. The
	 * one notice built from URL values, the Test connection result, is shown
	 * only when its nonce matches those values (result_args()), so a crafted
	 * link can neither put its own words on the screen nor fake a result.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, after our own redirect; the result values are checked against their own nonce below.
		if ( empty( $_GET[ self::NOTICE_ARG ] ) ) {
			return null;
		}

		$code = sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) );
		$http = isset( $_GET['http'] ) ? absint( wp_unslash( $_GET['http'] ) ) : 0;
		$via  = isset( $_GET['via'] ) ? sanitize_key( wp_unslash( $_GET['via'] ) ) : '';
		$err  = isset( $_GET['err'] ) ? sanitize_key( wp_unslash( $_GET['err'] ) ) : '';
		$sig  = isset( $_GET[ self::RESULT_SIG_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RESULT_SIG_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( in_array( $code, array( 'test_result', 'test_unreachable' ), true ) && ! wp_verify_nonce( $sig, self::result_action( $http, $via, $err ) ) ) {
			return null;
		}

		switch ( $code ) {
			case 'saved':
				return self::make_notice( 'success', __( 'Settings saved.', 'blt-toolkit' ) );

			case 'saved_adjusted':
				return self::make_notice( 'warning', __( 'Settings saved. A number was outside its allowed range or not a number, so the nearest allowed value, or the previous value, was saved instead. Check the values below.', 'blt-toolkit' ) );

			// UN-20: the other settings were saved; the self-service roles were not.
			case 'self_service_denied':
				return self::make_notice( 'error', __( 'Settings saved, but no role was changed: you are not allowed to change who can change their own username for one of the roles you chose. BLT Toolkit protects the Administrator role, your own roles and roles with more access than yours.', 'blt-toolkit' ) );

			case 'self_service_snapshot':
				return self::make_notice( 'error', __( 'Settings saved, but no role was changed: a copy of the roles could not be saved first. Try again; if this keeps happening, the PHP error log says why.', 'blt-toolkit' ) );

			case 'self_service_in_code':
				return self::make_notice( 'error', __( 'Settings saved, but no role was changed: this site\'s roles are defined in code, so who can change their own username must be set there.', 'blt-toolkit' ) );

			case 'self_service_failed':
				return self::make_notice( 'error', __( 'Settings saved, but the roles could not be saved. Check who can change their own username below and try again.', 'blt-toolkit' ) );

			case 'modules_saved':
				return self::make_notice( 'success', __( 'Modules saved. A module that is switched off loads no code; protection for administrators and the activity log keep running.', 'blt-toolkit' ) );

			case 'token_saved':
				return self::make_notice( 'success', __( 'Settings saved. The GitHub token is stored encrypted. Use Test connection to check it.', 'blt-toolkit' ) );

			case 'token_bad':
				return self::make_notice( 'error', __( 'Settings saved, but the token was not: it does not look like a GitHub token. Paste it exactly as GitHub showed it.', 'blt-toolkit' ) );

			case 'token_no_aead':
				return self::make_notice( 'error', __( 'Settings saved, but the token was not: this server has neither libsodium nor OpenSSL AES-GCM, so it cannot be stored encrypted. Define BLT_TOOLKIT_GITHUB_TOKEN in wp-config.php instead.', 'blt-toolkit' ) );

			case 'token_failed':
				return self::make_notice( 'error', __( 'Settings saved, but the token could not be encrypted, so it was not stored. Try again.', 'blt-toolkit' ) );

			case 'token_removed':
				return self::make_notice( 'success', __( 'The stored GitHub token was removed. A token in wp-config.php or in the shared BLT settings, if there is one, still applies.', 'blt-toolkit' ) );

			case 'test_result':
				return self::connection_notice( $http, $via );

			case 'test_unreachable':
				return self::make_notice(
					'error',
					sprintf(
						/* translators: %s: error code from the HTTP library, such as http_request_failed. */
						__( 'GitHub could not be reached from this server (%s). Check that the host allows outgoing HTTPS requests to api.github.com.', 'blt-toolkit' ),
						'' !== $err ? $err : 'unknown'
					)
				);

			case 'support_agent_created':
				return self::make_notice( 'success', __( 'The Support Agent role was created. Give it to someone from their profile or from the Users screen.', 'blt-toolkit' ) );

			case 'support_agent_exists':
				return self::make_notice( 'warning', __( 'The Support Agent role already exists, so nothing was changed.', 'blt-toolkit' ) );

			case 'support_agent_confirm':
				return self::make_notice( 'error', __( 'Nothing was changed: type the role name exactly as shown to confirm.', 'blt-toolkit' ) );

			case 'support_agent_snapshot':
				return self::make_notice( 'error', __( 'Nothing was changed: a copy of the roles could not be saved first. Try again; if this keeps happening, the PHP error log says why.', 'blt-toolkit' ) );

			case 'support_agent_denied':
				return self::make_notice( 'error', __( 'Nothing was changed: creating this role needs the "Create roles" permission and every capability the role would have.', 'blt-toolkit' ) );

			case 'support_agent_failed':
				return self::make_notice( 'error', __( 'The Support Agent role could not be created. Try again.', 'blt-toolkit' ) );

			// UN-30, the bulk rename.
			case 'bulk_started':
				return self::make_notice( 'success', __( 'The bulk rename has started. It keeps running in the background if you leave this page; come back here to see how it went.', 'blt-toolkit' ) );

			case 'bulk_ack':
				return self::make_notice( 'error', __( 'Nothing was renamed: confirm that you have a recent backup of this site first.', 'blt-toolkit' ) );

			case 'bulk_busy':
				return self::make_notice( 'error', __( 'Nothing was renamed: another bulk rename is still running. Start this one when it has finished, or cancel the other.', 'blt-toolkit' ) );

			case 'bulk_nothing':
				return self::make_notice( 'error', __( 'Nothing was renamed: no row of the file is ready.', 'blt-toolkit' ) );

			case 'bulk_not_started':
				return self::make_notice( 'error', __( 'Nothing was renamed: the bulk rename could not be started. Try again.', 'blt-toolkit' ) );

			case 'bulk_expired':
				return self::make_notice( 'error', __( 'Nothing was renamed: that dry run is no longer available. It expires after an hour, or when you upload another file. Upload the file again.', 'blt-toolkit' ) );

			case 'bulk_not_stored':
				return self::make_notice( 'error', __( 'The file was checked, but the dry run could not be saved. Try again; if this keeps happening, upload fewer rows at a time.', 'blt-toolkit' ) );

			case 'bulk_discarded':
				return self::make_notice( 'success', __( 'The dry run was discarded. Nothing was renamed.', 'blt-toolkit' ) );

			case 'bulk_too_large':
				return self::make_notice(
					'error',
					sprintf(
						/* translators: %s: the largest file accepted, such as 2 MB. */
						__( 'Nothing was uploaded: the file is larger than this server accepts. It can be at most %s; split it into smaller files.', 'blt-toolkit' ),
						size_format( BLT_Toolkit_Admin_Usernames::max_upload() )
					)
				);

			case 'failed':
				return self::make_notice( 'error', __( 'Nothing was changed: the request could not be completed. Try again.', 'blt-toolkit' ) );
		}

		return null;
	}

	/**
	 * The notice for a Test connection result.
	 *
	 * @param int    $http HTTP status GitHub answered with.
	 * @param string $via  Where the token came from: `constant`, `stored`, `shared` or `none`.
	 * @return array{type:string,message:string}
	 */
	private static function connection_notice( $http, $via ) {
		switch ( $via ) {
			case 'constant':
				$source = __( 'with the token from wp-config.php', 'blt-toolkit' );
				break;
			case 'stored':
				$source = __( 'with the token stored here', 'blt-toolkit' );
				break;
			case 'shared':
				$source = __( 'with the token from the shared BLT settings', 'blt-toolkit' );
				break;
			default:
				$source = __( 'without a token', 'blt-toolkit' );
		}

		$repo = BLT_Toolkit_Admin_Settings::repository();

		if ( 200 === $http ) {
			return self::make_notice(
				'success',
				sprintf(
					/* translators: 1: GitHub repository such as S-FX-com/BLT-Toolkit, 2: how the request was made, such as "with the token stored here". */
					__( 'Connected. GitHub answered HTTP 200 for %1$s %2$s, so update checks will work.', 'blt-toolkit' ),
					$repo,
					$source
				)
			);
		}

		switch ( $http ) {
			case 401:
				$hint = __( 'GitHub rejected the token. It may have expired or been revoked; create a new one and save it here.', 'blt-toolkit' );
				break;
			case 403:
			case 429:
				$hint = __( 'GitHub refused the request. The token may lack access to the repository, or this server reached GitHub\'s hourly limit for requests; try again later.', 'blt-toolkit' );
				break;
			case 404:
				$hint = __( 'GitHub did not find the repository. For a private repository this means the token cannot see it: use a fine-grained token with read-only Contents access to it.', 'blt-toolkit' );
				break;
			default:
				$hint = __( 'GitHub may be having trouble. Try again in a few minutes.', 'blt-toolkit' );
		}

		return self::make_notice(
			'error',
			sprintf(
				/* translators: 1: HTTP status code, 2: GitHub repository, 3: how the request was made, 4: advice. */
				__( 'GitHub answered HTTP %1$d for %2$s %3$s. %4$s', 'blt-toolkit' ),
				$http,
				$repo,
				$source,
				$hint
			)
		);
	}

	/**
	 * A notice array.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Text.
	 * @return array{type:string,message:string}
	 */
	private static function make_notice( $type, $message ) {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
