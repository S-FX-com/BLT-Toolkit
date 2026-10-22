<?php
/**
 * Where switching appears: Users list, profile, toolbar, notices, wp_die
 * pages, the login screen, the Meta widget, the site footer and the command
 * palette.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every place a "Switch to" or "Switch back" link is shown (SPEC 7.3).
 *
 * Booted by BLT_Toolkit_Switch::boot(), so none of this loads while the
 * Switch module is off (SPEC 4.1). boot() only adds hooks. Each callback
 * returns before any work when it has nothing to show, and the checks are
 * ordered cheapest first, so a visitor who is not switched costs nothing
 * beyond the function calls (SEC-9):
 *
 * - Users list row action (SW-12) and profile button (SW-13), through
 *   BLT_Toolkit_Switch::maybe_switch_url(), which is cached per request.
 * - Toolbar (SW-14): "Viewing as {Name}" while switched, with "Switch back
 *   to {Original}" and "Switch Off" under it; "Switch Off" in the account
 *   menu otherwise; "Switch to {Author}" on author archives (SW-15); the
 *   "Switch to…" search (SW-20).
 * - Admin notice after switching, and the switch-back link on `wp_die()`
 *   pages, including `admin_page_access_denied` (SW-16).
 * - The fixed "Switch back" link on the site when the toolbar is not showing
 *   (SW-17, X-5), styled by assets/css/blt-toolkit-front.css, which loads
 *   only when the link is printed. A signed-out page that carries it is
 *   marked uncacheable (SW-25).
 * - The login screen (SW-18) and the Meta widget (Appendix B).
 * - Command palette commands when `wp-commands` is enqueued (SW-26).
 *
 * The REST field (SW-27) and the search route (SW-20) are registered by
 * BLT_Toolkit_Rest::register_switch_routes().
 *
 * Every message addressed to the person who switched ("Viewing as",
 * "Switch back to") is rendered in that person's locale (SW-11).
 */
class BLT_Toolkit_Switch_Ui {

	/**
	 * Toolbar node IDs. WordPress prints them as `wp-admin-bar-{id}`.
	 */
	const NODE_SWITCHED    = 'blt-toolkit-switched';
	const NODE_SWITCH_BACK = 'blt-toolkit-switch-back';
	const NODE_SWITCH_OFF  = 'blt-toolkit-switch-off';
	const NODE_SEARCH      = 'blt-toolkit-switch-to';
	const NODE_SEARCH_FORM = 'blt-toolkit-switch-to-search';
	const NODE_AUTHOR      = 'blt-toolkit-author-switch';

	/**
	 * Asset handles.
	 */
	const FRONT_STYLE    = 'blt-toolkit-front';
	const TOOLBAR_STYLE  = 'blt-toolkit-toolbar';
	const SEARCH_SCRIPT  = 'blt-toolkit-switch-search';
	const PALETTE_SCRIPT = 'blt-toolkit-command-palette';

	/**
	 * Query arguments the switch flow appends to the redirect, read here to
	 * pick the notice (SW-16) and dropped from the address bar by core
	 * (`removable_query_args`).
	 */
	const ARG_SWITCHED      = BLT_Toolkit_Switch_Redirects::FLAG_SWITCHED;
	const ARG_SWITCHED_BACK = BLT_Toolkit_Switch_Redirects::FLAG_SWITCHED_BACK;
	const ARG_SWITCHED_OFF  = BLT_Toolkit_Switch_Redirects::FLAG_SWITCHED_OFF;

	/**
	 * Ids of the search form's elements (SW-20). The script finds them by id.
	 */
	const SEARCH_INPUT   = 'blt-tk-switch-search-input';
	const SEARCH_RESULTS = 'blt-tk-switch-search-results';

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Whether the footer link will be printed this request: null until
	 * decided. Decided once so the stylesheet and the link always agree.
	 *
	 * @var bool|null
	 */
	private static $footer = null;

	/**
	 * Whether the Meta widget printed the switch-back link this request.
	 *
	 * @var bool
	 */
	private static $meta_printed = false;

