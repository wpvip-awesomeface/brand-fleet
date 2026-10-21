<?php
/**
 * Variable List — a List variable as this site's own list.
 *
 * @var array $attributes Block attributes ("variable": list variable key, '' = the first one; "ordered").
 */

namespace BrandFleet;

$brand_fleet_key = Blocks::variable( (string) ( $attributes['variable'] ?? '' ), 'list' );
$brand_fleet_items = '' === $brand_fleet_key ? array() : Fleet::items( Blocks::value( $brand_fleet_key ) );

if ( ! $brand_fleet_items ) {
	if ( current_user_can( 'edit_posts' ) ) {
		printf( '<div %s><em>%s</em></div>', get_block_wrapper_attributes( array( 'class' => 'brand-fleet-list brand-fleet-list--empty' ) ), esc_html__( 'Variable List: nothing to show yet. Add a List variable under Brand Fleet → Variables, then fill it in for this site.', 'brand-fleet' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper attributes are pre-escaped by core.
	}
	return;
}
$brand_fleet_tag = empty( $attributes['ordered'] ) ? 'ul' : 'ol';
?>
<<?php echo esc_attr( $brand_fleet_tag ); ?> <?php echo get_block_wrapper_attributes( array( 'class' => 'brand-fleet-list' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by core. ?>>
	<?php foreach ( $brand_fleet_items as $brand_fleet_item ) : ?>
		<li><?php echo esc_html( $brand_fleet_item ); ?></li>
	<?php endforeach; ?>
</<?php echo esc_attr( $brand_fleet_tag ); ?>>
