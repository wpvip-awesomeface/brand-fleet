<?php
/** Secure MCP surface for reviewed fleet operations. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Abilities {
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', static function () { wp_register_ability_category( 'brand-fleet', array( 'label' => 'Brand Fleet', 'description' => 'Network variable governance.' ) ); } );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_trust' ) );
	}
	public function register(): void {
		$integer = array( 'type' => 'integer', 'minimum' => 1 );
		$object = array( 'type' => 'object' );
		$this->ability( 'inspect', array( 'site_id' => $integer ), array(), static function ( $in ) {
			$id = (int) ( $in['site_id'] ?? 0 );
			if ( $id && ! Fleet::site( $id ) ) { throw new \InvalidArgumentException( 'Site is outside this network or inactive.' ); }
			$values = array();
			if ( $id ) { foreach ( Fleet::definitions() as $key => $d ) { $values[ $key ] = Fleet::resolved( $id, $key ); } }
			return array( 'definitions' => Fleet::definitions(), 'segments' => Fleet_Segments::all(), 'segments_hash' => Fleet::hash( Fleet_Segments::all() ), 'site_segments' => $id ? Fleet_Segments::of( $id ) : array(), 'profile' => $id ? Fleet::profile( $id ) : array(), 'values' => $values, 'revision_hash' => $id ? Fleet::hash( Fleet::profile( $id ) ) : Fleet::hash( Fleet::definitions() ) );
		}, true );
		$this->ability( 'save-definition', array( 'key' => array( 'type' => 'string' ), 'definition' => $object, 'expected' => array( 'type' => 'string' ) ), array( 'key', 'definition', 'expected' ), static function ( $in ) {
			if ( ! hash_equals( Fleet::hash( Fleet::definitions() ), $in['expected'] ) ) { throw new \RuntimeException( 'Definitions changed; inspect again.' ); }
			Fleet::save_definition( $in['key'], $in['definition'], $in['expected'] ); return array( 'saved' => $in['key'] );
		} );
		$this->ability( 'configure-site', array( 'site_id' => $integer, 'values' => $object, 'connections' => $object, 'expected' => array( 'type' => 'string' ) ), array( 'site_id', 'values', 'expected' ), static fn( $in ) => Fleet::save_profile( $in['site_id'], $in['values'], $in['connections'] ?? null, $in['expected'] ) );
		$bulk = 'Network-admin fleet operation. Bulk batches run in the background on VIP cron: preview-bulk starts a preview, bulk-status reports progress (poll it, about once a minute), advance-bulk with confirm=true applies a ready preview, cancel-bulk stops one. Sites changed after preview are skipped. Null values reset inheritance. Never publishes sites or pages.';
		$sites = static fn( array $in ): array => ! empty( $in['segment'] ) ? Fleet_Segments::sites( sanitize_key( $in['segment'] ) ) : (array) ( $in['site_ids'] ?? array() );
		$this->ability( 'preview-bulk', array( 'site_ids' => array( 'type' => 'array', 'items' => $integer, 'minItems' => 1, 'maxItems' => 10000 ), 'segment' => array( 'type' => 'string' ), 'values' => $object ), array( 'values' ), static fn( $in ) => Fleet_Jobs::view( Fleet_Jobs::start( $sites( $in ), $in['values'] ) ), false, $bulk . ' Pass site_ids, or segment (a segment slug) to target its sites.' );
		$this->ability( 'preview-replace', array( 'site_ids' => array( 'type' => 'array', 'items' => $integer, 'minItems' => 1, 'maxItems' => 10000 ), 'find' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 200 ), 'with' => array( 'type' => 'string', 'maxLength' => 500 ), 'variable' => array( 'type' => 'string' ), 'types' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( Fleet_Replace::TYPES ) ) ), 'case' => array( 'type' => 'boolean', 'default' => false ), 'markup' => array( 'type' => 'boolean', 'default' => false ), 'meta' => array( 'type' => 'boolean', 'default' => false ), 'segment' => array( 'type' => 'string' ) ), array( 'find' ), static fn( $in ) => Fleet_Jobs::view( Fleet_Jobs::start_replace( $sites( $in ), $in ) ), false, $bulk . ' Pass site_ids, or segment to target a segment. meta=true also searches SEO titles and descriptions (Yoast, Rank Math) and the listed custom fields. When more_sites is not empty after applying, run again with site_ids=more_sites. Find and replace: finds text on the chosen sites (pages, posts, synced patterns, template parts and menus by default) and replaces it with `with`, or with a {{token}} when `variable` is a variable key. Visible text only unless markup=true. Every changed post keeps a revision; posts edited after preview are skipped.' );
		$this->ability( 'bulk-status', array( 'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ) ), array(), static fn( $in ) => Fleet_Jobs::view( Fleet_Jobs::get(), (int) ( $in['offset'] ?? 0 ) ), true, $bulk . ' Read-only. offset pages through result rows, 25 at a time.' );
		$this->ability( 'cancel-bulk', array( 'id' => array( 'type' => 'string' ) ), array( 'id' ), static fn( $in ) => Fleet_Jobs::view( Fleet_Jobs::cancel( $in['id'] ) ), false, $bulk . ' Sites already updated keep their new values.' );
		$this->ability(
			'clone-site',
			array( 'source_id' => $integer, 'slug' => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]{1,63}$' ), 'title' => array( 'type' => 'string', 'minLength' => 1 ), 'values' => $object, 'group_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'main_source_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'public' => array( 'type' => 'boolean', 'default' => false ), 'confirm' => array( 'type' => 'boolean', 'default' => false ) ),
			array( 'source_id', 'slug', 'title', 'values', 'confirm' ),
			static fn( $in ) => Fleet_Clone::run( $in ),
			false,
			'Network-admin fleet operation. Creates a new site from an existing location: copies its pages, posts, synced patterns, navigation, templates, template parts, global styles and design options, points copied links at the new site, gives it the same hub-managed pages, and enrolls it with the supplied variables (required clone fields such as business_name and location_name must be supplied). Group and main data source default to the source location\'s. Media stays in the source library. The new site is private unless public=true. Requires confirm=true. Inspect the source first.'
		);
		$this->ability( 'advance-bulk', array( 'id' => array( 'type' => 'string' ), 'cursor' => array( 'type' => 'integer', 'minimum' => 0 ), 'confirm' => array( 'type' => 'boolean', 'default' => false ), 'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ) ), array( 'id', 'cursor' ), static fn( $in ) => Fleet_Jobs::view( Fleet_Jobs::step( $in['id'], $in['cursor'], $in['confirm'] ?? false ), (int) ( $in['offset'] ?? 0 ) ), false, $bulk . ' Without confirm, processes up to 25 more sites now (optional; the background runner continues either way).' );
		$this->ability( 'export-definitions', array(), array(), static fn( $in ) => Fleet_Transfer::export(), true );
		$this->ability(
			'save-segments',
			array( 'segments' => array( 'type' => 'array', 'items' => $object, 'maxItems' => Fleet_Segments::MAX ), 'expected' => array( 'type' => 'string' ) ),
			array( 'segments', 'expected' ),
			static fn( $in ) => array( 'segments' => Fleet_Segments::save( $in['segments'], $in['expected'] ) ),
			false,
			'Replaces the whole ordered segment list (order = priority for segment defaults). Each item: { slug, label, rules: { variable_key: [ values ] } }; rules use dropdown or yes / no variables (yes = "1", no = "0"). A site is in a segment when every rule matches one of its values. expected is the hash of the current list (inspect returns it as segments_hash).'
		);
		$this->ability(
			'preview-import',
			array( 'csv' => array( 'type' => 'string', 'maxLength' => Fleet_Import::MAX_BYTES ), 'url' => array( 'type' => 'string' ), 'match' => array( 'type' => 'string', 'default' => 'site_id' ) ),
			array(),
			static fn( $in ) => Fleet_Jobs::view( Fleet_Import::start( isset( $in['csv'] ) && '' !== $in['csv'] ? $in['csv'] : Fleet_Import::fetch( (string) ( $in['url'] ?? '' ) ), (string) ( $in['match'] ?? 'site_id' ), isset( $in['csv'] ) ? 'CSV from MCP' : (string) $in['url'] ) ),
			false,
			$bulk . ' Import: per-site values from CSV text or a public https CSV link (for example a Google Sheet published as CSV). The header row holds variable keys plus the match column: site_id, domain, or an identifier variable key such as practice_code. Empty cells leave values unchanged. The batch view lists problems (unmatched rows, invalid values) under report.'
		);
		$this->ability(
			'preview-token-report',
			array( 'site_ids' => array( 'type' => 'array', 'items' => $integer, 'minItems' => 1, 'maxItems' => 10000 ), 'types' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( Fleet_Report::TYPES ) ) ) ),
			array( 'site_ids' ),
			static fn( $in ) => Fleet_Jobs::view( Fleet_Jobs::start_report( $in['site_ids'], $in ) ),
			false,
			'Read-only background scan for {{tokens}} that print as raw text (unknown key) or print nothing (empty value) on each site. Poll bulk-status; rows list affected posts per site. Never changes content.'
		);
		$this->ability(
			'import-definitions',
			array( 'document' => $object, 'preview' => array( 'type' => 'boolean', 'default' => true ), 'confirm' => array( 'type' => 'boolean', 'default' => false ), 'expected' => array( 'type' => 'string' ) ),
			array( 'document' ),
			static function ( $in ) {
				if ( ! empty( $in['confirm'] ) ) {
					if ( empty( $in['expected'] ) ) { throw new \InvalidArgumentException( 'Preview first and pass back its expected hash.' ); }
					return array( 'written' => Fleet_Transfer::apply_import( $in['document'], $in['expected'] ) );
				}
				return Fleet_Transfer::preview_import( $in['document'] );
			}
		);
	}
	/** Abilities for values export, history, overrides, undo, change requests and local SEO. */
	public function register_trust(): void {
		$integer = array( 'type' => 'integer', 'minimum' => 1 );
		$this->ability(
			'export-values',
			array( 'site_ids' => array( 'type' => 'array', 'items' => $integer, 'maxItems' => 10000 ), 'segment' => array( 'type' => 'string' ), 'mode' => array( 'type' => 'string', 'enum' => array( 'own', 'shown' ), 'default' => 'own' ) ),
			array(),
			static function ( $in ) {
				$ids = ! empty( $in['site_ids'] ) ? $in['site_ids'] : ( ! empty( $in['segment'] ) ? Fleet_Segments::sites( sanitize_key( $in['segment'] ) ) : Fleet_Overrides::fleet_sites() );
				return array( 'csv' => Fleet_Export::csv( $ids, (string) ( $in['mode'] ?? 'own' ) ) );
			},
			true,
			'Read-only. Every fleet site\'s values as CSV (header: site_id, domain, then variable keys), or only site_ids or a segment. mode=own gives each site\'s own values (empty = uses the shared value) and imports straight back with preview-import; mode=shown gives what each site displays.'
		);
		$this->ability( 'site-history', array( 'site_id' => $integer ), array( 'site_id' ), static fn( $in ) => array( 'history' => array_reverse( Fleet_History::get( (int) $in['site_id'] ) ) ), true, 'Read-only. The last 100 changes to one site\'s own values, newest first: time, user, key, old, new (null = uses the shared value), via (edit, bulk, import, undo, request, restore).' );
		$this->ability(
			'overrides',
			array( 'key' => array( 'type' => 'string' ) ),
			array(),
			static function ( $in ) {
				if ( ! empty( $in['key'] ) ) { return array( 'key' => $in['key'], 'site_ids' => Fleet_Overrides::sites( sanitize_key( $in['key'] ) ) ); }
				return array( 'fleet_sites' => Fleet_Overrides::fleet_count(), 'indexed' => Fleet_Overrides::indexed(), 'overrides' => array_map( static fn( $key ) => Fleet_Overrides::count( $key ), array_combine( array_keys( Fleet_Overrides::variables() ), array_keys( Fleet_Overrides::variables() ) ) ) );
			},
			true,
			'Read-only. Without key: per variable, how many fleet sites set their own value instead of the shared one. With key: those site IDs (reset them with preview-bulk and a null value).'
		);
		$this->ability( 'list-undo', array(), array(), static fn( $in ) => array( 'batches' => array_reverse( Fleet_Undo::index(), true ) ), true, 'Read-only. The newest 10 applied batches that keep undo data: id, kind, label, sites, and undone (the undo batch id) once undone.' );
		$this->ability( 'preview-undo', array( 'batch' => array( 'type' => 'string' ) ), array( 'batch' ), static fn( $in ) => Fleet_Jobs::view( Fleet_Undo::start( (string) $in['batch'] ) ), false, 'Starts a reviewed undo of an applied batch (see list-undo). Poll bulk-status, then advance-bulk with confirm=true. Sites or pages edited after the original batch are skipped. Each batch can be undone once.' );
		$this->ability( 'list-requests', array(), array(), static fn( $in ) => array( 'pending' => Fleet_Requests::pending() ), true, 'Read-only. Open change requests from site admins: id, site, key, value (already validated), note, user, time.' );
		$this->ability( 'decide-request', array( 'id' => array( 'type' => 'string' ), 'approve' => array( 'type' => 'boolean' ), 'reason' => array( 'type' => 'string', 'maxLength' => 500 ) ), array( 'id', 'approve' ), static fn( $in ) => Fleet_Requests::decide( (string) $in['id'], (bool) $in['approve'], (string) ( $in['reason'] ?? '' ) ), false, 'Approve (sets the value on that site) or decline one change request. The requester gets an email.' );
		$this->ability( 'local-seo-preview', array( 'site_id' => $integer ), array( 'site_id' ), static fn( $in ) => array( 'settings' => Fleet_Schema::settings(), 'json_ld' => Fleet_Schema::data( (int) $in['site_id'], array( 'enabled' => true ) + Fleet_Schema::settings() ) ), true, 'Read-only. The schema.org LocalBusiness JSON-LD one site would print with the current Local SEO settings, even while output is turned off.' );
	}
	/** Which fleet capability each ability needs; anything not listed needs a network admin. */
	const NEEDS = array( 'inspect' => 'any', 'save-definition' => 'manage', 'save-segments' => 'manage', 'export-definitions' => 'manage', 'import-definitions' => 'manage', 'configure-site' => 'values', 'preview-bulk' => 'bulk', 'preview-replace' => 'bulk', 'preview-import' => 'bulk', 'preview-token-report' => 'bulk', 'bulk-status' => 'bulk', 'advance-bulk' => 'bulk', 'cancel-bulk' => 'bulk', 'export-values' => 'bulk', 'site-history' => 'values', 'overrides' => 'values', 'list-undo' => 'bulk', 'preview-undo' => 'bulk', 'list-requests' => 'values', 'decide-request' => 'values', 'local-seo-preview' => 'manage' );
	public static function allowed( string $name ): bool {
		$need = self::NEEDS[ $name ] ?? '';
		if ( 'any' === $need ) { return Fleet::can_any(); }
		return $need ? Fleet::can( $need ) : Fleet::network_admin();
	}
	private function ability( string $name, array $properties, array $required, callable $run, bool $read = false, string $description = '' ): void {
		wp_register_ability( 'brand-fleet/' . $name, array( 'label' => 'Brand Fleet: ' . $name, 'description' => $description ? $description : 'Network-admin fleet operation. Inspect first; bulk writes require completed preview and confirm=true. Null values reset inheritance. Never publishes sites or pages.', 'category' => 'brand-fleet', 'input_schema' => array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ), 'permission_callback' => static fn() => self::allowed( $name ), 'execute_callback' => static function ( $input ) use ( $run ) { try { return $run( $input ?? array() ); } catch ( \Throwable $e ) { return new \WP_Error( 'brand_fleet_error', $e->getMessage() ); } }, 'meta' => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => $read, 'destructive' => false, 'idempotent' => $read ) ) ) );
	}
}
