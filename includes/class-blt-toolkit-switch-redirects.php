<?php
/**
 * Where a switch, switch back or switch off lands (SW-9).
 *
 * The redirect parameters, their resolution to public URLs and the admin
 * edit-screen context follow User Switching 1.12.2 by John Blackbourn,
 * reimplemented with BLT names.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects after switching (SW-9).
 *
 * A switch URL may carry one of five redirect parameters, read in this order:
 *
 * - `redirect_to`: a URL, later passed through wp_safe_redirect(), so only
 *   this site's hosts (and `allowed_redirect_hosts`) are followed.
 * - `redirect_to_post`, `redirect_to_term`, `redirect_to_user`,
 *   `redirect_to_comment`: an ID, resolved to the item's public URL only
 *   when it is publicly viewable (a draft or a private taxonomy resolves to
 *   nothing, so the default applies).
 *
 * The resolved URL then goes through core's `login_redirect` (switch to,
 * switch back) or `logout_redirect` (switch off), then
 * `blt_toolkit_switch_redirect`, then the default: the dashboard when the
 * user switched to may `read`, otherwise the home page; `users.php` after a
 * switch back; the home page after a switch off.
 *
 * pick(), args() and admin_context() are pure, so
 * tests/test-switch-redirects.php covers them; the rest needs WordPress.
 */
class BLT_Toolkit_Switch_Redirects {

	/**
	 * Redirect parameters, in the order they are read.
	 */
	const PARAMS = array( 'redirect_to', 'redirect_to_post', 'redirect_to_term', 'redirect_to_user', 'redirect_to_comment' );

	/**
	 * Query argument added after a switch to.
	 */
	const FLAG_SWITCHED = 'blt_switched';

	/**
	 * Query argument added after a switch back.
	 */
	const FLAG_SWITCHED_BACK = 'blt_switched_back';

	/**
	 * Query argument added after a switch off.
	 */
	const FLAG_SWITCHED_OFF = 'blt_switched_off';

	// ---------- Pure ----------

	/**
	 * The redirect a request asks for. Pure.
	 *
	 * The first non-empty parameter in PARAMS order wins, as in User
	 * Switching. A URL is returned as given (trimmed); an ID must be a
	 * positive whole number, otherwise the request asks for nothing.
	 *
	 * @param array $request Request parameters (unslashed), e.g. `$_GET`.
	 * @return array{type:string,value:string|int} `type` is '' (nothing), `url`, `post`, `term`, `user` or `comment`.
	 */
	public static function pick( array $request ) {
		foreach ( self::PARAMS as $param ) {
			if ( ! isset( $request[ $param ] ) || ! is_scalar( $request[ $param ] ) || '' === trim( (string) $request[ $param ] ) ) {
				continue;
			}

			$raw = trim( (string) $request[ $param ] );

			if ( 'redirect_to' === $param ) {
				return array(
					'type'  => 'url',
					'value' => $raw,
				);
			}

			return array(
				'type'  => substr( $param, strlen( 'redirect_to_' ) ),
				'value' => self::positive_id( $raw ),
			);
		}

		return array(
			'type'  => '',
			'value' => '',
		);
	}

	/**
	 * The redirect parameters to put on a switch URL. Pure.
	 *
	 * Keeps only the five known keys, a non-empty URL string for
	 * `redirect_to` and positive IDs for the rest; drops everything else.
	 * Values are not encoded here.
	 *
	 * @param array $redirect Parameters, e.g. `array( 'redirect_to_post' => 12 )`.
	 * @return array<string,string|int>
	 */
	public static function args( array $redirect ) {
		$out = array();

		foreach ( self::PARAMS as $param ) {
			if ( ! isset( $redirect[ $param ] ) || ! is_scalar( $redirect[ $param ] ) || is_bool( $redirect[ $param ] ) ) {
				continue;
			}

			if ( 'redirect_to' === $param ) {
				$url = trim( (string) $redirect[ $param ] );

				if ( '' !== $url ) {
					$out[ $param ] = $url;
				}

				continue;
			}

			$id = self::positive_id( (string) $redirect[ $param ] );

			if ( $id > 0 ) {
				$out[ $param ] = $id;
			}
		}

		return $out;
	}

	/**
	 * The item being edited on an admin screen, as redirect parameters for
	 * a switch off (SW-9, as User Switching's get_admin_redirect_to()). Pure.
	 *
	 * `post` (post.php), `tag_ID` (term.php), `user_id` (user-edit.php) and
	 * `c` (comment.php), first match wins.
	 *
	 * @param array $query The admin screen's query parameters (`$_GET`).
	 * @return array<string,int> Empty when the screen edits nothing known.
	 */
	public static function admin_context( array $query ) {
		$map = array(
			'post'    => 'redirect_to_post',
			'tag_ID'  => 'redirect_to_term',
			'user_id' => 'redirect_to_user',
			'c'       => 'redirect_to_comment',
		);

		foreach ( $map as $key => $param ) {
			if ( isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) ) {
				$id = self::positive_id( (string) $query[ $key ] );

				if ( $id > 0 ) {
					return array( $param => $id );
				}
			}
		}

