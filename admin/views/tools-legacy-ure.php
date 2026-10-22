<?php
/**
 * Tools > Import from legacy plugins > User Role Editor: the dry run of the
 * import (SPEC 15), a toggle per item and the Import button, then the dry
 * run of removing its `ure_*` capabilities with its own button.
 *
 * Nothing here writes; User Role Editor's options are only read.
 *
 * Included by BLT_Toolkit_Admin_Legacy::render() inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var array  $plan          BLT_Toolkit_Legacy::ure_plan().
 * @var array  $history       Item => "Imported ... by ...".
 * @var array  $choices       Item => array( 'available', 'checked' ).
 * @var string $snapshot_url  The stored backup's snapshot page, or ''.
 * @var array  $role_names    Role ID => name.
 * @var string $list_url      The tab's first page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_backup = $plan['backup'];
$blt_tk_caps   = $plan['caps'];
$blt_tk_any    = in_array( true, array_column( $choices, 'available' ), true );
$blt_tk_groups = array(
	'defaults' => array(
		'add'     => __( 'Added', 'blt-toolkit' ),
		'already' => __( 'Already there', 'blt-toolkit' ),
		'refused' => __( 'Roles you may not give, skipped', 'blt-toolkit' ),
		'missing' => __( 'Not roles on this site, skipped', 'blt-toolkit' ),
	),
	'toolbar'  => array(
		'add'     => __( 'Hidden after the import', 'blt-toolkit' ),
		'already' => __( 'Already hidden', 'blt-toolkit' ),
		'refused' => __( 'Roles you may not edit, skipped', 'blt-toolkit' ),
		'missing' => __( 'Not roles on this site, skipped', 'blt-toolkit' ),
	),
);
?>
<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All legacy plugins', 'blt-toolkit' ); ?></a></p>

<?php if ( $plan['active'] ) : ?>
	<div class="blt-callout">
		<strong><?php esc_html_e( 'User Role Editor is active', 'blt-toolkit' ); ?></strong>
		<span><?php esc_html_e( 'BLT Toolkit\'s Roles module is not running while it is. Import, then deactivate User Role Editor. Import before you delete it: deleting it removes these settings and its backup.', 'blt-toolkit' ); ?></span>
	</div>
<?php endif; ?>

<?php if ( ! $plan['found'] && array() === $blt_tk_caps['caps'] ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'Nothing from User Role Editor on this site', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'Its settings and its backup are not in the database, and no role or user holds its capabilities.', 'blt-toolkit' ); ?></span>
			</div>
		</div>
	</div>
	<?php
	return;
endif;
?>

<?php if ( $plan['found'] ) : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Legacy::URE_ACTION ); ?>
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Legacy::EXPECTED_FIELD ); ?>" value="<?php echo esc_attr( $plan['expected'] ); ?>" />

		<?php
		$blt_tk_sections = array(
			'defaults' => array(
				'item'   => 'ure_default_roles',
				'title'  => __( 'Other default roles', 'blt-toolkit' ),
				'desc'   => __( 'Roles every new user gets on top of WordPress\'s default role. They are added to Settings > Roles; any already set there stay.', 'blt-toolkit' ),
				'empty'  => __( 'User Role Editor gives new users no other roles.', 'blt-toolkit' ),
				'toggle' => __( 'Import the other default roles', 'blt-toolkit' ),
			),
			'toolbar'  => array(
				'item'   => 'ure_toolbar',
				'title'  => __( 'Hidden admin bar', 'blt-toolkit' ),
				'desc'   => __( 'Roles for which User Role Editor hides the admin bar become roles with "Hide the toolbar on the site" on. Their descriptions stay as they are.', 'blt-toolkit' ),
				'empty'  => __( 'User Role Editor hides the admin bar for no role.', 'blt-toolkit' ),
				'toggle' => __( 'Import the hidden admin bar', 'blt-toolkit' ),
			),
		);
		?>

		<?php foreach ( $blt_tk_sections as $blt_tk_key => $blt_tk_section ) : ?>
			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php echo esc_html( $blt_tk_section['title'] ); ?></h2>
					<p><?php echo esc_html( $blt_tk_section['desc'] ); ?></p>
					<?php if ( isset( $history[ $blt_tk_section['item'] ] ) ) : ?>
						<div class="blt-card-header-badges"><?php echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></div>
					<?php endif; ?>
				</div>
				<div class="blt-card-body">
					<?php $blt_tk_shown = false; ?>
					<?php foreach ( $blt_tk_groups[ $blt_tk_key ] as $blt_tk_group => $blt_tk_label ) : ?>
						<?php
						if ( array() === $plan[ $blt_tk_key ][ $blt_tk_group ] ) {
							continue;
						}

						$blt_tk_shown = true;
						?>
						<div class="blt-field">
							<div class="blt-field-label"><?php echo esc_html( $blt_tk_label ); ?></div>
							<div>
								<?php if ( 'refused' === $blt_tk_group && 'toolbar' === $blt_tk_key ) : ?>
									<ul class="blt-toolkit-review-list">
										<?php foreach ( $plan[ $blt_tk_key ][ $blt_tk_group ] as $blt_tk_reason ) : ?>
											<li><?php echo esc_html( $blt_tk_reason ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<span class="blt-chips">
										<?php foreach ( $plan[ $blt_tk_key ][ $blt_tk_group ] as $blt_tk_name ) : ?>
											<code class="blt-chip"><?php echo esc_html( $blt_tk_name ); ?></code>
										<?php endforeach; ?>
									</span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<?php if ( ! $blt_tk_shown ) : ?>
						<div class="blt-empty">
							<span class="blt-empty-title"><?php esc_html_e( 'Nothing to import', 'blt-toolkit' ); ?></span>
							<span><?php echo esc_html( $blt_tk_section['empty'] ); ?></span>
						</div>
					<?php endif; ?>

					<?php if ( isset( $history[ $blt_tk_section['item'] ] ) ) : ?>
						<p class="blt-field-desc"><?php echo esc_html( $history[ $blt_tk_section['item'] ] ); ?></p>
					<?php endif; ?>

					<div class="blt-toggle-stack">
						<?php BLT_Toolkit_Admin_Legacy::item_toggle( $blt_tk_section['item'], $choices[ $blt_tk_section['item'] ], $blt_tk_section['toggle'] ); ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Roles backup', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'User Role Editor saved a copy of the roles when it was first activated. It is stored as a snapshot, which you can view and restore on the Snapshots tab. No role changes now.', 'blt-toolkit' ); ?></p>
				<?php if ( $blt_tk_backup['imported'] > 0 ) : ?>
					<div class="blt-card-header-badges"><?php echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></div>
				<?php endif; ?>
			</div>
			<div class="blt-card-body">
				<?php if ( ! $blt_tk_backup['exists'] ) : ?>
					<div class="blt-empty">
						<span class="blt-empty-title"><?php esc_html_e( 'No backup found', 'blt-toolkit' ); ?></span>
						<span><?php esc_html_e( 'User Role Editor\'s backup is not in the database.', 'blt-toolkit' ); ?></span>
					</div>
				<?php elseif ( ! $blt_tk_backup['valid'] ) : ?>
					<div class="blt-empty">
						<span class="blt-empty-title"><?php esc_html_e( 'The backup cannot be used', 'blt-toolkit' ); ?></span>
						<span><?php esc_html_e( 'It is not a list of roles with their capabilities, so it is not imported.', 'blt-toolkit' ); ?></span>
					</div>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: number of roles. */
							esc_html( _n( 'The backup holds %s role.', 'The backup holds %s roles.', $blt_tk_backup['roles'], 'blt-toolkit' ) ),
							esc_html( number_format_i18n( $blt_tk_backup['roles'] ) )
						);

						if ( is_array( $blt_tk_backup['counts'] ) ) {
							echo ' ';
							printf(
								/* translators: 1: number of roles, 2: number of capabilities. */
								esc_html__( 'Differences from the roles today: roles %1$s, capabilities %2$s.', 'blt-toolkit' ),
								esc_html( number_format_i18n( (int) $blt_tk_backup['counts']['roles'] ) ),
								esc_html( number_format_i18n( (int) $blt_tk_backup['counts']['capabilities'] ) )
							);
						}
						?>
					</p>
					<?php if ( '' !== $snapshot_url ) : ?>
						<p>
							<a href="<?php echo esc_url( $snapshot_url ); ?>">
								<?php
								printf(
									/* translators: %d: snapshot number. */
									esc_html__( 'Stored as snapshot %d', 'blt-toolkit' ),
									(int) $blt_tk_backup['imported']
								);
								?>
							</a>
						</p>
					<?php endif; ?>
					<div class="blt-toggle-stack">
						<?php BLT_Toolkit_Admin_Legacy::item_toggle( 'ure_backup', $choices['ure_backup'], __( 'Store the backup as a snapshot', 'blt-toolkit' ) ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $blt_tk_any ) : ?>
			<div class="blt-settings-footer">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the selected items', 'blt-toolkit' ); ?></button>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
			</div>
		<?php else : ?>
			<p class="blt-field-desc"><?php esc_html_e( 'There is nothing left to import from User Role Editor.', 'blt-toolkit' ); ?></p>
		<?php endif; ?>
	</form>
