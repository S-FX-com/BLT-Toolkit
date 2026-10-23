<?php
/**
 * Roles screen: the Advanced view toolbar (RO-22), a GET form so it works
 * without JavaScript (RO-26): quick filter (label and raw name), Granted
 * only, Show deprecated, Readable names, and how many columns the matrix
 * uses (remembered per person). With JavaScript the filter and Granted only
 * apply as you type; the other controls, like every control without it,
 * take effect with Apply, so changing one never reloads the page under the
 * keyboard (WCAG 3.2.2).
 *
 * The two settings that default from Settings > Roles send `0` from a
 * hidden field before the toggle, so switching them off is kept in the URL.
 *
 * @package Blt_Toolkit
 *
 * @var array $state The request state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_page = BLT_Toolkit_Admin::screens();
$blt_tk_page = $blt_tk_page['roles']['slug'];
?>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="blt-toolbar blt-toolkit-roles-toolbar" role="search" data-blt-toolbar>
	<input type="hidden" name="page" value="<?php echo esc_attr( $blt_tk_page ); ?>" />
	<?php if ( ! empty( $state['user_id'] ) ) : ?>
		<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) (int) $state['user_id'] ); ?>" />
	<?php else : ?>
		<input type="hidden" name="role" value="<?php echo esc_attr( $state['role'] ); ?>" />
	<?php endif; ?>
	<input type="hidden" name="view" value="advanced" />
	<?php if ( 'all' !== $state['group'] ) : ?>
		<input type="hidden" name="group" value="<?php echo esc_attr( $state['group'] ); ?>" />
	<?php endif; ?>

	<span class="blt-toolkit-roles-filter">
		<label for="blt-tk-cap-filter"><?php esc_html_e( 'Quick filter', 'blt-toolkit' ); ?></label>
		<input type="search" id="blt-tk-cap-filter" name="s" value="<?php echo esc_attr( $state['search'] ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Admin_Roles::SEARCH_MAX ); ?>" data-blt-filter />
	</span>

	<?php BLT_Toolkit_Admin::toggle( 'granted', $state['granted'], __( 'Granted only', 'blt-toolkit' ), '', 'blt-tk-granted' ); ?>

	<input type="hidden" name="deprecated" value="0" />
	<?php BLT_Toolkit_Admin::toggle( 'deprecated', $state['deprecated'], __( 'Show deprecated', 'blt-toolkit' ), '', 'blt-tk-deprecated' ); ?>

	<input type="hidden" name="readable" value="0" />
	<?php BLT_Toolkit_Admin::toggle( 'readable', $state['readable'], __( 'Readable names', 'blt-toolkit' ), '', 'blt-tk-readable' ); ?>

	<span class="blt-toolkit-roles-columns">
		<label for="blt-tk-cols"><?php esc_html_e( 'Columns', 'blt-toolkit' ); ?></label>
		<select id="blt-tk-cols" name="cols">
			<?php foreach ( array( 1, 2, 3 ) as $blt_tk_cols ) : ?>
				<option value="<?php echo esc_attr( (string) $blt_tk_cols ); ?>" <?php selected( (int) $state['cols'], $blt_tk_cols ); ?>><?php echo esc_html( number_format_i18n( $blt_tk_cols ) ); ?></option>
			<?php endforeach; ?>
		</select>
	</span>

	<span class="blt-toolbar-spacer"></span>
	<button type="submit" class="button"><?php esc_html_e( 'Apply', 'blt-toolkit' ); ?></button>
</form>
