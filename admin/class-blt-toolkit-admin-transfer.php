<?php
/**
 * Tools > Export and import: the tab's body and its admin-post.php handlers.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Export and import tab of the Tools screen (RO-44, RO-45, SPEC 13.2).
 *
 * BLT_Toolkit_Admin_Tools draws the page (header, tab bar) and calls
 * render(), which prints the tab's cards inside the page's `.wrap`:
 *
 * - With no upload: the export form (pick roles, download the JSON file)
 *   and the import form (upload a file).
 * - `import=TOKEN`: the preview of an uploaded file (BLT_Toolkit_Role_Transfer
 *   ::preview()) with an action per role, the role details and separate
 *   permissions to bring along, and the Import button.
 *
 * Every write is a POST routed by BLT_Toolkit_Admin::route_post() (method,
 * `blt_reset_roles`, nonce) to a static handler here, which redirects back
 * with a one-time notice (post / redirect / get, SEC-7). The export is the
 * one handler that answers with a file instead of a redirect. Rendering
 * writes nothing (RO-5); the import itself runs through
 * BLT_Toolkit_Role_Transfer::import() (Guard, snapshot, one write, log).
 *
 * The tab belongs to the Roles module: while it is off the tab says so,
 * loads none of the module's classes and every handler refuses.
 */
class BLT_Toolkit_Admin_Transfer {

	/**
	 * The tab's key on the Tools screen.
	 */
	const TAB = 'transfer';

	/**
	 * admin-post.php actions (BLT_Toolkit_Admin::post_actions()).
	 */
	const EXPORT_ACTION  = 'blt_toolkit_roles_export';
	const UPLOAD_ACTION  = 'blt_toolkit_roles_import_upload';
	const IMPORT_ACTION  = 'blt_toolkit_roles_import';
	const DISCARD_ACTION = 'blt_toolkit_roles_import_discard';

	/**
	 * The upload's file field.
	 */
	const FILE_FIELD = 'blt_toolkit_roles_file';

	/**
	 * Query argument and field naming a stored upload.
	 */
	const TOKEN_ARG = 'import';

	/**
	 * The roles the export form preselects are those WordPress does not
	 * create, the usual blueprint.
	 */
	const BUILTIN = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	// ---------- URLs ----------

	/**
	 * The tab's URL, with optional query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return BLT_Toolkit_Admin::page_url( 'tools', array_merge( array( 'tab' => self::TAB ), $args ) );
	}

	// ---------- Rendering ----------

	/**
	 * Print the tab's body. Called by BLT_Toolkit_Admin_Tools inside the
	 * page's `.wrap`, after the tab bar. Writes nothing.
	 *
	 * @return void
	 */
	public static function render() {
		// First, so a handler's refusal shows while the module is off too.
		BLT_Toolkit_Admin_Role_Lifecycle::print_notice();

		if ( ! BLT_Toolkit_Plugin::is_active( 'roles' ) ) {
			BLT_Toolkit_Admin::module_callout( 'roles' );
			return;
		}

		$user_id = get_current_user_id();
		$token   = self::url_token();
		$data    = '' !== $token ? BLT_Toolkit_Role_Transfer::stored( $user_id, $token ) : null;

		if ( null !== $data ) {
			self::view( 'tools-transfer-preview', self::preview_data( $data ) );
			return;
		}

		self::view(
			'tools-transfer',
			array(
				'expired'    => '' !== $token,
				'roles'      => self::export_rows(),
				'max_upload' => self::max_upload(),
			)
		);
	}

	/**
	 * Include a view with its data as variables.
	 *
	 * @param string $view View file name without `.php`.
	 * @param array  $data Variables.
	 * @return void
	 */
	private static function view( $view, array $data ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, documented at the top of each view.
		extract( $data, EXTR_SKIP );

		require BLT_TOOLKIT_DIR . 'admin/views/' . $view . '.php';
	}

