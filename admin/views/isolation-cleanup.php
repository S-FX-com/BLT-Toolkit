<?php
/**
 * Roles > Clean up after separate permissions (RS-32's separate step): the
 * reverted type's capabilities that nothing registers any more (RO-11),
 * who holds them, then remove them or keep them.
 *
 * Included by BLT_Toolkit_Admin_Isolation::render() inside the screen's
 * `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string     $type     Post type from the URL.
 * @var array|null $plan     BLT_Toolkit_Isolation::cleanup_plan(), or null.
 * @var string     $code     The error code when there is no plan.
 * @var string     $message Why there is no plan.
 * @var string     $role     Role the person came from, or ''.
 * @var string     $back_url Where Back and Cancel go.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_has_holders = is_array( $plan ) && ( array() !== $plan['roles'] || array() !== $plan['users'] );
?>
<?php if ( null === $plan ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php echo esc_html( $message ); ?></span>
				<?php if ( 'blt_toolkit_still_isolated' === $code ) : ?>
					<span><a href="<?php echo esc_url( BLT_Toolkit_Admin_Isolation::url( 'revert', $type, array_filter( array( BLT_Toolkit_Admin_Isolation::ROLE_ARG => $role ) ) ) ); ?>"><?php esc_html_e( 'Make it share Posts\' permissions again', 'blt-toolkit' ); ?></a></span>
				<?php endif; ?>
				<span><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></span>
			</div>
		</div>
	</div>
<?php else : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				/* translators: %s: plural content type name, such as Events. */
				printf( esc_html__( 'Remove the capabilities %s no longer use', 'blt-toolkit' ), esc_html( $plan['label'] ) );
				?>
			</h2>
			<p><?php esc_html_e( 'Nothing checks these capabilities any more, so they do nothing. Until they are removed they are listed under Orphaned in Advanced view. A copy of every role is saved before they are removed.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Unused capabilities', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( array() === $plan['orphans'] ) : ?>
						<?php esc_html_e( 'None.', 'blt-toolkit' ); ?>
					<?php else : ?>
						<span class="blt-chips">
							<?php foreach ( $plan['orphans'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( array() !== $plan['kept'] ) : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Still in use, kept', 'blt-toolkit' ); ?></div>
					<div>
						<span class="blt-chips">
							<?php foreach ( $plan['kept'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</span>
						<p class="blt-field-desc"><?php esc_html_e( 'Something on this site registers these now, so they are never removed here.', 'blt-toolkit' ); ?></p>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( ! $blt_tk_has_holders ) : ?>
				<p><?php esc_html_e( 'No role or person holds any of them, so there is nothing to remove.', 'blt-toolkit' ); ?></p>
			<?php else : ?>
				<table class="widefat striped blt-toolkit-isolation-cleanup">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Role or person', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Loses', 'blt-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plan['roles'] as $blt_tk_id => $blt_tk_change ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $blt_tk_change['name'] ); ?>
									<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_id ); ?></code>
								</th>
								<td><?php require BLT_TOOLKIT_DIR . 'admin/views/isolation-change.php'; ?></td>
							</tr>
						<?php endforeach; ?>
						<?php foreach ( $plan['users'] as $blt_tk_change ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $blt_tk_change['name'] ); ?>
									<code class="blt-chip"><?php echo esc_html( $blt_tk_change['login'] ); ?></code>
								</th>
								<td><?php require BLT_TOOLKIT_DIR . 'admin/views/isolation-change.php'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_isolation_cleanup' ); ?>
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::TYPE_ARG ); ?>" value="<?php echo esc_attr( $plan['type'] ); ?>" />
		<input type="hidden" name="hash" value="<?php echo esc_attr( $plan['hash'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::ROLE_ARG ); ?>" value="<?php echo esc_attr( $role ); ?>" />

		<div class="blt-settings-footer">
			<?php if ( $blt_tk_has_holders ) : ?>
				<button type="submit" class="button button-primary" name="decision" value="remove"><?php esc_html_e( 'Remove them', 'blt-toolkit' ); ?></button>
			<?php endif; ?>
			<button type="submit" class="button" name="decision" value="keep"><?php echo $blt_tk_has_holders ? esc_html__( 'Keep them', 'blt-toolkit' ) : esc_html__( 'Done', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Decide later', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php endif; ?>
