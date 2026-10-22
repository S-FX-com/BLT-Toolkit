<?php
/**
 * Tools: what a restore or a reset means for people and content types, shared
 * by the restore preview (SNAP-4) and the reset preview (RO-43).
 *
 * - The roles the write deletes, each with how many people hold it, and how
 *   many people are left with no role at all (one grouped count,
 *   BLT_Toolkit_Snapshots::removal_impact()), with where to find them
 *   afterwards (US-12's No role view).
 * - The default role for new users, and additional default roles (US-2),
 *   that disappear.
 * - Content types with separate permissions (RS-30): the ones whose
 *   strings the roles get because the roles written predate them, and the
 *   ones nobody could edit afterwards, with RS-32's revert as the way out.
 *
 * Included by tools-snapshot-restore.php and tools-reset.php inside a card
 * body. Prints only escaped text.
 *
 * @package Blt_Toolkit
 *
 * @var array  $plan          restore_plan() or BLT_Toolkit_Reset::plan(): `removed`, `no_role`,
 *                            `default_role`, `extra_default`, `isolated`.
 * @var bool   $blt_tk_is_reset Whether a reset (true) or a restore (false) is previewed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_removed  = isset( $plan['removed'] ) ? (array) $plan['removed'] : array();
$blt_tk_no_role  = isset( $plan['no_role'] ) ? $plan['no_role'] : null;
$blt_tk_isolated = isset( $plan['isolated'] ) ? (array) $plan['isolated'] : array(
	'seeded'   => array(),
	'orphaned' => array(),
);
$blt_tk_users    = current_user_can( 'list_users' ) ? add_query_arg( 'blt_tk_no_role', '1', admin_url( 'users.php' ) ) : '';
?>
<?php if ( array() !== $blt_tk_removed ) : ?>
	<div class="blt-field">
		<div class="blt-field-label"><?php esc_html_e( 'Roles that disappear', 'blt-toolkit' ); ?></div>
		<div>
			<div class="blt-toolkit-table-scroll">
				<table class="widefat striped blt-toolkit-reset-removed">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Role', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'People who have it', 'blt-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $blt_tk_removed as $blt_tk_role => $blt_tk_row ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $blt_tk_row['name'] ); ?>
									<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_role ); ?></code>
								</th>
								<td><?php echo null === $blt_tk_row['users'] ? esc_html__( 'Not counted', 'blt-toolkit' ) : esc_html( number_format_i18n( (int) $blt_tk_row['users'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="blt-field-desc"><?php esc_html_e( 'People keep their other roles. Someone who has two of these roles is counted under each.', 'blt-toolkit' ); ?></p>
		</div>
	</div>

	<?php if ( null !== $blt_tk_no_role && (int) $blt_tk_no_role > 0 ) : ?>
		<div class="blt-callout" role="note">
			<strong>
				<?php
				printf(
					/* translators: %s: number of people. */
					esc_html( _n( '%s person will have no role', '%s people will have no role', (int) $blt_tk_no_role, 'blt-toolkit' ) ),
					esc_html( number_format_i18n( (int) $blt_tk_no_role ) )
				);
				?>
			</strong>
			<span>
				<?php esc_html_e( 'They have only roles that disappear, so afterwards they can sign in but do nothing. Find them under Users, No role, and give them a role there.', 'blt-toolkit' ); ?>
				<?php if ( '' !== $blt_tk_users ) : ?>
					<a href="<?php echo esc_url( $blt_tk_users ); ?>"><?php esc_html_e( 'Users without a role', 'blt-toolkit' ); ?></a>
				<?php endif; ?>
			</span>
		</div>
	<?php elseif ( null !== $blt_tk_no_role ) : ?>
		<p class="blt-field-desc"><?php esc_html_e( 'Everyone who has one of these roles also has another role, so nobody is left without a role.', 'blt-toolkit' ); ?></p>
	<?php endif; ?>
