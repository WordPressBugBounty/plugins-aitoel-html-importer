<?php
defined('ABSPATH') || exit;

/**
 * Verify Elementor is installed/active AND that the Flexbox Container feature
 * is enabled — the converter emits container-based markup (`elType: container`),
 * which renders a BLANK page on sites where Elementor's Container feature is off.
 */
class HTEL_Elementor_Check {

    /**
     * Check if Elementor is loaded.
     */
    public static function is_active() {
        return did_action('elementor/loaded');
    }

    /**
     * Is Elementor's Flexbox "Container" feature active on this site?
     *
     * The converter outputs flexbox-container elements. If a site has the
     * Container feature turned OFF (legacy Section/Column mode), the imported
     * template renders as a BLANK page on the front end — the #1 support issue.
     *
     * Detection strategy (most authoritative first), built to NEVER false-warn:
     *   1. Ask Elementor's experiments manager. `is_feature_active('container')`
     *      honors both the saved option and the per-version default. We first
     *      confirm the 'container' experiment is still REGISTERED — in newer
     *      Elementor it graduated/was retired, so containers are ALWAYS on and
     *      `is_feature_active()` would wrongly report the now-unknown id as off.
     *   2. Fallback heuristic when the experiments API is unavailable: the
     *      explicit option `elementor_experiment-container`
     *      ('active' | 'inactive' | 'default'), then the version default
     *      (containers ship default-ON from Elementor 3.16.0).
     *
     * @return bool True if containers will render (or we can't be sure → don't warn).
     */
    /**
     * Elementor Pro's Nav Menu widget is genuinely USABLE on this site.
     *
     * Registered is NOT enough: free Elementor 4.2.2 registers `nav-menu` as
     * Elementor\Modules\Promotions\Widgets\Pro_Widget_Promotion — an upsell
     * placeholder that renders a "get Pro" box instead of a menu. Emitting a
     * nav-menu widget against that stub would put an advert in the customer's
     * header, and the class check is the only reliable discriminator (the
     * missing-widgets registry check passes for stubs).
     */
    public static function is_pro_nav_menu_usable() {
        if (!defined('ELEMENTOR_PRO_VERSION')) {
            return false;
        }
        if (!did_action('elementor/loaded') || !class_exists('\Elementor\Plugin')) {
            return false;
        }
        $widgets = \Elementor\Plugin::instance()->widgets_manager->get_widget_types();
        if (!isset($widgets['nav-menu'])) {
            return false;
        }
        return stripos(get_class($widgets['nav-menu']), 'Promotion') === false;
    }

