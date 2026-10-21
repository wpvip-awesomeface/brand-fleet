<?php
/**
 * Local SEO: one schema.org LocalBusiness (or a more specific type, such as Dentist)
 * per enrolled site, built from its own variables, so search engines see each
 * location's name, address, phone, hours and holiday closures.
 *
 * Output varies only by site and prints in the page head; nothing loads for visitors.
 */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Schema {
	const OPTION = 'brand_fleet_local_seo';
	/** Business types offered in the admin; any schema.org LocalBusiness subtype works through the filter. */
	const TYPES = array(
		'LocalBusiness'           => 'Local business (general)',
		'Dentist'                 => 'Dentist',
		'MedicalClinic'           => 'Medical clinic',
		'Physician'               => 'Physician',
		'Optician'                => 'Optician',
		'VeterinaryCare'          => 'Veterinary care',
		'HealthAndBeautyBusiness' => 'Health and beauty',
		'ProfessionalService'     => 'Professional service',
		'LegalService'            => 'Legal service',
		'FinancialService'        => 'Financial service',
		'RealEstateAgent'         => 'Real estate agent',
		'AutoRepair'              => 'Auto repair',
		'Restaurant'              => 'Restaurant',
		'Store'                   => 'Store',
	);
	/** Schema fields → [ label, variable types that fit ]. */
	const FIELDS = array(
		'name'      => array( 'Business name', array( 'text' ) ),
		'telephone' => array( 'Phone', array( 'phone', 'text' ) ),
		'email'     => array( 'Email', array( 'email' ) ),
		'image'     => array( 'Logo or photo', array( 'image', 'url' ) ),
		'street'    => array( 'Street address', array( 'text', 'textarea' ) ),
		'locality'  => array( 'City', array( 'text', 'select' ) ),
		'region'    => array( 'Province or state', array( 'text', 'select' ) ),
		'postal'    => array( 'Postal or ZIP code', array( 'text' ) ),
		'country'   => array( 'Country', array( 'text', 'select' ) ),
		'latitude'  => array( 'Latitude', array( 'number', 'text' ) ),
		'longitude' => array( 'Longitude', array( 'number', 'text' ) ),
		'hours'     => array( 'Opening hours', array( 'hours' ) ),
		'price'     => array( 'Price range (for example $$)', array( 'text', 'select' ) ),
		'url'       => array( 'Booking or website link (default: the site address)', array( 'url' ) ),
	);

	/** Hooks the structured data output. */
	public function init(): void {
		add_action( 'wp_head', array( $this, 'print' ), 30 );
	}

	/** Saved structured data settings, with defaults. */
	public static function settings(): array {
		$saved = (array) get_network_option( get_current_network_id(), self::OPTION, array() );
		return $saved + array(
			'enabled' => false,
			'type'    => 'LocalBusiness',
			'where'   => 'home',
			'map'     => self::guess(),
			'same_as' => array(),
		);
	}
	/** First-run mapping from well-known keys, so the form starts mostly filled in. */
	private static function guess(): array {
		$defs  = Fleet::definitions();
		$map   = array();
		$known = array(
			'name'      => array( 'location_name', 'business_name' ),
			'telephone' => array( 'phone_sales', 'phone', 'phone_main' ),
			'email'     => array( 'email' ),
			'image'     => array( 'logo_url', 'logo' ),
			'street'    => array( 'address', 'street_address' ),
			'locality'  => array( 'city' ),
			'region'    => array( 'province', 'state', 'region' ),
			'postal'    => array( 'postal_code', 'zip' ),
			'country'   => array( 'country' ),
		);
		foreach ( $known as $field => $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $defs[ $key ] ) ) {
					$map[ $field ] = $key;
					break;
				}
			}
		}
		$hours = Blocks::variable( '', 'hours' );
		if ( '' !== $hours ) {
			$map['hours'] = $hours;
		}
		return $map;
	}

	/** Saves the structured data settings. */
	public static function save( array $in ): array {
		if ( ! Fleet::can( 'manage' ) ) {
			throw new \RuntimeException( 'Fleet manager permission required.' );
		}
		$defs = Fleet::definitions();
		$map  = array();
		foreach ( self::FIELDS as $field => $spec ) {
			$key = sanitize_key( (string) ( $in['map'][ $field ] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the UI handler.
			if ( ! isset( $defs[ $key ] ) || ! in_array( $defs[ $key ]['type'], $spec[1], true ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						'%s needs a %s variable.',
						$spec[0],
						implode( ' or ', array_map( static fn( $t ) => Fleet::TYPES[ $t ] ?? $t, $spec[1] ) )
					)
				);
			}
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			$map[ $field ] = $key;
		}
		$same_as  = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $in['same_as'] ?? array() ) ),
				static fn( $key ) => 'url' === ( $defs[ $key ]['type'] ?? '' )
			)
		);
		$settings = array(
			'enabled' => ! empty( $in['enabled'] ),
			'type'    => isset( self::TYPES[ $in['type'] ?? '' ] ) ? $in['type'] : 'LocalBusiness',
			'where'   => 'all' === ( $in['where'] ?? '' ) ? 'all' : 'home',
			'map'     => $map,
			'same_as' => $same_as,
		);
		if ( $settings['enabled'] && empty( $map['name'] ) ) {
			throw new \InvalidArgumentException( 'Choose the variable that holds each location\'s business name.' );
		}
		update_network_option( get_current_network_id(), self::OPTION, $settings );
		Fleet::audit( 'local-seo', 0, array_values( $map ) );
		Fleet_Cache::schedule();
		return $settings;
	}

	/** Prints the LocalBusiness JSON-LD for the current site. */
	public function print(): void {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ( 'home' === $settings['where'] && ! is_front_page() ) ) {
			return;
		}
		$data = self::data( get_current_blog_id() );
		if ( ! $data ) {
			return;
		}
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & hex-escaped, so it can never close the script tag.
		echo "\n" .
			'<script type="application/ld+json">' .
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) .
			"</script>\n";
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** The JSON-LD object for one enrolled site, or [] when it has no business name. */
	public static function data( int $site, ?array $settings = null ): array {
		$settings = $settings ?? self::settings();
		if ( ! Fleet::profile( $site ) ) {
			return array();
		}
		$map   = (array) $settings['map'];
		$value = static fn( string $field ): string => isset( $map[ $field ] )
			? trim( (string) Fleet::resolved( $site, $map[ $field ] )['value'] )
			: '';
		$name  = $value( 'name' );
		if ( '' === $name ) {
			return array();
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => $settings['type'],
			'@id'      => trailingslashit( get_home_url( $site ) ) . '#localbusiness',
			'name'     => $name,
			'url'      => $value( 'url' ) ? $value( 'url' ) : trailingslashit( get_home_url( $site ) ),
		);
		foreach (
			array(
				'telephone' => 'telephone',
				'email'     => 'email',
				'image'     => 'image',
				'price'     => 'priceRange',
			)
			as $field => $property
		) {
			if ( '' !== $value( $field ) ) {
				$data[ $property ] = $value( $field );
			}
		}
		$address = array_filter(
			array(
				'streetAddress'   => (string) preg_replace( '/\s*\R\s*/', ', ', $value( 'street' ) ),
				'addressLocality' => $value( 'locality' ),
				'addressRegion'   => $value( 'region' ),
				'postalCode'      => $value( 'postal' ),
				'addressCountry'  => $value( 'country' ),
			),
			'strlen'
		);
		if ( $address ) {
			$data['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}
		if ( is_numeric( $value( 'latitude' ) ) && is_numeric( $value( 'longitude' ) ) ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $value( 'latitude' ),
				'longitude' => (float) $value( 'longitude' ),
			);
		}
		if ( '' !== $value( 'hours' ) ) {
			try {
				// Hours and closures are worked out in the site's own timezone.
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Timezone and segment reads for one site; restored right after.
				$switched = get_current_blog_id() !== $site && switch_to_blog( $site );
				try {
					$spec = Fleet_Hours::specification( $site, Fleet_Hours::parse( $value( 'hours' ) ) );
				} finally {
					if ( $switched ) {
						restore_current_blog();
					}
				}
				if ( $spec ) {
					$data['openingHoursSpecification'] = $spec;
				}
			} catch ( \InvalidArgumentException $e ) {
				unset( $e ); // Unreadable hours are left out rather than printed wrong.
			}
		}
		$links = array_values(
			array_filter(
				array_map(
					static fn( $key ) => esc_url_raw( (string) Fleet::resolved( $site, $key )['value'] ),
					(array) $settings['same_as']
				)
			)
		);
		if ( $links ) {
			$data['sameAs'] = $links;
		}
		/** Filters one site's LocalBusiness JSON-LD before it prints. */
		return (array) apply_filters( 'brand_fleet_local_business', $data, $site );
	}
}
