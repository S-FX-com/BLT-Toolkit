<?php
/**
 * Roles > Give {Type} separate permissions (RS-30): confirm the names, then
 * the dry run, then confirm.
 *
 * The names step is a GET form (it changes nothing); the dry run lists, for
 * each role and each user with Posts or Pages capabilities of their own,
 * the capabilities they receive so their access stays the same. The
 * confirm form posts to admin-post.php with the dry run's lock token, so a
 * confirm whose roles changed since is refused.
 *
 * Included by BLT_Toolkit_Admin_Isolation::render() inside the screen's
 * `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string     $type     Post type from the URL.
 * @var string     $label    Its plural name.
 * @var string     $state    `missing`, `isolated`, `not_candidate`, `form` or `preview`.
 * @var string     $message  Why the names were refused, or why the type is not a candidate.
 * @var string     $field    The field the message is about (`singular` or `plural`).
 * @var string     $singular Singular to show.
 * @var string     $plural   Plural to show.
 * @var array|null $plan     BLT_Toolkit_Isolation::plan() on the preview step.
 * @var array[]    $rows     The new capabilities: cap, label, from, meta.
 * @var string     $shares   `post` or `page`.
 * @var string     $role     Role the person came from, or ''.
 * @var string     $back_url Where Back and Cancel go.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_shared_label = 'page' === $shares ? __( 'Pages', 'blt-toolkit' ) : __( 'Posts', 'blt-toolkit' );
$blt_tk_page_slug    = BLT_Toolkit_Admin::screens()['roles']['slug'];
?>
<?php if ( 'missing' === $state ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'That content type is not registered on this site', 'blt-toolkit' ); ?></span>
				<span><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></span>
			</div>
		</div>
	</div>
<?php elseif ( 'isolated' === $state ) : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				/* translators: %s: plural content type name, such as Events. */
				printf( esc_html__( '%s already have separate permissions', 'blt-toolkit' ), esc_html( $label ) );
				?>
			</h2>
		</div>
		<div class="blt-card-body">
			<p>
				<a href="<?php echo esc_url( BLT_Toolkit_Admin_Isolation::url( 'revert', $type, array_filter( array( BLT_Toolkit_Admin_Isolation::ROLE_ARG => $role ) ) ) ); ?>"><?php esc_html_e( 'Make them share Posts\' permissions again', 'blt-toolkit' ); ?></a>
			</p>
		</div>
	</div>
<?php elseif ( 'not_candidate' === $state ) : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				/* translators: %s: plural content type name, such as Events. */
				printf( esc_html__( 'Give %s separate permissions', 'blt-toolkit' ), esc_html( $label ) );
				?>
			</h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-callout">
				<strong><?php esc_html_e( 'Not available for this content type', 'blt-toolkit' ); ?></strong>
				<span><?php echo esc_html( $message ); ?></span>
			</div>
		</div>
	</div>
