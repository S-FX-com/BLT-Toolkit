<?php
/**
 * Roles screen: a save refused because the role changed since the form was
 * drawn (RO-2; SPEC 19, URE-5). Shows what changed in the meantime (when a
 * recent snapshot holds the role as it was drawn) and the change the person
 * tried to make, worked out against that drawn copy. Nothing was written.
 *
 * @package Blt_Toolkit
 *
 * @var string     $role      Role ID.
 * @var string     $name      Role display name.
 * @var string     $message   The store's message.
 * @var array|null $diff      Role_Diff::diff() from the drawn copy to now, or null.
 * @var string     $diff_html That diff as escaped HTML (BLT_Toolkit_Admin_Activity::diff_html()).
 * @var array      $attempted `add` and `remove`: capability names.
 * @var string     $back_url  The editor, to reload the role.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card blt-toolkit-conflict">
	<div class="blt-card-header">
		<h2>
			<?php
			printf(
				/* translators: %s: role name. */
				esc_html__( 'The %s role changed since you opened it', 'blt-toolkit' ),
				esc_html( $name )
			);
			?>
		</h2>
		<div class="blt-card-header-badges">
			<?php echo BLT_Toolkit_Admin::badge( 'cancelled', __( 'Not saved', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
		</div>
		<p><?php echo esc_html( $message ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Changed by someone else', 'blt-toolkit' ); ?></div>
			<div>
				<?php if ( null !== $diff ) : ?>
					<?php echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in BLT_Toolkit_Admin_Activity::diff_html(). ?>
				<?php else : ?>
					<p><?php esc_html_e( 'The role was changed outside BLT Toolkit (by another plugin or by code), so the exact difference is not known. Reload the role to see it as it is now.', 'blt-toolkit' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( array() !== $attempted['add'] || array() !== $attempted['remove'] ) : ?>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Your change, not saved', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( array() !== $attempted['add'] ) : ?>
						<p class="blt-toolkit-diff-label"><?php esc_html_e( 'You granted:', 'blt-toolkit' ); ?></p>
						<span class="blt-chips">
							<?php foreach ( $attempted['add'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
					<?php if ( array() !== $attempted['remove'] ) : ?>
						<p class="blt-toolkit-diff-label"><?php esc_html_e( 'You removed:', 'blt-toolkit' ); ?></p>
						<span class="blt-chips">
							<?php foreach ( $attempted['remove'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
<div class="blt-settings-footer">
	<a class="button button-primary" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Reload the role', 'blt-toolkit' ); ?></a>
</div>
