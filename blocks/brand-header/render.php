<?php
/**
 * Brand Header — parent mark (constant) + per-site brand logo (swaps),
 * demo nav, and an accent CTA. Everything resolves from the current site's
 * Brand Identity settings, so the same template-part markup renders
 * differently per brand site.
 */

namespace BrandFleet;

$brand_fleet_business    = Settings::business_name();
$brand_fleet_logo        = trim( Settings::get( Settings::OPT_LOGO_URL ) );
$brand_fleet_parent_logo = Settings::parent_logo_url();
$brand_fleet_accent      = Settings::accent();
$brand_fleet_home        = home_url( '/' );

$brand_fleet_nav = array(
	__( 'Services', 'brand-fleet' ),
	__( 'Who We Serve', 'brand-fleet' ),
	__( 'How We Deliver', 'brand-fleet' ),
	__( 'Resources', 'brand-fleet' ),
	__( 'About Us', 'brand-fleet' ),
);

$brand_fleet_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'brand-fleet-header',
		'style' => '--brand-fleet-accent:' . esc_attr( $brand_fleet_accent ) . ';',
	)
);
?>
<header <?php echo $brand_fleet_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() output is pre-escaped. ?>>
	<div class="brand-fleet-header__inner">
		<div class="brand-fleet-header__brand">
			<?php if ( '' !== $brand_fleet_parent_logo ) : ?>
				<a class="brand-fleet-header__parent" href="<?php echo esc_url( $brand_fleet_home ); ?>">
					<img src="<?php echo esc_url( $brand_fleet_parent_logo ); ?>" alt="<?php esc_attr_e( 'Parent company', 'brand-fleet' ); ?>" />
				</a>
				<span class="brand-fleet-header__divider" aria-hidden="true"></span>
			<?php endif; ?>
			<a class="brand-fleet-header__logo" href="<?php echo esc_url( $brand_fleet_home ); ?>">
				<?php if ( '' !== $brand_fleet_logo ) : ?>
					<img src="<?php echo esc_url( $brand_fleet_logo ); ?>" alt="<?php echo esc_attr( $brand_fleet_business ); ?>" />
				<?php else : ?>
					<span class="brand-fleet-header__logo-text"><?php echo esc_html( $brand_fleet_business ); ?></span>
				<?php endif; ?>
			</a>
		</div>
		<nav class="brand-fleet-header__nav" aria-label="<?php esc_attr_e( 'Primary', 'brand-fleet' ); ?>">
			<?php foreach ( $brand_fleet_nav as $brand_fleet_item ) : ?>
				<a href="#"><?php echo esc_html( $brand_fleet_item ); ?></a>
			<?php endforeach; ?>
		</nav>
		<div class="brand-fleet-header__actions">
			<a class="brand-fleet-header__login" href="#"><?php esc_html_e( 'Login', 'brand-fleet' ); ?></a>
			<a class="brand-fleet-btn" href="#"><?php esc_html_e( 'Request a Quote', 'brand-fleet' ); ?></a>
		</div>
	</div>
</header>
