<?php
/**
 * Roles > Make {Type} share Posts' permissions again (RS-32): what changes
 * for whom, then confirm.
 *
 * Reverting writes no role: the type shares Posts' (or Pages') permissions
 * from the next page load, and its own capabilities stay on roles until the
 * separate cleanup step, which the confirm leads to.
 *
 * Included by BLT_Toolkit_Admin_Isolation::render() inside the screen's
 * `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string     $type     Post type from the URL.
 * @var array|null $plan     BLT_Toolkit_Isolation::revert_plan(), or null.
 * @var string     $message  Why there is no plan.
 * @var string     $role     Role the person came from, or ''.
 * @var string     $back_url Where Back and Cancel go.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( null === $plan ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php echo esc_html( $message ); ?></span>
				<span><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></span>
			</div>
		</div>
	</div>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_isolation_revert' ); ?>
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::TYPE_ARG ); ?>" value="<?php echo esc_attr( $plan['type'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::ROLE_ARG ); ?>" value="<?php echo esc_attr( $role ); ?>" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2>
					<?php
					printf(
						/* translators: 1: plural content type name, such as Events. 2: Posts or Pages. */
						esc_html__( 'Make %1$s share %2$s\' permissions again', 'blt-toolkit' ),
						esc_html( $plan['label'] ),
						esc_html( $plan['shares_label'] )
					);
					?>
				</h2>
				<p>
					<?php
					printf(
						/* translators: 1: plural content type name, such as Events. 2: Posts or Pages. */
						esc_html__( 'From the next page load, anyone who can work with %2$s can do the same with %1$s, and the levels set for %1$s alone stop applying. No role is changed now; the capabilities %1$s used stay on roles until you remove them in the next step.', 'blt-toolkit' ),
						esc_html( $plan['label'] ),
						esc_html( $plan['shares_label'] )
					);
					?>
				</p>
			</div>
			<div class="blt-card-body">
				<?php if ( $plan['failed'] ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Not in effect now', 'blt-toolkit' ); ?></strong>
						<span><?php esc_html_e( 'These separate permissions are not in effect at the moment (another plugin changed them, or the type registered before BLT Toolkit loaded), so reverting changes nobody\'s access today.', 'blt-toolkit' ); ?></span>
					</div>
				<?php elseif ( $plan['missing'] ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Not registered', 'blt-toolkit' ); ?></strong>
						<span><?php esc_html_e( 'This content type is not registered right now (its plugin may be inactive), so reverting changes nobody\'s access today.', 'blt-toolkit' ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( array() === $plan['roles'] && array() === $plan['users'] ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: plural content type name, such as Events. 2: Posts or Pages. */
							esc_html__( 'Every role has the same access to %1$s as to %2$s, so nobody\'s access changes.', 'blt-toolkit' ),
							esc_html( $plan['label'] ),
							esc_html( $plan['shares_label'] )
						);
						?>
					</p>
				<?php else : ?>
					<table class="widefat striped blt-toolkit-isolation-revert">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Role or person', 'blt-toolkit' ); ?></th>
								<th scope="col">
									<?php
									/* translators: %s: plural content type name, such as Events. */
									printf( esc_html__( 'Gains for %s', 'blt-toolkit' ), esc_html( $plan['label'] ) );
									?>
								</th>
								<th scope="col">
									<?php
									/* translators: %s: plural content type name, such as Events. */
									printf( esc_html__( 'Loses for %s', 'blt-toolkit' ), esc_html( $plan['label'] ) );
									?>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plan['roles'] as $blt_tk_id => $blt_tk_row ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $blt_tk_row['name'] ); ?>
										<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_id ); ?></code>
									</th>
									<td>
										<?php foreach ( $blt_tk_row['gains'] as $blt_tk_cap ) : ?>
											<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
										<?php endforeach; ?>
									</td>
									<td>
										<?php foreach ( $blt_tk_row['loses'] as $blt_tk_cap ) : ?>
											<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
										<?php endforeach; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php foreach ( $plan['users'] as $blt_tk_row ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $blt_tk_row['name'] ); ?>
										<code class="blt-chip"><?php echo esc_html( $blt_tk_row['login'] ); ?></code>
									</th>
									<td>
										<?php foreach ( $blt_tk_row['gains'] as $blt_tk_cap ) : ?>
											<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
										<?php endforeach; ?>
									</td>
									<td>
										<?php foreach ( $blt_tk_row['loses'] as $blt_tk_cap ) : ?>
											<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
										<?php endforeach; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="blt-field-desc"><?php esc_html_e( 'Gains are the shared capabilities they hold that their separate ones did not give; losses are separate capabilities they hold without the shared one.', 'blt-toolkit' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary">
				<?php
				printf(
					/* translators: 1: plural content type name, such as Events. 2: Posts or Pages. */
					esc_html__( 'Make %1$s share %2$s\' permissions', 'blt-toolkit' ),
					esc_html( $plan['label'] ),
					esc_html( $plan['shares_label'] )
				);
				?>
			</button>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php endif; ?>
