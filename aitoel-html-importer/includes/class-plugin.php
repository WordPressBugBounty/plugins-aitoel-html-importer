<?php
defined('ABSPATH') || exit;

/**
 * Main plugin class — singleton, registers all hooks.
 */
class HTEL_Plugin {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    private function load_dependencies() {
        require_once HTEL_PLUGIN_DIR . 'includes/class-elementor-check.php';
        require_once HTEL_PLUGIN_DIR . 'includes/class-license.php';
        require_once HTEL_PLUGIN_DIR . 'includes/class-converter.php';
        require_once HTEL_PLUGIN_DIR . 'includes/class-admin.php';
        // Custom updater only for paid/direct-download version (not WordPress.org)
        if (file_exists(HTEL_PLUGIN_DIR . 'includes/class-updater.php')) {
            require_once HTEL_PLUGIN_DIR . 'includes/class-updater.php';
        }
    }

    private function register_hooks() {
        // Admin menu
        add_action('admin_menu', array('HTEL_Admin', 'register_menu'));

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array('HTEL_Admin', 'enqueue_assets'));
        // "Go Premium" opens in a new tab — on every admin page, where the menu item exists
        add_action('admin_enqueue_scripts', array('HTEL_Admin', 'enqueue_go_premium_tab'));

        // AJAX handlers
        add_action('wp_ajax_htel_convert', array('HTEL_Converter', 'ajax_convert'));
        add_action('wp_ajax_htel_feedback', array('HTEL_Converter', 'ajax_feedback'));
        add_action('wp_ajax_htel_activate_license', array('HTEL_License', 'ajax_activate'));
        add_action('wp_ajax_htel_deactivate_license', array('HTEL_License', 'ajax_deactivate'));
        add_action('wp_ajax_htel_validate_license', array('HTEL_License', 'ajax_validate'));
        add_action('wp_ajax_htel_refresh_license_state', array('HTEL_License', 'ajax_refresh_state'));
        add_action('wp_ajax_htel_enable_containers', array('HTEL_Elementor_Check', 'ajax_enable_containers'));

        // Settings registration
        add_action('admin_init', array('HTEL_Admin', 'register_settings'));

        // Plugin action links
        add_filter('plugin_action_links_' . plugin_basename(HTEL_PLUGIN_FILE), array($this, 'action_links'));

        // Admin notices
        add_action('admin_notices', array('HTEL_Elementor_Check', 'maybe_show_notice'));
        // Warn (on our pages) when Elementor is active but Flexbox Containers are
        // OFF — converted templates would render blank until enabled.
        add_action('admin_notices', array('HTEL_Elementor_Check', 'maybe_show_container_notice'));
        // Missing-widget warning (Elementor 4 legacy-widgets case): a converted
        // template using widget types this site does not register renders those
        // blocks EMPTY (customer-reported: accordion on Elementor Pro 4.1.12).
        // The notice self-heals once the widgets are enabled.
        add_action('admin_notices', array('HTEL_Elementor_Check', 'maybe_show_missing_widgets_notice'));
        add_action('admin_init', array('HTEL_Elementor_Check', 'maybe_handle_missing_widgets_dismiss'));

        // Plugin update checker — only for paid/direct-download version
        if (class_exists('HTEL_Updater')) {
            add_action('admin_init', array('HTEL_Updater', 'init'));
        }
    }

    public function action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=htel-settings') . '">' .
            esc_html__('Settings', 'aitoel-html-importer') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Activation hook — runs on plugin activate (fresh install OR upgrade).
     *
     * Self-heals stale bad config from prior plugin versions:
     *   - htel_api_url : if empty/malformed, delete so get_api_url() falls
     *                    back to the default constant on next read.
     *
     * Customers upgrading from v1.3.12-v1.3.14 may have a corrupted
     * htel_api_url option that was saved empty by the old settings handler
     * (which used esc_url_raw alone, allowing empty submissions). This
     * activation pass cleans that up so they don't have to manually edit
     * Settings to recover.
     *
     * Tests in wp-plugin/tests/test-activation-hook.php enforce that
     * activate() leaves htel_api_url either absent (→ default fallback)
     * or set to a valid URL. Never empty, never malformed.
     */
    public static function activate() {
        $stored = get_option('htel_api_url', null);
        if ($stored !== null) {
            // Option exists — validate it. If it's bad, drop it so the
            // read-time fallback in get_api_url() takes over cleanly.
            $is_valid = is_string($stored) && $stored !== ''
                && preg_match('#^https?://[^\s/]+#i', $stored)
                && filter_var($stored, FILTER_VALIDATE_URL);
            if (!$is_valid) {
                delete_option('htel_api_url');
            }
        }
    }
}