	/**
	 * Register the hooks. Called by BLT_Toolkit_Switch::boot(); safe to call
	 * twice. Only adds hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// SW-12, SW-13.
		add_filter( 'user_row_actions', array( __CLASS__, 'user_row_actions' ), 10, 2 );
		add_action( 'personal_options', array( __CLASS__, 'personal_options' ) );

		// SW-14, SW-15, SW-20. After core's nodes (the `edit` node is added at
		// priority 80), so a parent can be checked before it is used.
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 90 );

		// SW-16.
		add_action( 'all_admin_notices', array( __CLASS__, 'admin_notices' ), 1 );
		add_filter( 'wp_die_handler', array( __CLASS__, 'wrap_wp_die_handler' ), PHP_INT_MAX );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );

		// SW-17, SW-18, Appendix B (Meta widget), SW-25.
		add_action( 'template_redirect', array( __CLASS__, 'nocache_footer_page' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_link' ) );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ), 1 );
		add_action( 'wp_meta', array( __CLASS__, 'meta_link' ) );

		// Toolbar styles and the search script (SW-14, SW-20), and the command
		// palette (SW-26) after core has enqueued `wp-commands`.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_toolbar' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_command_palette' ), 20 );

		// The footer decision depends on who is signed in.
		add_action( 'set_current_user', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Forget the per-request answers. Hooked to `set_current_user`.
	 *
	 * @return void
	 */
	public static function forget() {
		self::$footer = null;
	}

	// ---------- Who switched ----------

	/**
	 * The user who switched in (BLT_Toolkit_Switch::old_user(): the old-user
	 * cookie, bound to the current session while someone is signed in),
	 * whether or not anyone is signed in now. It is cached per request and
	 * reads no database when the browser sent no old-user cookie. The links
	 * shown go through the login URL, where switching back also checks the
	 * stack (SW-5).
	 *
	 * @return WP_User|false
	 */
	public static function original() {
		$user = BLT_Toolkit_Switch::old_user();

		return ( $user instanceof WP_User && $user->exists() ) ? $user : false;
	}

	/**
	 * The originating user while signed in as someone else, or false (not
	 * switched, or switched off and signed out).
	 *
	 * @return WP_User|false
	 */
	public static function switched_from() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$original = self::original();

		if ( ! $original || get_current_user_id() === (int) $original->ID ) {
			return false;
		}

