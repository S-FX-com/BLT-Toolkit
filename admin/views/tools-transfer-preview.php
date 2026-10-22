<?php
/**
 * Tools > Export and import: the preview of an uploaded roles file (RO-45).
 *
 * Each role of the file is New, Changed or Unchanged against this site (or
 * cannot be imported), with what differs and the capabilities nothing here
 * registers flagged "not registered here". Each gets an action; actions the
 * Guard refuses for this person are disabled with the reason, and a
 * Critical grant asks for the role's typed name (SEC-10). Role details and
 * separate permissions come along only when chosen. The form posts to
 * admin-post.php with the preview's lock token, so an import whose roles
 * changed since is refused; Discard is a separate form.
 *
 * Included by BLT_Toolkit_Admin_Transfer::render() inside the Tools
 * screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string $token       The stored upload's token.
 * @var string $file        Its file name.
 * @var string $site        The site it was exported from.
 * @var string $exported_at When, in this site's time format.
 * @var array  $preview     BLT_Toolkit_Role_Transfer::preview(), each role with `cap_rows`.
 * @var array  $labels      Action => name.
 * @var string $back_url    The tab without the upload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_status = array(
	BLT_Toolkit_Role_Transfer::STATUS_NEW       => array( 'on', __( 'New', 'blt-toolkit' ) ),
	BLT_Toolkit_Role_Transfer::STATUS_CHANGED   => array( 'pending', __( 'Changed', 'blt-toolkit' ) ),
	BLT_Toolkit_Role_Transfer::STATUS_UNCHANGED => array( 'off', __( 'Unchanged', 'blt-toolkit' ) ),
	BLT_Toolkit_Role_Transfer::STATUS_INVALID   => array( 'cancelled', __( 'Cannot be imported', 'blt-toolkit' ) ),
);

$blt_tk_count_text = array(
	/* translators: %s: number of roles. */
	BLT_Toolkit_Role_Transfer::STATUS_NEW       => _n( '%s new', '%s new', (int) $preview['counts']['new'], 'blt-toolkit' ),
	/* translators: %s: number of roles. */
	BLT_Toolkit_Role_Transfer::STATUS_CHANGED   => _n( '%s changed', '%s changed', (int) $preview['counts']['changed'], 'blt-toolkit' ),
	/* translators: %s: number of roles. */
	BLT_Toolkit_Role_Transfer::STATUS_UNCHANGED => _n( '%s unchanged', '%s unchanged', (int) $preview['counts']['unchanged'], 'blt-toolkit' ),
	/* translators: %s: number of roles. */
	BLT_Toolkit_Role_Transfer::STATUS_INVALID   => _n( '%s cannot be imported', '%s cannot be imported', (int) $preview['counts']['invalid'], 'blt-toolkit' ),
);

$blt_tk_form    = 'blt-tk-import-form';
$blt_tk_discard = 'blt-tk-import-discard';

// Chips shown outside a role's capability list; the list has them all.
$blt_tk_chip_limit = 12;

/**
 * Print one capability as a list item: chip, label and flags.
 *
 * @param array  $row  A cap row (cap, label, value, tier, unregistered, pending).
 * @param string $note Optional text after the label, such as "denied".
 * @return void
 */
