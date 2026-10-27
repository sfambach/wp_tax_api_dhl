<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod DHL
 * Description:       Connects the owner's DHL developer app (Shipment Tracking – Unified API) and tells other Taxmod modules where a parcel stands.
 * Version:           0.0.9
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam), Claude (Anthropic AI)
 * Author URI:        https://www.fambach.net
 * License:           PolyForm Noncommercial 1.0.0
 * License URI:       https://polyformproject.org/licenses/noncommercial/1.0.0/
 * Text Domain:       taxapidhl
 * GitHub Plugin URI: sfambach/wp_tax_api_dhl
 * Primary Branch:    main
 */

if (! defined('ABSPATH')) {
    exit;
}

$taxapidhlAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxapidhlAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Taxmod DHL:</strong> '
            . esc_html__('run "composer install" in the plugin folder — the autoloader is missing.', 'taxapidhl')
            . '</p></div>';
    });

    return;
}

require_once $taxapidhlAutoloader;

TaxApiDhl\WordPress\Plugin::boot();
