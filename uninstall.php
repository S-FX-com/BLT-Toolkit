<?php
/**
 * Uninstall cleanup for BLT Toolkit (SPEC 14.6).
 *
 * Data deletion is OPT-IN (Settings > Advanced > "Delete all plugin data on uninstall").
 * By default nothing is deleted, so removing and reinstalling the plugin
 * loses nothing.
 *
 * With it on, this removes what BLT Toolkit itself created: its two tables,
 * options, transients, user meta, job state, scheduled events (WP-Cron and
 * Action Scheduler), update-checker state, and its own capabilities and
 * read-only markers from every role and user. It never restores a
 * snapshot, never reverts the owner's role edits, never deletes a role, and
 * never touches another BLT plugin's `blt_*` capabilities or the shared BLT
 * family store (`blt_family_shared`, `blt_family_opt_in`), which other BLT
 * plugins may still read.
 *
 * WordPress runs this file without loading the plugin, so it is
 * self-contained.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$blt_toolkit_settings = get_option( 'blt_toolkit_settings', array() );
$blt_toolkit_delete   = false;

if ( is_array( $blt_toolkit_settings ) && isset( $blt_toolkit_settings['advanced'] ) && is_array( $blt_toolkit_settings['advanced'] ) && isset( $blt_toolkit_settings['advanced']['delete_data_on_uninstall'] ) ) {
	$blt_toolkit_delete = $blt_toolkit_settings['advanced']['delete_data_on_uninstall'];
}

// The same reading as BLT_Toolkit_Settings::to_bool(), so this agrees with
// what Settings > Advanced shows: only true, a non-zero number or the strings
// 1, true, on and yes mean "on". A value stored outside the Settings screen,
// such as the string "false" from `wp option patch`, deletes nothing.
if ( is_string( $blt_toolkit_delete ) ) {
	$blt_toolkit_delete = in_array( strtolower( trim( $blt_toolkit_delete ) ), array( '1', 'true', 'on', 'yes' ), true );
} elseif ( is_int( $blt_toolkit_delete ) || is_float( $blt_toolkit_delete ) ) {
	$blt_toolkit_delete = 0 != $blt_toolkit_delete; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 1 and 1.0 alike.
}

if ( true !== $blt_toolkit_delete ) {
	return;
}

global $wpdb;

// Tables.
foreach ( array( 'blt_toolkit_log', 'blt_toolkit_snapshots' ) as $blt_toolkit_table ) {
	$blt_toolkit_table = $wpdb->prefix . $blt_toolkit_table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is built from the prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$blt_toolkit_table}" );
}

// Options, including every job's state. `blt_toolkit_isolation_state` holds
// the RS-33 flag and the reverted types awaiting the RS-32 cleanup;
// `blt_toolkit_legacy_lock` exists only while a batch of Username Changer's
// audit log is copied (SPEC 15), unless a fatal error cut that batch short.
$blt_toolkit_options = array( 'blt_toolkit_settings', 'blt_toolkit_db_version', 'blt_toolkit_secrets', 'blt_toolkit_legacy_imports', 'blt_toolkit_legacy_lock', 'blt_toolkit_log_failure', 'blt_toolkit_isolation_state' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off lookup during uninstall.
$blt_toolkit_jobs = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'blt_toolkit_job_' ) . '%' ) );

foreach ( array_merge( $blt_toolkit_options, (array) $blt_toolkit_jobs ) as $blt_toolkit_option ) {
	delete_option( $blt_toolkit_option );
}

foreach ( array( 'blt_toolkit_no_role_count', 'blt_toolkit_recovery_in_code', 'blt_toolkit_log_menus' ) as $blt_toolkit_transient ) {
	delete_transient( $blt_toolkit_transient );
}

// Per-user transients, named with a user ID: the bulk rename's dry run
// (UN-30, an hour), the profile's rename notice (UN-18) and the username
// form's result (UN-23), five minutes each; the Roles screen's pending review
// or refused save (an hour), its one-time result notice and the role
// lifecycle's (ten minutes), the Users list's bulk role result (US-3,
// five minutes), the Tools screen's one-time result notice (ten minutes) and
// a role import's stored upload (RO-45, an hour, compressed). Found by
// prefix in the options table, with their timeouts; on a site with a
// persistent object cache they live there instead and expire on their own.
$blt_toolkit_prefixes = array(
	'blt_toolkit_bulk_plan_',
	'blt_toolkit_username_notice_',
	'blt_toolkit_username_form_',
	'blt_toolkit_roles_pending_',
	'blt_toolkit_lifecycle_notice_',
	'blt_toolkit_bulk_roles_notice_',
	'blt_toolkit_tools_notice_',
	'blt_toolkit_import_',
);

foreach ( $blt_toolkit_prefixes as $blt_toolkit_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_' ) as $blt_toolkit_kind ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off lookup during uninstall.
		$blt_toolkit_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $blt_toolkit_kind . $blt_toolkit_prefix ) . '%' ) );

		foreach ( (array) $blt_toolkit_names as $blt_toolkit_option ) {
			delete_option( $blt_toolkit_option );
		}
	}
}

// The plugin's own capabilities (SPEC 5.1) and every read-only marker. Other
// BLT plugins' blt_* capabilities (blt_view_activity_log, say) stay.
$blt_toolkit_caps = array(
	'blt_switch_users',
	'blt_change_usernames',
	'blt_change_own_username',
	'blt_edit_roles',
	'blt_edit_capabilities',
	'blt_create_roles',
	'blt_delete_roles',
	'blt_reset_roles',
	'blt_view_toolkit_log',
);

/**
 * Whether a capability key belongs to BLT Toolkit.
 *
 * @param string $cap Capability key.
 * @return bool
 */
