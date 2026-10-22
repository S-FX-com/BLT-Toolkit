<?php
/**
 * Roles screen, left column (RO-20): the role list card with its badges and
 * Add role (SPEC 13.2), then, in Advanced view and user mode, the capability
 * group tree as links, one URL per group (RO-13, RO-26).
 *
 * @package Blt_Toolkit
 *
 * @var array[] $roles   Each: id, name, users (null when not counted), builtin, default, critical, description, read_only, current, url.
 * @var string  $add_url Add role link, or ''.
 * @var array[] $tree    Group tree nodes, or empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-toolkit-roles-side">
	<div class="blt-card blt-toolkit-role-list-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Roles', 'blt-toolkit' ); ?></h2>
			<div class="blt-card-header-badges">
				<span class="blt-badge blt-badge-off"><?php echo esc_html( number_format_i18n( count( $roles ) ) ); ?></span>
			</div>
		</div>
		<nav aria-label="<?php esc_attr_e( 'Roles', 'blt-toolkit' ); ?>">
			<ul class="blt-toolkit-role-list">
				<?php foreach ( $roles as $blt_tk_role ) : ?>
					<li>
						<a class="blt-toolkit-role-link<?php echo $blt_tk_role['current'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $blt_tk_role['url'] ); ?>"<?php echo $blt_tk_role['current'] ? ' aria-current="page"' : ''; ?>>
							<span class="blt-toolkit-role-line">
								<span class="blt-toolkit-role-name"><?php echo esc_html( $blt_tk_role['name'] ); ?></span>
								<?php if ( null !== $blt_tk_role['users'] ) : ?>
									<span class="blt-toolkit-role-count">
										<?php
										printf(
											/* translators: %s: number of users. */
											esc_html( _n( '%s user', '%s users', (int) $blt_tk_role['users'], 'blt-toolkit' ) ),
											esc_html( number_format_i18n( (int) $blt_tk_role['users'] ) )
										);
										?>
									</span>
								<?php endif; ?>
							</span>
							<?php if ( $blt_tk_role['builtin'] || $blt_tk_role['default'] || $blt_tk_role['critical'] || $blt_tk_role['read_only'] ) : ?>
								<span class="blt-toolkit-role-badges">
									<?php
									if ( $blt_tk_role['builtin'] ) {
										echo BLT_Toolkit_Admin::badge( 'off', __( 'Built-in', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
									}

									if ( $blt_tk_role['default'] ) {
										echo BLT_Toolkit_Admin::badge( 'on', __( 'Default', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
									}

									if ( $blt_tk_role['critical'] ) {
										echo BLT_Toolkit_Admin::badge( 'cancelled', __( 'Has Critical capabilities', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
									}

									if ( $blt_tk_role['read_only'] ) {
										echo BLT_Toolkit_Admin::badge( 'pending', __( 'Read only for you', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
									}
									?>
								</span>
							<?php endif; ?>
							<?php if ( '' !== $blt_tk_role['description'] ) : ?>
								<span class="blt-toolkit-role-desc"><?php echo esc_html( $blt_tk_role['description'] ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php if ( '' !== $add_url ) : ?>
			<div class="blt-card-body">
				<a class="button" href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Add role', 'blt-toolkit' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( array() !== $tree ) : ?>
		<nav class="blt-card blt-toolkit-tree-card" aria-label="<?php esc_attr_e( 'Capability groups', 'blt-toolkit' ); ?>">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Capability groups', 'blt-toolkit' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<?php BLT_Toolkit_Admin_Roles::partial( 'roles-tree', array( 'nodes' => $tree ) ); ?>
			</div>
		</nav>
	<?php endif; ?>
</div>