    public static function are_containers_active() {
        // Elementor not loaded — the Elementor-missing notice covers that case;
        // don't pile on a (meaningless) container warning here.
        if (!self::is_active()) {
            return true;
        }

        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->experiments)) {
            $experiments = \Elementor\Plugin::$instance->experiments;
            if (is_object($experiments)
                && method_exists($experiments, 'is_feature_active')
                && method_exists($experiments, 'get_features')) {
                try {
                    $feature = $experiments->get_features('container');
                    // Experiment retired (graduated to stable) → containers always render.
                    if (empty($feature)) {
                        return true;
                    }
                    return (bool) $experiments->is_feature_active('container');
                } catch (\Throwable $e) {
                    // fall through to the option/version heuristic
                }
            }
        }

        // Fallback: explicit option wins, then the version default.
        $opt = get_option('elementor_experiment-container', 'default');
        $ver = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null;
        return self::containers_active_from_heuristic($opt, $ver);
    }

    /**
     * Pure decision for the experiments-API-unavailable fallback. Extracted so it
     * can be unit-tested without a live Elementor instance.
     *
     *   option 'active'   → on
     *   option 'inactive' → off (user explicitly disabled — the blank-page case)
     *   option 'default'/unset → containers ship default-ON from Elementor 3.16.0;
     *                            older Elementor defaulted OFF. Unknown version →
     *                            assume on (never false-warn).
     *
     * @param string      $opt              Value of elementor_experiment-container.
     * @param string|null $elementor_version ELEMENTOR_VERSION, or null if undefined.
     * @return bool
     */
    public static function containers_active_from_heuristic($opt, $elementor_version) {
        if ($opt === 'active') {
            return true;
        }
        if ($opt === 'inactive') {
            return false;
        }
        if (is_string($elementor_version) && $elementor_version !== '') {
            return version_compare($elementor_version, '3.16.0', '>=');
        }
        return true; // never false-warn when genuinely unsure
    }

    /**
     * Whether the current user is allowed to flip the Elementor experiment.
     * Enabling an experiment is a site-level setting → manage_options.
     */
    public static function current_user_can_enable_containers() {
        return current_user_can('manage_options');
    }

    /**
     * Show admin notice if Elementor is not active.
     */
    public static function maybe_show_notice() {
        if (self::is_active()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) return;

        // Only show on our plugin pages or plugins page
        if (strpos($screen->id, 'htel') === false && $screen->id !== 'plugins') {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('HTML to Elementor', 'aitoel-html-importer') . ':</strong> ';
        echo esc_html__('Elementor must be installed and activated to use this plugin.', 'aitoel-html-importer');
        echo '</p></div>';
    }

    /**
     * Show admin notice when Elementor IS active but the Flexbox Container
     * feature is OFF — converted templates would render blank. Offers a
     * one-click enable (admins) or a manual instruction (non-admins).
     *
     * Scoped to our plugin pages, where admin.js (which wires the enable
     * button) is enqueued.
     */
    public static function maybe_show_container_notice() {
        // Only relevant when Elementor is active but containers are off.
        if (!self::is_active() || self::are_containers_active()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'htel') === false) {
            return;
        }

        $settings_url = admin_url('admin.php?page=elementor-settings#tab-experiments');

        echo '<div class="notice notice-warning htel-container-notice"><p>';
        echo '<strong>' . esc_html__('AI to Elementor', 'aitoel-html-importer') . ':</strong> ';
        echo esc_html__('Elementor’s Flexbox Container feature is turned OFF on this site. Converted templates use containers, so they will appear BLANK on the front end until it is enabled.', 'aitoel-html-importer');
        echo '</p><p>';
        if (self::current_user_can_enable_containers()) {
            echo '<button type="button" class="button button-primary htel-enable-containers">'
                . esc_html__('Enable Flexbox Containers', 'aitoel-html-importer')
                . '</button> ';
            echo '<a href="' . esc_url($settings_url) . '" target="_blank" rel="noopener" style="margin-left:8px;">'
                . esc_html__('or open Elementor → Settings → Features', 'aitoel-html-importer')
                . '</a>';
            echo ' <span class="htel-enable-containers-status" style="margin-left:8px;"></span>';
        } else {
            echo esc_html__('Ask a site administrator to enable it at Elementor → Settings → Features → Flexbox Container.', 'aitoel-html-importer');
        }
        echo '</p></div>';
    }

    /**
     * AJAX: one-click enable of the Flexbox Container experiment.
     */
    public static function ajax_enable_containers() {
        check_ajax_referer('htel_convert_nonce', 'nonce');

        if (!self::current_user_can_enable_containers()) {
            wp_send_json_error(array(
                'message' => esc_html__('You do not have permission to change Elementor settings. Ask a site administrator.', 'aitoel-html-importer'),
            ));
        }

        // Set the experiment option to active. Elementor's experiments manager
        // reads this option; 'active' force-enables the feature regardless of
        // the per-version default.
        update_option('elementor_experiment-container', 'active');

        // Best-effort: clear Elementor's generated CSS cache so any already-
        // converted (previously blank) pages regenerate with the container CSS.
        if (class_exists('\Elementor\Plugin')
            && isset(\Elementor\Plugin::$instance->files_manager)
            && method_exists(\Elementor\Plugin::$instance->files_manager, 'clear_cache')) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        if (self::are_containers_active()) {
            wp_send_json_success(array(
                'message' => esc_html__('Flexbox Containers enabled. Your converted pages will now render.', 'aitoel-html-importer'),
            ));
        }

        wp_send_json_error(array(
            'message' => esc_html__('Could not enable Flexbox Containers automatically. Please enable it manually at Elementor → Settings → Features.', 'aitoel-html-importer'),
        ));
    }

    /* ------------------------------------------------------------------
     * Missing-widget detection (Elementor 4 / legacy-widgets refund guard)
     *
     * New-generation Elementor treats the CLASSIC widgets our templates use
     * (accordion, text-editor, heading, …) as "legacy". When a widget type is
     * not registered on the site, Elementor renders that element as an EMPTY
     * block — the editor shows a blank selection box and the front end shows
     * nothing. Real case: customer on Elementor Pro 4.1.12 reported "the FAQ
     * did not convert" — his JSON contained the full accordion; his Elementor
     * simply had no `accordion` widget registered (2026-07-14).
     *
     * Strategy mirrors the Flexbox-Container check above: detect authoritatively
     * (ask the live widgets manager, never version-sniff), warn clearly, never
     * gate the import, and never false-warn (unknown ⇒ stay silent).
     * ------------------------------------------------------------------ */

    /**
     * Collect the unique widgetType slugs used anywhere in an Elementor
     * content tree. Pure + recursive (unit-tested).
     *
     * @param array $elements Elementor elements array (template 'content').
     * @return string[] Unique widgetType slugs, in first-seen order.
     */
    public static function collect_widget_types($elements) {
        $found = array();
        if (!is_array($elements)) {
            return $found;
        }
        $walk = function ($els) use (&$walk, &$found) {
            foreach ((array) $els as $el) {
                if (!is_array($el)) {
                    continue;
                }
                if (isset($el['widgetType']) && is_string($el['widgetType']) && $el['widgetType'] !== '') {
                    if (!in_array($el['widgetType'], $found, true)) {
                        $found[] = $el['widgetType'];
                    }
                }
                if (!empty($el['elements'])) {
                    $walk($el['elements']);
                }
            }
        };
        $walk($elements);
        return $found;
    }

    /**
     * Pure core of the missing-widget check: given the used types and a
     * registry lookup, return the types the lookup does NOT know.
     * Extracted so it can be unit-tested without a live Elementor.
     *
     * @param string[] $types  widgetType slugs used by the template.
     * @param callable $lookup fn(string $slug): bool — true when registered.
     * @return string[] Slugs not registered.
     */
    public static function missing_from_registry($types, $lookup) {
        $missing = array();
        foreach ((array) $types as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            if (!call_user_func($lookup, $slug)) {
                $missing[] = $slug;
            }
        }
        return $missing;
    }

    /**
     * Which of the given widget types are NOT registered in this site's
     * Elementor? Authoritative: asks the live widgets manager. Never
     * false-warns — if the manager is unavailable, reports nothing missing.
     *
     * @param string[] $types widgetType slugs used by the template.
     * @return string[] Missing slugs ([] when all render, or when unsure).
     */
    public static function get_missing_widget_types($types) {
        if (!self::is_active() || !class_exists('\Elementor\Plugin')) {
            return array();
        }
        $instance = \Elementor\Plugin::$instance;
        if (!is_object($instance) || !isset($instance->widgets_manager)
            || !is_object($instance->widgets_manager)
            || !method_exists($instance->widgets_manager, 'get_widget_types')) {
            return array();
        }
        $manager = $instance->widgets_manager;
        try {
            return self::missing_from_registry($types, function ($slug) use ($manager) {
                return (bool) $manager->get_widget_types($slug);
            });
        } catch (\Throwable $e) {
            return array(); // never false-warn
        }
    }

    /**
     * Run the missing-widget check for a just-imported template and persist
     * the result so a dismissible admin notice can surface it on our screens.
     * Clean result clears any previous warning.
     *
     * @param array $content Elementor content tree of the imported template.
     * @return string[] Missing widget slugs (also persisted when non-empty).
     */
    public static function record_template_widget_compat($content) {
        $missing = self::get_missing_widget_types(self::collect_widget_types($content));
        if (!empty($missing)) {
            update_option('htel_missing_widgets', array(
                'types' => array_values($missing),
                'time'  => time(),
            ), false);
        } else {
            delete_option('htel_missing_widgets');
        }
        return $missing;
    }

    /**
     * Dismiss handler (admin_init): clears the persisted warning when the
     * notice's dismiss link is followed (nonce-checked). Link-based (no JS)
     * to stay WP.org-guideline clean.
     */
    public static function maybe_handle_missing_widgets_dismiss() {
        if (!isset($_GET['htel_dismiss_missing_widgets'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'htel_dismiss_missing_widgets')) {
            return;
        }
        delete_option('htel_missing_widgets');
        wp_safe_redirect(remove_query_arg(array('htel_dismiss_missing_widgets', '_wpnonce')));
        exit;
    }

    /**
     * Admin notice: the last imported template uses widget types this site's
     * Elementor does not register — those blocks appear EMPTY until the
     * legacy/classic widgets are enabled. Self-heals: if the widgets are now
     * registered (user enabled the feature / updated Elementor), the stored
     * warning is cleared silently.
     */
    public static function maybe_show_missing_widgets_notice() {
        $stored = get_option('htel_missing_widgets');
        if (empty($stored) || empty($stored['types']) || !is_array($stored['types'])) {
            return;
        }

        // Re-check live: if everything is registered now, clear + stay silent.
        $still_missing = self::get_missing_widget_types($stored['types']);
        if (empty($still_missing)) {
            delete_option('htel_missing_widgets');
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        // Our plugin screens + the Elementor library list (where the imported
        // template lives) — the two places the user looks after an import.
        $on_ours    = strpos($screen->id, 'htel') !== false;
        $on_library = strpos($screen->id, 'elementor_library') !== false;
        if (!$on_ours && !$on_library) {
            return;
        }

        $features_url = admin_url('admin.php?page=elementor-settings#tab-experiments');
        $dismiss_url  = wp_nonce_url(
            add_query_arg('htel_dismiss_missing_widgets', '1'),
            'htel_dismiss_missing_widgets'
        );

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>' . esc_html__('AI to Elementor:', 'aitoel-html-importer') . '</strong> ';
        printf(
            /* translators: %s: comma-separated list of Elementor widget names */
            esc_html__('Your imported template uses Elementor widgets that are NOT active on this site: %s. Those blocks will appear EMPTY in the editor and on the page.', 'aitoel-html-importer'),
            '<code>' . esc_html(implode(', ', array_map('sanitize_key', $still_missing))) . '</code>'
        );
        echo '</p><p>';
        printf(
            /* translators: %s: link to the Elementor Features settings screen */
            esc_html__('On Elementor 4 (and late 3.x) some classic widgets ship as “legacy” and are off by default. Enable them under %s (look for Legacy / Classic widgets or Nested Elements) and reload the editor — or simply convert the page again: the plugin now picks widgets that match your Elementor version automatically.', 'aitoel-html-importer'),
            '<a href="' . esc_url($features_url) . '" target="_blank" rel="noopener">' . esc_html__('Elementor → Settings → Features', 'aitoel-html-importer') . '</a>'
        );
        echo ' <a href="' . esc_url($dismiss_url) . '" style="margin-left:8px;">' . esc_html__('Dismiss', 'aitoel-html-importer') . '</a>';
        echo '</p></div>';
    }
}
