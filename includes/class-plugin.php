<?php
/**
 * Wiring for settings, blocks, content filters, and variable replacement.
 * Network activation enables the fleet UI; explicit enrollment controls managed resolution.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static ?Plugin $instance = null;

	public Settings $settings;
	public Admin $admin;
	public Content $content;
	public Blocks $blocks;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot(): void {
		$this->settings = new Settings();
		$this->admin    = new Admin();
		$this->content  = new Content();
		$this->blocks   = new Blocks();

		( new Fleet_Abilities() )->init();
		( new Fleet_Admin() )->init();
		( new Fleet_Onboarding() )->init();
		// Locks must be shared by every site in the network.
		wp_cache_add_global_groups( array( 'brand_fleet_locks' ) );
		// Background batches and cache purges run on VIP Cron Control: one tick at a time, every minute while there is work.
		add_filter( 'cron_schedules', array( Fleet_Jobs::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- One-minute interval, only scheduled while a batch or purge is pending; unschedules itself when idle.
		add_action( Fleet_Jobs::HOOK, array( Fleet_Jobs::class, 'tick' ) );
		add_action( 'brand_fleet_purge', array( Fleet_Cache::class, 'run' ), 10, 2 );
		$this->settings->init();
		$this->admin->init();
		$this->content->init();
		$this->blocks->init();
	}
}
