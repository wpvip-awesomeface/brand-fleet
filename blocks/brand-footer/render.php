<?php
/**
 * Brand Footer — brand column (logo, copyright,
 * legal links, social icons), Services + Company link columns, and a
 * "Chat with us" contact column. Phones, address, socials, legal name, and
 * accent all come from the current site's Brand Identity settings; social
 * networks with no URL simply don't render.
 */

namespace BrandFleet;

$brand_fleet_business = Settings::business_name();
$brand_fleet_legal    = Settings::legal_name();
$brand_fleet_logo     = Settings::footer_logo_url();
$brand_fleet_accent   = Settings::accent();
$brand_fleet_sales    = trim( Settings::get( Settings::OPT_PHONE_SALES ) );
$brand_fleet_support  = trim( Settings::get( Settings::OPT_PHONE_SUPPORT ) );
$brand_fleet_address  = trim( Settings::get( Settings::OPT_ADDRESS ) );
$brand_fleet_socials  = Settings::socials();
$brand_fleet_year     = wp_date( 'Y' );

$brand_fleet_services = array(
	__( 'Consulting', 'brand-fleet' ),
	__( 'Managed Services', 'brand-fleet' ),
	__( 'Implementation', 'brand-fleet' ),
	__( 'Training', 'brand-fleet' ),
	__( 'Support', 'brand-fleet' ),
);

$brand_fleet_company = array(
	__( 'About', 'brand-fleet' )        => '#',
	__( 'Resources', 'brand-fleet' )    => '#',
	__( 'Legal Center', 'brand-fleet' ) => home_url( '/privacy-policy/' ),
	__( 'Careers', 'brand-fleet' )      => '#',
	__( 'Contact', 'brand-fleet' )      => '#',
);

/**
 * Minimal geometric icon set — no icon font, no external asset.
 *
 * @param string $brand_fleet_label Network label from Settings::SOCIAL_OPTS.
 */
$brand_fleet_icon = static function ( string $brand_fleet_label ): string {
	switch ( $brand_fleet_label ) {
		case 'Facebook':
			return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 21v-7h2.4l.4-3h-2.8V9.1c0-.9.3-1.5 1.6-1.5H16V4.9c-.3 0-1.1-.1-2-.1-2 0-3.4 1.2-3.4 3.5V11H8.2v3h2.4v7h2.9z"/></svg>';
		case 'LinkedIn':
			return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.4 8.6H3.8V20h2.6V8.6zM5.1 7.4a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM20.2 13.7c0-3-1.6-4.4-3.8-4.4-1.7 0-2.5 1-2.9 1.6V8.6H10.9V20h2.6v-6.1c0-1.6.8-2.4 2-2.4s1.9.8 1.9 2.4V20h2.8v-6.3z"/></svg>';
		case 'Instagram':
			return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4.5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3.6" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="16.6" cy="7.4" r="1.2" fill="currentColor"/></svg>';
		case 'YouTube':
			return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="3.2" fill="currentColor"/><path fill="#0e1b33" d="M10.2 9.5v5l4.6-2.5-4.6-2.5z"/></svg>';
		case 'X':
			return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 4h3.6l4.1 5.8L16.6 4H20l-6.5 7.6L20.5 20h-3.6l-4.5-6.3L7.2 20H3.8l6.9-8L4 4z"/></svg>';
	}
	return '';
};

if ( 'corporate' === ( $attributes['variant'] ?? 'default' ) ) {
	require __DIR__ . '/corporate.php';
	return;
}

$brand_fleet_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'brand-fleet-footer',
		'style' => '--brand-fleet-accent:' . esc_attr( $brand_fleet_accent ) . ';',
	)
);
?>
<footer <?php echo $brand_fleet_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() output is pre-escaped. ?>>
	<div class="brand-fleet-footer__inner">
		<div class="brand-fleet-footer__brand">
			<?php if ( '' !== $brand_fleet_logo ) : ?>
				<img class="brand-fleet-footer__logo" src="<?php echo esc_url( $brand_fleet_logo ); ?>" alt="<?php echo esc_attr( $brand_fleet_business ); ?>" />
			<?php else : ?>
				<span class="brand-fleet-footer__logo-text"><?php echo esc_html( $brand_fleet_business ); ?></span>
			<?php endif; ?>
			<p class="brand-fleet-footer__copy">
				<?php
				printf(
					/* translators: 1: year, 2: legal entity name */
					esc_html__( '©%1$s %2$s. All rights reserved.', 'brand-fleet' ),
					esc_html( $brand_fleet_year ),
					esc_html( $brand_fleet_legal )
				);
				?>
			</p>
			<p class="brand-fleet-footer__legal">
				<a href="#"><?php esc_html_e( 'Terms', 'brand-fleet' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>"><?php esc_html_e( 'Privacy', 'brand-fleet' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>"><?php esc_html_e( 'Sitemap', 'brand-fleet' ); ?></a>
			</p>
			<?php if ( $brand_fleet_socials ) : ?>
				<ul class="brand-fleet-footer__social">
					<?php foreach ( $brand_fleet_socials as $brand_fleet_label => $brand_fleet_url ) : ?>
						<li>
							<a href="<?php echo esc_url( $brand_fleet_url ); ?>" aria-label="<?php echo esc_attr( $brand_fleet_label ); ?>">
								<?php echo $brand_fleet_icon( $brand_fleet_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG from the closure above; no variable data. ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="brand-fleet-footer__col">
			<h3><?php esc_html_e( 'Services', 'brand-fleet' ); ?></h3>
			<ul>
				<?php foreach ( $brand_fleet_services as $brand_fleet_item ) : ?>
					<li><a href="#"><?php echo esc_html( $brand_fleet_item ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="brand-fleet-footer__col">
			<h3><?php esc_html_e( 'Company', 'brand-fleet' ); ?></h3>
			<ul>
				<?php foreach ( $brand_fleet_company as $brand_fleet_label => $brand_fleet_url ) : ?>
					<li><a href="<?php echo esc_url( $brand_fleet_url ); ?>"><?php echo esc_html( $brand_fleet_label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="brand-fleet-footer__col brand-fleet-footer__contact">
			<h3><?php esc_html_e( 'Chat with us', 'brand-fleet' ); ?></h3>
			<?php if ( '' !== $brand_fleet_sales ) : ?>
				<p><span><?php esc_html_e( 'Sales:', 'brand-fleet' ); ?></span>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $brand_fleet_sales ) ); ?>"><?php echo esc_html( $brand_fleet_sales ); ?></a></p>
			<?php endif; ?>
			<?php if ( '' !== $brand_fleet_support ) : ?>
				<p><span><?php esc_html_e( '24/7 Live Support:', 'brand-fleet' ); ?></span>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $brand_fleet_support ) ); ?>"><?php echo esc_html( $brand_fleet_support ); ?></a></p>
			<?php endif; ?>
			<?php if ( '' !== $brand_fleet_address ) : ?>
				<address>
					<?php echo nl2br( esc_html( $brand_fleet_address ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() runs before nl2br(); only <br> tags are added. ?>
				</address>
			<?php endif; ?>
			<a class="brand-fleet-btn brand-fleet-btn--outline" href="#"><?php esc_html_e( 'Request a Call', 'brand-fleet' ); ?></a>
		</div>
	</div>
</footer>
