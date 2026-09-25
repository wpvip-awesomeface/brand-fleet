<?php
/**
 * Settings → Brand Identity. Plain Settings API form.
 */

namespace BrandFleet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** Media picker + color picker, only on our settings screen. */
	public function assets( string $hook ): void {
		if ( 'settings_page_' . Settings::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'brand-fleet-admin',
			BRAND_FLEET_URL . 'assets/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			BRAND_FLEET_VERSION,
			true
		);
	}

	/** URL input + "Select image" button that opens the media library. */
	private function image_row( string $key, string $label, string $hint = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>"
					type="url" class="regular-text brand-fleet-media-field"
					value="<?php echo esc_attr( Settings::get( $key ) ); ?>" />
				<button type="button" class="button brand-fleet-media-btn" data-target="<?php echo esc_attr( $key ); ?>">
					<?php esc_html_e( 'Select image', 'brand-fleet' ); ?>
				</button>
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function menu(): void {
		add_options_page(
			__( 'Brand Identity', 'brand-fleet' ),
			__( 'Brand Identity', 'brand-fleet' ),
			'manage_options',
			Settings::PAGE,
			array( $this, 'render' )
		);
	}

	private function text_row( string $key, string $label, string $hint = '', string $type = 'text' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>"
					type="<?php echo esc_attr( $type ); ?>" class="regular-text"
					value="<?php echo esc_attr( Settings::get( $key ) ); ?>" />
				<?php if ( '' !== $hint ) : ?>
					<p class="description"><?php echo esc_html( $hint ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Fleet::profile( get_current_blog_id() ) ) {
			( new Fleet_Admin() )->local();
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Brand Identity', 'brand-fleet' ); ?></h1>
			<p><?php esc_html_e( 'Per-site branding for multisite demos. The header, footer, and {{token}} swaps on this site render from these values.', 'brand-fleet' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( Settings::GROUP ); ?>

				<h2><?php esc_html_e( 'Branding', 'brand-fleet' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->text_row( Settings::OPT_BUSINESS_NAME, __( 'Business name', 'brand-fleet' ), __( 'Short display name, e.g. "Example Brand". Replaces {{business_name}}.', 'brand-fleet' ) );
					$this->text_row( Settings::OPT_LEGAL_NAME, __( 'Legal name', 'brand-fleet' ), __( 'Full legal entity, e.g. "Example Brand Holdings, Inc.". Replaces {{legal_name}} and drives the copyright line.', 'brand-fleet' ) );
					$this->image_row( Settings::OPT_LOGO_URL, __( 'Brand logo', 'brand-fleet' ), __( 'The secondary logo that swaps per site (header + footer).', 'brand-fleet' ) );
					$this->image_row( Settings::OPT_LOGO_FOOTER_URL, __( 'Footer logo', 'brand-fleet' ), __( 'Optional dark-background variant for the footer; falls back to the brand logo.', 'brand-fleet' ) );
					$this->image_row( Settings::OPT_PARENT_LOGO_URL, __( 'Parent logo', 'brand-fleet' ), __( 'The constant parent-company mark. Leave empty on spoke sites to inherit the master\'s.', 'brand-fleet' ) );
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPT_ACCENT ); ?>"><?php esc_html_e( 'Accent color', 'brand-fleet' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( Settings::OPT_ACCENT ); ?>" id="<?php echo esc_attr( Settings::OPT_ACCENT ); ?>"
								type="text" class="brand-fleet-color-field"
								value="<?php echo esc_attr( Settings::get( Settings::OPT_ACCENT ) ); ?>"
								data-default-color="<?php echo esc_attr( Settings::DEFAULT_ACCENT ); ?>" />
							<p class="description"><?php esc_html_e( 'Buttons and highlights in the header and footer.', 'brand-fleet' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Location & contact', 'brand-fleet' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->text_row( Settings::OPT_PHONE_SALES, __( 'Sales phone', 'brand-fleet' ), __( 'Replaces {{phone_sales}}.', 'brand-fleet' ) );
					$this->text_row( Settings::OPT_PHONE_SUPPORT, __( 'Support phone', 'brand-fleet' ), __( 'Replaces {{phone_support}}.', 'brand-fleet' ) );
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::OPT_ADDRESS ); ?>"><?php esc_html_e( 'Address', 'brand-fleet' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( Settings::OPT_ADDRESS ); ?>" id="<?php echo esc_attr( Settings::OPT_ADDRESS ); ?>" rows="3" class="regular-text"><?php echo esc_textarea( Settings::get( Settings::OPT_ADDRESS ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One line per row; shown in the footer and replaces {{address}}.', 'brand-fleet' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Social links', 'brand-fleet' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Leave a network empty to hide its icon.', 'brand-fleet' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					foreach ( Settings::SOCIAL_OPTS as $key => $label ) {
						$this->text_row( $key, $label, '', 'url' );
					}
					?>
				</table>

				<h2><?php esc_html_e( 'Content source', 'brand-fleet' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->text_row( Settings::OPT_MASTER_SITE, __( 'Master site', 'brand-fleet' ), __( 'Blog ID or sub-site path (e.g. "master-brand") that shared-content blocks pull from. Leave empty if this site IS the master.', 'brand-fleet' ) );
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
