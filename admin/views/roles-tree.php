<?php
/**
 * Roles screen: one level of the capability group tree (RO-13), as links,
 * one URL per group (RO-26). Includes itself for each level below.
 *
 * @package Blt_Toolkit
 *
 * @var array[] $nodes Each: id, label, url, total, granted, current, children.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<ul class="blt-toolkit-tree">
	<?php foreach ( $nodes as $blt_tk_node ) : ?>
		<li>
			<a class="blt-toolkit-tree-link<?php echo $blt_tk_node['current'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $blt_tk_node['url'] ); ?>"<?php echo $blt_tk_node['current'] ? ' aria-current="page"' : ''; ?>>
				<span class="blt-toolkit-tree-label"><?php echo esc_html( $blt_tk_node['label'] ); ?></span>
				<span class="blt-toolkit-tree-count">
					<span aria-hidden="true"><?php echo esc_html( number_format_i18n( (int) $blt_tk_node['granted'] ) . '/' . number_format_i18n( (int) $blt_tk_node['total'] ) ); ?></span>
					<span class="screen-reader-text">
						<?php
						printf(
							/* translators: 1: capabilities granted, 2: capabilities in the group. */
							esc_html__( '%1$s of %2$s granted', 'blt-toolkit' ),
							esc_html( number_format_i18n( (int) $blt_tk_node['granted'] ) ),
							esc_html( number_format_i18n( (int) $blt_tk_node['total'] ) )
						);
						?>
					</span>
				</span>
			</a>
			<?php
			if ( array() !== $blt_tk_node['children'] ) {
				BLT_Toolkit_Admin_Roles::partial( 'roles-tree', array( 'nodes' => $blt_tk_node['children'] ) );
			}
			?>
		</li>
	<?php endforeach; ?>
</ul>
