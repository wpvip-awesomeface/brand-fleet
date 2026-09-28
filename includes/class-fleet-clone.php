<?php
/** Clone a fleet location into a new site without the Add Site form, for Secure MCP and other automation. */
namespace BrandFleet;

defined( 'ABSPATH' ) || exit;
class Fleet_Clone {
	/** Content that defines a location's pages and look. Media stays in the source library and keeps its URLs. */
	const TYPES = array( 'page', 'post', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles' );
	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );
	const TAXONOMIES = array( 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'category', 'post_tag' );
	const OPTIONS = array( 'blogdescription', 'show_on_front', 'permalink_structure', 'category_base', 'tag_base', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG', 'active_plugins' );
	/** Editor bookkeeping and IDs that would point at the wrong site. */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_thumbnail_id' );
	const LIMIT = 500;

	/**
	 * Create a site from a location, rewrite its links, hand it the same hub pages, and enroll it.
	 *
	 * @return array{site_id:int,url:string,copied:int,profile:array}
	 */
	public static function run( array $in ): array {
		if ( empty( $in['confirm'] ) ) { throw new \InvalidArgumentException( 'Set confirm=true to create the site.' ); }
		if ( ! Fleet::network_admin() || ! current_user_can( 'create_sites' ) ) { throw new \RuntimeException( 'Network site creation permission required.' ); }
		$source = absint( $in['source_id'] ?? 0 );
		if ( ! Fleet::site( $source ) || is_main_site( $source ) ) { throw new \InvalidArgumentException( 'Choose an active location (not the network main site) to clone.' ); }
		$slug  = sanitize_title( (string) ( $in['slug'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $in['title'] ?? '' ) );
		if ( '' === $slug || strlen( $slug ) > 63 || '' === $title ) { throw new \InvalidArgumentException( 'Enter a site address (letters, numbers, hyphens) and a title.' ); }
		$network = get_network();
		if ( is_subdomain_install() ) {
			$domain = $slug . '.' . preg_replace( '/^www\./', '', $network->domain );
			$path   = $network->path;
		} else {
			if ( in_array( $slug, get_subdirectory_reserved_names(), true ) ) { throw new \InvalidArgumentException( 'That site address is reserved by WordPress.' ); }
			$domain = $network->domain;
			$path   = $network->path . $slug . '/';
		}
		if ( domain_exists( $domain, $path, (int) $network->id ) ) { throw new \InvalidArgumentException( 'That site address is already in use.' ); }
		$values = (array) ( $in['values'] ?? array() );
		$unknown = array_diff( array_keys( $values ), array_keys( Fleet::definitions() ) );
		if ( $unknown ) { throw new \InvalidArgumentException( 'Unknown variable: ' . implode( ', ', $unknown ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
		$profile = Fleet::profile( $source );
		// Same validation as the Add Site form: required clone fields must be typed for the new site.
		$setup = Fleet_Onboarding::prepare(
			array(
				'brand_fleet_values'    => array_map( 'strval', $values ),
				'brand_fleet_group_id'  => $in['group_id'] ?? ( $profile['group_id'] ?? 0 ),
				'brand_fleet_source_id' => $in['main_source_id'] ?? ( $profile['source_id'] ?? 0 ),
				'mlp_based_on_site'     => $source,
			)
		);
		$items = self::read( $source );
		$site  = wpmu_create_blog( $domain, $path, $title, get_current_user_id(), array( 'public' => empty( $in['public'] ) ? 0 : 1 ), (int) $network->id );
		if ( is_wp_error( $site ) ) { throw new \RuntimeException( $site->get_error_message() ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
		try {
			$map = self::write( (int) $site, $items );
			( new Fleet_Onboarding() )->relink( $source, (int) $site );
			/**
			 * A location was cloned. $map is source post ID => new post ID.
			 *
			 * @param int   $source Starting site ID.
			 * @param int   $site   New site ID.
			 * @param array $map    Post ID map.
			 */
			do_action( 'brand_fleet_cloned_site', $source, (int) $site, $map );
			Fleet_Onboarding::apply( (int) $site, $setup );
		} catch ( \Throwable $e ) {
			// A site now exists; keep it out of public view and say why.
			update_blog_option( (int) $site, 'brand_fleet_setup_error', $e->getMessage() );
			wp_update_site( (int) $site, array( 'public' => 0, 'archived' => 1 ) );
			throw new \RuntimeException( 'Site ' . (int) $site . ' was created but archived because cloning failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
		}
		return array( 'site_id' => (int) $site, 'url' => get_home_url( (int) $site, '/' ), 'copied' => count( $map ), 'profile' => Fleet::profile( (int) $site ) );
	}

	/** Snapshot the source site's content, terms, meta and design options. */
	private static function read( int $source ): array {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Network-admin clone; read-only here, restored in finally.
		switch_to_blog( $source );
		try {
			$ids = ( new \WP_Query( array( 'post_type' => self::TYPES, 'post_status' => self::STATUSES, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'posts_per_page' => self::LIMIT + 1, 'no_found_rows' => true ) ) )->posts; // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One bounded read per clone, capped below.
			if ( count( $ids ) > self::LIMIT ) { throw new \InvalidArgumentException( 'This location has more than ' . self::LIMIT . ' items; clone it from Network Admin instead.' ); }
			$posts = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id, ARRAY_A );
				$terms = array();
				foreach ( self::TAXONOMIES as $tax ) {
					if ( is_object_in_taxonomy( $post['post_type'], $tax ) ) { $terms[ $tax ] = wp_get_object_terms( $id, $tax, array( 'fields' => 'slugs' ) ); }
				}
				$posts[] = array( 'post' => $post, 'meta' => get_post_meta( $id ), 'terms' => $terms );
			}
			$stylesheet = (string) get_option( 'stylesheet' );
			$options = array( 'template' => get_option( 'template' ), 'stylesheet' => $stylesheet, 'theme_mods_' . $stylesheet => get_option( 'theme_mods_' . $stylesheet ) );
			foreach ( self::OPTIONS as $name ) { $options[ $name ] = get_option( $name ); }
			return array( 'posts' => $posts, 'options' => $options, 'page_on_front' => (int) get_option( 'page_on_front' ), 'page_for_posts' => (int) get_option( 'page_for_posts' ) );
		} finally {
			restore_current_blog();
		}
	}

	/** Write the snapshot into the new site; returns source post ID => new post ID. */
	private static function write( int $site, array $items ): array {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Network-admin clone into a site created moments ago, restored in finally.
		switch_to_blog( $site );
		try {
			// Remove WordPress's starter post, page and privacy draft so copied slugs stay identical.
			$starter = ( new \WP_Query( array( 'post_type' => 'any', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 20, 'no_found_rows' => true ) ) )->posts;
			foreach ( $starter as $id ) { wp_delete_post( $id, true ); }
			foreach ( $items['options'] as $name => $value ) {
				if ( false !== $value ) { update_option( $name, $value ); }
			}
			$map = array();
			foreach ( $items['posts'] as $item ) {
				$p  = $item['post'];
				$id = wp_insert_post( wp_slash( array( 'post_type' => $p['post_type'], 'post_status' => $p['post_status'], 'post_title' => $p['post_title'], 'post_name' => $p['post_name'], 'post_content' => $p['post_content'], 'post_excerpt' => $p['post_excerpt'], 'menu_order' => $p['menu_order'], 'comment_status' => $p['comment_status'], 'ping_status' => $p['ping_status'], 'post_date' => $p['post_date'], 'post_date_gmt' => $p['post_date_gmt'] ) ), true );
				if ( is_wp_error( $id ) ) { throw new \RuntimeException( 'Could not copy “' . $p['post_title'] . '”: ' . $id->get_error_message() ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is escaped by UI handler; API returns structured errors.
				$map[ (int) $p['ID'] ] = (int) $id;
				foreach ( $item['terms'] as $tax => $slugs ) {
					if ( $slugs && ! is_wp_error( $slugs ) ) { wp_set_object_terms( $id, $slugs, $tax ); }
				}
				foreach ( $item['meta'] as $key => $values ) {
					if ( in_array( $key, self::SKIP_META, true ) ) { continue; }
					foreach ( $values as $value ) { add_post_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) ); }
				}
			}
			// Second pass: parents and synced pattern / navigation references now that every new ID exists.
			foreach ( $items['posts'] as $item ) {
				$p       = $item['post'];
				$parent  = (int) $p['post_parent'];
				$content = preg_replace_callback( '/"ref":(\d+)/', static fn( $m ) => '"ref":' . ( $map[ (int) $m[1] ] ?? (int) $m[1] ), $p['post_content'] );
				$change  = array();
				if ( $parent && isset( $map[ $parent ] ) ) { $change['post_parent'] = $map[ $parent ]; }
				if ( $content !== $p['post_content'] ) { $change['post_content'] = $content; }
				if ( $change ) { wp_update_post( wp_slash( array( 'ID' => $map[ (int) $p['ID'] ] ) + $change ) ); }
			}
			foreach ( array( 'page_on_front', 'page_for_posts' ) as $name ) { update_option( $name, $map[ $items[ $name ] ] ?? 0 ); }
			delete_option( 'rewrite_rules' );
			return $map;
		} finally {
			restore_current_blog();
		}
	}
}
