<?php
/** Secure MCP surface for reviewed fleet operations. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Abilities {
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', static function () { wp_register_ability_category( 'brand-fleet', array( 'label' => 'Brand Fleet', 'description' => 'Network variable governance.' ) ); } );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}
	public function register(): void {
		$integer = array( 'type' => 'integer', 'minimum' => 1 );
		$object = array( 'type' => 'object' );
		$this->ability( 'inspect', array( 'site_id' => $integer ), array(), static function ( $in ) {
			$id = (int) ( $in['site_id'] ?? 0 );
			if ( $id && ! Fleet::site( $id ) ) { throw new \InvalidArgumentException( 'Site is outside this network or inactive.' ); }
			$values = array();
			if ( $id ) { foreach ( Fleet::definitions() as $key => $d ) { $values[ $key ] = Fleet::resolved( $id, $key ); } }
			return array( 'definitions' => Fleet::definitions(), 'profile' => $id ? Fleet::profile( $id ) : array(), 'values' => $values, 'revision_hash' => $id ? Fleet::hash( Fleet::profile( $id ) ) : Fleet::hash( Fleet::definitions() ) );
		}, true );
		$this->ability( 'save-definition', array( 'key' => array( 'type' => 'string' ), 'definition' => $object, 'expected' => array( 'type' => 'string' ) ), array( 'key', 'definition', 'expected' ), static function ( $in ) {
			if ( ! hash_equals( Fleet::hash( Fleet::definitions() ), $in['expected'] ) ) { throw new \RuntimeException( 'Definitions changed; inspect again.' ); }
			Fleet::save_definition( $in['key'], $in['definition'], $in['expected'] ); return array( 'saved' => $in['key'] );
		} );
		$this->ability( 'configure-site', array( 'site_id' => $integer, 'values' => $object, 'connections' => $object, 'expected' => array( 'type' => 'string' ) ), array( 'site_id', 'values', 'expected' ), static fn( $in ) => Fleet::save_profile( $in['site_id'], $in['values'], $in['connections'] ?? null, $in['expected'] ) );
		$this->ability( 'preview-bulk', array( 'site_ids' => array( 'type' => 'array', 'items' => $integer, 'minItems' => 1, 'maxItems' => 10000 ), 'values' => $object ), array( 'site_ids', 'values' ), static fn( $in ) => Fleet_Jobs::start( $in['site_ids'], $in['values'] ) );
		$this->ability( 'advance-bulk', array( 'id' => array( 'type' => 'string' ), 'cursor' => array( 'type' => 'integer', 'minimum' => 0 ), 'confirm' => array( 'type' => 'boolean', 'default' => false ) ), array( 'id', 'cursor' ), static fn( $in ) => Fleet_Jobs::step( $in['id'], $in['cursor'], $in['confirm'] ?? false ) );
	}
	private function ability( string $name, array $properties, array $required, callable $run, bool $read = false ): void {
		wp_register_ability( 'brand-fleet/' . $name, array( 'label' => 'Brand Fleet: ' . $name, 'description' => 'Network-admin fleet operation. Inspect first; bulk writes require completed preview and confirm=true. Null values reset inheritance. Never publishes sites or pages.', 'category' => 'brand-fleet', 'input_schema' => array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ), 'permission_callback' => array( Fleet::class, 'network_admin' ), 'execute_callback' => static function ( $input ) use ( $run ) { try { return $run( $input ?? array() ); } catch ( \Throwable $e ) { return new \WP_Error( 'brand_fleet_error', $e->getMessage() ); } }, 'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => $read, 'destructive' => false, 'idempotent' => $read ) ) ) );
	}
}