		return array();
	}

	/**
	 * A positive whole number from a string, or 0. Pure.
	 *
	 * @param string $raw Raw value.
	 * @return int
	 */
	private static function positive_id( $raw ) {
		$raw = trim( (string) $raw );

		if ( 1 !== preg_match( '/^[0-9]{1,19}$/', $raw ) ) {
			return 0;
		}

		$id = (int) $raw;

		return $id > 0 ? $id : 0;
	}

	// ---------- WordPress ----------

	/**
	 * The URL a request asks for, resolved per SW-9.
	 *
	 * @param array $request Request parameters (unslashed).
	 * @return array{type:string,url:string,requested:string} `url` is '' when nothing usable was asked for.
	 */
	public static function resolve( array $request ) {
		$pick = self::pick( $request );
		$url  = '';

		switch ( $pick['type'] ) {
			case 'url':
				// Confirmation flags (`updated`, `blt_switched` ...) are not carried over.
				$url = remove_query_arg( wp_removable_query_args(), (string) $pick['value'] );
				break;

			case 'post':
				if ( $pick['value'] > 0 && is_post_publicly_viewable( (int) $pick['value'] ) ) {
					$url = (string) get_permalink( (int) $pick['value'] );
				}
				break;

			case 'term':
				$term = $pick['value'] > 0 ? get_term( (int) $pick['value'] ) : null;

				if ( $term instanceof WP_Term && is_taxonomy_viewable( $term->taxonomy ) ) {
					$link = get_term_link( $term );
					$url  = is_string( $link ) ? $link : '';
				}
				break;

			case 'user':
				$user = $pick['value'] > 0 ? get_userdata( (int) $pick['value'] ) : false;

				if ( $user instanceof WP_User ) {
					$url = get_author_posts_url( $user->ID );
				}
				break;

			case 'comment':
				$comment = $pick['value'] > 0 ? get_comment( (int) $pick['value'] ) : null;

				if ( $comment instanceof WP_Comment ) {
					if ( 'approved' === wp_get_comment_status( $comment ) && is_post_publicly_viewable( (int) $comment->comment_post_ID ) ) {
						$url = (string) get_comment_link( $comment );
					} elseif ( is_post_publicly_viewable( (int) $comment->comment_post_ID ) ) {
						$url = (string) get_permalink( (int) $comment->comment_post_ID );
					}
				}
				break;
		}

		return array(
			'type'      => $pick['type'],
			'url'       => $url,
			// What core's redirect filters receive as "requested": the raw
			// redirect_to, or the resolved public URL.
			'requested' => 'url' === $pick['type'] ? (string) $pick['value'] : $url,
		);
	}

	/**
	 * Where to send the browser after a switch (SW-9).
	 *
	 * @param string       $action   `blt_switch_to_user`, `blt_switch_back` or `blt_switch_off`.
	 * @param WP_User|null $new_user The user now signed in, or null after a switch off.
	 * @param WP_User|null $old_user The user switched from.
	 * @param array        $request  Request parameters (unslashed).
	 * @return string The URL, before the flag is added.
	 */
	public static function destination( $action, $new_user, $old_user, array $request ) {
		$resolved = self::resolve( $request );
		$url      = $resolved['url'];

		if ( $new_user instanceof WP_User ) {
			/** This filter is documented in wp-login.php */
			$url = apply_filters( 'login_redirect', $url, $resolved['requested'], $new_user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter (SW-9), applied as wp-login.php does.
		} else {
			/** This filter is documented in wp-login.php */
			$url = apply_filters( 'logout_redirect', $url, $resolved['requested'], $old_user instanceof WP_User ? $old_user : null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter (SW-9), applied as wp-login.php does.
		}

		$url = is_string( $url ) ? $url : '';

		if ( '' === $url ) {
			$url = self::fallback( $action, $new_user );
		}

		/**
		 * Filter where a switch, switch back or switch off lands (SW-9).
		 *
		 * Applied after core's `login_redirect` or `logout_redirect`. Return a
		 * non-empty URL to override; it still goes through wp_safe_redirect(),
		 * so another host needs `allowed_redirect_hosts`.
		 *
		 * @param string       $redirect_to '' to keep `$requested`.
		 * @param string       $requested   The URL resolved per SW-9 (the redirect
		 *                                  parameter, core's filter, then the
		 *                                  default for the action).
		 * @param WP_User|null $user        The user now signed in, or null after a switch off.
		 * @param string       $action      `blt_switch_to_user`, `blt_switch_back` or `blt_switch_off`.
		 */
		$filtered = apply_filters( 'blt_toolkit_switch_redirect', '', $url, $new_user instanceof WP_User ? $new_user : null, (string) $action );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $url;
	}

	/**
	 * The default destination when nothing was asked for.
	 *
	 * @param string       $action   Action.
	 * @param WP_User|null $new_user The user now signed in.
	 * @return string
	 */
	public static function fallback( $action, $new_user ) {
		if ( 'blt_switch_back' === $action ) {
			return admin_url( 'users.php' );
		}

		if ( 'blt_switch_to_user' === $action && $new_user instanceof WP_User && user_can( $new_user, 'read' ) ) {
			return admin_url();
		}

		return home_url( '/' );
	}

	/**
	 * Add the "just switched" flag and redirect with wp_safe_redirect(),
	 * then stop.
	 *
	 * A URL on a host that is not allowed is replaced before the flag is
	 * added: by the dashboard, as wp_safe_redirect() would, or by the home
	 * page after a switch off, when nobody is signed in to see a dashboard.
	 *
	 * @param string $url  Destination.
	 * @param string $flag One of the FLAG_* query arguments.
	 * @return void
	 */
	public static function go( $url, $flag ) {
		$fallback = self::FLAG_SWITCHED_OFF === $flag ? home_url( '/' ) : admin_url();
		$url      = wp_validate_redirect( (string) $url, $fallback );

		wp_safe_redirect( add_query_arg( (string) $flag, '1', '' !== $url ? $url : $fallback ), 302, 'WordPress/BLT Toolkit' );
		exit;
	}
}
