<?php
/**
 * Old author URLs redirect to the new ones (UN-13a).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the author slugs a user had before a rename and sends a request for
 * an old one to the current author URL with a 301 (UN-13a, setting
 * `usernames.redirect_old_slugs`).
 *
 * Storage: user meta `blt_toolkit_previous_nicenames` (SPEC 14.4), one row
 * per previous slug, so a slug is found with an exact `meta_value` match
 * and the newest rename wins when two users once had the same slug. The
 * list is kept for every rename whatever the setting, so turning redirects
 * on later covers past renames.
 *
 * Cost per request (SEC-9): one hook on `template_redirect`. A request that
 * is not a 404 for an author slug returns before any query; a 404 author
 * request runs at most two (does a live user have the slug, then whose old
 * slug is it).
 */
class BLT_Toolkit_Author_Redirects {

	/**
	 * User meta key (SPEC 14.4), one row per previous slug.
	 */
	const META = 'blt_toolkit_previous_nicenames';

	/**
	 * Most previous slugs kept per user; the oldest go first.
	 */
	const MAX_KEPT = 20;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Hook the redirect when the setting is on. Called by the Usernames
	 * module's boot().
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( BLT_Toolkit_Settings::get( 'usernames', 'redirect_old_slugs' ) ) {
			// Before redirect_canonical() (priority 10), which has nothing to
			// offer for an author slug that no longer exists.
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 9 );
		}
	}

	/**
	 * Record a rename's old slug (UN-13a). Called by the rename procedure
	 * after the new slug is written.
	 *
	 * The old slug becomes the newest entry; the new slug is dropped from the
	 * list (a user renamed back to an earlier name serves that slug live).
	 *
	 * @param int    $user_id      User.
	 * @param string $old_nicename Slug before the rename.
	 * @param string $new_nicename Slug after it.
	 * @return void
	 */
	public static function remember( $user_id, $old_nicename, $new_nicename ) {
		$user_id      = (int) $user_id;
		$old_nicename = (string) $old_nicename;
		$new_nicename = (string) $new_nicename;

		if ( $user_id <= 0 || '' === $old_nicename || $old_nicename === $new_nicename ) {
			return;
		}

		// Deleting first moves an existing entry to the end (newest). Never
		// with an empty value: delete_user_meta() would then remove every row.
		delete_user_meta( $user_id, self::META, $old_nicename );

		if ( '' !== $new_nicename ) {
			delete_user_meta( $user_id, self::META, $new_nicename );
		}

		add_user_meta( $user_id, self::META, $old_nicename );

		$kept = get_user_meta( $user_id, self::META, false );

		foreach ( self::overflow( is_array( $kept ) ? $kept : array(), self::MAX_KEPT ) as $slug ) {
			if ( '' !== $slug ) {
				delete_user_meta( $user_id, self::META, $slug );
			}
		}
	}

	/**
	 * The oldest entries beyond the limit. Pure.
	 *
	 * @param array $slugs Slugs, oldest first (the order core reads meta in).
	 * @param int   $max   How many to keep.
	 * @return string[]
	 */
	public static function overflow( array $slugs, $max ) {
		$slugs = array_values( array_filter( array_map( 'strval', $slugs ), 'strlen' ) );
		$extra = count( $slugs ) - max( 0, (int) $max );

		return $extra > 0 ? array_slice( $slugs, 0, $extra ) : array();
	}

	/**
	 * 301 from an old author slug to the current author URL (UN-13a).
	 * `template_redirect` callback.
	 *
	 * Only a request WordPress already answers with a 404 is considered, and
	 * only when it asked for an author slug. A slug a live user has always
	 * wins over a stored previous one (a user who now owns it is served,
	 * never redirected away).
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}

		$slug = get_query_var( 'author_name' );

		if ( ! is_string( $slug ) || '' === $slug ) {
			return;
		}

		$slug = sanitize_title_for_query( $slug );

		if ( '' === $slug || get_user_by( 'slug', $slug ) ) {
			return;
		}

		$url = self::target_url( self::owner_of( $slug ), (int) get_query_var( 'paged' ) );

		if ( '' === $url ) {
			return;
		}

		wp_safe_redirect( $url, 301, 'BLT Toolkit' );
		exit;
	}

	/**
	 * The user whose previous slug this is, newest rename first; 0 when none.
	 *
	 * @param string $slug Sanitized slug.
	 * @return int
	 */
	public static function owner_of( $slug ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Runs only for a 404 author request; the newest row wins, which get_users() cannot order by.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY umeta_id DESC LIMIT 1", self::META, (string) $slug ) );

		return null === $id ? 0 : (int) $id;
	}

	/**
	 * The current author URL of a user, with the page number kept.
	 *
	 * @param int $user_id User, or 0.
	 * @param int $paged   Page number of the request (0 or 1 for the first).
	 * @return string '' when the user no longer exists.
	 */
	private static function target_url( $user_id, $paged ) {
		global $wp_rewrite;

		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || '' === (string) $user->user_nicename ) {
			return '';
		}

		$url = get_author_posts_url( $user_id, $user->user_nicename );

		if ( $paged > 1 ) {
			if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_permalinks() ) {
				$url = trailingslashit( $url ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
			} else {
				$url = add_query_arg( 'paged', $paged, $url );
			}
		}

		return $url;
	}
}
