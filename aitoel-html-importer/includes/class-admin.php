<?php
defined('ABSPATH') || exit;

/**
 * Admin pages — conversion UI and settings.
 */
class HTEL_Admin {

    /** Where "Go Premium" goes: the pricing section of the site's homepage. */
    const PREMIUM_URL = 'https://aitoelementor.com/#pricing';

    /**
     * Register admin menu pages.
     */
    public static function register_menu() {
        // Top-level menu
        add_menu_page(
            __('AI to Elementor', 'aitoel-html-importer'),
            __('AI to Elementor', 'aitoel-html-importer'),
            'edit_posts',
            'htel-convert',
            array(__CLASS__, 'render_convert_page'),
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2L3 7v6l7 5 7-5V7l-7-5zm0 2.18L14.5 7.5 10 10.82 5.5 7.5 10 4.18zM5 8.82l4 2.86v3.5L5 12.32v-3.5zm10 0v3.5l-4 2.86v-3.5l4-2.86z"/></svg>'),
            58 // Position: after Appearance (60) but before typical plugins
        );

        // Convert submenu (replaces the auto-generated duplicate)
        add_submenu_page(
            'htel-convert',
            __('Convert', 'aitoel-html-importer'),
            __('Convert', 'aitoel-html-importer'),
            'edit_posts',
            'htel-convert',
            array(__CLASS__, 'render_convert_page')
        );

        // Settings submenu
        add_submenu_page(
            'htel-convert',
            __('Settings', 'aitoel-html-importer'),
            __('Settings', 'aitoel-html-importer'),
            'manage_options',
            'htel-settings',
            array(__CLASS__, 'render_settings_page')
        );

        // "Go Premium" (George, 2026-09-19): a plain link to the plans on the site, for an
        // administrator of a site with no active licence.
        //
        // Its slug IS the URL. WordPress prints a submenu that has no page callback with its slug
        // verbatim as the href (wp-admin/menu-header.php), so the link needs no redirect and no
        // write to the $submenu global. (Only the new-tab target needs a script, below.) The URL
        // survives add_submenu_page() because plugin_basename() leaves a stream URL whole, and
        // `https` is a registered stream only when PHP has its openssl extension (Site Health
        // recommends it). Without it the slug becomes `https:/aitoelementor.com/#pricing`, which an
        // https admin resolves to a path on the customer's own site — accepted as rare,
        // docs/BACKLOG.md #285.
        //
        // WP.org: 1.3.17 (4b8a685) was the compliance pass after this plugin was rejected as
        // Trialware (Guideline 5). What it removed was a banner on the Convert page — a FREE badge
        // and "You have 1 free conversion. Need more? Upgrade for unlimited conversions" — shown on
        // EVERY load to every site failing this same licence test (admin.js swapped it to "USED"
        // once the quota ran out). So this item is licence-conditioned exactly like what was
        // removed, and it is on the same screen: on the plugin's own pages (Convert and Settings)
        // WordPress expands this submenu, so Go Premium sits in the sidebar beside the converter on
        // every load, for every administrator of such a site. What differs is the claim and the
        // form: it says nothing about limits, quotas, unlocking or price, and it is one menu item
        // in the sidebar, not a banner in the page, with no badge or styling. Guideline 5 reads
        // "Attempting to upsell the user on ad-hoc products and features is acceptable, provided it
        // falls within bounds of guideline 11"; Guideline 11 allows upgrade prompts "limited in
        // scope ... contextually or only on the plugin's setting page". Shipping it still reverses
        // the stance 1.3.17 took in its changelog ("all in-plugin 'upgrade' call-to-action UI
        // removed") — STATUS.md, 2026-09-19. It opens in a new tab: see enqueue_go_premium_tab().
        // Pinned by wp-plugin/tests/test-go-premium-menu.php and test-go-premium-newtab.js.
        if (!self::has_active_license()) {
            add_submenu_page(
                'htel-convert',
                __('Go Premium', 'aitoel-html-importer'),
                __('Go Premium', 'aitoel-html-importer'),
                'manage_options',
                self::PREMIUM_URL
            );
        }
    }

    /**
     * "Go Premium" opens in a new tab (George, 2026-09-19), so an administrator who clicks it in
     * the middle of a conversion keeps what they pasted. WordPress prints no target on menu links,
     * so a few lines of script, printed in the footer after the menu, set target and rel and add
     * the "(opens in a new tab)" note for screen readers. It is enqueued on every admin screen for
     * the users who get the item (the same licence test and capability as the menu), including the
     * few screens WordPress prints without the admin menu, such as the Customizer and the
     * media-upload frame, which is why it returns quietly when the link is not on the page.
     * WordPress's own command palette (Ctrl+K, 6.9+) lists the item as well; its menu entries
     * navigate the current tab (document.location), so from there it opens in the same tab.
     * Run in a real browser by wp-plugin/tests/test-go-premium-newtab.js.
     */
    public static function enqueue_go_premium_tab() {
        if (self::has_active_license() || !current_user_can('manage_options')) {
            return;
        }
        wp_register_script('htel-go-premium', false, array(), HTEL_VERSION, true);
        wp_enqueue_script('htel-go-premium');
        wp_add_inline_script('htel-go-premium', sprintf(
            '(function(){var a=document.querySelector(%1$s);if(!a){return;}a.target="_blank";a.rel="noopener noreferrer";var s=document.createElement("span");s.className="screen-reader-text";s.textContent=%2$s;a.appendChild(s);}());',
            wp_json_encode('#adminmenu a[href="' . self::PREMIUM_URL . '"]'),
            wp_json_encode(' ' . __('(opens in a new tab)', 'aitoel-html-importer'))
        ));
    }

