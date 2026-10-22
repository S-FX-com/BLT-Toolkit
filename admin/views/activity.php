<?php
/**
 * Activity screen (SPEC 13.2): filter toolbar, the log table in a card, and
 * Export CSV.
 *
 * Included by BLT_Toolkit_Admin_Activity::render().
 *
 * @package Blt_Toolkit
 *
 * @var array                             $args      Normalized query arguments.
 * @var BLT_Toolkit_Admin_Activity_Table  $table     The prepared table.
 * @var int                               $total     Rows matching the filters.
 * @var bool                              $filtered  Whether any filter is set.
 * @var int                               $log_total Rows in the whole log.
 * @var array<string,string>              $filters   The filters that are set, as URL arguments.
 * @var array<string,string>              $sort      A non-default sort, as URL arguments.
 * @var array<string,string>              $modules   Module filter choices.
 * @var array<string,string>              $actions   Action filter choices.
 * @var array<string,string>|null         $actors    Who filter choices, or null for a text field.
 * @var array<string,string>              $acting_as Acting-as filter choices.
 * @var string                            $reset_url The unfiltered screen.
 * @var string                            $sub       Subtitle.
 * @var array                             $buttons   Header buttons.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$actor_text = 0 === strpos( $args['actor'], 'login:' ) ? substr( $args['actor'], 6 ) : '';
?>
<div class="wrap blt-ui blt-ui-wide blt-toolkit blt-toolkit-activity">
	<?php BLT_Toolkit_Admin::header( $sub, $buttons ); ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Activity log', 'blt-toolkit' ); ?></h2>
			<p>
				<?php
				if ( $filtered ) {
					printf(
						/* translators: 1: number of entries matching the filters, 2: number of entries in the log. */
						esc_html( _n( '%1$s entry matches these filters, out of %2$s in the log.', '%1$s entries match these filters, out of %2$s in the log.', $total, 'blt-toolkit' ) ),
						esc_html( number_format_i18n( $total ) ),
						esc_html( number_format_i18n( $log_total ) )
					);
				} elseif ( 0 === $total ) {
					esc_html_e( 'Every switch, username change and role change is recorded with who made it, including who they were acting as if they had switched to another user.', 'blt-toolkit' );
				} else {
					printf(
						/* translators: %s: number of entries in the log. */
						esc_html( _n( '%s entry. Every switch, username change and role change is recorded with who made it, including who they were acting as if they had switched to another user.', '%s entries. Every switch, username change and role change is recorded with who made it, including who they were acting as if they had switched to another user.', $total, 'blt-toolkit' ) ),
						esc_html( number_format_i18n( $total ) )
					);
				}
				?>
			</p>
			<?php if ( $total > 0 ) : ?>
				<div class="blt-card-header-badges">
					<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-activity-export">
						<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Activity::EXPORT_ACTION ); ?>
						<?php foreach ( $filters as $name => $value ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
						<?php endforeach; ?>
						<button type="submit" class="button">
							<?php
							echo esc_html(
								$filtered
									? __( 'Export these entries as CSV', 'blt-toolkit' )
									: __( 'Export CSV', 'blt-toolkit' )
							);
							?>
						</button>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $log_total > 0 ) : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="blt-toolbar blt-toolkit-activity-filters" aria-label="<?php esc_attr_e( 'Filter the activity log', 'blt-toolkit' ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( BLT_Toolkit_Admin::screens()['activity']['slug'] ); ?>" />
				<?php foreach ( $sort as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endforeach; ?>

				<label class="blt-toolkit-activity-filter" for="blt-toolkit-activity-module">
					<span><?php esc_html_e( 'Module', 'blt-toolkit' ); ?></span>
					<select id="blt-toolkit-activity-module" name="module">
						<option value=""><?php esc_html_e( 'All modules', 'blt-toolkit' ); ?></option>
						<?php foreach ( $modules as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['module'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="blt-toolkit-activity-filter" for="blt-toolkit-activity-action">
					<span><?php esc_html_e( 'Action', 'blt-toolkit' ); ?></span>
					<select id="blt-toolkit-activity-action" name="log_action">
						<option value=""><?php esc_html_e( 'All actions', 'blt-toolkit' ); ?></option>
						<?php foreach ( $actions as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['action'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="blt-toolkit-activity-filter" for="blt-toolkit-activity-actor">
					<span><?php esc_html_e( 'Who', 'blt-toolkit' ); ?></span>
					<?php if ( null !== $actors ) : ?>
						<select id="blt-toolkit-activity-actor" name="actor">
							<option value=""><?php esc_html_e( 'Anyone', 'blt-toolkit' ); ?></option>
							<option value="none" <?php selected( $args['actor'], 'none' ); ?>><?php esc_html_e( 'System (no signed-in user)', 'blt-toolkit' ); ?></option>
							<?php foreach ( $actors as $user_id => $label ) : ?>
								<option value="<?php echo esc_attr( $user_id ); ?>" <?php selected( $args['actor'], (string) $user_id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="text" id="blt-toolkit-activity-actor" name="actor" value="<?php echo esc_attr( '' !== $actor_text ? $actor_text : $args['actor'] ); ?>" placeholder="<?php esc_attr_e( 'Username or user ID', 'blt-toolkit' ); ?>" />
					<?php endif; ?>
				</label>

				<label class="blt-toolkit-activity-filter" for="blt-toolkit-activity-acting-as">
					<span><?php esc_html_e( 'Acting as', 'blt-toolkit' ); ?></span>
					<select id="blt-toolkit-activity-acting-as" name="acting_as">
						<option value=""><?php esc_html_e( 'Switched or not', 'blt-toolkit' ); ?></option>
						<option value="none" <?php selected( $args['acting_as'], 'none' ); ?>><?php esc_html_e( 'Not switched', 'blt-toolkit' ); ?></option>
						<option value="any" <?php selected( $args['acting_as'], 'any' ); ?>><?php esc_html_e( 'Any switched session', 'blt-toolkit' ); ?></option>
						<?php foreach ( $acting_as as $user_id => $label ) : ?>
							<option value="<?php echo esc_attr( $user_id ); ?>" <?php selected( $args['acting_as'], (string) $user_id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="blt-toolkit-activity-filter" for="blt-toolkit-activity-from">
					<span><?php esc_html_e( 'From', 'blt-toolkit' ); ?></span>
					<input type="date" id="blt-toolkit-activity-from" name="from" value="<?php echo esc_attr( $args['from'] ); ?>" />
				</label>

				<label class="blt-toolkit-activity-filter" for="blt-toolkit-activity-to">
					<span><?php esc_html_e( 'To', 'blt-toolkit' ); ?></span>
					<input type="date" id="blt-toolkit-activity-to" name="to" value="<?php echo esc_attr( $args['to'] ); ?>" />
				</label>

				<span class="blt-toolkit-activity-filter-actions">
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'blt-toolkit' ); ?></button>
					<?php if ( $filtered ) : ?>
						<a href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Reset', 'blt-toolkit' ); ?></a>
					<?php endif; ?>
				</span>
			</form>
		<?php endif; ?>

		<div class="blt-card-body">
			<?php if ( $total > 0 ) : ?>
				<?php // A GET form, so the page-number box under the table works without JavaScript. ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="blt-toolkit-activity-table">
					<input type="hidden" name="page" value="<?php echo esc_attr( BLT_Toolkit_Admin::screens()['activity']['slug'] ); ?>" />
					<?php foreach ( array_merge( $filters, $sort ) as $name => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
					<?php $table->display(); ?>
				</form>
			<?php elseif ( $filtered ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'No activity matches these filters', 'blt-toolkit' ); ?></span>
					<span><a href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Show all activity', 'blt-toolkit' ); ?></a></span>
				</div>
			<?php else : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'No activity recorded yet', 'blt-toolkit' ); ?></span>
					<span><?php esc_html_e( 'Switches, username changes and role changes will be listed here as they happen, with who made each change and when.', 'blt-toolkit' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