<?php endif; ?>

<?php if ( '' !== $plan['default_role'] ) : ?>
	<div class="blt-callout">
		<strong><?php esc_html_e( 'The role new users get disappears', 'blt-toolkit' ); ?></strong>
		<span>
			<?php
			printf(
				/* translators: %s: role ID. */
				esc_html__( 'New users get the %s role (Settings > General). Afterwards that role no longer exists, so choose another one there.', 'blt-toolkit' ),
				'<code>' . esc_html( $plan['default_role'] ) . '</code>'
			);
			?>
		</span>
	</div>
<?php endif; ?>

<?php if ( array() !== $plan['extra_default'] ) : ?>
	<div class="blt-callout">
		<strong><?php esc_html_e( 'Additional default roles disappear', 'blt-toolkit' ); ?></strong>
		<span><?php esc_html_e( 'New users stop getting these extra roles, because they no longer exist:', 'blt-toolkit' ); ?></span>
		<span class="blt-chips">
			<?php foreach ( $plan['extra_default'] as $blt_tk_role ) : ?>
				<code class="blt-chip"><?php echo esc_html( $blt_tk_role ); ?></code>
			<?php endforeach; ?>
		</span>
	</div>
<?php endif; ?>

<?php if ( array() !== $blt_tk_isolated['seeded'] ) : ?>
	<div class="blt-callout">
		<strong><?php esc_html_e( 'Content types with separate permissions', 'blt-toolkit' ); ?></strong>
		<span>
			<?php
			printf(
				esc_html(
					$blt_tk_is_reset
						/* translators: %s: list of content types, such as Events. */
						? __( 'WordPress\'s default roles know nothing of %s, which have separate permissions here. Each role that is reset gets their capabilities to match the Posts or Pages access it gets, as when separate permissions were given, so whoever can edit Posts or Pages can still edit them.', 'blt-toolkit' )
						/* translators: %s: list of content types, such as Events. */
						: __( 'The snapshot was taken before %s got separate permissions. Each role gets their capabilities to match the Posts or Pages access it has in the snapshot, as when separate permissions were given, so whoever can edit Posts or Pages can still edit them.', 'blt-toolkit' )
				),
				esc_html( implode( wp_get_list_item_separator(), array_values( $blt_tk_isolated['seeded'] ) ) )
			);
			?>
		</span>
	</div>
<?php endif; ?>

<?php if ( array() !== $blt_tk_isolated['orphaned'] ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				esc_html(
					$blt_tk_is_reset
						/* translators: %s: list of content types, such as Events. */
						? __( 'After this reset, no role lets anyone edit %s, which keep their separate permissions. Give a role their capabilities afterwards, or make them share Posts\' permissions again:', 'blt-toolkit' )
						/* translators: %s: list of content types, such as Events. */
						: __( 'After restoring, no role lets anyone edit %s, which keep their separate permissions. Give a role their capabilities afterwards, or make them share Posts\' permissions again:', 'blt-toolkit' )
				),
				esc_html( implode( wp_get_list_item_separator(), array_values( $blt_tk_isolated['orphaned'] ) ) )
			);
			?>
		</p>
		<?php if ( BLT_Toolkit_Plugin::is_active( 'roles' ) && class_exists( 'BLT_Toolkit_Admin_Isolation' ) ) : ?>
			<ul class="ul-disc">
				<?php foreach ( $blt_tk_isolated['orphaned'] as $blt_tk_type => $blt_tk_label ) : ?>
					<li>
						<a href="<?php echo esc_url( BLT_Toolkit_Admin_Isolation::url( 'revert', (string) $blt_tk_type ) ); ?>">
							<?php
							printf(
								/* translators: %s: content type name, such as Events. */
								esc_html__( 'Make %s share Posts\' permissions again', 'blt-toolkit' ),
								esc_html( $blt_tk_label )
							);
							?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
<?php endif; ?>
