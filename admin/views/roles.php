<?php
/**
 * Roles screen: the role editor (SPEC 13.2, RO-20). Left: the role list and,
 * in Advanced view, the capability group tree. Main: the role header, the
 * Simple / Advanced tabs (RS-2), the view, and the role's details.
 *
 * Included by BLT_Toolkit_Admin_Roles::render_editor() inside the screen's
 * `.wrap`. Writes nothing; every form posts to admin-post.php.
 *
 * @package Blt_Toolkit
 *
 * @var array         $state    The request state (BLT_Toolkit_Admin_Roles::state()).
 * @var array[]       $roles    The role list.
 * @var string        $add_url  Add role link, or ''.
 * @var array[]       $tree     Group tree (Advanced view), or empty.
 * @var array         $header   Role header data (`users` is null when not counted).
 * @var array[]       $tabs     Simple / Advanced tabs, or empty when only Simple is open to the person.
 * @var array|null    $matrix   Advanced matrix data, or null in Simple view.
 * @var array|null    $add_cap  Add capability data (Advanced view), or null.
 * @var array         $details  Role details data.
 * @var true|WP_Error $editable Whether the person may change this role.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_back = http_build_query( BLT_Toolkit_Admin_Roles::state_args( $state ) );
?>
<?php // WCAG 2.4.1: the role list and group tree come first; skip past them. ?>
<a class="blt-toolkit-skip" href="<?php echo null !== $matrix ? '#blt-tk-matrix' : '#blt-tk-role-main'; ?>"><?php echo null !== $matrix ? esc_html__( 'Skip to the capabilities', 'blt-toolkit' ) : esc_html__( 'Skip to the role', 'blt-toolkit' ); ?></a>
<div class="blt-toolkit-roles-layout">
	<?php
	BLT_Toolkit_Admin_Roles::partial(
		'roles-sidebar',
		array(
			'roles'   => $roles,
			'add_url' => $add_url,
			'tree'    => $tree,
		)
	);
	?>

	<div class="blt-toolkit-roles-main" id="blt-tk-role-main" tabindex="-1">
		<div class="blt-card blt-toolkit-role-header">
			<div class="blt-card-header">
				<h2>
					<?php echo esc_html( $header['name'] ); ?>
					<code class="blt-chip"><?php echo esc_html( $header['id'] ); ?></code>
				</h2>
				<div class="blt-card-header-badges">
					<?php
					// No count on a site WordPress calls large (SEC-9).
					$blt_tk_users_text = null === $header['users']
						? __( 'Users with this role', 'blt-toolkit' )
						: sprintf(
							/* translators: %s: number of users. */
							_n( '%s user', '%s users', (int) $header['users'], 'blt-toolkit' ),
							number_format_i18n( (int) $header['users'] )
						);
					?>
					<?php if ( '' !== $header['users_url'] ) : ?>
						<a href="<?php echo esc_url( $header['users_url'] ); ?>"><?php echo esc_html( $blt_tk_users_text ); ?></a>
					<?php elseif ( null !== $header['users'] ) : ?>
						<span><?php echo esc_html( $blt_tk_users_text ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $header['description'] ) : ?>
					<p><?php echo esc_html( $header['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( array() !== $header['links'] || '' !== $header['compare_url'] || $header['test_as']['available'] ) : ?>
				<div class="blt-card-body">
					<?php if ( array() !== $header['links'] || '' !== $header['compare_url'] ) : ?>
						<div class="blt-toolkit-role-actions">
							<?php foreach ( $header['links'] as $blt_tk_link ) : ?>
								<a class="button" href="<?php echo esc_url( $blt_tk_link['url'] ); ?>"><?php echo esc_html( $blt_tk_link['label'] ); ?></a>
							<?php endforeach; ?>
							<?php if ( '' !== $header['compare_url'] ) : ?>
								<a class="button" href="<?php echo esc_url( $header['compare_url'] ); ?>"><?php esc_html_e( 'Compare with another role', 'blt-toolkit' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( $header['test_as']['available'] ) : ?>
						<?php
						BLT_Toolkit_Admin_Roles::partial(
							'roles-test-as',
							array(
								'test_as' => $header['test_as'],
								'role'    => $header['id'],
								'name'    => $header['name'],
								'back'    => $blt_tk_back,
							)
						);
						?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( array() !== $tabs ) : ?>
			<nav class="blt-settings-tabs blt-toolkit-view-tabs" aria-label="<?php esc_attr_e( 'Role editor view', 'blt-toolkit' ); ?>">
				<?php foreach ( $tabs as $blt_tk_tab ) : ?>
					<a class="blt-settings-tab<?php echo $blt_tk_tab['current'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $blt_tk_tab['url'] ); ?>"<?php echo $blt_tk_tab['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $blt_tk_tab['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php
		if ( null !== $matrix ) {
			BLT_Toolkit_Admin_Roles::partial(
				'roles-advanced',
				array(
					'state'   => $state,
					'matrix'  => $matrix,
					'add_cap' => $add_cap,
					'name'    => $header['name'],
				)
			);
		} else {
			BLT_Toolkit_Admin_Roles::partial(
				'roles-simple',
				array(
					'state' => $state,
					'name'  => $header['name'],
				)
			);
		}

		BLT_Toolkit_Admin_Roles::partial(
			'roles-details',
			array(
				'details' => $details,
				'name'    => $header['name'],
			)
		);
		?>
	</div>
</div>