	/**
	 * The roles the export form lists: ID, name, how many capabilities each
	 * grants and denies, and whether it is preselected.
	 *
	 * @return array[]
	 */
	private static function export_rows() {
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$meta = is_array( $meta ) ? $meta : array();
		$out  = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$id   = (string) $id;
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();

			$out[] = array(
				'id'       => $id,
				'name'     => translate_user_role( isset( $role['name'] ) && is_scalar( $role['name'] ) ? (string) $role['name'] : $id ),
				'granted'  => count( array_filter( $caps ) ),
				'denied'   => count( $caps ) - count( array_filter( $caps ) ),
				'details'  => isset( $meta[ $id ] ),
				'selected' => ! in_array( $id, self::BUILTIN, true ),
			);
		}

		return $out;
	}

	/**
	 * Everything the preview view shows for a stored upload.
	 *
	 * @param array $data BLT_Toolkit_Role_Transfer::stored().
	 * @return array
	 */
	private static function preview_data( array $data ) {
		$choices = isset( $data['choices'] ) && is_array( $data['choices'] ) ? $data['choices'] : array();
		$preview = BLT_Toolkit_Role_Transfer::preview( $data['doc'], wp_get_current_user(), $choices );

		foreach ( $preview['roles'] as $id => $row ) {
			$preview['roles'][ $id ]['cap_rows'] = self::cap_rows( $row );
		}

		return array(
			'token'       => (string) $data['token'],
			'file'        => isset( $data['file'] ) ? (string) $data['file'] : '',
			'site'        => (string) $data['doc']['site'],
			'exported_at' => self::exported_at( (string) $data['doc']['exported_at'] ),
			'preview'     => $preview,
			'labels'      => self::action_labels(),
			'back_url'    => self::url(),
		);
	}

	/**
	 * A role's capabilities as the preview lists them, grouped: what the
	 * file has that this role lacks (or, for a new role, everything), what
	 * this role has that the file lacks, and what the two set differently.
	 * Each row: cap, label, value (or from and to), tier, `unregistered`
	 * and `pending` (the type whose separate permissions would register it).
	 *
	 * @param array $row A preview role.
	 * @return array{added:array[],removed:array[],flipped:array[]}
	 */
	private static function cap_rows( array $row ) {
		$unregistered = array_fill_keys( $row['unregistered'], true );
		$pending      = $row['pending'];
		$out          = array(
			'added'   => array(),
			'removed' => array(),
			'flipped' => array(),
		);

		foreach ( array( 'added', 'removed', 'flipped' ) as $group ) {
			foreach ( $row[ $group ] as $cap => $value ) {
				$cap = (string) $cap;

				$out[ $group ][] = array(
					'cap'          => $cap,
					'label'        => BLT_Toolkit_Capability_Catalog::label( $cap ),
					'value'        => is_array( $value ) ? ! empty( $value['to'] ) : (bool) $value,
					'tier'         => BLT_Toolkit_Caps::tier( $cap ),
					'unregistered' => isset( $unregistered[ $cap ] ),
					'pending'      => isset( $pending[ $cap ] ) ? (string) $pending[ $cap ] : '',
				);
			}
		}

		return $out;
	}

	/**
	 * The file's export time in the site's timezone, or what it says when
	 * it is not a date.
	 *
	 * @param string $value `exported_at` from the file.
	 * @return string
	 */
	private static function exported_at( $value ) {
		$time = '' !== $value ? strtotime( $value ) : false;

		if ( false === $time ) {
			return $value;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * The actions' names.
	 *
	 * @return array<string,string>
	 */
	public static function action_labels() {
		return array(
			BLT_Toolkit_Role_Transfer::ACTION_CREATE  => __( 'Create', 'blt-toolkit' ),
			BLT_Toolkit_Role_Transfer::ACTION_MERGE   => __( 'Merge capabilities', 'blt-toolkit' ),
			BLT_Toolkit_Role_Transfer::ACTION_REPLACE => __( 'Replace capabilities', 'blt-toolkit' ),
			BLT_Toolkit_Role_Transfer::ACTION_SKIP    => __( 'Skip', 'blt-toolkit' ),
		);
	}

	/**
	 * The upload token in the URL, or ''.
	 *
	 * @return string
	 */
	private static function url_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses what to display; the token is compared with the person's own stored upload.
		$token = isset( $_GET[ self::TOKEN_ARG ] ) && is_string( $_GET[ self::TOKEN_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::TOKEN_ARG ] ) ) : '';

		return 1 === preg_match( '/^[a-f0-9]{16,64}$/', $token ) ? $token : '';
	}

	/**
	 * The largest file accepted, in bytes: the smaller of what this server
	 * receives and the format's own limit.
	 *
	 * @return int
	 */
	public static function max_upload() {
		$php = (int) wp_max_upload_size();

		return $php > 0 ? min( $php, BLT_Toolkit_Role_Transfer::MAX_BYTES ) : BLT_Toolkit_Role_Transfer::MAX_BYTES;
	}

	// ---------- POST handlers ----------

	/**
	 * POST: download the selected roles as a JSON file (RO-44). Streams the
	 * file and stops; an empty selection redirects back.
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::require_module();

		$ids   = array();
		$roles = (array) wp_roles()->roles;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in BLT_Toolkit_Admin::route_post(); each value is checked against the role IDs below.
		$posted = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();

		foreach ( $posted as $id ) {
			$id = is_string( $id ) ? $id : '';

			if ( '' !== $id && isset( $roles[ $id ] ) ) {
				$ids[] = $id;
			}
		}

		if ( array() === $ids ) {
			self::back( array(), 'error', __( 'Nothing was exported: choose at least one role.', 'blt-toolkit' ) );
		}

		$doc  = BLT_Toolkit_Role_Transfer::export_document( $ids );
		$json = BLT_Toolkit_Role_Transfer::encode( $doc );

		if ( '' === $json ) {
			self::back( array(), 'error', __( 'Nothing was exported: the roles could not be written as a file.', 'blt-toolkit' ) );
		}

		$filename = BLT_Toolkit_Role_Transfer::filename( BLT_Toolkit_Role_Transfer::site_label(), wp_date( 'Y-m-d' ) );

		BLT_Toolkit_Role_Transfer::log_export( $doc, $filename );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $json ) );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON file download, not HTML.
		exit;
	}

	/**
	 * Before the nonce check of the upload: when PHP threw the body away
	 * (over `post_max_size`), say why instead of failing the nonce. Returns
	 * otherwise. Nothing is written either way.
	 *
	 * @return void
	 */
	public static function handle_dropped() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only checks that the body is empty; nothing in it is used.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
		$limit  = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		if ( $limit > 0 && $length > $limit ) {
			self::back( array(), 'error', self::too_large_message() );
		}
	}

	/**
	 * POST: check an uploaded file and keep it for its preview (RO-45).
	 * Redirects to the preview, or back with the reason the file was
	 * refused.
	 *
	 * @return void
	 */
	public static function handle_upload() {
		self::require_module();

		$file = self::read_upload();

		if ( is_wp_error( $file ) ) {
			self::back( array(), 'error', $file->get_error_message() );
		}

		$doc = BLT_Toolkit_Role_Transfer::parse( $file['bytes'] );

		if ( is_wp_error( $doc ) ) {
			self::back(
				array(),
				'error',
				sprintf(
					/* translators: 1: file name, 2: the reason. */
					__( 'Nothing was imported from %1$s: %2$s', 'blt-toolkit' ),
					'' !== $file['name'] ? $file['name'] : __( 'the file', 'blt-toolkit' ),
					$doc->get_error_message()
				)
			);
		}

		$token = BLT_Toolkit_Role_Transfer::store( get_current_user_id(), $doc, $file['name'] );

		if ( false === $token ) {
			self::back( array(), 'error', __( 'The file was read, but it could not be kept for the preview. Try again.', 'blt-toolkit' ) );
		}

		wp_safe_redirect( self::url( array( self::TOKEN_ARG => $token ) ) );
		exit;
	}

	/**
	 * POST: import a previewed file (RO-45). Redirects back to the preview
	 * with the reason when anything is refused, or to the tab with what was
	 * imported.
	 *
	 * @return void
	 */
	public static function handle_import() {
		self::require_module();

		$user_id = get_current_user_id();
		$token   = sanitize_key( self::posted( self::TOKEN_ARG ) );
		$data    = BLT_Toolkit_Role_Transfer::stored( $user_id, $token );

		if ( null === $data ) {
			self::back( array(), 'error', __( 'Nothing was imported: that preview is no longer available. It expires after an hour, or when you upload another file. Upload the file again.', 'blt-toolkit' ) );
		}

		$choices = self::posted_choices( $data['doc'] );

		BLT_Toolkit_Role_Transfer::remember_choices(
			$user_id,
			$data,
			array(
				'actions' => $choices['actions'],
				'meta'    => $choices['meta'],
				'types'   => $choices['types'],
			)
		);

		$choices['file'] = isset( $data['file'] ) ? (string) $data['file'] : '';

		$result  = BLT_Toolkit_Role_Transfer::import( $data['doc'], $choices, wp_get_current_user() );
		$preview = array( self::TOKEN_ARG => $token );

		if ( is_wp_error( $result ) ) {
			$code  = $result->get_error_code();
			$error = (array) $result->get_error_data();
			$items = array();

			if ( 'blt_toolkit_critical_review' === $code && isset( $error['roles'] ) && is_array( $error['roles'] ) ) {
				foreach ( $error['roles'] as $id => $entry ) {
					$items[] = sprintf(
						/* translators: 1: role name, 2: role ID, 3: a list of capabilities. */
						__( '%1$s (%2$s): %3$s', 'blt-toolkit' ),
						$entry['name'],
						$id,
						implode( wp_get_list_item_separator(), $entry['caps'] )
					);
				}

				self::back( $preview, 'error', $result->get_error_message(), $items );
			}

			if ( 'blt_toolkit_import_changed' === $code || 'blt_toolkit_import_nothing' === $code ) {
				self::back( $preview, 'warning', $result->get_error_message() );
			}

			$isolated = isset( $error['isolated'] ) && is_array( $error['isolated'] ) ? $error['isolated'] : array();

			if ( array() !== $isolated ) {
				/* translators: %s: a list of post type keys. */
				$items[] = sprintf( __( 'Separate permissions were already given to: %s. They stay; see the Activity screen.', 'blt-toolkit' ), implode( wp_get_list_item_separator(), $isolated ) );

				self::back(
					$preview,
					'error',
					sprintf(
						/* translators: %s: the reason. */
						__( 'The roles were not imported: %s', 'blt-toolkit' ),
						$result->get_error_message()
					),
					$items
				);
			}

			self::back(
				$preview,
				'error',
				sprintf(
					/* translators: %s: the reason. */
					__( 'Nothing was imported: %s', 'blt-toolkit' ),
					$result->get_error_message()
				)
			);
		}

		BLT_Toolkit_Role_Transfer::discard( $user_id );

		self::back( array(), array() === $result['user_errors'] ? 'success' : 'warning', self::success_message( $result ), self::success_lines( $result ) );
	}

	/**
	 * POST: forget an uploaded file without importing it.
	 *
	 * @return void
	 */
	public static function handle_discard() {
		BLT_Toolkit_Role_Transfer::discard( get_current_user_id() );

		self::back( array(), 'success', __( 'The uploaded file was discarded. Nothing was imported.', 'blt-toolkit' ) );
	}

	// ---------- Handler helpers ----------

	/**
	 * Refuse when the Roles module is not running.
	 *
	 * @return void
	 */
	private static function require_module() {
		if ( ! BLT_Toolkit_Plugin::is_active( 'roles' ) ) {
			self::back( array(), 'error', __( 'Nothing was changed: the Roles module is not running.', 'blt-toolkit' ) );
		}
	}

	/**
	 * The uploaded file's contents and name, or why it cannot be read.
	 *
	 * @return array{bytes:string,name:string}|WP_Error
	 */
	private static function read_upload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in BLT_Toolkit_Admin::route_post(); the upload's fields are checked one by one below.
		$upload = isset( $_FILES[ self::FILE_FIELD ] ) && is_array( $_FILES[ self::FILE_FIELD ] ) ? $_FILES[ self::FILE_FIELD ] : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$error = is_array( $upload ) && isset( $upload['error'] ) && is_numeric( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new WP_Error( 'blt_toolkit_import_no_file', __( 'Nothing was imported: choose a roles file (.json) to upload.', 'blt-toolkit' ) );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new WP_Error( 'blt_toolkit_import_too_large', self::too_large_message() );
		}

		$tmp  = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
		$size = isset( $upload['size'] ) && is_numeric( $upload['size'] ) ? (int) $upload['size'] : 0;
		$name = isset( $upload['name'] ) && is_string( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';

		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'blt_toolkit_import_upload', __( 'Nothing was imported: the file could not be uploaded. Try again.', 'blt-toolkit' ) );
		}

		if ( $size > self::max_upload() ) {
			return new WP_Error( 'blt_toolkit_import_too_large', self::too_large_message() );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's temporary copy of the upload, checked with is_uploaded_file(); read once, parsed, never written anywhere as is.
		$bytes = file_get_contents( $tmp );

		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'blt_toolkit_import_upload', __( 'Nothing was imported: the file could not be uploaded. Try again.', 'blt-toolkit' ) );
		}

		if ( strlen( $bytes ) > self::max_upload() ) {
			return new WP_Error( 'blt_toolkit_import_too_large', self::too_large_message() );
		}

		return array(
			'bytes' => $bytes,
			'name'  => $name,
		);
	}

	/**
	 * The message for a file over the size limit.
	 *
	 * @return string
	 */
	private static function too_large_message() {
		return sprintf(
			/* translators: %s: the largest file accepted, such as 1 MB. */
			__( 'Nothing was imported: the file is too large. A roles file can be at most %s.', 'blt-toolkit' ),
			size_format( self::max_upload() )
		);
	}

	/**
	 * The import form's choices, checked against the stored file: an
	 * action per role of the file, whether to bring role details, which
	 * content types get separate permissions, each typed role name (SEC-10)
	 * and the preview's lock token.
	 *
	 * @param array $doc The stored file.
	 * @return array{actions:array<string,string>,meta:bool,types:string[],confirm:array<string,string>,hash:string}
	 */
	private static function posted_choices( array $doc ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in BLT_Toolkit_Admin::route_post(); every value is checked against the stored file or sanitized below.
		$actions = isset( $_POST['actions'] ) && is_array( $_POST['actions'] ) ? wp_unslash( $_POST['actions'] ) : array();
		$confirm = isset( $_POST['confirm'] ) && is_array( $_POST['confirm'] ) ? wp_unslash( $_POST['confirm'] ) : array();
		$types   = isset( $_POST['types'] ) && is_array( $_POST['types'] ) ? wp_unslash( $_POST['types'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$out = array(
			'actions' => array(),
			'meta'    => '1' === self::posted( 'import_meta' ),
			'types'   => array(),
			'confirm' => array(),
			'hash'    => strtolower( self::posted( 'hash' ) ),
		);

		foreach ( array_keys( isset( $doc['roles'] ) ? (array) $doc['roles'] : array() ) as $id ) {
			$id = (string) $id;

			if ( isset( $actions[ $id ] ) && is_string( $actions[ $id ] ) ) {
				$out['actions'][ $id ] = sanitize_key( $actions[ $id ] );
			}

			if ( isset( $confirm[ $id ] ) && is_string( $confirm[ $id ] ) ) {
				$out['confirm'][ $id ] = sanitize_text_field( $confirm[ $id ] );
			}
		}

		$known = isset( $doc['isolated_post_types'] ) ? (array) $doc['isolated_post_types'] : array();

		foreach ( $types as $type ) {
			$type = is_string( $type ) ? sanitize_key( $type ) : '';

			if ( '' !== $type && isset( $known[ $type ] ) && ! in_array( $type, $out['types'], true ) ) {
				$out['types'][] = $type;
			}
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $out['hash'] ) ) {
			$out['hash'] = '';
		}

		return $out;
	}

	/**
	 * One posted field, unslashed and sanitized as text, or ''.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function posted( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in BLT_Toolkit_Admin::route_post().
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * The success notice's message.
	 *
	 * @param array $result BLT_Toolkit_Role_Transfer::import().
	 * @return string
	 */
	private static function success_message( array $result ) {
		if ( $result['snapshot_id'] > 0 ) {
			return __( 'The roles were imported. A copy of every role was saved first, so this can be undone from Snapshots.', 'blt-toolkit' );
		}

		return __( 'The import is done.', 'blt-toolkit' );
	}

	/**
	 * The success notice's lines: what happened to each role, the types
	 * given separate permissions, role details, and users the separate
	 * permissions could not be given to.
	 *
	 * @param array $result BLT_Toolkit_Role_Transfer::import().
	 * @return string[]
	 */
	private static function success_lines( array $result ) {
		$lines = array();
		$parts = array(
			/* translators: %s: a list of role IDs. */
			'created'  => __( 'Created: %s', 'blt-toolkit' ),
			/* translators: %s: a list of role IDs. */
			'merged'   => __( 'Capabilities merged into: %s', 'blt-toolkit' ),
			/* translators: %s: a list of role IDs. */
			'replaced' => __( 'Capabilities replaced: %s', 'blt-toolkit' ),
			/* translators: %s: a list of role IDs. */
			'skipped'  => __( 'Skipped: %s', 'blt-toolkit' ),
			/* translators: %s: a list of post type keys. */
			'types'    => __( 'Separate permissions given to: %s', 'blt-toolkit' ),
			/* translators: %s: a list of role IDs. */
			'meta'     => __( 'Role details imported for: %s', 'blt-toolkit' ),
		);

		foreach ( $parts as $key => $format ) {
			if ( ! empty( $result[ $key ] ) ) {
				$lines[] = sprintf( $format, implode( wp_get_list_item_separator(), (array) $result[ $key ] ) );
			}
		}

		foreach ( (array) $result['user_errors'] as $login => $message ) {
			/* translators: 1: username, 2: why their capabilities could not be changed. */
			$lines[] = sprintf( __( 'Separate permissions could not be given to %1$s: %2$s', 'blt-toolkit' ), $login, $message );
		}

		if ( $result['unregistered'] > 0 ) {
			/* translators: %s: number of capabilities. */
			$lines[] = sprintf( _n( '%s capability in the file is not registered on this site; it does nothing until a plugin or content type here uses it.', '%s capabilities in the file are not registered on this site; they do nothing until a plugin or content type here uses them.', (int) $result['unregistered'], 'blt-toolkit' ), number_format_i18n( (int) $result['unregistered'] ) );
		}

		return $lines;
	}

	/**
	 * Store the one-time notice (the role lifecycle's, which the Roles and
	 * Tools screens print) and redirect to the tab. Stops.
	 *
	 * @param array  $args    Query arguments.
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 * @param array  $items   Optional list under the message.
	 * @return void
	 */
	private static function back( array $args, $type, $message, array $items = array() ) {
		$token = strtolower( wp_generate_password( 12, false ) );

		set_transient(
			BLT_Toolkit_Admin_Role_Lifecycle::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'token'   => $token,
				'type'    => $type,
				'message' => (string) $message,
				'items'   => array_values( array_map( 'strval', $items ) ),
			),
			10 * MINUTE_IN_SECONDS
		);

		$args[ BLT_Toolkit_Admin_Role_Lifecycle::NOTICE_ARG ] = $token;

		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