    /**
     * A site is licensed when its licence is active AND it has a key — the test the menu and the
     * script data in this class both use (settings-page.php and class-updater.php still carry their
     * own copies of it). "Active" is the status cached in the options: a subscription that lapsed
     * stays "active" here until the next conversion or a manual licence refresh.
     */
    private static function has_active_license() {
        return HTEL_License::get_status() === 'active' && !empty(HTEL_License::get_key());
    }

    /**
     * Register settings.
     */
    public static function register_settings() {
        register_setting('htel_settings', 'htel_api_url', array(
            'type'              => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_api_url'),
            'default'           => HTEL_API_URL,
        ));
        register_setting('htel_settings', 'htel_telemetry_opt_in', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ));
        // Conversion Quality Sampling — opt-OUT (see ToS §9.4).
        // Default false means sampling is ON; user checks to disable.
        register_setting('htel_settings', 'htel_sampling_opt_out', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ));
        // Site Styles: Global Colors + Global Fonts. Held back from release on 2026-08-07
        // ("option B") and reinstated 2026-08-27 on George's call, this time with the fonts
        // half — which had been built in the engine since 2026-07-17 and never left the
        // process, because neither the API service nor the route ever named it.
        //
        // DEFAULT FALSE, and it stays false: this writes into the customer's ACTIVE KIT, which
        // is site-wide and affects pages we did not convert. A feature that edits something the
        // user did not point at is opt-in or it is a support ticket.
        register_setting('htel_settings', 'htel_globalize', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ));
    }

    /**
     * Sanitize callback for the htel_api_url setting.
     *
     * Refuses to persist an empty or malformed value — falls back to the
     * existing stored value (if it was valid) or to HTEL_API_URL constant.
     *
     * Why this is necessary on top of get_api_url() self-healing:
     *   - get_api_url() is the READ path. Self-healing there means an
     *     accidentally-saved-empty option silently disappears on next read.
     *     That's defense-in-depth, but the customer's UI experience is
     *     "I saved something, then it vanished" — confusing.
     *   - This sanitize callback is the WRITE path. We refuse the bad
     *     value at submit time and surface an admin notice explaining why.
     *     The customer sees: "the API URL field can't be empty; reverted
     *     to https://api.aitoelementor.com" — much clearer.
     *
     * Tests in wp-plugin/tests/test-settings-sanitize.php enforce this.
     */
    public static function sanitize_api_url($value) {
        // Trim whitespace; many "empty" submissions are actually whitespace
        $value = is_string($value) ? trim($value) : '';

        // Valid URL with http(s) scheme — accept (esc_url_raw for safety)
        if ($value !== ''
            && preg_match('#^https?://[^\s/]+#i', $value)
            && filter_var($value, FILTER_VALIDATE_URL)) {
            $clean = esc_url_raw($value);
            return rtrim($clean, '/');
        }

        // Surface an admin notice so the user knows their submission was
        // refused (rather than silently saved-and-then-defaulted).
        add_settings_error(
            'htel_api_url',
            'htel_api_url_invalid',
            sprintf(
                /* translators: %s is the canonical API URL */
                esc_html__('The API URL must be a complete URL with http:// or https://. Reverted to %s.', 'aitoel-html-importer'),
                esc_html(HTEL_API_URL)
            ),
            'error'
        );

        // Fall back to existing valid stored value, otherwise the constant
        $existing = get_option('htel_api_url', '');
        if (is_string($existing) && $existing !== ''
            && preg_match('#^https?://[^\s/]+#i', $existing)
            && filter_var($existing, FILTER_VALIDATE_URL)) {
            return rtrim($existing, '/');
        }
        return HTEL_API_URL;
    }

    /**
     * Enqueue admin CSS and JS on our pages only.
     */
    public static function enqueue_assets($hook) {
        // Match our pages regardless of how WP sanitizes the menu title
        if (strpos($hook, 'htel-convert') === false && strpos($hook, 'htel-settings') === false) {
            return;
        }

        wp_enqueue_style(
            'htel-admin',
            HTEL_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            HTEL_VERSION
        );

        wp_enqueue_script(
            'htel-admin',
            HTEL_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            HTEL_VERSION,
            true
        );

        $has_license = self::has_active_license();

        wp_localize_script('htel-admin', 'htel_ajax', array(
            'ajax_url'       => admin_url('admin-ajax.php'),
            'convert_nonce'  => wp_create_nonce('htel_convert_nonce'),
            'license_nonce'  => wp_create_nonce('htel_license_nonce'),
            'has_license'    => $has_license ? '1' : '0',
        ));
    }

    /**
     * Render the conversion page.
     */
    public static function render_convert_page() {
        include HTEL_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Render the settings page.
     */
    public static function render_settings_page() {
        include HTEL_PLUGIN_DIR . 'templates/settings-page.php';
    }
}
