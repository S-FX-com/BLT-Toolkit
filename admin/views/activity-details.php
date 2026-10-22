<?php
/**
 * One activity log row's disclosure: everything recorded, readable.
 *
 * Included by BLT_Toolkit_Admin_Activity::details_html(), inside a
 * `<details>` element in the table's first column.
 *
 * @package Blt_Toolkit
 *
 * @var array                      $row     Row from BLT_Toolkit_Log::shape_row().
 * @var array                      $details The row's details, without the switch chain.
 * @var array{actor:string,acting_as:string} $who Who made the change.
 * @var string[]                   $chain   The switch chain as names, oldest first, or empty.
 * @var string                     $object  What the row acted on, or ''.
 * @var BLT_Toolkit_Admin_Activity $this    The Activity screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dl class="blt-toolkit-activity-fields">
	<dt><?php esc_html_e( 'When', 'blt-toolkit' ); ?></dt>
	<dd>
		<?php
		printf(
			/* translators: 1: date and time in the site's timezone, 2: the same time in UTC. */
			esc_html__( '%1$s (%2$s UTC)', 'blt-toolkit' ),
			esc_html( $this->site_time( $row['created_at'] ) ),
			esc_html( $row['created_at'] )
		);
		?>
	</dd>

	<dt><?php esc_html_e( 'Module and action', 'blt-toolkit' ); ?></dt>
	<dd>
		<?php
		printf(
			/* translators: 1: module name, 2: action name. */
			esc_html__( '%1$s: %2$s', 'blt-toolkit' ),
			esc_html( $this->module_label( $row['module'] ) ),
			esc_html( $this->action_label( $row['action'] ) )
		);
		?>
		<code><?php echo esc_html( $row['module'] . '/' . $row['action'] ); ?></code>
	</dd>

	<dt><?php esc_html_e( 'Who', 'blt-toolkit' ); ?></dt>
	<dd><?php echo esc_html( $who['actor'] ); ?></dd>

	<?php if ( '' !== $who['acting_as'] ) : ?>
		<dt><?php esc_html_e( 'Acting as', 'blt-toolkit' ); ?></dt>
		<dd><?php echo esc_html( $who['acting_as'] ); ?></dd>
	<?php endif; ?>

	<?php if ( count( $chain ) > 1 ) : ?>
		<dt><?php esc_html_e( 'Switched through', 'blt-toolkit' ); ?></dt>
		<dd>
			<ol class="blt-toolkit-activity-chain">
				<?php foreach ( $chain as $chain_name ) : ?>
					<li><?php echo esc_html( $chain_name ); ?></li>
				<?php endforeach; ?>
			</ol>
		</dd>
	<?php endif; ?>

	<?php if ( '' !== $object ) : ?>
		<dt><?php esc_html_e( 'Changed', 'blt-toolkit' ); ?></dt>
		<dd><?php echo esc_html( $object ); ?></dd>
	<?php endif; ?>

	<?php if ( null !== $row['snapshot_id'] ) : ?>
		<dt><?php esc_html_e( 'Role snapshot', 'blt-toolkit' ); ?></dt>
		<dd>
			<?php
			printf(
				/* translators: %d: snapshot ID. */
				esc_html__( 'Snapshot %d, taken just before this change', 'blt-toolkit' ),
				(int) $row['snapshot_id']
			);
			?>
		</dd>
	<?php endif; ?>

	<?php if ( '' !== $row['ip'] ) : ?>
		<dt><?php esc_html_e( 'IP address', 'blt-toolkit' ); ?></dt>
		<dd><?php echo esc_html( $row['ip'] ); ?></dd>
	<?php endif; ?>

	<?php if ( '' !== $row['source'] ) : ?>
		<dt><?php esc_html_e( 'Imported from', 'blt-toolkit' ); ?></dt>
		<dd><?php echo esc_html( $row['source'] ); ?></dd>
	<?php endif; ?>

	<dt><?php esc_html_e( 'Entry', 'blt-toolkit' ); ?></dt>
	<dd><?php echo esc_html( '#' . (int) $row['id'] ); ?></dd>
</dl>

<?php if ( ! empty( $details ) ) : ?>
	<div class="blt-toolkit-activity-recorded">
		<p class="blt-toolkit-activity-block-title"><?php esc_html_e( 'Recorded details', 'blt-toolkit' ); ?></p>
		<?php echo $this->fields_html( $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped piece by piece in fields_html(). ?>
	</div>
<?php endif; ?>
