<?php
/**
 * Plugin Name: AI to Elementor — HTML Importer for Elementor
 * Description: Convert any HTML page into a fully editable Elementor template with one click. Works with AI page generators (Lovable, v0, Stitch, Cursor, bolt.new) and hand-written HTML.
 * Version: 1.3.49
 * Author: AI to Elementor
 * Author URI: https://aitoelementor.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aitoel-html-importer
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Requires Plugins: elementor
 *
 * Plugin URI intentionally omitted — this plugin's canonical page is the
 * WordPress.org directory listing. Author URI points to the project homepage.
 *
 * This plugin is not affiliated with or endorsed by Elementor Ltd.
 * "Elementor" is a trademark of Elementor Ltd. The plugin provides a
 * conversion bridge *for* Elementor.
 */

defined('ABSPATH') || exit;

define('HTEL_VERSION', '1.3.49');
define('HTEL_PLUGIN_FILE', __FILE__);
define('HTEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HTEL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Default API endpoint
if (!defined('HTEL_API_URL')) {
    define('HTEL_API_URL', 'https://api.aitoelementor.com');
}

require_once HTEL_PLUGIN_DIR . 'includes/class-plugin.php';

// Activation hook — runs on plugin activate (fresh install OR re-activate after
// update). Self-heals stale bad config from prior versions. See HTEL_Plugin::activate().
register_activation_hook(__FILE__, array('HTEL_Plugin', 'activate'));

HTEL_Plugin::instance();
