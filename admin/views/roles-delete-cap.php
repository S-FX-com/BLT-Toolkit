<?php
/**
 * Roles screen: delete an orphaned capability (RO-24). Lists every role and
 * user holding it and the result of a dry run (the Guard on every one of
 * them); the confirmation posts to admin-post.php, where the roles are
 * snapshotted before the capability is taken out. A capability that core, an
 * active content type or taxonomy, or BLT Toolkit registers is never offered
 * (RO-11; SPEC 19, URE-7), even when the URL asks.
 *
 * @package Blt_Toolkit
 *
 * @var string     $cap      Capability.
 * @var bool       $known    Whether the catalog knows it.
 * @var bool       $orphan   Whether it is orphaned.
 * @var array|null $entry    Its catalog entry.
 * @var string     $back_url The editor.
 * @var string     $back     The editor's query string.
 * @var array|null $plan     roles (ID => name), users (ID => "Name (login)"), guard (true or WP_Error).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2>
			<?php esc_html_e( 'Delete a capability', 'blt-toolkit' ); ?>
			<?php if ( '' !== $cap ) : ?>
				<code class="blt-chip"><?php echo esc_html( $cap ); ?></code>
			<?php endif; ?>
		</h2>
		<?php if ( $orphan ) : ?>
			<div class="blt-card-header-badges">
				<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Orphaned', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			</div>
		<?php endif; ?>
	</div>
	<div class="blt-card-body">
		<?php if ( ! $known ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'No role or user holds this capability', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'There is nothing to delete.', 'blt-toolkit' ); ?></span>
			</div>
		<?php elseif ( ! $orphan ) : ?>
			<div class="blt-callout">
				<strong><?php esc_html_e( 'This capability cannot be deleted', 'blt-toolkit' ); ?></strong>
				<span>
					<?php
					printf(
						/* translators: %s: where the capability comes from, such as Core or Events. */
						esc_html__( 'It is registered by %s, which checks it, so deleting it would break that. Only capabilities nothing on this site registers can be deleted.', 'blt-toolkit' ),
						esc_html( null !== $entry ? (string) $entry['source_label'] : '' )
					);
					?>
				</span>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing on this site registers this capability: it was probably left behind by a plugin that is gone. Deleting it takes it away from everyone below. A copy of the roles is saved first, so it can be restored.', 'blt-toolkit' ); ?></p>

			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Roles that hold it', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( array() === $plan['roles'] ) : ?>
						<?php esc_html_e( 'None', 'blt-toolkit' ); ?>
					<?php else : ?>
						<span class="blt-chips">
							<?php foreach ( $plan['roles'] as $blt_tk_role ) : ?>
								<span class="blt-chip"><?php echo esc_html( $blt_tk_role ); ?></span>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Users who hold it themselves', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( array() === $plan['users'] ) : ?>
						<?php esc_html_e( 'None', 'blt-toolkit' ); ?>
					<?php else : ?>
						<ul class="blt-toolkit-review-list">
							<?php foreach ( $plan['users'] as $blt_tk_user ) : ?>
								<li><?php echo esc_html( $blt_tk_user ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Dry run', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( true === $plan['guard'] ) : ?>
						<?php
						printf(
							/* translators: 1: number of roles in words, such as 2 roles, 2: number of users in words, such as 1 user. */
							esc_html__( '%1$s and %2$s will lose it. You may make this change.', 'blt-toolkit' ),
							esc_html( BLT_Toolkit_Admin_Roles::count_roles( count( $plan['roles'] ) ) ),
							esc_html( BLT_Toolkit_Admin_Roles::count_users( count( $plan['users'] ) ) )
						);
						?>
					<?php else : ?>
						<span class="blt-text-danger"><?php echo esc_html( $plan['guard']->get_error_message() ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ( $orphan && null !== $plan && true === $plan['guard'] ) : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-settings-footer">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_delete_cap' ); ?>
		<input type="hidden" name="cap" value="<?php echo esc_attr( $cap ); ?>" />
		<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
		<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Delete capability', 'blt-toolkit' ); ?></button>
	</form>
<?php else : ?>
	<div class="blt-settings-footer">
		<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back', 'blt-toolkit' ); ?></a>
	</div>
<?php endif; ?>