$blt_tk_cap_item = static function ( array $row, $note = '' ) {
	?>
	<li>
		<code class="blt-chip"><?php echo esc_html( $row['cap'] ); ?></code>
		<?php echo esc_html( $row['label'] ); ?>
		<?php if ( '' !== $note ) : ?>
			<span class="description">(<?php echo esc_html( $note ); ?>)</span>
		<?php endif; ?>
		<?php if ( BLT_Toolkit_Caps::CRITICAL === $row['tier'] && $row['value'] ) : ?>
			<?php echo BLT_Toolkit_Admin::badge( 'cancelled', __( 'Critical', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
		<?php endif; ?>
		<?php if ( $row['unregistered'] ) : ?>
			<?php echo BLT_Toolkit_Admin::badge( 'off', __( 'Not registered here', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
		<?php elseif ( '' !== $row['pending'] ) : ?>
			<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Registered with separate permissions', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
		<?php endif; ?>
	</li>
	<?php
};
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Import preview', 'blt-toolkit' ); ?></h2>
		<p>
			<?php
			if ( '' !== $site ) {
				printf(
					/* translators: 1: file name, 2: address of the site it came from, 3: when it was exported. */
					esc_html__( '%1$s, exported from %2$s on %3$s. Nothing has changed yet.', 'blt-toolkit' ),
					'<code>' . esc_html( '' !== $file ? $file : __( 'The file', 'blt-toolkit' ) ) . '</code>',
					esc_html( $site ),
					esc_html( '' !== $exported_at ? $exported_at : __( 'an unknown date', 'blt-toolkit' ) )
				);
			} else {
				printf(
					/* translators: %s: file name. */
					esc_html__( '%s. Nothing has changed yet.', 'blt-toolkit' ),
					'<code>' . esc_html( '' !== $file ? $file : __( 'The file', 'blt-toolkit' ) ) . '</code>'
				);
			}
			?>
		</p>
		<div class="blt-card-header-badges">
			<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Dry run', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			<?php foreach ( $blt_tk_count_text as $blt_tk_key => $blt_tk_text ) : ?>
				<?php if ( (int) $preview['counts'][ $blt_tk_key ] > 0 ) : ?>
					<?php echo BLT_Toolkit_Admin::badge( $blt_tk_status[ $blt_tk_key ][0], sprintf( $blt_tk_text, number_format_i18n( (int) $preview['counts'][ $blt_tk_key ] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="blt-card-body">
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Create adds a role that is new here, with the name and capabilities in the file.', 'blt-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Merge capabilities keeps everything the role has here and adds what the file grants. A capability the file denies is denied only where the role here does not mention it.', 'blt-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Replace capabilities makes the role\'s capabilities exactly the file\'s, removing the ones the file does not have. The role keeps its name here.', 'blt-toolkit' ); ?></li>
			<li><?php esc_html_e( 'Skip leaves the role as it is. Roles that exist here are set to Skip until you choose otherwise.', 'blt-toolkit' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'A copy of every role is saved before anything changes, so the import can be undone from Snapshots. Nobody is given or loses a role.', 'blt-toolkit' ); ?></p>
		<?php if ( (int) $preview['unregistered'] > 0 ) : ?>
			<div class="blt-callout">
				<strong><?php esc_html_e( 'Not registered here', 'blt-toolkit' ); ?></strong>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of capabilities. */
							_n(
								'%s capability in this file is not registered on this site: the content type or plugin that uses it is not here. A role can still get it, but it does nothing until something on this site uses it. Each one is flagged below.',
								'%s capabilities in this file are not registered on this site: the content types or plugins that use them are not here. A role can still get them, but they do nothing until something on this site uses them. Each one is flagged below.',
								(int) $preview['unregistered'],
								'blt-toolkit'
							),
							number_format_i18n( (int) $preview['unregistered'] )
						)
					);
					?>
				</span>
			</div>
		<?php endif; ?>
	</div>
</div>

<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( $blt_tk_form ); ?>">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Transfer::IMPORT_ACTION ); ?>
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Transfer::TOKEN_ARG ); ?>" value="<?php echo esc_attr( $token ); ?>" />
	<input type="hidden" name="hash" value="<?php echo esc_attr( $preview['hash'] ); ?>" />

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Roles', 'blt-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'Choose what to do with each role in the file.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<table class="widefat striped blt-toolkit-transfer-preview">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Role', 'blt-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Compared with this site', 'blt-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'blt-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['roles'] as $blt_tk_id => $blt_tk_row ) : ?>
						<?php
						$blt_tk_id     = (string) $blt_tk_id;
						$blt_tk_field  = 'blt-tk-import-' . md5( $blt_tk_id );
						$blt_tk_rows   = $blt_tk_row['cap_rows'];
						$blt_tk_badge  = $blt_tk_status[ $blt_tk_row['status'] ];
						$blt_tk_crit   = array();
						$blt_tk_denied = array();

						foreach ( $blt_tk_row['actions'] as $blt_tk_action => $blt_tk_entry ) {
							if ( $blt_tk_entry['allowed'] && array() !== $blt_tk_entry['critical'] ) {
								$blt_tk_crit[ $blt_tk_action ] = $blt_tk_entry['critical'];
							} elseif ( ! $blt_tk_entry['allowed'] ) {
								$blt_tk_denied[ $blt_tk_action ] = $blt_tk_entry['reason'];
							}
						}
						?>
						<tr>
							<th scope="row">
								<strong><?php echo esc_html( '' !== $blt_tk_row['name'] ? $blt_tk_row['name'] : $blt_tk_id ); ?></strong>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_id ); ?></code>
								<?php echo BLT_Toolkit_Admin::badge( $blt_tk_badge[0], $blt_tk_badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								<?php if ( '' !== $blt_tk_row['site_name'] && $blt_tk_row['site_name'] !== $blt_tk_row['name'] ) : ?>
									<span class="blt-field-desc">
										<?php
										printf(
											/* translators: %s: the role's name on this site. */
											esc_html__( 'Called %s here; importing keeps that name.', 'blt-toolkit' ),
											esc_html( translate_user_role( $blt_tk_row['site_name'] ) )
										);
										?>
									</span>
								<?php endif; ?>
							</th>
							<td data-label="<?php esc_attr_e( 'Compared with this site', 'blt-toolkit' ); ?>">
								<?php if ( BLT_Toolkit_Role_Transfer::STATUS_INVALID === $blt_tk_row['status'] ) : ?>
									<p><?php echo esc_html( BLT_Toolkit_Role_Transfer::role_error_message( $blt_tk_row['error'] ) ); ?></p>
									<?php if ( ! empty( $blt_tk_row['bad_caps'] ) ) : ?>
										<ul class="ul-disc">
											<?php foreach ( array_slice( $blt_tk_row['bad_caps'], 0, $blt_tk_chip_limit, true ) as $blt_tk_cap => $blt_tk_why ) : ?>
												<li>
													<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_cap ); ?></code>
													<?php echo esc_html( BLT_Toolkit_Role_Transfer::cap_name_message( $blt_tk_why ) ); ?>
												</li>
											<?php endforeach; ?>
										</ul>
										<?php if ( count( $blt_tk_row['bad_caps'] ) > $blt_tk_chip_limit ) : ?>
											<p class="blt-field-desc">
												<?php
												$blt_tk_more = count( $blt_tk_row['bad_caps'] ) - $blt_tk_chip_limit;
												printf(
													/* translators: %s: number of further capability names. */
													esc_html( _n( 'And %s more.', 'And %s more.', $blt_tk_more, 'blt-toolkit' ) ),
													esc_html( number_format_i18n( $blt_tk_more ) )
												);
												?>
											</p>
										<?php endif; ?>
									<?php endif; ?>
								<?php elseif ( BLT_Toolkit_Role_Transfer::STATUS_UNCHANGED === $blt_tk_row['status'] ) : ?>
									<p><?php esc_html_e( 'Same capabilities as the role here. Nothing to import.', 'blt-toolkit' ); ?></p>
								<?php elseif ( BLT_Toolkit_Role_Transfer::STATUS_NEW === $blt_tk_row['status'] ) : ?>
									<?php
									$blt_tk_granted = count( array_filter( $blt_tk_row['caps'] ) );
									$blt_tk_deny    = count( $blt_tk_row['caps'] ) - $blt_tk_granted;
									?>
									<p>
										<?php
										printf(
											/* translators: 1: number of capabilities granted, 2: number denied. */
											esc_html__( 'No role with this ID here. In the file: %1$s granted, %2$s denied.', 'blt-toolkit' ),
											esc_html( number_format_i18n( $blt_tk_granted ) ),
											esc_html( number_format_i18n( $blt_tk_deny ) )
										);
										?>
									</p>
								<?php else : ?>
									<p>
										<?php
										printf(
											/* translators: 1: capabilities only in the file, 2: capabilities only here, 3: capabilities set differently. */
											esc_html__( 'Only in the file: %1$s. Only here: %2$s. Set differently: %3$s.', 'blt-toolkit' ),
											esc_html( number_format_i18n( count( $blt_tk_rows['added'] ) ) ),
											esc_html( number_format_i18n( count( $blt_tk_rows['removed'] ) ) ),
											esc_html( number_format_i18n( count( $blt_tk_rows['flipped'] ) ) )
										);
										?>
									</p>
								<?php endif; ?>

								<?php if ( array() !== $blt_tk_row['unregistered'] ) : ?>
									<?php $blt_tk_unreg = count( $blt_tk_row['unregistered'] ); ?>
									<p>
										<?php echo BLT_Toolkit_Admin::badge( 'off', __( 'Not registered here', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
										<span class="blt-chips">
											<?php foreach ( array_slice( $blt_tk_row['unregistered'], 0, $blt_tk_chip_limit ) as $blt_tk_cap ) : ?>
												<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
											<?php endforeach; ?>
										</span>
										<?php if ( $blt_tk_unreg > $blt_tk_chip_limit ) : ?>
											<span class="blt-field-desc">
												<?php
												printf(
													/* translators: %s: number of further capability names. */
													esc_html( _n( 'And %s more.', 'And %s more.', $blt_tk_unreg - $blt_tk_chip_limit, 'blt-toolkit' ) ),
													esc_html( number_format_i18n( $blt_tk_unreg - $blt_tk_chip_limit ) )
												);
												?>
											</span>
										<?php endif; ?>
									</p>
								<?php endif; ?>

								<?php if ( array() !== $blt_tk_row['pending'] ) : ?>
									<p class="blt-field-desc">
										<?php
										printf(
											/* translators: %s: a list of content type names, such as Events. */
											esc_html__( 'Registered here once %s get separate permissions (below):', 'blt-toolkit' ),
											esc_html( implode( wp_get_list_item_separator(), array_unique( array_values( $blt_tk_row['pending'] ) ) ) )
										);
										?>
										<?php foreach ( array_keys( $blt_tk_row['pending'] ) as $blt_tk_cap ) : ?>
											<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_cap ); ?></code>
										<?php endforeach; ?>
									</p>
								<?php endif; ?>

								<?php if ( array() !== $blt_tk_rows['added'] || array() !== $blt_tk_rows['removed'] || array() !== $blt_tk_rows['flipped'] ) : ?>
									<details class="blt-toolkit-transfer-caps">
										<summary>
											<?php
											printf(
												/* translators: %s: role name. */
												esc_html__( 'Capabilities of %s', 'blt-toolkit' ),
												esc_html( '' !== $blt_tk_row['name'] ? $blt_tk_row['name'] : $blt_tk_id )
											);
											?>
										</summary>
										<?php if ( array() !== $blt_tk_rows['added'] ) : ?>
											<p><strong><?php echo BLT_Toolkit_Role_Transfer::STATUS_NEW === $blt_tk_row['status'] ? esc_html__( 'In the file', 'blt-toolkit' ) : esc_html__( 'Only in the file', 'blt-toolkit' ); ?></strong></p>
											<ul class="ul-disc">
												<?php foreach ( $blt_tk_rows['added'] as $blt_tk_cap_row ) : ?>
													<?php $blt_tk_cap_item( $blt_tk_cap_row, $blt_tk_cap_row['value'] ? '' : __( 'denied', 'blt-toolkit' ) ); ?>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
										<?php if ( array() !== $blt_tk_rows['removed'] ) : ?>
											<p><strong><?php esc_html_e( 'Only here (Replace removes them, Merge keeps them)', 'blt-toolkit' ); ?></strong></p>
											<ul class="ul-disc">
												<?php foreach ( $blt_tk_rows['removed'] as $blt_tk_cap_row ) : ?>
													<?php $blt_tk_cap_item( array_merge( $blt_tk_cap_row, array( 'value' => false ) ), $blt_tk_cap_row['value'] ? '' : __( 'denied here', 'blt-toolkit' ) ); ?>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
										<?php if ( array() !== $blt_tk_rows['flipped'] ) : ?>
											<p><strong><?php esc_html_e( 'Set differently', 'blt-toolkit' ); ?></strong></p>
											<ul class="ul-disc">
												<?php foreach ( $blt_tk_rows['flipped'] as $blt_tk_cap_row ) : ?>
													<?php $blt_tk_cap_item( $blt_tk_cap_row, $blt_tk_cap_row['value'] ? __( 'denied here, granted in the file', 'blt-toolkit' ) : __( 'granted here, denied in the file', 'blt-toolkit' ) ); ?>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</details>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Action', 'blt-toolkit' ); ?>">
								<label class="screen-reader-text" for="<?php echo esc_attr( $blt_tk_field ); ?>">
									<?php
									printf(
										/* translators: %s: role name. */
										esc_html__( 'Action for %s', 'blt-toolkit' ),
										esc_html( '' !== $blt_tk_row['name'] ? $blt_tk_row['name'] : $blt_tk_id )
									);
									?>
								</label>
								<select id="<?php echo esc_attr( $blt_tk_field ); ?>" name="actions[<?php echo esc_attr( $blt_tk_id ); ?>]">
									<?php foreach ( $blt_tk_row['actions'] as $blt_tk_action => $blt_tk_entry ) : ?>
										<option value="<?php echo esc_attr( $blt_tk_action ); ?>"<?php selected( $blt_tk_row['selected'], $blt_tk_action ); ?><?php disabled( ! $blt_tk_entry['allowed'] ); ?>><?php echo esc_html( $labels[ $blt_tk_action ] ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php foreach ( $blt_tk_denied as $blt_tk_action => $blt_tk_reason ) : ?>
									<p class="blt-field-desc">
										<?php
										printf(
											/* translators: 1: action name, such as Create, 2: why it is not allowed. */
											esc_html__( '%1$s is not available: %2$s', 'blt-toolkit' ),
											esc_html( $labels[ $blt_tk_action ] ),
											esc_html( $blt_tk_reason )
										);
										?>
									</p>
								<?php endforeach; ?>
								<?php if ( array() !== $blt_tk_crit ) : ?>
									<div class="blt-callout">
										<strong><?php esc_html_e( 'Critical', 'blt-toolkit' ); ?></strong>
										<?php foreach ( $blt_tk_crit as $blt_tk_action => $blt_tk_caps ) : ?>
											<span>
												<?php
												printf(
													/* translators: %s: action name, such as Create. */
													esc_html__( '%s grants:', 'blt-toolkit' ),
													esc_html( $labels[ $blt_tk_action ] )
												);
												?>
												<?php foreach ( $blt_tk_caps as $blt_tk_cap ) : ?>
													<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
												<?php endforeach; ?>
											</span>
										<?php endforeach; ?>
										<span><?php esc_html_e( 'These can be used to take over the site or its users.', 'blt-toolkit' ); ?></span>
										<label for="<?php echo esc_attr( $blt_tk_field . '-confirm' ); ?>">
											<?php
											printf(
												/* translators: %s: role name. */
												esc_html__( 'To confirm, type the role name: %s', 'blt-toolkit' ),
												'<strong>' . esc_html( $blt_tk_row['confirm_name'] ) . '</strong>'
											);
											?>
										</label>
										<input type="text" class="regular-text" id="<?php echo esc_attr( $blt_tk_field . '-confirm' ); ?>" name="confirm[<?php echo esc_attr( $blt_tk_id ); ?>]" value="" autocomplete="off" spellcheck="false" />
									</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( array() !== $preview['meta'] ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Role details', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'The description and "Hide the toolbar on the site" of these roles differ from the file. They change only if you choose to import them.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Import role details', 'blt-toolkit' ); ?></div>
					<div>
						<?php BLT_Toolkit_Admin::toggle( 'import_meta', $preview['meta_chosen'], __( 'Import role details', 'blt-toolkit' ), __( 'For roles that exist here after the import: a new role\'s details come with it only when it is created.', 'blt-toolkit' ), 'blt-tk-import-meta' ); ?>
					</div>
				</div>
				<?php foreach ( $preview['meta'] as $blt_tk_id => $blt_tk_meta ) : ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<?php echo esc_html( $blt_tk_meta['name'] ); ?>
							<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_id ); ?></code>
						</div>
						<div>
							<?php if ( $blt_tk_meta['from']['description'] !== $blt_tk_meta['to']['description'] ) : ?>
								<p>
									<?php
									printf(
										/* translators: 1: description now, 2: description in the file. */
										esc_html__( 'Description: %1$s becomes %2$s', 'blt-toolkit' ),
										'' !== $blt_tk_meta['from']['description'] ? '&ldquo;' . esc_html( $blt_tk_meta['from']['description'] ) . '&rdquo;' : esc_html__( '(none)', 'blt-toolkit' ),
										'' !== $blt_tk_meta['to']['description'] ? '&ldquo;' . esc_html( $blt_tk_meta['to']['description'] ) . '&rdquo;' : esc_html__( '(none)', 'blt-toolkit' )
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( $blt_tk_meta['from']['hide_toolbar'] !== $blt_tk_meta['to']['hide_toolbar'] ) : ?>
								<p><?php echo $blt_tk_meta['to']['hide_toolbar'] ? esc_html__( 'Toolbar on the site: shown becomes hidden', 'blt-toolkit' ) : esc_html__( 'Toolbar on the site: hidden becomes shown', 'blt-toolkit' ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $blt_tk_meta['reason'] ) : ?>
								<p class="blt-field-desc">
									<?php
									printf(
										/* translators: %s: why the details cannot be changed. */
										esc_html__( 'Not imported: %s', 'blt-toolkit' ),
										esc_html( $blt_tk_meta['reason'] )
									);
									?>
								</p>
							<?php elseif ( ! $blt_tk_meta['exists'] ) : ?>
								<p class="blt-field-desc"><?php esc_html_e( 'Only if the role is created.', 'blt-toolkit' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $preview['types'] ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Separate permissions', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'On the site the file came from, these content types have permissions of their own, which the roles in the file use. A type you choose gets separate permissions here first, seeded so that nobody\'s access changes, and then the roles are imported.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $preview['types'] as $blt_tk_type => $blt_tk_info ) : ?>
					<?php $blt_tk_type = (string) $blt_tk_type; ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<?php echo esc_html( $blt_tk_info['label'] ); ?>
							<code class="blt-chip"><?php echo esc_html( $blt_tk_type ); ?></code>
						</div>
						<div>
							<?php if ( 'ready' === $blt_tk_info['state'] ) : ?>
								<label class="blt-toggle">
									<input type="checkbox" name="types[]" value="<?php echo esc_attr( $blt_tk_type ); ?>" id="<?php echo esc_attr( 'blt-tk-import-type-' . $blt_tk_type ); ?>"<?php checked( $blt_tk_info['chosen'] ); ?> />
									<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
									<span class="blt-toggle-text">
										<span class="blt-toggle-label">
											<?php
											printf(
												/* translators: 1: plural content type name, such as Events, 2: capability pattern, such as *_events. */
												esc_html__( 'Give %1$s separate permissions (%2$s)', 'blt-toolkit' ),
												esc_html( $blt_tk_info['label'] ),
												esc_html( '*_' . $blt_tk_info['plural'] )
											);
											?>
										</span>
										<span class="blt-toggle-desc">
											<?php
											printf(
												/* translators: 1: number of roles, 2: number of people. */
												esc_html__( 'Roles that receive the new capabilities to keep their access: %1$s. People with capabilities of their own who do: %2$s.', 'blt-toolkit' ),
												esc_html( number_format_i18n( (int) $blt_tk_info['roles'] ) ),
												esc_html( number_format_i18n( (int) $blt_tk_info['users'] ) )
											);
											?>
										</span>
									</span>
								</label>
							<?php elseif ( 'missing' === $blt_tk_info['state'] ) : ?>
								<?php echo BLT_Toolkit_Admin::badge( 'off', __( 'Skipped', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								<p class="blt-field-desc">
									<?php
									printf(
										/* translators: %s: post type key. */
										esc_html__( 'No content type %s is registered on this site, so its separate permissions are skipped and its capabilities are flagged "not registered here". Activate the plugin that adds it, then import the file again.', 'blt-toolkit' ),
										'<code>' . esc_html( $blt_tk_type ) . '</code>'
									);
									?>
								</p>
							<?php elseif ( 'same' === $blt_tk_info['state'] ) : ?>
								<?php echo BLT_Toolkit_Admin::badge( 'on', __( 'Already separate here', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								<p class="blt-field-desc"><?php esc_html_e( 'This content type already has the same separate permissions here. Nothing to do.', 'blt-toolkit' ); ?></p>
							<?php else : ?>
								<?php echo BLT_Toolkit_Admin::badge( 'off', __( 'Skipped', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								<p class="blt-field-desc"><?php echo esc_html( $blt_tk_info['message'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="blt-settings-footer">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'blt-toolkit' ); ?></button>
		<button type="submit" class="button" form="<?php echo esc_attr( $blt_tk_discard ); ?>"><?php esc_html_e( 'Discard this file', 'blt-toolkit' ); ?></button>
	</div>
</form>

<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( $blt_tk_discard ); ?>">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Transfer::DISCARD_ACTION ); ?>
</form>