<?php endif; ?>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'User Role Editor\'s capabilities', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'User Role Editor gives roles its own ure_* capabilities. Nothing uses them once it is gone; the Roles screen lists them as orphaned, "From User Role Editor". Removing them saves a copy of the roles first.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php if ( array() === $blt_tk_caps['caps'] ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'None left', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'No role or user holds a User Role Editor capability.', 'blt-toolkit' ); ?></span>
			</div>
		<?php else : ?>
			<?php if ( '' !== $blt_tk_caps['blocked'] ) : ?>
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Not removed now', 'blt-toolkit' ); ?></strong>
					<span><?php echo esc_html( $blt_tk_caps['blocked'] ); ?></span>
				</div>
			<?php elseif ( is_wp_error( $blt_tk_caps['guard'] ) ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p>
						<?php
						printf(
							/* translators: %s: the reason. */
							esc_html__( 'You cannot remove them: %s', 'blt-toolkit' ),
							esc_html( $blt_tk_caps['guard']->get_error_message() )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php foreach ( $blt_tk_caps['roles'] as $blt_tk_role => $blt_tk_role_caps ) : ?>
				<div class="blt-field">
					<div class="blt-field-label">
						<?php
						printf(
							/* translators: %s: role name. */
							esc_html__( 'Role: %s', 'blt-toolkit' ),
							esc_html( isset( $role_names[ $blt_tk_role ] ) ? $role_names[ $blt_tk_role ] : $blt_tk_role )
						);
						?>
					</div>
					<div>
						<span class="blt-chips">
							<?php foreach ( $blt_tk_role_caps as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</span>
					</div>
				</div>
			<?php endforeach; ?>

			<?php foreach ( $blt_tk_caps['users'] as $blt_tk_user ) : ?>
				<div class="blt-field">
					<div class="blt-field-label">
						<?php
						printf(
							/* translators: %s: username. */
							esc_html__( 'User: %s', 'blt-toolkit' ),
							esc_html( $blt_tk_user['label'] )
						);
						?>
					</div>
					<div>
						<span class="blt-chips">
							<?php foreach ( $blt_tk_user['caps'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</span>
					</div>
				</div>
			<?php endforeach; ?>

			<?php if ( isset( $history['ure_caps'] ) ) : ?>
				<p class="blt-field-desc"><?php echo esc_html( $history['ure_caps'] ); ?></p>
			<?php endif; ?>

			<?php if ( '' === $blt_tk_caps['blocked'] && true === $blt_tk_caps['guard'] ) : ?>
				<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
					<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Legacy::REMOVE_ACTION ); ?>
					<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Legacy::EXPECTED_FIELD ); ?>" value="<?php echo esc_attr( $blt_tk_caps['expected'] ); ?>" />
					<div class="blt-settings-footer">
						<button type="submit" class="button">
							<?php
							printf(
								/* translators: %s: number of capabilities. */
								esc_html( _n( 'Remove %s capability from everyone listed', 'Remove %s capabilities from everyone listed', count( $blt_tk_caps['caps'] ), 'blt-toolkit' ) ),
								esc_html( number_format_i18n( count( $blt_tk_caps['caps'] ) ) )
							);
							?>
						</button>
					</div>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