<?php else : ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( $blt_tk_page_slug ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::ARG ); ?>" value="isolate" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::TYPE_ARG ); ?>" value="<?php echo esc_attr( $type ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::STEP_ARG ); ?>" value="preview" />
		<?php if ( '' !== $role ) : ?>
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::ROLE_ARG ); ?>" value="<?php echo esc_attr( $role ); ?>" />
		<?php endif; ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2>
					<?php
					/* translators: %s: plural content type name, such as Events. */
					printf( esc_html__( 'Give %s separate permissions', 'blt-toolkit' ), esc_html( $label ) );
					?>
				</h2>
				<p>
					<?php
					printf(
						/* translators: 1: plural content type name, such as Events. 2: Posts or Pages. */
						esc_html__( '%1$s use the same permissions as %2$s, so anyone who can edit %2$s can edit %1$s too. Separate permissions give %1$s capabilities of their own, so each role can get a different level for them in Simple view. Everyone keeps exactly the access they have today until you change it.', 'blt-toolkit' ),
						esc_html( $label ),
						esc_html( $blt_tk_shared_label )
					);
					?>
				</p>
			</div>
			<div class="blt-card-body">
				<?php if ( '' !== $message ) : ?>
					<div class="notice notice-error inline" role="alert">
						<p><?php echo esc_html( $message ); ?></p>
					</div>
				<?php endif; ?>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-tk-iso-singular"><?php esc_html_e( 'Singular', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text code" id="blt-tk-iso-singular" name="singular" value="<?php echo esc_attr( $singular ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Isolation::NAME_MAX ); ?>" pattern="[a-z0-9_\-]+" required spellcheck="false" autocomplete="off" aria-describedby="blt-tk-iso-singular-desc"<?php echo 'singular' === $field && '' !== $message ? ' aria-invalid="true"' : ''; ?> />
						<p class="blt-field-desc" id="blt-tk-iso-singular-desc">
							<?php
							printf(
								/* translators: %s: example capability, such as edit_event. */
								esc_html__( 'Used for one item, as in %s.', 'blt-toolkit' ),
								'<code>edit_' . esc_html( $singular ) . '</code>'
							);
							?>
						</p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-tk-iso-plural"><?php esc_html_e( 'Plural', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text code" id="blt-tk-iso-plural" name="plural" value="<?php echo esc_attr( $plural ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Isolation::NAME_MAX ); ?>" pattern="[a-z0-9_\-]+" required spellcheck="false" autocomplete="off" aria-describedby="blt-tk-iso-plural-desc"<?php echo 'plural' === $field && '' !== $message ? ' aria-invalid="true"' : ''; ?> />
						<p class="blt-field-desc" id="blt-tk-iso-plural-desc">
							<?php
							printf(
								/* translators: 1: example capability, such as edit_events. 2: another, such as publish_events. */
								esc_html__( 'Used for the type\'s permissions, as in %1$s and %2$s. Lowercase letters, digits, underscores and hyphens; it must not match another content type\'s.', 'blt-toolkit' ),
								'<code>edit_' . esc_html( $plural ) . '</code>',
								'<code>publish_' . esc_html( $plural ) . '</code>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button<?php echo 'preview' === $state ? '' : ' button-primary'; ?>"><?php echo 'preview' === $state ? esc_html__( 'Update the preview', 'blt-toolkit' ) : esc_html__( 'Preview the change', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>

	<?php if ( 'preview' === $state && is_array( $plan ) ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'New capabilities', 'blt-toolkit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: plural content type name, such as Events. */
						esc_html__( 'From the next page load, %s check these instead of the shared ones. Each is given to whoever holds the capability it replaces.', 'blt-toolkit' ),
						esc_html( $label )
					);
					?>
				</p>
			</div>
			<div class="blt-card-body">
				<table class="widefat striped blt-toolkit-isolation-caps">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Capability', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Replaces', 'blt-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $blt_tk_row ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $blt_tk_row['label'] ); ?>
									<code class="blt-chip"><?php echo esc_html( $blt_tk_row['cap'] ); ?></code>
									<?php if ( $blt_tk_row['meta'] ) : ?>
										<span class="description"><?php esc_html_e( '(per item, worked out by WordPress)', 'blt-toolkit' ); ?></span>
									<?php endif; ?>
								</th>
								<td>
									<?php if ( '' !== $blt_tk_row['from'] && $blt_tk_row['from'] !== $blt_tk_row['cap'] ) : ?>
										<code class="blt-chip"><?php echo esc_html( $blt_tk_row['from'] ); ?></code>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Roles', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'What each role receives so that its access stays exactly the same. A capability a role denies is denied for the new one too.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php if ( array() === $plan['roles'] ) : ?>
					<p><?php esc_html_e( 'No role needs a change.', 'blt-toolkit' ); ?></p>
				<?php else : ?>
					<table class="widefat striped blt-toolkit-isolation-roles">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Role', 'blt-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Receives', 'blt-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plan['roles'] as $blt_tk_role_id => $blt_tk_change ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $blt_tk_change['name'] ); ?>
										<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_role_id ); ?></code>
									</th>
									<td>
										<?php require BLT_TOOLKIT_DIR . 'admin/views/isolation-change.php'; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( array() !== $plan['kept'] ) : ?>
					<p class="blt-field-desc">
						<?php
						printf(
							/* translators: %s: comma-separated role names. */
							esc_html__( 'No change needed: %s.', 'blt-toolkit' ),
							esc_html( implode( wp_get_list_item_separator(), $plan['kept'] ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'People with permissions of their own', 'blt-toolkit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: Posts or Pages. */
						esc_html__( 'People given or denied %s capabilities individually, outside their roles, receive the matching ones the same way.', 'blt-toolkit' ),
						esc_html( $blt_tk_shared_label )
					);
					?>
				</p>
			</div>
			<div class="blt-card-body">
				<?php if ( array() === $plan['users'] ) : ?>
					<p><?php esc_html_e( 'Nobody needs a change.', 'blt-toolkit' ); ?></p>
				<?php else : ?>
					<table class="widefat striped blt-toolkit-isolation-users">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Person', 'blt-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Receives', 'blt-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plan['users'] as $blt_tk_change ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $blt_tk_change['name'] ); ?>
										<code class="blt-chip"><?php echo esc_html( $blt_tk_change['login'] ); ?></code>
									</th>
									<td>
										<?php require BLT_TOOLKIT_DIR . 'admin/views/isolation-change.php'; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
			<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_isolation_isolate' ); ?>
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::TYPE_ARG ); ?>" value="<?php echo esc_attr( $plan['type'] ); ?>" />
			<input type="hidden" name="singular" value="<?php echo esc_attr( $plan['singular'] ); ?>" />
			<input type="hidden" name="plural" value="<?php echo esc_attr( $plan['plural'] ); ?>" />
			<input type="hidden" name="hash" value="<?php echo esc_attr( $plan['hash'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Isolation::ROLE_ARG ); ?>" value="<?php echo esc_attr( $role ); ?>" />

			<div class="blt-callout">
				<strong><?php esc_html_e( 'Before you confirm', 'blt-toolkit' ); ?></strong>
				<span>
					<?php
					printf(
						/* translators: 1: plural content type name, such as Events. 2: Posts or Pages. */
						esc_html__( 'A copy of every role is saved first. If BLT Toolkit is deactivated later, %1$s share %2$s\' permissions again until it is reactivated.', 'blt-toolkit' ),
						esc_html( $label ),
						esc_html( $blt_tk_shared_label )
					);
					?>
				</span>
			</div>

			<div class="blt-settings-footer">
				<button type="submit" class="button button-primary">
					<?php
					/* translators: %s: plural content type name, such as Events. */
					printf( esc_html__( 'Give %s separate permissions', 'blt-toolkit' ), esc_html( $label ) );
					?>
				</button>
				<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
			</div>
		</form>
	<?php endif; ?>
<?php endif; ?>
