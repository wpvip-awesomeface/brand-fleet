<?php
/**
 * Shared Content — renders a master-site page in the current site's context.
 *
 * @var array $attributes Block attributes ("slug": master page path; '' = master front page).
 */

namespace BrandFleet;

$brand_fleet_slug = isset( $attributes['slug'] ) ? sanitize_text_field( (string) $attributes['slug'] ) : '';
$brand_fleet_html = Plugin::get_instance()->content->render_shared( $brand_fleet_slug );

if ( '' === $brand_fleet_html ) {
	// Editors get a hint; visitors get nothing.
	if ( current_user_can( 'edit_posts' ) ) {
		printf(
			'<div %s><em>%s</em></div>',
			get_block_wrapper_attributes( array( 'class' => 'brand-fleet-shared-content brand-fleet-shared-content--empty' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by core.
			esc_html__( 'Shared Content: nothing found — check the master site setting and the slug.', 'brand-fleet' )
		);
	}
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'brand-fleet-shared-content' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by core. ?>>
	<?php echo $brand_fleet_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered post content from the trusted master site, passed through the_content filters (same trust model as core rendering its own post_content). ?>
</div>
