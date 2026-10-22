<?php
/**
 * Settings > Roles (SPEC 9 and 10, settings group `roles`).
 *
 * `extra_default_roles`, `role_meta` and `isolated_post_types` are not on
 * this form: the features that own them write them through
 * BLT_Toolkit_Settings::set().
 *
 * Included inside the settings form by views/settings.php.
 *
 * @package Blt_Toolkit
 *
 * @var array  $settings BLT_Toolkit_Settings::all().
 * @var string $field    Name of the settings field array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$roles_settings = $settings['roles'];
$views          = array(
	'simple'   => __( 'Simple', 'blt-toolkit' ),
	'advanced' => __( 'Advanced', 'blt-toolkit' ),
);
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Role editor', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Simple and Advanced are two views of the same roles. Simple shows one access level per content type; Advanced shows every capability.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-default-view"><?php esc_html_e( 'Opens in', 'blt-toolkit' ); ?></label></div>
			<div>
				<select id="blt-toolkit-default-view" name="<?php echo esc_attr( $field ); ?>[default_view]">
					<?php foreach ( $views as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $roles_settings['default_view'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="blt-field-desc"><?php esc_html_e( 'The view the Roles screen opens in for someone who has not picked one yet. Each person\'s choice is remembered. People who cannot use Advanced view always see Simple.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Advanced view', 'blt-toolkit' ); ?></div>
			<div class="blt-toggle-stack">
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[readable_labels]',
					$roles_settings['readable_labels'],
					__( 'Readable capability names', 'blt-toolkit' ),
					__( 'Shows "Edit others\' posts" rather than edit_others_posts. The raw name is always shown beside it.', 'blt-toolkit' )
				);
				BLT_Toolkit_Admin::toggle(
					$field . '[show_deprecated]',
					$roles_settings['show_deprecated'],
					__( 'Show deprecated capabilities', 'blt-toolkit' ),
					__( 'Also lists capabilities WordPress no longer uses, such as level_0 to level_10.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Protection', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'People who are not administrators can never edit, delete, rename or switch into an administrator, or change the roles of someone with more access than they have, whatever is set here.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php
		BLT_Toolkit_Admin::toggle(
			$field . '[hide_admins]',
			$roles_settings['hide_admins'],
			__( 'Hide administrators from everyone else', 'blt-toolkit' ),
			__( 'People who are not administrators do not see administrator accounts in the Users list, its counts, or the users REST API. It keeps admin accounts out of view of delegated user managers.', 'blt-toolkit' )
		);
		?>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Snapshots', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'A copy of every role is saved before each change, so any change can be undone from Tools.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-snapshot-retention"><?php esc_html_e( 'Snapshots to keep', 'blt-toolkit' ); ?></label></div>
			<div>
				<?php BLT_Toolkit_Admin_Settings::number( 'roles', 'snapshot_retention', $roles_settings['snapshot_retention'], 'blt-toolkit-snapshot-retention' ); ?>
				<p class="blt-field-desc"><?php esc_html_e( 'From 1 to 500. The newest ones are kept, plus the baseline taken when BLT Toolkit was first activated, which is never removed.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
	</div>
	<?php BLT_Toolkit_Admin_Settings::later( __( 'Roles every new user receives on top of the default role, and a description and hidden toolbar per role, set from the Roles screen.', 'blt-toolkit' ) ); ?>
</div>
