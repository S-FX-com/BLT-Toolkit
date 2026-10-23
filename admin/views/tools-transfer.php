<?php
/**
 * Tools > Export and import, before an upload: the export form (RO-44) and
 * the import upload form (RO-45). Two separate forms, each a POST with a
 * nonce; the export answers with a file.
 *
 * Included by BLT_Toolkit_Admin_Transfer::render() inside the Tools
 * screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var bool    $expired    Whether the URL named an upload that is gone.
 * @var array[] $roles      Each array( 'id', 'name', 'granted', 'denied', 'details', 'selected' ).
 * @var int     $max_upload Largest file accepted, in bytes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( $expired ) : ?>
	<div class="notice notice-warning inline blt-toolkit-notice" role="status">
		<p><?php esc_html_e( 'That preview is no longer available. It expires after an hour, or when you upload another file. Upload the file again.', 'blt-toolkit' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Transfer::EXPORT_ACTION ); ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Export roles', 'blt-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'Download the roles you choose as a file, to import them on another site. The file holds each role\'s name and capabilities, its description and toolbar setting, and the separate permissions of any content type the roles use. It holds no users.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Roles to export', 'blt-toolkit' ); ?></legend>
				<table class="widefat striped blt-toolkit-transfer-roles">
					<thead>
						<tr>
							<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Export', 'blt-toolkit' ); ?></span></td>
							<th scope="col"><?php esc_html_e( 'Role', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Capabilities', 'blt-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $roles as $blt_tk_row ) : ?>
							<?php $blt_tk_field = 'blt-tk-export-' . sanitize_html_class( $blt_tk_row['id'] ); ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" id="<?php echo esc_attr( $blt_tk_field ); ?>" name="roles[]" value="<?php echo esc_attr( $blt_tk_row['id'] ); ?>"<?php checked( $blt_tk_row['selected'] ); ?> />
								</th>
								<td>
									<label for="<?php echo esc_attr( $blt_tk_field ); ?>"><strong><?php echo esc_html( $blt_tk_row['name'] ); ?></strong></label>
									<code class="blt-chip"><?php echo esc_html( $blt_tk_row['id'] ); ?></code>
									<?php if ( $blt_tk_row['details'] ) : ?>
										<span class="blt-field-desc"><?php esc_html_e( 'With description or toolbar setting', 'blt-toolkit' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $blt_tk_row['denied'] > 0 ) {
										printf(
											/* translators: 1: number of capabilities granted, 2: number denied. */
											esc_html__( '%1$s granted, %2$s denied', 'blt-toolkit' ),
											esc_html( number_format_i18n( $blt_tk_row['granted'] ) ),
											esc_html( number_format_i18n( $blt_tk_row['denied'] ) )
										);
									} else {
										printf(
											/* translators: %s: number of capabilities granted. */
											esc_html__( '%s granted', 'blt-toolkit' ),
											esc_html( number_format_i18n( $blt_tk_row['granted'] ) )
										);
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</fieldset>
		</div>
	</div>

	<div class="blt-settings-footer">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Download export file', 'blt-toolkit' ); ?></button>
	</div>
</form>

<?php // The action is in the URL too: PHP drops the whole body of a POST over post_max_size, and the router can then still say why. ?>
<form method="post" action="<?php echo esc_url( add_query_arg( 'action', BLT_Toolkit_Admin_Transfer::UPLOAD_ACTION, BLT_Toolkit_Admin::post_url() ) ); ?>" enctype="multipart/form-data">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Transfer::UPLOAD_ACTION ); ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Import roles', 'blt-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'Upload a file exported from BLT Toolkit on another site. Nothing changes yet: the next step shows, for each role in the file, whether it is new here or differs from the role here, and lets you choose what to do with it.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-tk-import-file"><?php esc_html_e( 'Roles file', 'blt-toolkit' ); ?></label></div>
				<div>
					<input type="file" id="blt-tk-import-file" name="<?php echo esc_attr( BLT_Toolkit_Admin_Transfer::FILE_FIELD ); ?>" accept=".json,application/json" required aria-describedby="blt-tk-import-file-desc" />
					<p class="blt-field-desc" id="blt-tk-import-file-desc">
						<?php
						printf(
							/* translators: %s: the largest file accepted, such as 1 MB. */
							esc_html__( 'A .json file named like blt-toolkit-roles-example-com-2026-10-22.json, of at most %s.', 'blt-toolkit' ),
							esc_html( size_format( $max_upload ) )
						);
						?>
					</p>
				</div>
			</div>
		</div>
	</div>

	<div class="blt-settings-footer">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Preview the import', 'blt-toolkit' ); ?></button>
	</div>
</form>