		return $original;
	}

	// ---------- Users list and profile (SW-12, SW-13) ----------

	/**
	 * "Switch To" on a Users list row (SW-12), only when the current user may
	 * switch to that user. The row's cost is that one capability check:
	 * BLT_Toolkit_Switch::maybe_switch_url() caches it per target, and the
	 * Guard caches the SEC-2 comparison per role set (SEC-9), so rows of
	 * users with the same roles compare once.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_User $user    The row's user.
	 * @return array
	 */
	public static function user_row_actions( $actions, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $actions;
		}

		$url = BLT_Toolkit_Switch::maybe_switch_url( $user );

		if ( ! is_string( $url ) || '' === $url ) {
			return $actions;
		}

		// Translated once per request, not once per row (SEC-9).
		static $labels = null;

		if ( null === $labels ) {
			$labels = array(
				/* translators: %s: the user's display name. */
				'aria' => __( 'Switch to %s', 'blt-toolkit' ),
				'text' => esc_html__( 'Switch&nbsp;To', 'blt-toolkit' ),
			);
		}

		$actions = (array) $actions;

		$actions['blt_switch_to_user'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( sprintf( $labels['aria'], BLT_Toolkit_Plugin::display_name( $user ) ) ),
			$labels['text']
		);

		return $actions;
	}

	/**
	 * "Switch To" button on the profile screen (SW-13), with an accessible
	 * name that names the user.
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public static function personal_options( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$url = BLT_Toolkit_Switch::maybe_switch_url( $user );

		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		?>
		<tr class="blt-tk-switch-profile">
			<th scope="row"><?php esc_html_e( 'Switch user', 'blt-toolkit' ); ?></th>
			<td>
				<?php
				printf(
					'<a class="button" id="blt-tk-switch-to-user" href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( $url ),
					/* translators: %s: the user's display name. */
					esc_attr( sprintf( __( 'Switch to %s', 'blt-toolkit' ), $user->display_name ) ),
					esc_html__( 'Switch&nbsp;To', 'blt-toolkit' )
				);
				?>
				<p class="description"><?php esc_html_e( 'See the site as this user. Each switch, and any change you make with BLT Toolkit while switched, is logged under your own name.', 'blt-toolkit' ); ?></p>
			</td>
		</tr>
		<?php
	}

	// ---------- Toolbar (SW-14, SW-15, SW-20) ----------

	/**
	 * Toolbar nodes. `admin_bar_menu` callback, priority 90.
	 *
	 * @param WP_Admin_Bar $bar The toolbar.
	 * @return void
	 */
	public static function admin_bar_menu( $bar ) {
		if ( ! $bar instanceof WP_Admin_Bar || ! is_user_logged_in() ) {
			return;
		}

		$original = self::switched_from();
		$off_url  = self::can_switch_off() ? BLT_Toolkit_Switch::switch_off_url( self::switch_off_redirect() ) : '';

		if ( $original ) {
			self::add_switched_nodes( $bar, $original, $off_url );
		} elseif ( '' !== $off_url && $bar->get_node( 'user-actions' ) ) {
			$bar->add_node(
				array(
					'parent' => 'user-actions',
					'id'     => self::NODE_SWITCH_OFF,
					/* translators: "Switch Off" means signing out for a moment, with a way back. */
					'title'  => esc_html__( 'Switch Off', 'blt-toolkit' ),
					'href'   => $off_url,
				)
			);
		}

		if ( self::search_enabled() ) {
			self::add_search_nodes( $bar );
		}

		if ( ! is_admin() && is_author() ) {
			self::add_author_node( $bar, $original );
		}
	}

	/**
	 * "Viewing as {Name}" and its children (SW-14).
	 *
	 * The top-level node is not the switch-back action: a click, or a screen
	 * reader's activation, would change identity under a name that does not
	 * say so (WCAG 2.4.4). Like core's account menu it links to the viewed
	 * user's profile (or, for a user who cannot `read`, is a focusable item
	 * with no link), and switching back is its child "Switch back to
	 * {Original}".
	 *
	 * @param WP_Admin_Bar $bar      The toolbar.
	 * @param WP_User      $original Who switched.
	 * @param string       $off_url  Switch Off URL, or '' when not offered.
	 * @return void
	 */
	private static function add_switched_nodes( WP_Admin_Bar $bar, WP_User $original, $off_url ) {
		$user     = wp_get_current_user();
		$back_url = BLT_Toolkit_Switch::switch_back_url( $original, self::here() );
		$back     = BLT_Toolkit_Switch::switch_back_message( $original );

		// Addressed to the person who switched, so in their language (SW-11).
		$switched = switch_to_user_locale( (int) $original->ID );
		/* translators: %s: display name of the user being viewed as. */
		$viewing = sprintf( __( 'Viewing as %s', 'blt-toolkit' ), $user->display_name );
		$lang    = self::lang_attr( $original );

		if ( $switched ) {
			restore_previous_locale();
		}

		$meta = array(
			'class' => 'blt-tk-switched',
			'lang'  => $lang,
		);

		$profile = current_user_can( 'read' ) ? get_edit_profile_url( (int) $user->ID ) : '';

		if ( '' === $profile ) {
			$meta['tabindex'] = 0;
		}

		$bar->add_node(
			array(
				'parent' => 'top-secondary',
				'id'     => self::NODE_SWITCHED,
				'title'  => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html( $viewing ) . '</span>',
				'href'   => '' !== $profile ? $profile : false,
				'meta'   => $meta,
			)
		);

		$bar->add_node(
			array(
				'parent' => self::NODE_SWITCHED,
				'id'     => self::NODE_SWITCH_BACK,
				'title'  => esc_html( $back ),
				'href'   => $back_url,
				'meta'   => array( 'lang' => $lang ),
			)
		);

		if ( '' !== $off_url ) {
			$bar->add_node(
				array(
					'parent' => self::NODE_SWITCHED,
					'id'     => self::NODE_SWITCH_OFF,
					/* translators: "Switch Off" means signing out for a moment, with a way back. */
					'title'  => esc_html__( 'Switch Off', 'blt-toolkit' ),
					'href'   => $off_url,
				)
			);
		}
	}

	/**
	 * The "Switch to…" search (SW-20): a node that links to the Users screen,
	 * with a search form under it that also works without JavaScript (it
	 * searches the Users screen). The script turns the field into a
	 * typeahead over `GET blt-toolkit/v1/switch/users`.
	 *
	 * @param WP_Admin_Bar $bar The toolbar.
	 * @return void
	 */
	private static function add_search_nodes( WP_Admin_Bar $bar ) {
		$users_url = admin_url( 'users.php' );

		$bar->add_node(
			array(
				'parent' => 'top-secondary',
				'id'     => self::NODE_SEARCH,
				'title'  => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Switch to…', 'blt-toolkit' ) . '</span>',
				'href'   => $users_url,
				'meta'   => array( 'class' => 'blt-tk-switch-to' ),
			)
		);

		$form = sprintf(
			'<form class="blt-tk-switch-search" role="search" method="get" action="%1$s">'
				. '<label class="screen-reader-text" for="%2$s">%3$s</label>'
				. '<input type="search" id="%2$s" class="blt-tk-switch-search-input" name="s" value="" maxlength="100" autocomplete="off" spellcheck="false" placeholder="%4$s" />'
				. '<ul id="%5$s" class="blt-tk-switch-search-results" hidden></ul>'
				. '<span class="blt-tk-switch-search-status" aria-live="polite"></span>'
			. '</form>',
			esc_url( $users_url ),
			esc_attr( self::SEARCH_INPUT ),
			esc_html__( 'Find a user to switch to', 'blt-toolkit' ),
			esc_attr__( 'Name, username or email', 'blt-toolkit' ),
			esc_attr( self::SEARCH_RESULTS )
		);

		$bar->add_node(
			array(
				'parent' => self::NODE_SEARCH,
				'id'     => self::NODE_SEARCH_FORM,
				'title'  => $form,
				'meta'   => array(
					'class'    => 'blt-tk-switch-search-item',
					'tabindex' => -1,
				),
			)
		);
	}

	/**
	 * "Switch to {Author}" on an author archive, or "Switch back to {Name}"
	 * when the author is the person who switched (SW-15). Placed under the
	 * Edit User node when there is one, otherwise at the top level.
	 *
	 * @param WP_Admin_Bar  $bar      The toolbar.
	 * @param WP_User|false $original Who switched, or false.
	 * @return void
	 */
	private static function add_author_node( WP_Admin_Bar $bar, $original ) {
		$author = get_queried_object();

		if ( ! $author instanceof WP_User ) {
			return;
		}

		$lang = '';

		if ( $original && (int) $original->ID === (int) $author->ID ) {
			$title = BLT_Toolkit_Switch::switch_back_message( $original );
			$href  = BLT_Toolkit_Switch::switch_back_url( $original, self::here() );
			$lang  = self::lang_attr( $original );
		} else {
			if ( false === BLT_Toolkit_Switch::maybe_switch_url( $author ) ) {
				return;
			}

			/* translators: %s: the author's display name. */
			$title = sprintf( __( 'Switch to %s', 'blt-toolkit' ), $author->display_name );
			$href  = BLT_Toolkit_Switch::switch_to_url( $author, self::here() );
		}

		$bar->add_node(
			array(
				'parent' => $bar->get_node( 'edit' ) ? 'edit' : false,
				'id'     => self::NODE_AUTHOR,
				'title'  => esc_html( $title ),
				'href'   => $href,
				'meta'   => array( 'lang' => $lang ),
			)
		);
	}

	/**
	 * Whether the current user may switch off and the setting offers it
	 * (SW-1, SW-14).
	 *
	 * @return bool
	 */
	private static function can_switch_off() {
		return (bool) BLT_Toolkit_Settings::get( 'switch', 'switch_off' ) && current_user_can( 'blt_switch_users' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
	}

	/**
	 * Whether the "Switch to…" search is on and the current user may switch
	 * (SW-20).
	 *
	 * @return bool
	 */
	private static function search_enabled() {
		return (bool) BLT_Toolkit_Settings::get( 'switch', 'search' ) && current_user_can( 'blt_switch_users' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
	}

	/**
	 * Toolbar styles while switched, and the search script and its styles,
	 * on any screen that shows the toolbar. Hooked to `admin_enqueue_scripts`
	 * and, through enqueue_front(), `wp_enqueue_scripts`.
	 *
	 * @return void
	 */
	public static function enqueue_toolbar() {
		if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
			return;
		}

		$search = self::search_enabled();

		if ( ! $search && ! self::switched_from() ) {
			return;
		}

		wp_enqueue_style(
			self::TOOLBAR_STYLE,
			BLT_TOOLKIT_URL . 'assets/css/blt-toolkit-toolbar.css',
			array( 'admin-bar' ),
			BLT_TOOLKIT_VERSION
		);

		if ( ! $search ) {
			return;
		}

		wp_enqueue_script(
			self::SEARCH_SCRIPT,
			BLT_TOOLKIT_URL . 'assets/js/blt-toolkit-switch-search.js',
			array(),
			BLT_TOOLKIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'root'     => esc_url_raw( rest_url( BLT_TOOLKIT_REST_NS . '/switch/users' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'minChars' => 2,
			'input'    => self::SEARCH_INPUT,
			'results'  => self::SEARCH_RESULTS,
			'strings'  => array(
				'label'     => __( 'Users you can switch to', 'blt-toolkit' ),
				'hint'      => __( 'Type at least 2 characters.', 'blt-toolkit' ),
				'searching' => __( 'Searching…', 'blt-toolkit' ),
				'none'      => __( 'No user you can switch to matches.', 'blt-toolkit' ),
				/* translators: %s: number of users found. */
				'found'     => __( 'Users found: %s. Use the up and down arrow keys to choose one, then press Enter.', 'blt-toolkit' ),
				'failed'    => __( 'The search did not work. Try again, or reload the page.', 'blt-toolkit' ),
			),
		);

		wp_add_inline_script(
			self::SEARCH_SCRIPT,
			'window.bltToolkit = window.bltToolkit || {}; window.bltToolkit.switchSearch = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	// ---------- Notices and wp_die pages (SW-16) ----------

	/**
	 * The notice after switching, and the switch-back link on every admin
	 * screen while switched (SW-16). `all_admin_notices` callback.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		$original = self::switched_from();
		$user     = wp_get_current_user();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only: which notice to show after the switch flow's own redirect.
		$just_switched = isset( $_GET[ self::ARG_SWITCHED ] );
		$switched_back = isset( $_GET[ self::ARG_SWITCHED_BACK ] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $original ) {
			$message = '';

			if ( $just_switched ) {
				$message = BLT_Toolkit_Switch::switched_to_message( $user );
			} elseif ( $switched_back ) {
				// A nested switch back: the person reading is still the one who
				// switched in, so the message is in their language (SW-11).
				$message = BLT_Toolkit_Switch::switched_back_message( $user, $original );
			}

			$url  = BLT_Toolkit_Switch::switch_back_url( $original, self::here() );
			$link = BLT_Toolkit_Switch::switch_back_message( $original );
			?>
			<div id="blt-tk-switched-notice" class="notice notice-success is-dismissible">
				<p lang="<?php echo esc_attr( self::lang_attr( $original ) ); ?>">
					<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
					<?php if ( '' !== $message ) : ?>
						<?php echo esc_html( $message ); ?>
					<?php endif; ?>
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $link ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		if ( ! $switched_back || ! $user->exists() ) {
			return;
		}
		?>
		<div id="blt-tk-switched-notice" class="notice notice-success is-dismissible">
			<p><?php echo esc_html( BLT_Toolkit_Switch::switched_back_message( $user ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Add the switch-back link to `wp_die()` pages while switched (SW-16),
	 * including the "not allowed" page after `admin_page_access_denied`.
	 *
	 * Wraps whichever HTML handler is in use, so another plugin's handler
	 * still runs, and adds the link through the handler's own `link_url` and
	 * `link_text` arguments (or, when the caller set those, after the
	 * message). The AJAX, JSON and XML handlers are separate filters and are
	 * left alone. Nothing is looked up until `wp_die()` runs.
	 *
	 * @param callable $handler The handler WordPress will call.
	 * @return callable
	 */
	public static function wrap_wp_die_handler( $handler ) {
		if ( ! is_callable( $handler ) ) {
			return $handler;
		}

		return static function ( $message, $title = '', $args = array() ) use ( $handler ) {
			list( $message, $title, $args ) = BLT_Toolkit_Switch_Ui::add_die_link( $message, $title, $args );

			return call_user_func( $handler, $message, $title, $args );
		};
	}

	/**
	 * The `wp_die()` arguments with the switch-back link added, when the
	 * person who switched can be validated. Otherwise unchanged.
	 *
	 * @param mixed $message Message (string or WP_Error).
	 * @param mixed $title   Title, or an HTTP status as core allows.
	 * @param mixed $args    Arguments, or an HTTP status as core allows.
	 * @return array{0:mixed,1:mixed,2:mixed}
	 */
	public static function add_die_link( $message, $title, $args ) {
		$original = self::original();

		if ( ! $original ) {
			return array( $message, $title, $args );
		}

		list( $title, $args ) = self::die_args( $title, $args );

		$url  = BLT_Toolkit_Switch::switch_back_url( $original, self::die_redirect() );
		$text = BLT_Toolkit_Switch::switch_back_message( $original );

		if ( empty( $args['link_url'] ) && empty( $args['link_text'] ) ) {
			// Core escapes link_url with esc_url() and prints link_text as is.
			$args['link_url']  = $url;
			$args['link_text'] = esc_html( $text );
		} elseif ( is_string( $message ) ) {
			$message .= sprintf(
				'<p class="blt-tk-die-switch-back"><a href="%1$s">%2$s</a></p>',
				esc_url( $url ),
				esc_html( $text )
			);
		}

		return array( $message, $title, $args );
	}

	/**
	 * The `wp_die()` title and arguments as an array, read the way core's
	 * _wp_die_process_input() reads them: an integer `$args`, or an integer
	 * `$title`, is the response code, and anything else goes through
	 * wp_parse_args(), so a query string such as `response=403` keeps its
	 * values.
	 *
	 * @param mixed $title Title, or an HTTP status.
	 * @param mixed $args  Arguments: array, object, query string or HTTP status.
	 * @return array{0:mixed,1:array}
	 */
	public static function die_args( $title, $args ) {
		if ( is_int( $args ) ) {
			$args = array( 'response' => $args );
		} elseif ( is_int( $title ) ) {
			$args  = array( 'response' => $title );
			$title = '';
		}

		return array( $title, wp_parse_args( $args ) );
	}

	/**
	 * Where switching back from a `wp_die()` page lands: the same page, as
	 * User Switching does, when it is a page a person can simply reload (a
	 * GET outside the login screen and admin-post.php). A form submission,
	 * or the switch flow's own refusal on the login screen, would only be
	 * replayed with a nonce that belongs to the switched session, so those
	 * fall back to the switch flow's default.
	 *
	 * @return array<string,string>
	 */
	private static function die_redirect() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$page   = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

		if ( 'GET' !== $method || in_array( $page, array( 'wp-login.php', 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return array();
		}

		return self::here();
	}

	/**
	 * Let core drop the switch flow's query arguments from the address bar.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args   = (array) $args;
		$args[] = self::ARG_SWITCHED;
		$args[] = self::ARG_SWITCHED_BACK;
		$args[] = self::ARG_SWITCHED_OFF;

		return $args;
	}

	// ---------- Site footer, login screen, Meta widget (SW-17, SW-18) ----------

	/**
	 * Keep page caches from storing a page that shows the footer link to
	 * someone who is signed out (after a Switch Off, SW-17, SW-25): a cache
	 * that only bypasses signed-in visitors would otherwise serve
	 * "Switch back to {Name}" to everyone. Sends core's no-cache headers and
	 * defines `DONOTCACHEPAGE`, which caching plugins honor.
	 * `template_redirect` callback, before any output; returns at once,
	 * without a query, for a visitor with no switch cookie.
	 *
	 * @return void
	 */
	public static function nocache_footer_page() {
		if ( is_user_logged_in() || ! self::shows_footer_link() ) {
			return;
		}

		nocache_headers();

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page caches' own constant.
		}
	}

	/**
	 * Front-end assets: the footer link's stylesheet, only when the link
	 * will be printed (SW-17), and the toolbar's. `wp_enqueue_scripts`
	 * callback.
	 *
	 * @return void
	 */
	public static function enqueue_front() {
		if ( self::shows_footer_link() ) {
			wp_enqueue_style(
				self::FRONT_STYLE,
				BLT_TOOLKIT_URL . 'assets/css/blt-toolkit-front.css',
				array(),
				BLT_TOOLKIT_VERSION
			);
		}

		self::enqueue_toolbar();
	}

	/**
	 * Whether the fixed footer link is printed on this request (SW-17, X-5):
	 * someone switched (or switched off) and can be validated, the toolbar
	 * is not showing, and the setting and `blt_toolkit_switch_show_footer_link`
	 * allow it. Decided once per request.
	 *
	 * @return bool
	 */
	private static function shows_footer_link() {
		if ( null !== self::$footer ) {
			return self::$footer;
		}

		self::$footer = false;

		if ( is_admin() || is_admin_bar_showing() || ! self::original() ) {
			return false;
		}

		/**
		 * Filter whether the fixed "Switch back to {Name}" link is printed on
		 * the site (SW-17). It is printed only while switched with the
		 * toolbar hidden, or after a Switch Off, whatever this returns.
		 *
		 * @param bool $show The Settings > Switching toggle (`switch.footer_link`).
		 */
		self::$footer = (bool) apply_filters( 'blt_toolkit_switch_show_footer_link', (bool) BLT_Toolkit_Settings::get( 'switch', 'footer_link' ) );

		return self::$footer;
	}

	/**
	 * The fixed "Switch back to {Name}" link (SW-17). `wp_footer` callback.
	 *
	 * Skipped when the Meta widget already printed the link on this page, as
	 * User Switching does.
	 *
	 * @return void
	 */
	public static function footer_link() {
		if ( self::$meta_printed || ! self::shows_footer_link() ) {
			return;
		}

		$original = self::original();

		if ( ! $original ) {
			return;
		}

		printf(
			'<div class="blt-tk-switch-back" id="blt-tk-switch-back"><a class="blt-tk-switch-back-link" href="%1$s" lang="%2$s">%3$s</a></div>',
			esc_url( BLT_Toolkit_Switch::switch_back_url( $original, self::here() ) ),
			esc_attr( self::lang_attr( $original ) ),
			esc_html( BLT_Toolkit_Switch::switch_back_message( $original ) )
		);
	}

	/**
	 * "Switch back to {Name}" on the login screen when an old-user cookie
	 * validates (SW-18), keeping `redirect_to` and the interim login flag.
	 *
	 * @param string $message The login screen message.
	 * @return string
	 */
	public static function login_message( $message ) {
		$original = self::original();

		if ( ! $original ) {
			return $message;
		}

		$redirect = array();
		$interim  = false;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: carried into the link, where the switch flow checks its own nonce.
		if ( ! empty( $_REQUEST['interim-login'] ) ) {
			$interim = true;
		} elseif ( ! empty( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) {
			$redirect['redirect_to'] = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$url = BLT_Toolkit_Switch::switch_back_url( $original, $redirect );

		// The auth-check modal's login screen: the switch flow answers with
		// core's interim result page, which closes the modal.
		if ( $interim ) {
			$url = add_query_arg( 'interim-login', '1', $url );
		}

		$message  = (string) $message;
		$message .= sprintf(
			'<p class="message blt-tk-login-switch-back" id="blt-tk-login-switch-back" lang="%1$s"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <a href="%2$s">%3$s</a></p>',
			esc_attr( self::lang_attr( $original ) ),
			esc_url( $url ),
			esc_html( BLT_Toolkit_Switch::switch_back_message( $original ) )
		);

		return $message;
	}

	/**
	 * "Switch back to {Name}" in the Meta widget (Appendix B). `wp_meta`
	 * callback.
	 *
	 * @return void
	 */
	public static function meta_link() {
		$original = self::original();

		if ( ! $original ) {
			return;
		}

		self::$meta_printed = true;

		printf(
			'<li class="blt-tk-meta-switch-back" lang="%1$s"><a href="%2$s">%3$s</a></li>',
			esc_attr( self::lang_attr( $original ) ),
			esc_url( BLT_Toolkit_Switch::switch_back_url( $original, self::here() ) ),
			esc_html( BLT_Toolkit_Switch::switch_back_message( $original ) )
		);
	}

	// ---------- Command palette (SW-26) ----------

	/**
	 * "Switch back", "Switch Off" and "Switch to {user}" in the command
	 * palette, when core enqueued `wp-commands` on this screen (SW-26).
	 * `admin_enqueue_scripts` callback, priority 20.
	 *
	 * "Switch to {user}" searches through `GET blt-toolkit/v1/switch/users`,
	 * so it lists only users the current user may switch to, and follows the
	 * toolbar search setting.
	 *
	 * @return void
	 */
	public static function enqueue_command_palette() {
		if ( ! wp_script_is( 'wp-commands', 'enqueued' ) ) {
			return;
		}

		$original = self::switched_from();
		$off      = self::can_switch_off();
		$search   = self::search_enabled();

		if ( ! $original && ! $off && ! $search ) {
			return;
		}

		$config = array(
			'switchBack' => null,
			'switchOff'  => null,
			'switchTo'   => null,
		);

		if ( $original ) {
			$config['switchBack'] = array(
				'url'    => self::raw_url( BLT_Toolkit_Switch::switch_back_url( $original, self::here() ) ),
				'label'  => BLT_Toolkit_Switch::switch_back_message( $original ),
				'avatar' => self::avatar( $original ),
			);
		}

		if ( $off ) {
			$config['switchOff'] = array(
				'url'   => self::raw_url( BLT_Toolkit_Switch::switch_off_url( self::switch_off_redirect() ) ),
				/* translators: "Switch Off" means signing out for a moment, with a way back. */
				'label' => __( 'Switch Off', 'blt-toolkit' ),
			);
		}

		if ( $search ) {
			$config['switchTo'] = array(
				'path'     => '/' . BLT_TOOLKIT_REST_NS . '/switch/users',
				'minChars' => 2,
				/* translators: %s: a user's display name. */
				'label'    => __( 'Switch to %s', 'blt-toolkit' ),
				'prefix'   => __( 'Switch to', 'blt-toolkit' ),
			);
		}

		wp_enqueue_script(
			self::PALETTE_SCRIPT,
			BLT_TOOLKIT_URL . 'assets/js/blt-toolkit-command-palette.js',
			array( 'wp-commands', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-api-fetch', 'wp-url' ),
			BLT_TOOLKIT_VERSION,
			true
		);

		wp_add_inline_script(
			self::PALETTE_SCRIPT,
			'window.bltToolkit = window.bltToolkit || {}; window.bltToolkit.commands = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	// ---------- Helpers ----------

	/**
	 * The redirect arguments that bring the person back to this page.
	 *
	 * @return array<string,string>
	 */
	public static function here() {
		return array( 'redirect_to' => self::current_url() );
	}

	/**
	 * The current URL, without the switch flow's notice arguments.
	 *
	 * @return string
	 */
	public static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host ) {
			return home_url( '/' );
		}

		$url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;

		return remove_query_arg( array( self::ARG_SWITCHED, self::ARG_SWITCHED_BACK, self::ARG_SWITCHED_OFF ), $url );
	}

	/**
	 * Where Switch Off lands (SW-9): in wp-admin, the item being edited
	 * (post, term, user or comment), as User Switching does; on the site,
	 * this page.
	 *
	 * @return array<string,int|string>
	 */
	public static function switch_off_redirect() {
		if ( ! is_admin() ) {
			return self::here();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only: admin_context() keeps only positive whole-number IDs of the item on screen.
		return BLT_Toolkit_Switch_Redirects::admin_context( wp_unslash( $_GET ) );
	}

	/**
	 * A user's locale as an HTML `lang` value (SW-11).
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function lang_attr( WP_User $user ) {
		return str_replace( '_', '-', (string) get_user_locale( $user ) );
	}

	/**
	 * A URL for a script rather than an HTML attribute: `&amp;` back to `&`.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function raw_url( $url ) {
		return wp_specialchars_decode( (string) $url, ENT_QUOTES );
	}

	/**
	 * A small avatar URL, or '' when the site shows no avatars.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	public static function avatar( WP_User $user ) {
		if ( ! get_option( 'show_avatars' ) ) {
			return '';
		}

		$url = get_avatar_url( $user->ID, array( 'size' => 48 ) );

		return is_string( $url ) ? $url : '';
	}
}
