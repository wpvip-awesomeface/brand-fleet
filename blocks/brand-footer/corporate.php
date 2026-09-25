<?php
/** Corporate variant: site identity and only existing, published local links. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;

$brand_fleet_links = array();
foreach ( array( 'about' => 'Our company', 'our-approach' => 'Our approach', 'our-location' => 'Our location', 'contact' => 'Contact', 'privacy' => 'Privacy', 'accessibility' => 'Accessibility', 'terms' => 'Terms' ) as $brand_fleet_slug => $brand_fleet_label ) {
	$brand_fleet_page = get_page_by_path( $brand_fleet_slug );
	if ( $brand_fleet_page && 'publish' === $brand_fleet_page->post_status ) {
		$brand_fleet_links[ $brand_fleet_label ] = get_permalink( $brand_fleet_page );
	}
}
$brand_fleet_notice = isset( $attributes['demoNotice'] ) ? (string) $attributes['demoNotice'] : '';
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'brand-fleet-footer brand-fleet-footer--corporate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes wrapper attributes. ?>>
	<div class="brand-fleet-footer__inner">
		<div class="brand-fleet-footer__brand">
			<a class="brand-fleet-footer__logo-text" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $brand_fleet_business ); ?></a>
			<p class="brand-fleet-footer__copy">© <?php echo esc_html( $brand_fleet_year . ' ' . $brand_fleet_legal ); ?></p>
			<?php if ( $brand_fleet_socials ) : ?>
				<p><?php esc_html_e( 'Connect with us', 'brand-fleet' ); ?></p>
				<ul class="brand-fleet-footer__social" aria-label="<?php esc_attr_e( 'Social links', 'brand-fleet' ); ?>">
					<?php foreach ( $brand_fleet_socials as $brand_fleet_label => $brand_fleet_url ) : ?>
						<li><a href="<?php echo esc_url( $brand_fleet_url ); ?>" aria-label="<?php echo esc_attr( $brand_fleet_business . ' — ' . $brand_fleet_label ); ?>"><?php echo $brand_fleet_icon( $brand_fleet_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG icons. ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $brand_fleet_address ) : ?>
			<div class="brand-fleet-footer__col brand-fleet-footer__contact">
				<h2><?php esc_html_e( 'Our address', 'brand-fleet' ); ?></h2>
				<address><?php echo nl2br( esc_html( $brand_fleet_address ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped before adding line breaks. ?></address>
			</div>
		<?php endif; ?>
		<nav class="brand-fleet-footer__col" aria-label="<?php esc_attr_e( 'Footer links', 'brand-fleet' ); ?>">
			<h2><?php esc_html_e( 'Explore', 'brand-fleet' ); ?></h2>
			<ul><?php foreach ( $brand_fleet_links as $brand_fleet_label => $brand_fleet_url ) : ?><li><a href="<?php echo esc_url( $brand_fleet_url ); ?>"><?php echo esc_html( $brand_fleet_label ); ?></a></li><?php endforeach; ?></ul>
		</nav>
		<?php if ( '' !== $brand_fleet_notice ) : ?><p class="brand-fleet-footer__notice"><?php echo esc_html( $brand_fleet_notice ); ?></p><?php endif; ?>
	</div>
</div>