$blt_toolkit_owns_cap = static function ( $cap ) use ( $blt_toolkit_caps ) {
	$cap = (string) $cap;

	return in_array( $cap, $blt_toolkit_caps, true ) || 0 === strpos( $cap, 'blt_readonly_' );
};

// Roles: one write to the roles option for every role (the RO-3 technique).
$blt_toolkit_roles   = wp_roles();
$blt_toolkit_changed = false;
$blt_toolkit_use_db  = $blt_toolkit_roles->use_db;

$blt_toolkit_roles->use_db = false;

foreach ( $blt_toolkit_roles->role_objects as $blt_toolkit_role ) {
	foreach ( array_keys( (array) $blt_toolkit_role->capabilities ) as $blt_toolkit_cap ) {
		if ( $blt_toolkit_owns_cap( $blt_toolkit_cap ) ) {
			$blt_toolkit_role->remove_cap( $blt_toolkit_cap );
			$blt_toolkit_changed = true;
		}
	}
}

$blt_toolkit_roles->use_db = $blt_toolkit_use_db;

if ( $blt_toolkit_changed && $blt_toolkit_use_db ) {
	update_option( $blt_toolkit_roles->role_key, $blt_toolkit_roles->roles );
}

// User-level data. User meta is shared by every site of a network, so it is
// left alone there (the plugin never runs on multisite in any case).
if ( ! is_multisite() ) {
	foreach ( array( 'blt_toolkit_editor_mode', 'blt_toolkit_columns', 'blt_toolkit_previous_nicenames', 'blt_toolkit_username_changed_at' ) as $blt_toolkit_meta_key ) {
		delete_metadata( 'user', 0, $blt_toolkit_meta_key, '', true );
	}

	// Per-user grants and markers live in the {prefix}capabilities meta. Only
	// rows that mention a blt_ key are read, then filtered precisely.
	$blt_toolkit_caps_key = $wpdb->get_blog_prefix() . 'capabilities';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off scan during uninstall.
	$blt_toolkit_rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $blt_toolkit_caps_key, '%' . $wpdb->esc_like( 'blt_' ) . '%' ) );

	foreach ( (array) $blt_toolkit_rows as $blt_toolkit_row ) {
		$blt_toolkit_user_caps = maybe_unserialize( $blt_toolkit_row->meta_value );

		if ( ! is_array( $blt_toolkit_user_caps ) ) {
			continue;
		}

		$blt_toolkit_kept = array();

		foreach ( $blt_toolkit_user_caps as $blt_toolkit_cap => $blt_toolkit_grant ) {
			if ( ! $blt_toolkit_owns_cap( $blt_toolkit_cap ) ) {
				$blt_toolkit_kept[ $blt_toolkit_cap ] = $blt_toolkit_grant;
			}
		}

		if ( count( $blt_toolkit_kept ) !== count( $blt_toolkit_user_caps ) ) {
			update_user_meta( (int) $blt_toolkit_row->user_id, $blt_toolkit_caps_key, $blt_toolkit_kept );
			clean_user_cache( (int) $blt_toolkit_row->user_id );
		}
	}
}

// Scheduled events: every hook of this plugin, with any arguments.
$blt_toolkit_crons = _get_cron_array();

if ( is_array( $blt_toolkit_crons ) ) {
	$blt_toolkit_hooks = array();

	foreach ( $blt_toolkit_crons as $blt_toolkit_events ) {
		foreach ( array_keys( (array) $blt_toolkit_events ) as $blt_toolkit_hook ) {
			if ( 0 === strpos( (string) $blt_toolkit_hook, 'blt_toolkit_' ) ) {
				$blt_toolkit_hooks[ $blt_toolkit_hook ] = true;
			}
		}
	}

	foreach ( array_keys( $blt_toolkit_hooks ) as $blt_toolkit_hook ) {
		wp_unschedule_hook( $blt_toolkit_hook );
	}
}

// Job ticks queued with Action Scheduler (JOB-1), on sites that have it. The
// plugin that ships Action Scheduler is loaded during an uninstall request.
// By hook alone: with a group and empty arguments, Action Scheduler would
// cancel only actions without arguments, and every tick carries its job ID.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'blt_toolkit_job_as_tick' );
}

// plugin-update-checker state.
delete_option( 'external_updates-blt-toolkit' );
wp_clear_scheduled_hook( 'puc_cron_check_updates-blt-toolkit' );
