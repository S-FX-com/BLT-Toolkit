<?php
/**
 * One role's or person's change on the separate-permissions pages: the
 * capabilities granted, denied and removed, each with what it replaces.
 *
 * Included inside a table cell by isolation-isolate.php and
 * isolation-cleanup.php.
 *
 * @package Blt_Toolkit
 *
 * @var array $blt_tk_change array( 'add' => cap => bool, 'remove' => string[], 'from' => cap => replaced cap ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_from    = isset( $blt_tk_change['from'] ) ? (array) $blt_tk_change['from'] : array();
$blt_tk_granted = array_keys( array_filter( (array) $blt_tk_change['add'] ) );
$blt_tk_denied  = array_keys( array_diff_key( (array) $blt_tk_change['add'], array_flip( $blt_tk_granted ) ) );
$blt_tk_lists   = array(
	array( $blt_tk_granted, __( 'Granted', 'blt-toolkit' ) ),
	array( $blt_tk_denied, __( 'Denied', 'blt-toolkit' ) ),
	array( (array) $blt_tk_change['remove'], __( 'Removed', 'blt-toolkit' ) ),
);
?>
<?php foreach ( $blt_tk_lists as $blt_tk_list ) : ?>
	<?php if ( array() !== $blt_tk_list[0] ) : ?>
		<p class="blt-toolkit-isolation-change">
			<strong><?php echo esc_html( $blt_tk_list[1] ); ?>:</strong>
			<?php foreach ( $blt_tk_list[0] as $blt_tk_cap ) : ?>
				<span class="blt-toolkit-isolation-cap">
					<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_cap ); ?></code><?php if ( isset( $blt_tk_from[ $blt_tk_cap ] ) ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: the capability it replaces, such as edit_posts. */
								esc_html__( '(as %s)', 'blt-toolkit' ),
								esc_html( (string) $blt_tk_from[ $blt_tk_cap ] )
							);
							?>
						</span>
					<?php endif; ?>
				</span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
<?php endforeach; ?>
