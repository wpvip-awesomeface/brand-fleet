<?php
/**
 * Opening Hours — the current site's hours from an Opening hours variable.
 *
 * The weekly table and upcoming special dates render on the server. The open-now line
 * and today's highlight are filled in by view.js from the next week of opening times,
 * so the cached page never shows a stale "open now".
 *
 * @var array $attributes Block attributes ("variable": hours variable key, '' = the first one; "showStatus"; "showSpecial").
 */

namespace BrandFleet;

$brand_fleet_key = Blocks::variable( (string) ( $attributes['variable'] ?? '' ), 'hours' );
$brand_fleet_value = '' === $brand_fleet_key ? '' : Blocks::value( $brand_fleet_key );
$brand_fleet_hours = null;
try {
	$brand_fleet_hours = '' === $brand_fleet_value ? null : Fleet_Hours::parse( $brand_fleet_value );
} catch ( \InvalidArgumentException $e ) {
	$brand_fleet_hours = null;
}

if ( ! $brand_fleet_hours ) {
	if ( current_user_can( 'edit_posts' ) ) {
		printf(
			'<div %s><em>%s</em></div>',
			get_block_wrapper_attributes( array( 'class' => 'brand-fleet-hours brand-fleet-hours--empty' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Wrapper attributes are pre-escaped by core.
			esc_html__( 'Opening Hours: this site has no hours yet. Add an Opening hours variable under Brand Fleet → Variables, then set this site\'s hours.', 'brand-fleet' )
		);
	}
	return;
}

$brand_fleet_site = get_current_blog_id();
$brand_fleet_next = Fleet_Hours::upcoming( $brand_fleet_site, $brand_fleet_hours, 8 );
$brand_fleet_special = array_filter(
	Fleet_Hours::upcoming( $brand_fleet_site, $brand_fleet_hours, 21 ),
	static function ( $row ) use ( $brand_fleet_hours ) {
		if ( null === $brand_fleet_hours['week'] ) {
			return true;
		}
		$weekly = $brand_fleet_hours['week'][ strtolower( $row['day']->format( 'D' ) ) ] ?? array();
		return $row['ranges'] !== $weekly;
	}
);
$brand_fleet_zone = wp_timezone_string();
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'brand-fleet-hours' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by core. ?>>
	<?php if ( ! empty( $attributes['showStatus'] ) ) : ?>
		<p class="brand-fleet-hours__status" hidden data-intervals="<?php echo esc_attr( (string) wp_json_encode( Fleet_Hours::intervals( $brand_fleet_next ) ) ); ?>" data-open="<?php esc_attr_e( 'Open now', 'brand-fleet' ); ?>" data-closes="<?php esc_attr_e( 'closes %s', 'brand-fleet' ); ?>" data-closed="<?php esc_attr_e( 'Closed now', 'brand-fleet' ); ?>" data-opens="<?php esc_attr_e( 'opens %s', 'brand-fleet' ); ?>"></p>
	<?php endif; ?>
	<?php if ( null !== $brand_fleet_hours['week'] ) : ?>
		<table class="brand-fleet-hours__week" data-zone="<?php echo esc_attr( $brand_fleet_zone ); ?>">
			<tbody>
				<?php foreach ( Fleet_Hours::weekly_rows( $brand_fleet_hours ) as $brand_fleet_row ) : ?>
					<tr data-days="<?php echo esc_attr( implode( ',', $brand_fleet_row['keys'] ) ); ?>"><th scope="row"><?php echo esc_html( $brand_fleet_row['days'] ); ?></th><td><?php echo esc_html( $brand_fleet_row['times'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php if ( ! empty( $attributes['showSpecial'] ) && $brand_fleet_special ) : ?>
		<ul class="brand-fleet-hours__special">
			<?php foreach ( $brand_fleet_special as $brand_fleet_row ) : ?>
				<li><strong><?php echo esc_html( wp_date( 'D, M j', $brand_fleet_row['day']->getTimestamp() ) ); ?>:</strong> <?php echo esc_html( Fleet_Hours::label( $brand_fleet_row['ranges'] ) . ( '' !== $brand_fleet_row['note'] ? ' — ' . $brand_fleet_row['note'] : '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
