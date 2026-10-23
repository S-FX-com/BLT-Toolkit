<?php
/**
 * Roles screen: compare two roles (RO-25), read only. A GET form picks the
 * roles; the result lists, group by group, every capability the two treat
 * differently, side by side.
 *
 * @package Blt_Toolkit
 *
 * @var array<string,string> $names    Role ID => display name.
 * @var string               $a        First role, or ''.
 * @var string               $b        Second role, or ''.
 * @var string               $b_pick   Role preselected as the second one.
 * @var array[]              $sections Each: id, label, rows (cap, label, tier, a, b: granted|denied|absent).
 * @var array|null           $counts   only_a, only_b, differ, same; null before two roles are chosen.
 * @var string               $back_url The role editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_page   = BLT_Toolkit_Admin::screens();
$blt_tk_page   = $blt_tk_page['roles']['slug'];
$blt_tk_states = array(
	'granted' => __( 'Granted', 'blt-toolkit' ),
	'denied'  => __( 'Denied', 'blt-toolkit' ),
	'absent'  => __( 'Not granted', 'blt-toolkit' ),
);
$blt_tk_name_a = isset( $names[ $a ] ) ? $names[ $a ] : $a;
$blt_tk_name_b = isset( $names[ $b ] ) ? $names[ $b ] : $b;
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Compare roles', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'See where two roles differ, group by group. Nothing changes here.', 'blt-toolkit' ); ?></p>
	</div>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="blt-toolbar">
		<input type="hidden" name="page" value="<?php echo esc_attr( $blt_tk_page ); ?>" />
		<input type="hidden" name="step" value="compare" />
		<span class="blt-toolkit-roles-filter">
			<label for="blt-tk-compare-a"><?php esc_html_e( 'Role', 'blt-toolkit' ); ?></label>
			<select id="blt-tk-compare-a" name="a">
				<?php foreach ( $names as $blt_tk_id => $blt_tk_name ) : ?>
					<option value="<?php echo esc_attr( (string) $blt_tk_id ); ?>" <?php selected( $a, (string) $blt_tk_id ); ?>><?php echo esc_html( $blt_tk_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span class="blt-toolkit-roles-filter">
			<label for="blt-tk-compare-b"><?php esc_html_e( 'Compared with', 'blt-toolkit' ); ?></label>
			<select id="blt-tk-compare-b" name="b">
				<?php foreach ( $names as $blt_tk_id => $blt_tk_name ) : ?>
					<option value="<?php echo esc_attr( (string) $blt_tk_id ); ?>" <?php selected( $b_pick, (string) $blt_tk_id ); ?>><?php echo esc_html( $blt_tk_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span class="blt-toolbar-spacer"></span>
		<button type="submit" class="button"><?php esc_html_e( 'Compare', 'blt-toolkit' ); ?></button>
	</form>
	<div class="blt-card-body">
		<?php if ( null === $counts ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'Choose two different roles', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'Pick a role and another to compare it with, then select Compare.', 'blt-toolkit' ); ?></span>
			</div>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: 1: number of capabilities, 2: first role, 3: number, 4: second role, 5: number they share. */
					esc_html__( '%1$s granted only to %2$s, %3$s granted only to %4$s, %5$s granted to both.', 'blt-toolkit' ),
					esc_html( number_format_i18n( (int) $counts['only_a'] ) ),
					'<strong>' . esc_html( $blt_tk_name_a ) . '</strong>',
					esc_html( number_format_i18n( (int) $counts['only_b'] ) ),
					'<strong>' . esc_html( $blt_tk_name_b ) . '</strong>',
					esc_html( number_format_i18n( (int) $counts['same'] ) )
				);
				?>
			</p>
			<?php if ( array() === $sections ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'These roles have the same capabilities', 'blt-toolkit' ); ?></span>
				</div>
			<?php endif; ?>
			<?php foreach ( $sections as $blt_tk_section ) : ?>
				<h3 class="blt-toolkit-matrix-heading"><?php echo esc_html( $blt_tk_section['label'] ); ?></h3>
				<div class="blt-toolkit-table-scroll">
					<table class="widefat striped blt-toolkit-compare">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Capability', 'blt-toolkit' ); ?></th>
								<th scope="col"><?php echo esc_html( $blt_tk_name_a ); ?></th>
								<th scope="col"><?php echo esc_html( $blt_tk_name_b ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $blt_tk_section['rows'] as $blt_tk_row ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $blt_tk_row['label'] ); ?>
										<code class="blt-chip"><?php echo esc_html( $blt_tk_row['cap'] ); ?></code>
										<?php echo BLT_Toolkit_Admin_Roles::tier_badge( $blt_tk_row['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
									</th>
									<?php foreach ( array( 'a', 'b' ) as $blt_tk_side ) : ?>
										<td class="blt-toolkit-compare-<?php echo esc_attr( $blt_tk_row[ $blt_tk_side ] ); ?>"><?php echo esc_html( $blt_tk_states[ $blt_tk_row[ $blt_tk_side ] ] ); ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
<div class="blt-settings-footer">
	<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a>
</div>
