<?php
/**
 * Bulk rename from CSV, before a dry run (UN-30 steps 1 and 2): download the
 * template, upload the file. Two separate forms, each a POST with a nonce.
 *
 * Included by admin/views/usernames.php inside the card.
 *
 * @package Blt_Toolkit
 *
 * @var int $max_rows  Row cap (rows with a new username).
 * @var int $max_upload Largest file accepted, in bytes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card-body">
	<div class="blt-field">
		<div class="blt-field-label"><?php esc_html_e( '1. Download the template', 'blt-toolkit' ); ?></div>
		<div>
			<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
				<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Usernames::TEMPLATE_ACTION ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Download template', 'blt-toolkit' ); ?></button>
			</form>
			<p class="blt-field-desc"><?php esc_html_e( 'A CSV file with a row for every user you can see: user_id, username and an empty new_username column. Fill in new_username for the users to rename and leave it empty for the others.', 'blt-toolkit' ); ?></p>
		</div>
	</div>

	<div class="blt-field">
		<div class="blt-field-label"><label for="blt-tk-bulk-csv"><?php esc_html_e( '2. Upload the file', 'blt-toolkit' ); ?></label></div>
		<div>
			<?php // The action is in the URL too: PHP drops the whole body of a POST over post_max_size, and the router can then still say why (UN-30 step 2). ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( 'action', BLT_Toolkit_Admin_Usernames::PLAN_ACTION, BLT_Toolkit_Admin::post_url() ) ); ?>" enctype="multipart/form-data" class="blt-toolkit-bulk-upload">
				<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Usernames::PLAN_ACTION ); ?>
				<input type="file" id="blt-tk-bulk-csv" name="<?php echo esc_attr( BLT_Toolkit_Admin_Usernames::FILE_FIELD ); ?>" accept=".csv,text/csv" required aria-describedby="blt-tk-bulk-csv-desc" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Check the file', 'blt-toolkit' ); ?></button>
			</form>
			<p class="blt-field-desc" id="blt-tk-bulk-csv-desc">
				<?php
				printf(
					/* translators: 1: the most rows with a new username one file may have, such as 5,000. 2: the largest file accepted, such as 2 MB. */
					esc_html__( 'Columns user_id and new_username, or username and new_username, in the first row. UTF-8, with commas or semicolons, as saved by Excel, Numbers or Google Sheets. Up to %1$s rows with a new username (rows left empty are skipped), in a file of at most %2$s. Nothing changes yet: you see a dry run of every row first.', 'blt-toolkit' ),
					esc_html( number_format_i18n( $max_rows ) ),
					esc_html( size_format( $max_upload ) )
				);
				?>
			</p>
		</div>
	</div>
</div>
