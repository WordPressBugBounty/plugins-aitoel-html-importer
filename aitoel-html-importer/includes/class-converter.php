<?php
defined('ABSPATH') || exit;

/**
 * Handles HTML-to-Elementor conversion via API and saves result as Elementor template.
 */
class HTEL_Converter {

    /**
     * What of a conversion response may touch the customer's kit — and the one rule that decides.
     *
     * THE CONSENT CHECK LIVES ON THIS SIDE. Until this existed, the only thing between a response
     * and a customer's SITE-WIDE kit was the API choosing not to send a payload — and
     * `htel_api_url` is a setting the customer can repoint. Measured by review with the option OFF
     * and a mocked API returning an unsolicited palette: the request carried no `globalize` and
     * the kit was written anyway.
     *
     * It is a named method rather than two inline ternaries for the same reason `siteStyles()` is
     * one on the API side: the rule is worth a test, and a test needs something to hold. The
     * previous version's consent lived at the far end of an HTTP call, which is not a place a
     * test — or a customer — can rely on.
     *
     * @param array $body The decoded API response.
     * @return array [colours, fonts, typography] - ALWAYS three; a 2-element return under the 3-way list() was cycle 3's blocker — both empty unless the site asked for Site Styles.
     */
    private static function site_styles_from_response($body) {
        if (!get_option('htel_globalize', false)) {
            // THREE elements ALWAYS. 58bcbf7 widened the caller to a 3-way list() and left this branch
            // at two - so every globalize-OFF conversion (the DEFAULT arm) raised E_WARNING
            // 'Undefined array key 2', which on display_errors hosts prefixed the AJAX JSON and
            // made a SUCCESSFUL import read as a failure. The suite was green over it because
            // the runner filtered the words 'PHP Warning' out of its own evidence (also fixed).
            return array(array(), array(), array());
        }
        $colors = (isset($body['global_colors']) && is_array($body['global_colors']))
            ? $body['global_colors'] : array();
        $fonts = (isset($body['global_fonts']) && is_array($body['global_fonts']))
            ? $body['global_fonts'] : array();
        $typography = (isset($body['global_typography']) && is_array($body['global_typography']))
            ? $body['global_typography'] : array();
        return array($colors, $fonts, $typography);
    }

    /**
     * Clear Elementor's generated CSS, ONCE per request however many injectors ran.
     *
     * Both injectors used to call `clear_cache()` themselves, so adding colours and fonts
     * regenerated every post's CSS twice on a site that may have thousands of them. The flush has
     * to happen — a kit whose variables changed without it renders the old values — but once is
     * enough, and the second one is pure cost.
     */
    private static $css_flushed = false;
    private static function flush_elementor_css() {
        if (self::$css_flushed) { return; }
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            self::$css_flushed = true;
        }
    }

    /**
     * The id of the kit ELEMENTOR WILL ACTUALLY READ, or 0.
     *
     * ASK ELEMENTOR. Do not re-implement its answer. Two earlier versions of this method tried,
     * and each closed one state and left the others:
     *
     *   v1  `get_option('elementor_active_kit')`      — a DELETED post passed
     *   v2  ...plus `get_post($kit_id)`               — a PLAIN POST and a TRASHED KIT passed
     *
     * Elementor's own test is `$kit && $kit instanceof Kit && 'trash' !== post_status`
     * (`core/kits/manager.php`). Anything looser writes the palette onto a post Elementor will
     * never read, while `globalize` is still REQUESTED — so the engine rewrites the page's
     * colours into `__globals__` references that resolve to nothing, and the customer's page
     * renders Elementor's DEFAULTS instead of their own colours. Silently: the error_log net
     * needs `0 === $colors_added`, and twelve colours were "added".
     *
     * Measured in the QA WordPress by `PHL/_kit-authority.php`, all five states:
     *
     *     state                    v2 (get_post)   this method
     *     healthy kit (4)                      4             4
     *     dead id 999777                       0             0
     *     a plain 'post'                    9513             0
     *     a TRASHED kit                     9514             0
     *     restored (4)                         4             4
     *
     * NOT memoised. A function-static cannot be cleared from PHP, so a memo would make this
     * method untestable: the suite drives five kit states in one process and every state after
     * the first would read the first one's answer. Elementor's documents manager already caches
     * the document, so the four asks in a request cost one load.
     */
    /**
     * Should this request ask the API for Site Styles at all?
     *
     * TWO conditions, and both are load-bearing. The SETTING is consent: without it nothing about
     * the customer's site-wide kit is any of our business. The KIT is capability: with `globalize`
     * the ENGINE rewrites the page's colours into `__globals__` references, and a reference with
     * no kit behind it does not fall back to the inline colour — it renders Elementor's DEFAULT.
     * Asking without a kit is therefore strictly worse than not asking.
     *
     * Extracted from `ajax_convert()` because review pointed out the rule had no test: the only
     * way to reach that line was to drive the whole AJAX handler, which terminates in
     * `wp_send_json`. A rule nothing can exercise is a rule waiting to be edited by accident.
     * `test-site-styles.php` now drives this across every kit state, and asserts that
     * `ajax_convert()` still calls it rather than rebuilding the condition inline.
     */
    private static function should_globalize() {
        return get_option('htel_globalize', false) && (bool) self::active_kit_id();
    }

    private static function active_kit_id() {
        // F3 (review cycle 4): when the kit OPTION itself is empty, asking Elementor's
        // kits_manager instantiates document machinery that warns ('post_status on null') on
        // every conversion of a broken-kit site - and on display_errors hosts those warnings
        // prefix the AJAX JSON exactly like cycle 3's blocker. Nothing to write to anyway.
        if (!get_option('elementor_active_kit')) {
            return 0;
        }
        if (!class_exists('\Elementor\Plugin')) {
            return 0;   // Elementor not loaded — no kit to write to, skip silently.
        }
        $plugin = \Elementor\Plugin::$instance;
        if (!$plugin || empty($plugin->kits_manager)) {
            return 0;
        }
        $kit = $plugin->kits_manager->get_active_kit();
        if (!$kit) {
            return 0;
        }
        $post = $kit->get_main_post();
        if (!$post || empty($post->ID)) {
            return 0;   // Elementor hands back an EMPTY kit instance for every broken state.
        }
        return (int) $post->ID;
    }

    /** Kit entries one conversion may add, mirroring the engine's own caps. See inject_*. */
    const MAX_KIT_COLORS = 12;
    const MAX_KIT_FONTS = 4;


    /**
     * AJAX handler for conversion.
     */
    public static function ajax_convert() {
        check_ajax_referer('htel_convert_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => esc_html__('You do not have permission to create templates', 'aitoel-html-importer')));
        }

        // Check Elementor
        if (!HTEL_Elementor_Check::is_active()) {
            wp_send_json_error(array('message' => esc_html__('Elementor must be installed and activated', 'aitoel-html-importer')));
        }

        // Check license — allow free tier (no license) or paid
        $has_license = HTEL_License::is_valid_for_conversion();
        $license_key = $has_license ? HTEL_License::get_key() : '';

        // Get HTML input
        $html = '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Imported Page';
        // Site-part intent ("I'm converting a header or footer" checkbox, copy approved
        // 2026-08-14). Strictly 'header'|'footer'; anything else is dropped so the API
        // emits the ordinary page envelope.
        $intent = isset($_POST['intent']) ? sanitize_text_field(wp_unslash($_POST['intent'])) : '';
        if (!in_array($intent, array('header', 'footer'), true)) { $intent = ''; }
        $imported_images = array(); // Track imported image count

        if (!empty($_FILES['zip_file']) && isset($_FILES['zip_file']['tmp_name'])) {
            // ─── ZIP upload: extract HTML + import images ───
            // Build a sanitized copy of the $_FILES entry before passing to
            // the handler. Per WP.org review, every $_FILES field read is
            // user input and must be sanitized at the boundary.
            $zip_file_sanitized = array(
                'name'     => isset($_FILES['zip_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['zip_file']['name'])) : '',
                'tmp_name' => isset($_FILES['zip_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['zip_file']['tmp_name'])) : '',
                'error'    => isset($_FILES['zip_file']['error']) ? (int) $_FILES['zip_file']['error'] : UPLOAD_ERR_NO_FILE,
                'size'     => isset($_FILES['zip_file']['size']) ? (int) $_FILES['zip_file']['size'] : 0,
            );
            $result = self::handle_zip_upload($zip_file_sanitized, $title);
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            $html = $result['html'];
            $title = $result['title'];
            $imported_images = $result['imported_images'];
        } elseif (!empty($_POST['html_b64'])) {
            // Base64-encoded HTML body. The JS encodes the payload before POSTing
            // to bypass server-level WAFs (mod_security, Imunify360, BitNinja) on
            // shared cPanel hosts that pattern-match request bodies for HTML/script
            // signatures and 403 the request before it reaches WordPress. The
            // Apache 403 footer is the giveaway — see batch10/SA-customer issue.
            //
            // Encoding is opaque to WAFs (just base64 chars). Decoding here gets
            // us back to the original HTML for the rest of the conversion pipeline,
            // identical to the legacy `html` field path below.
            //
            // wp_unslash because POST data goes through WP's magic-quotes-style
            // slash addition; base64_decode is permissive and tolerates the
            // expected trailing '=' padding (which doesn't get slash-escaped).
            $b64 = wp_unslash($_POST['html_b64']);
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $decoded = base64_decode($b64, true);
            if ($decoded === false) {
                wp_send_json_error(array('message' => esc_html__('Invalid encoded HTML payload', 'aitoel-html-importer')));
            }
            $html = $decoded;
        } elseif (!empty($_POST['html'])) {
            // Legacy plain-text HTML path (kept for backwards compatibility with
            // any third-party integrations that POST directly to admin-ajax).
            // Note: HTML content is intentionally NOT sanitized with wp_kses() because
            // the entire purpose of this plugin is to convert arbitrary HTML to Elementor.
            // Sanitizing would strip the HTML structures we need to convert.
            // The HTML is sent to our external API for conversion and never rendered
            // directly on the site — it is transformed into Elementor JSON data.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $html = wp_unslash($_POST['html']);
        } elseif (!empty($_FILES['html_file']) && isset($_FILES['html_file']['tmp_name'])) {
            // Validate file upload structure BEFORE reading any of its fields.
            // $_FILES entries are user input and must be sanitized per WP.org
            // security review. We:
            //   - sanitize_file_name() the client-supplied name (never trust it)
            //   - sanitize_text_field() the tmp_name path before filesystem ops
            //   - reject anything that isn't the expected structure
            $upload_error  = isset($_FILES['html_file']['error']) ? (int) $_FILES['html_file']['error'] : UPLOAD_ERR_NO_FILE;
            $upload_name   = isset($_FILES['html_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['html_file']['name'])) : '';
            $upload_tmp    = isset($_FILES['html_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['html_file']['tmp_name'])) : '';

            if ($upload_error !== UPLOAD_ERR_OK) {
                wp_send_json_error(array('message' => esc_html__('File upload failed', 'aitoel-html-importer')));
            }
            if (empty($upload_name) || empty($upload_tmp) || !is_uploaded_file($upload_tmp)) {
                wp_send_json_error(array('message' => esc_html__('Invalid upload', 'aitoel-html-importer')));
            }
            $ext = strtolower(pathinfo($upload_name, PATHINFO_EXTENSION));
            if ($ext !== 'html' && $ext !== 'htm') {
                wp_send_json_error(array('message' => esc_html__('Only .html and .htm files are accepted', 'aitoel-html-importer')));
            }
            // Use WP_Filesystem if available; fall back to file_get_contents.
            // The file lives on the server's tmp dir, validated above.
            $html = file_get_contents($upload_tmp);
            if ($html === false) {
                wp_send_json_error(array('message' => esc_html__('Could not read uploaded file', 'aitoel-html-importer')));
            }
            if (empty($title) || $title === 'Imported Page') {
                // Derive a safe title from the sanitized filename. pathinfo() on
                // the already-sanitized $upload_name is safe, and we run the
                // result through sanitize_text_field() before sending it to the
                // API where it becomes the post_title.
                $derived_title = pathinfo($upload_name, PATHINFO_FILENAME);
                $title = sanitize_text_field($derived_title);
                if (empty($title)) {
                    $title = 'Imported Page';
                }
            }
        }

        if (empty($html)) {
            wp_send_json_error(array('message' => esc_html__('No HTML content provided', 'aitoel-html-importer')));
        }

        // Size check (5MB for HTML content after extraction)
        if (strlen($html) > 5 * 1024 * 1024) {
            wp_send_json_error(array('message' => esc_html__('HTML exceeds 5MB limit', 'aitoel-html-importer')));
        }

        // Send to API
        $api_url = HTEL_License::get_api_url() . '/api/convert';
        $domain = HTEL_License::get_domain();

        // Legacy opt-IN telemetry (still honoured for users who previously enabled it)
        $telemetry = get_option('htel_telemetry_opt_in', false);

        // Conversion Quality Sampling (ToS §9.4) — enabled by default, user can opt OUT.
        // We pass the opt-out flag to the API; if true, the API skips sampling.
        $sampling_opt_out = get_option('htel_sampling_opt_out', false);

        // Site Styles (Global Colors + Global Fonts). Opt-in and off by default — it writes
        // into the ACTIVE KIT, which is site-wide. Without the flag the API returns neither a
        // palette nor a font list and both injection paths below stay inert, exactly as they
        // did between 2026-08-07 and 2026-08-27.
        // AND NOT WITHOUT A KIT TO PUT IT IN. With `globalize`, the ENGINE rewrites the page's
        // colours into `__globals__` references — and a dangling reference renders Elementor's
        // DEFAULT colour, not the author's. So if there is no active kit the page would come back
        // recoloured with nothing to fix it. Asking only when a kit exists means the engine never
        // makes the references in the first place.
        $globalize = self::should_globalize();

        $request_body = array_filter(array(
            'html'              => $html,
            'title'             => $title,
            'license_key'       => $license_key ?: null,
            'domain'            => $domain,
            'telemetry_opt_in'  => $telemetry ? true : null,
            'sampling_opt_out'  => $sampling_opt_out ? true : null,
            'globalize'         => $globalize ? true : null,
            // #175 capability declaration: THIS build injects FULL typography presets
            // (size/weight/responsive, not family-only) into the kit atomically with the
            // template - so the engine may emit __globals__ typography refs. Only sent
            // alongside globalize: without a kit to inject into, a ref would dangle and
            // render the kit DEFAULT, exactly like the colours.
            'caps'              => $globalize ? array('typography_presets' => true) : null,
            // The site's Elementor version — lets the engine pick version-correct widgets
            // (on 4.x the classic accordion can ship legacy-off and render EMPTY, so the
            // engine emits native nested-accordion instead). Absent on old plugins and raw
            // JSON downloads → the engine keeps classic output, so this can never break.
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            // Ask for native SVG icons. Only the plugin may request this: the returned Image
            // widgets carry the SVG in a `__hte_svg` payload that import_svg_icons() consumes —
            // uploading it when enabled, or rewriting the widget back to inline-SVG HTML when the
            // site owner has it off. There is now a THIRD outcome the previous version of this
            // comment did not admit: when nothing survives the safety filter the widget is DELETED
            // rather than filled with the raw payload, because writing back the one input the
            // filter rejected is not a fallback, it is the defence running backwards.
            // (A raw JSON download, which cannot consume the payload, never sends this flag.)
            'svg_icons'         => true,
            // Declared site-part intent — the engine emits a header/footer-typed template
            // (sticky kept, hamburger kept + shimmed) and the grader skips the fragment
            // warning because the customer told us.
            'intent'            => $intent ?: null,
            // Pro present AND the nav-menu widget is real (not the free tier's
            // promo stub) → the API maps a declared header's nav to a native
            // nav-menu widget + __hte_menu payload consumed below at import.
            'elementor_pro'     => HTEL_Elementor_Check::is_pro_nav_menu_usable() ? true : null,
            // Opt into the pending+confirm flow: the API logs the conversion with
            // confirmed_at = NULL and advances NO counter until confirm_conversion()
            // reports that the template actually landed on this site. Without this the
            // API falls back to counting the moment IT finishes, which burned the
            // customer's quota for imports that never reached them.
            'wants_confirm'     => true,
        ));

        $response = wp_remote_post($api_url, array(
            'timeout' => 120, // Allow up to 2 minutes for large pages
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($request_body),
        ));

        // Store the HTML in a transient so the "Report a problem" button can retrieve it
        set_transient('htel_last_conversion_html', $html, 30 * MINUTE_IN_SECONDS);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            $code = $response->get_error_code();
            $diag = self::build_connection_error_diagnostic($err, $code, $api_url, strlen(wp_json_encode($request_body)));
            wp_send_json_error(array(
                'message' => $diag,
                'error_code' => $code,
                'raw_error' => $err,
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            $error = isset($body['error']) ? $body['error'] : 'Conversion failed (HTTP ' . $code . ')';

            // Free tier service quota reached — neutral error relay (no
            // upgrade CTA per WP.org Guideline 5).
            if ($error === 'free_limit_reached') {
                // `reason` lets the UI stop calling this a failure and drop the retry button that
                // cannot succeed — the same mechanism already used for js_rendered_page below.
                // It carries NO quota numbers and NO upgrade payload: the `free_tier` metadata
                // removed in the Guideline 5 pass is deliberately NOT reinstated.
                wp_send_json_error(array(
                    'message' => isset($body['message']) ? $body['message'] : esc_html__('Conversion service quota reached for this site this month.', 'aitoel-html-importer'),
                    'reason'  => 'free_limit_reached',
                ));
            }

            // Paid-tier monthly quota reached — relay the API's structured
            // usage so the settings page can show "X/Y used this month"
            // without an extra round-trip. No upgrade CTA in plugin UI.
            if ($error === 'monthly_limit_reached') {
                if (isset($body['usage']) && is_array($body['usage'])) {
                    update_option('htel_license_usage', $body['usage']);
                }
                wp_send_json_error(array(
                    'message' => isset($body['message']) ? $body['message'] : esc_html__('Monthly conversion service quota reached.', 'aitoel-html-importer'),
                    'usage'   => isset($body['usage']) ? $body['usage'] : null,
                    'reason'  => 'monthly_limit_reached',
                ));
            }

            // JS-rendered page (React/Vue/Next/etc. SPA shell) — the page's content
            // is generated at runtime by JavaScript, so there was nothing static to
            // import. The API did NOT charge this conversion. Relay the guidance
            // message instead of a generic failure so the user knows what to do.
            if ($error === 'js_rendered_page') {
                wp_send_json_error(array(
                    'message'     => isset($body['message']) ? $body['message'] : esc_html__('This page is built by JavaScript, so there was no static content to import. Save the fully-rendered HTML from your browser (right-click → Save As → “Webpage, Complete”) and upload that file, or paste the page URL. You were not charged for this conversion.', 'aitoel-html-importer'),
                    'reason'      => 'js_rendered_page',
                    'not_charged' => true,
                ));
            }

            wp_send_json_error(array('message' => $error));
        }

        // Persist the live usage counter from the API response so the
        // settings page reflects current month's usage without a
        // validate round-trip after each conversion.
        if (isset($body['usage']) && is_array($body['usage'])) {
            update_option('htel_license_usage', $body['usage']);
        }

        // Save as Elementor template
        $template = $body['template'];
        $fonts = isset($body['meta']['fonts']) ? $body['meta']['fonts'] : array();
        $element_count = isset($body['meta']['element_count']) ? intval($body['meta']['element_count']) : 0;
        // Global Colors palette (present only when globalize was requested + supported).
        // The template's __globals__ refs dangle until these land in the kit, so inject
        // them atomically with the page (inside save_elementor_template).
        list($global_colors, $global_fonts, $global_typography) = self::site_styles_from_response($body);
        // Global Fonts, the same contract: present only when globalize was requested AND the
        // deployed converter returned a list. Family-level presets — Elementor's typography
        // globals are all-or-nothing bundles, so sizes are deliberately not mapped.

        $template_id = self::save_elementor_template($template, $title, $fonts, $global_colors, $global_fonts, $global_typography);

        if (is_wp_error($template_id)) {
            wp_send_json_error(array(
                /* translators: %s: technical error message returned by WordPress */
                'message' => sprintf(esc_html__('Failed to save template: %s', 'aitoel-html-importer'), $template_id->get_error_message()),
            ));
        }

        $edit_url = admin_url('post.php?post=' . $template_id . '&action=elementor');
        $library_url = admin_url('edit.php?post_type=elementor_library');

        // Site-part success note (approved copy 2026-08-14): tells the customer where the
        // header/footer landed and how to use it, Pro vs non-Pro.
        $site_part_note = self::full_success_note(
            is_array($template) ? $template : array(),
            self::$last_menu_stats
        );
        $success_data = array(
            'message'       => esc_html__('Template created successfully!', 'aitoel-html-importer'),
            'template_id'   => $template_id,
            'edit_url'      => $edit_url,
            'library_url'   => $library_url,
            'element_count' => $element_count,
            'font_count'    => count($fonts),
            'image_count'   => count($imported_images),
            // The converter emits flexbox-container markup. If this site has the
            // Container feature OFF the new template renders BLANK on the front end,
            // so the UI surfaces a prominent warning (and a one-click enable for admins).
            'containers_active'    => HTEL_Elementor_Check::are_containers_active(),
            'can_enable_containers'=> HTEL_Elementor_Check::current_user_can_enable_containers(),
            // Compatibility heads-up from the conversion service: null on a clean page (the plugin
            // shows nothing), or { tier, headline, message, issues:[{reason,fix}] } when the page
            // has features that may not convert perfectly. The template still imported either way.
            'compatibility'        => isset($body['meta']['compatibility']) ? $body['meta']['compatibility'] : null,
            // The site-part copy when a header/footer landed, PLUS a menu sentence on any conversion
        // that wrote or reused a WordPress menu — ordinary pages included..
            'site_part_note'       => $site_part_note,
            // Widget types the template uses that this site's Elementor does NOT register
            // (Elementor 4 "legacy widgets" case) — those blocks render EMPTY until enabled.
            // [] when everything renders. Also persisted for the admin notice.
            'missing_widgets'      => HTEL_Elementor_Check::record_template_widget_compat($template['content']),
        );

        // Per WP.org Guideline 5 (Trialware): the plugin no longer
        // forwards free_tier metadata to the JS layer (the metadata was
        // previously used to trigger an in-plugin upgrade CTA, which has
        // been removed). The external service still enforces quotas;
        // when reached they surface via the standard error-message path.

        // The template is on the site — only now may the conversion be counted.
        // Deliberately after every failure path above: anything that aborts earlier
        // leaves the row unconfirmed and the customer's quota untouched.
        self::confirm_conversion(
            isset($body['conversion_id']) ? $body['conversion_id'] : '',
            $domain,
            empty($license_key)
        );

        wp_send_json_success($success_data);
    }

    /**
     * Add the converted page's palette to the active Elementor kit as Global Colors.
     *
     * ADDITIVE ONLY: dedup by `_id`; our ids are `hte`-prefixed so they never collide with
     * Elementor's system colors (primary/secondary/text/accent) or a customer's own. An entry
     * the kit already holds is left exactly as it is — position, title AND value, ours or
     * theirs — and nothing is ever removed or reordered. "Refresh" used to be in this
     * sentence and in the code; both are gone, because `_id` is a hash of the colour, so
     * refreshing an existing id could never carry new information and could only revert a
     * change the CUSTOMER had made. Runs in the same request as the page save so the
     * template's `__globals__` references resolve on first render.
     *
     * @param array $global_colors  [{_id,title,color}, ...] from the API.
     * @return int  Count of newly-added colors (0 if all present / kit missing).
     */
    private static function inject_global_colors($global_colors) {
        if (empty($global_colors) || !is_array($global_colors)) {
            return 0;
        }
        $kit_id = self::active_kit_id();
        if (!$kit_id) {
            return 0; // No usable kit (absent, or the option names a post that is gone).
        }
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($settings)) {
            $settings = array();
        }
        $existing = (isset($settings['custom_colors']) && is_array($settings['custom_colors']))
            ? $settings['custom_colors'] : array();

        // ROWS WITHOUT AN `_id` ARE THE CUSTOMER'S AND MUST SURVIVE. The merge rebuilds the list
        // from `$order`, and `$order` was built only from rows that HAVE an id — so an entry
        // written by a theme demo kit, a migration or a third-party plugin was silently and
        // permanently deleted on the customer's first conversion. Measured: a row titled
        // "Client brand red" with no `_id` vanished, while the checkbox promised "Nothing is ever
        // removed". Unkeyed rows are now carried through in place.
        $by_id = array();
        $order = array();
        $unkeyed = array();
        foreach ($existing as $c) {
            // A DUPLICATE ID ALREADY IN THE KIT USED TO DESTROY A ROW. `$order` collected the raw
            // id every time it appeared, and the rebuild emitted `$by_id[$id]` once per
            // occurrence — so a kit holding two rows under the same id came back as the SECOND
            // one, twice, and the first was gone. The row COUNT was unchanged, which is why a
            // count assertion could not see it. Measured by review: A/B/C in, B/B/C out.
            //
            // A repeat is treated as unkeyed: it keeps its own contents and its own position, and
            // only the first occurrence owns the id for merging.
            if (isset($c['_id']) && is_string($c['_id']) && '' !== $c['_id']
                && !isset($by_id[$c['_id']])) {
                $by_id[$c['_id']] = $c; $order[] = $c['_id'];
            } else {
                $unkeyed[] = $c; $order[] = array('__unkeyed' => count($unkeyed) - 1);
            }
        }

        // A CAP ON OUR SIDE TOO. The engine caps at 12 colours and 4 fonts per page, but the
        // engine deploys separately from this plugin AND `htel_api_url` is customer-settable, so
        // the bound that protects the customer's kit cannot live only at the other end of an
        // HTTP call. Review wrote 200 entries through this function with a hand-made payload.
        // COUNTED. Twelve is the cap and Elementor does not paginate this list, so the cap is
        // right — but a page carrying twenty colours quietly lost eight of them, and the
        // customer had no way to know which or why.
        $kitmalformed = 0;
        $kitcolorcap = max(0, count($global_colors) - self::MAX_KIT_COLORS);
        $global_colors = array_slice($global_colors, 0, self::MAX_KIT_COLORS);

        $added = 0;
        foreach ($global_colors as $c) {
            // ONE LOAD-BEARING CHECK, NOT TWO. This carried an `is_string()` pair as well, which
            // fired FIRST for an array id or an array colour — so the empty-string refusal below
            // could never run for those cases, and ablating it left the suite green. That is the
            // same decorative-guard finding review made about the fonts half, surviving on this
            // side because the two halves had drifted apart. `sanitize_text_field()` returns ''
            // for an array (measured), so the empty check catches everything the pair did.
            if (!isset($c['_id']) || !isset($c['color'])) {
                $kitmalformed++;
                continue;
            }
            $id = sanitize_text_field($c['_id']);
            $color = sanitize_text_field($c['color']);
            // Both checks are load-bearing: `sanitize_text_field()` returns '' for an array, so an
            // array id or an array colour arrives here as an empty string. Review found the colour
            // half had no such check and wrote `{"color":""}` into the kit.
            if ('' === $id || '' === $color) {
                $kitmalformed++;
                continue;
            }
            // AN ENTRY THAT ALREADY EXISTS IS NOT OURS TO TOUCH, in any field.
            //
            // The previous version kept the customer's title and updated the VALUE, on the
            // reasoning that the value is what the id identifies. That reasoning is backwards
            // here: `_id` IS a hash of the colour, so for a given id our payload can only ever
            // carry the SAME colour we first sent. The update branch could therefore never
            // deliver new information — its only possible effect was to overwrite a value the
            // CUSTOMER had changed, on a Global Color that repaints pages they never converted.
            //
            // So an existing id is skipped entirely. Additive, never destructive.
            if (isset($by_id[$id])) { continue; }
            $by_id[$id] = array(
                '_id'   => $id,
                // `is_string` to match the font side. Without it an array title reaches
                // `sanitize_text_field()`, which returns '' — so a malformed payload wrote an
                // UNNAMED swatch instead of falling back to 'Imported'. No error, no wrong
                // colour, just a blank row in the customer's picker.
                'title' => isset($c['title']) && is_string($c['title'])
                    ? sanitize_text_field($c['title']) : 'Imported',
                'color' => $color,
            );
            $order[] = $id;
            $added++;
        }

        $merged = array();
        foreach ($order as $id) {
            $merged[] = is_array($id) ? $unkeyed[$id['__unkeyed']] : $by_id[$id];
        }
        $settings['custom_colors'] = $merged;
        self::report_drops(array('kitcolorcap' => $kitcolorcap, 'kitmalformed' => $kitmalformed));
        // SLASHED (#319). update_post_meta() unslashes what it is given, and `$settings` is the
        // customer's WHOLE kit as read back: written raw, WordPress stripped its backslashes, so a
        // conversion adding the page's colours broke the escapes in their own Site Settings Custom CSS.
        update_post_meta($kit_id, '_elementor_page_settings', wp_slash($settings));
        // ONLY IF SOMETHING CHANGED. `clear_cache()` runs `delete_post_meta_by_key()` three times
        // across the whole site and unlinks the generated CSS directory — measured at 26 cached
        // rows to 0 on a re-import that added NOTHING. A customer re-importing a page they already
        // converted was paying for their entire site's CSS to be regenerated for no change.
        if ($added > 0) { self::flush_elementor_css(); }
        return $added;
    }

    /**
     * Merge Global Fonts into the active kit's `custom_typography`.
     *
     * The exact mirror of inject_global_colors(), and it writes to the control Elementor itself
     * calls "Custom Fonts" — verified against the plugin's own source rather than assumed:
     * `core/kits/documents/tabs/global-typography.php` declares `custom_typography` as a repeater
     * with the same shape as `custom_colors`, while `system_typography` has `add` and `remove`
     * disabled because its four entries (primary/secondary/text/accent) are fixed. So the custom
     * list is the only place an imported font belongs, and system_typography is left untouched.
     *
     * The field keys are Elementor's own: TYPOGRAPHY_GROUP_PREFIX is `typography_`, giving
     * `typography_typography` and `typography_font_family` — which is exactly what the engine has
     * been emitting since 2026-07-17.
     *
     * ADDITIVE ONLY: an entry already in the kit is left exactly as it is — position, title and
     * family — and nothing is ever removed. Only ids the kit has never seen are appended. See the
     * reasoning at the skip below: `_id` is a hash of the family, so an existing id can only hold
     * the family we already sent, and "updating" it could do nothing except revert a customer's
     * change.
     *
     * @param array $global_fonts  [{_id,title,typography_typography,typography_font_family}, ...]
     * @return int  Count of newly-added fonts (0 if all present / kit missing).
     */
    /**
     * #175 - merge FULL typography presets into the kit's `custom_typography`.
     *
     * The mirror of inject_global_fonts() one field-list wider: these entries carry sizes,
     * weights, line-heights and their _tablet/_mobile variants, because the engine only emits
     * them to a caller that declared `caps.typography_presets` - this build. Every field is
     * rebuilt from an allow-list; sized values must be {unit, size} with a numeric size and a
     * known unit, or the FIELD is dropped (never the row - a preset missing one odd field
     * still styles everything else, while a dropped row dangles every widget that refs it).
     *
     * ADDITIVE ONLY, same as the palette: existing ids are never touched, so a customer's
     * edit to an imported preset survives re-import. Ids are `ht`-prefixed by the engine.
     *
     * @param array $global_typography [{_id,title,typography_typography,typography_*...}, ...]
     * @return int newly-added presets.
     */
    /** Ids whose incoming values differed from the kit's existing row - their refs must not stand. */
    private static $typography_mismatched_ids = array();

    // Icons that reached the page as inline markup instead of an editable Image widget, split by
    // cause. report_drops() records these too, but it ends in error_log() and therefore NEVER
    // reaches a customer — the same gap the nav-menu fallback had, fixed the same way: a sentence
    // in the success note. Reset per conversion so a second import cannot inherit the first count.
    private static $svg_held = 0;   // native icon import is switched OFF on this site
    private static $svg_write = 0;  // it is switched ON and the write failed

    /**
     * Does the kit's row for $id carry the same TYPOGRAPHY VALUES as the incoming preset?
     * Title is ignored - a customer renaming 'Heading 2' to 'My H2' must not force a mismatch.
     * Field-by-field over the union of typography_* keys; sized values compare unit+size.
     */
    private static function typography_values_match($existing, $id, $incoming) {
        $stored = null;
        foreach ($existing as $row) {
            if (isset($row['_id']) && $row['_id'] === $id) { $stored = $row; break; }
        }
        if (!is_array($stored)) {
            return false;
        }
        $keys = array();
        foreach (array_merge(array_keys($stored), array_keys($incoming)) as $k) {
            if (0 === strpos($k, 'typography_') && 'typography_typography' !== $k) { $keys[$k] = true; }
        }
        foreach (array_keys($keys) as $k) {
            $a = isset($stored[$k]) ? $stored[$k] : null;
            $b = isset($incoming[$k]) ? $incoming[$k] : null;
            if (is_array($a) !== is_array($b)) {
                return false; // one side sized, one not - mismatch, and never a string-cast warning
            }
            if (is_array($a) && is_array($b)) {
                $ua = isset($a['unit']) ? $a['unit'] : ''; $ub = isset($b['unit']) ? $b['unit'] : '';
                $sa = isset($a['size']) ? (float) $a['size'] : null; $sb = isset($b['size']) ? (float) $b['size'] : null;
                if ($ua !== $ub || $sa !== $sb) { return false; }
            } elseif ((string) $a !== (string) $b) {
                return false;
            }
        }
        return true;
    }

    /**
     * Strip the __globals__ typography refs that point at mismatched ids, so this page keeps its
     * own inline styling instead of adopting another page's preset. Walks the whole tree.
     */
    private static function strip_mismatched_typography_refs(&$elements) {
        if (empty(self::$typography_mismatched_ids) || !is_array($elements)) {
            return 0;
        }
        $stripped = 0;
        $ids = self::$typography_mismatched_ids;
        $walk = function (&$els) use (&$walk, &$stripped, $ids) {
            foreach ($els as &$el) {
                if (isset($el['settings']['__globals__']['typography_typography'])
                    && is_string($el['settings']['__globals__']['typography_typography'])) {
                    foreach ($ids as $id) {
                        if (false !== strpos($el['settings']['__globals__']['typography_typography'], 'id=' . $id)) {
                            unset($el['settings']['__globals__']['typography_typography']);
                            if (empty($el['settings']['__globals__'])) { unset($el['settings']['__globals__']); }
                            $stripped++;
                            break;
                        }
                    }
                }
                if (!empty($el['elements']) && is_array($el['elements'])) { $walk($el['elements']); }
            }
        };
        $walk($elements);
        return $stripped;
    }

    private static function inject_global_typography($global_typography) {
        if (empty($global_typography) || !is_array($global_typography)) {
            return 0;
        }
        $kit_id = self::active_kit_id();
        if (!$kit_id) {
            return 0;
        }
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($settings)) {
            $settings = array();
        }
        $existing = (isset($settings['custom_typography']) && is_array($settings['custom_typography']))
            ? $settings['custom_typography'] : array();
        $have = array();
        foreach ($existing as $row) {
            if (isset($row['_id']) && is_string($row['_id']) && '' !== $row['_id']) {
                $have[$row['_id']] = true;
            }
        }
        $plain_keys = array('typography_font_family', 'typography_font_weight',
            'typography_text_transform', 'typography_font_style', 'typography_text_decoration');
        $sized_keys = array('typography_font_size', 'typography_line_height',
            'typography_letter_spacing', 'typography_word_spacing');
        $units = array('px', 'em', 'rem', '%', 'vh', 'vw', 'custom');
        $added = 0;
        foreach ($global_typography as $p) {
            if (!is_array($p) || !isset($p['_id']) || !is_string($p['_id']) || '' === $p['_id']) {
                continue;
            }
            $id = sanitize_text_field($p['_id']);
            if ('' === $id) {
                continue;
            }
            if (isset($have[$id])) {
                // ADDITIVE ONLY - but adoption is not. Review cycle 2 measured the trap: page A
                // imports first and owns the 'Heading 2' row; page B, authored with a DIFFERENT
                // uniform h2, arrives second - its row is skipped here, its widgets still carry
                // refs to this id, and B's headings silently RENDER AS A's (Georgia-for-Verdana,
                // on a real WordPress). The same customer-visible failure as the id collision,
                // triggered by import order, and the readme promised the opposite.
                //
                // So on an id hit the incoming values are COMPARED to the stored row: identical
                // (or a row the customer merely renamed) -> reuse is safe, refs stand. DIFFERENT
                // -> this page must NOT adopt: the refs for this id are stripped from the
                // template below, the widgets keep their own inline typography (rule 3: inline
                // stays precisely so the template is self-sufficient), and the customer's
                // existing preset - possibly their own edit - is never touched.
                if (!self::typography_values_match($existing, $id, $p)) {
                    self::$typography_strip_reason = 'mismatch';
                    self::$typography_mismatched_ids[] = $id;
                }
                continue;
            }
            $row = array(
                '_id'                   => $id,
                'title'                 => (isset($p['title']) && is_string($p['title']) && '' !== $p['title'])
                    ? sanitize_text_field($p['title']) : 'Imported',
                'typography_typography' => 'custom',
            );
            foreach ($plain_keys as $k) {
                foreach (array('', '_tablet', '_mobile') as $sfx) {
                    if (isset($p[$k . $sfx]) && is_string($p[$k . $sfx]) && '' !== $p[$k . $sfx]) {
                        $row[$k . $sfx] = sanitize_text_field($p[$k . $sfx]);
                    }
                }
            }
            foreach ($sized_keys as $k) {
                foreach (array('', '_tablet', '_mobile') as $sfx) {
                    if (!isset($p[$k . $sfx]) || !is_array($p[$k . $sfx])) {
                        continue;
                    }
                    $v = $p[$k . $sfx];
                    if (!isset($v['size']) || !is_numeric($v['size'])) {
                        continue; // malformed field: drop the FIELD, keep the row
                    }
                    // An unknown unit DROPS THE FIELD, exactly as documented. The first version
                    // silently coerced it to px — a 12pt size would have written 12px — while
                    // three commit messages said 'dropped'. The engine emits px/em/rem/%/vw/vh
                    // today; anything else is a representation this build does not understand,
                    // and guessing is how a wrong size lands in a SITE-WIDE preset.
                    if (!isset($v['unit']) || !is_string($v['unit']) || !in_array($v['unit'], $units, true)) {
                        continue;
                    }
                    $row[$k . $sfx] = array('unit' => $v['unit'], 'size' => 0 + $v['size'], 'sizes' => array());
                }
            }
            $existing[] = $row;
            $have[$id] = true;
            $added++;
        }
        if ($added > 0) {
            $settings['custom_typography'] = $existing;
            update_post_meta($kit_id, '_elementor_page_settings', wp_slash($settings));   // slashed: see inject_global_colors (#319)
            self::flush_elementor_css();
        }
        return $added;
    }

    private static function inject_global_fonts($global_fonts) {
        if (empty($global_fonts) || !is_array($global_fonts)) {
            return 0;
        }
        $kit_id = self::active_kit_id();
        if (!$kit_id) {
            return 0; // No usable kit (absent, or the option names a post that is gone).
        }
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($settings)) {
            $settings = array();
        }
        $existing = (isset($settings['custom_typography']) && is_array($settings['custom_typography']))
            ? $settings['custom_typography'] : array();

        // Same rule as the palette: a row the customer owns but that carries no `_id` is carried
        // through untouched rather than dropped when the list is rebuilt.
        $by_id = array();
        $order = array();
        $unkeyed = array();
        foreach ($existing as $f) {
            // Same as the palette: a repeated id keeps its own row rather than being collapsed
            // onto the last one that carried it.
            if (isset($f['_id']) && is_string($f['_id']) && '' !== $f['_id']
                && !isset($by_id[$f['_id']])) {
                $by_id[$f['_id']] = $f; $order[] = $f['_id'];
            } else {
                $unkeyed[] = $f; $order[] = array('__unkeyed' => count($unkeyed) - 1);
            }
        }

        $kitmalformed = 0;
        $kitfontcap = max(0, count($global_fonts) - self::MAX_KIT_FONTS);
        $global_fonts = array_slice($global_fonts, 0, self::MAX_KIT_FONTS);

        $added = 0;
        foreach ($global_fonts as $f) {
            if (!isset($f['_id']) || !isset($f['typography_font_family'])) {
                $kitmalformed++;
                continue;
            }
            // `sanitize_text_field()` returns '' for an array, so an array id or an array family
            // arrives here as an empty string and the check below refuses it. An earlier version
            // also had an `is_string()` pair in front of this — review ablated it and the suite
            // stayed green, because it could never fire first. A guard that cannot go red is
            // decoration; the empty-string check is the one doing the work.
            $id = sanitize_text_field($f['_id']);
            $family = sanitize_text_field($f['typography_font_family']);
            if ('' === $id || '' === $family) {
                $kitmalformed++;
                continue;
            }
            // Same rule, same reason: `_id` is a hash of the family, so an existing id can only
            // hold the family we already sent. Skipping it cannot lose information and stops us
            // overwriting a font the customer has since changed.
            if (isset($by_id[$id])) { continue; }
            $by_id[$id] = array(
                '_id'                    => $id,
                'title'                  => isset($f['title']) && is_string($f['title'])
                    ? sanitize_text_field($f['title']) : 'Imported',
                'typography_typography'  => 'custom',
                'typography_font_family' => $family,
            );
            $order[] = $id;
            $added++;
        }

        $merged = array();
        foreach ($order as $id) {
            $merged[] = is_array($id) ? $unkeyed[$id['__unkeyed']] : $by_id[$id];
        }
        $settings['custom_typography'] = $merged;
        self::report_drops(array('kitfontcap' => $kitfontcap, 'kitmalformed' => $kitmalformed));
        update_post_meta($kit_id, '_elementor_page_settings', wp_slash($settings));   // slashed: see inject_global_colors (#319)
        if ($added > 0) { self::flush_elementor_css(); }   // see the note in inject_global_colors
        return $added;
    }

    /**
     * Save the converted template as an Elementor library item.
     *
     * @param array  $template       The full Elementor template (content, page_settings, etc.)
     * @param string $title          Template title
     * @param array  $fonts          List of font families used
     * @param array  $global_colors  Optional Global Colors palette to inject into the kit.
     * @param array  $global_fonts   Optional Global Fonts list to inject into the kit.
     * @return int|WP_Error          Post ID on success, WP_Error on failure
     */
    /**
     * Tell the API the template actually landed, so the conversion may be counted.
     *
     * The server only advances a quota when this call arrives. That is the whole point:
     * /api/convert with `wants_confirm` writes its log row with confirmed_at = NULL, so a
     * conversion that dies after the API responded — a security plugin eating the response,
     * a PHP fatal mid-save — costs the customer nothing. Before this existed the free tier
     * was charged on server success alone, which permanently locked out any prospect whose
     * single trial import failed downstream.
     *
     * Non-fatal by design. The template is already saved by the time we get here, so a
     * confirm failure must never surface to the user; it fails in the customer's favour
     * (the conversion simply goes uncounted).
     *
     * @param string $conversion_id  UUID from the convert response. Empty on an older API
     *                               that already counted at convert time — then we send nothing.
     * @param string $domain         Site domain; the server matches it against the logged row.
     * @param bool   $was_free       True when no licence key was used, so the free-tier
     *                               counter is the one that advances.
     * @return bool  True only when the API acknowledged the confirm.
     */
    private static function confirm_conversion($conversion_id, $domain, $was_free) {
        if (empty($conversion_id)) {
            return false;
        }

        $response = wp_remote_post(HTEL_License::get_api_url() . '/api/conversion-confirm', array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'conversion_id' => $conversion_id,
                'domain'        => $domain,
                'was_free'      => (bool) $was_free,
            )),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /** Result of the last import_pro_nav_menus() run — feeds the customer note. */
    private static $last_menu_stats = array('found' => 0, 'created' => 0, 'fallbacks' => 0, 'reused' => 0);

    /**
     * Reuses inside ONE import run.
     *
     * `create_wp_menu_from_payload()` cannot record a reuse on `$last_menu_stats` directly:
     * `import_pro_nav_menus()` builds a LOCAL `$stats` and the caller REPLACES the whole static
     * array with it, so the increment would be silently discarded and the customer told nothing
     * on exactly the path this counter exists to announce.
     */
    private static $menu_reused_in_run = 0;

    /**
     * Menu items dropped by the last `import_pro_nav_menus()` run, including the children that
     * went with them. It lives here rather than in a local because the counting happens inside
     * `create_wp_menu_from_payload()` and the reporting happens in its caller — and threading a
     * reference through a recursive closure to say one number was not worth the shape.
     */
    private static $last_nav_items_dropped = 0;

    /** Total items in a menu payload subtree, children included. */
    private static function count_menu_items($item) {
        $n = 0;
        if (!empty($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as $child) {
                $n += 1 + self::count_menu_items($child);
            }
        }
        return $n;
    }

    /** Remote-image import stats for the last conversion: found / imported / failed. */
    private static $last_remote_images = array('found' => 0, 'imported' => 0, 'failed' => 0);

    /**
     * Consume `__hte_menu` payloads (emitted when the converter was told this
     * site has a usable Pro nav-menu widget): create a real WordPress menu per
     * payload and point the widget at it. When the widget is NOT usable, rewrite
     * it to the carried fallback markup.
     *
     * THE FALLBACK BRANCH IS NOT REACHABLE THE WAY THIS COMMENT USED TO CLAIM. It named two
     * causes — "availability changed between convert and import" and "the payload arrived at a
     * free site" — and review showed both are impossible on the only path that produces the
     * payload: the engine emits `__hte_menu` solely when the plugin reported Pro as usable, and
     * the import re-checks the SAME function in the SAME request. So `$usable` is true whenever a
     * payload exists, and `create_wp_menu_from_payload()` always runs. What remains reachable is a
     * THROW inside that call, or `wp_create_nav_menu` failing — real, but neither of the two
     * things the comment asserted. It also means all three nav counters need Elementor Pro to
     * fire at all, which the QA rig does not have.
     * Non-fatal by design: a menu problem must never fail the whole import.
     */
    private static function import_pro_nav_menus(array &$elements, $title) {
        $stats = array('found' => 0, 'created' => 0, 'fallbacks' => 0, 'reused' => 0);
        self::$menu_reused_in_run = 0;
        $navfallback = 0;
        $navempty = 0;
        // RESET ON ENTRY TOO. It is reset after reporting, so a throw between the walk and the
        // report would leak the count into the next conversion in the same PHP process.
        self::$last_nav_items_dropped = 0;
        $usable = HTEL_Elementor_Check::is_pro_nav_menu_usable();
        $walk = function (&$els) use (&$walk, &$stats, $usable, $title, &$navfallback, &$navempty) {
            foreach ($els as &$el) {
                if (isset($el['widgetType']) && $el['widgetType'] === 'nav-menu'
                    && !empty($el['settings']['__hte_menu']) && is_array($el['settings']['__hte_menu'])) {
                    $stats['found']++;
                    $payload = $el['settings']['__hte_menu'];
                    unset($el['settings']['__hte_menu']);
                    $menu_id = 0;
                    if ($usable) {
                        try {
                            $reused_before = (int) self::$menu_reused_in_run;
                    $menu_id = self::create_wp_menu_from_payload($payload, $title);
                        } catch (\Throwable $e) {
                            $menu_id = 0;
                        }
                    }
                    if ($menu_id > 0) {
                        // VERIFIED against live Pro 4.2.2 (wp71, 2026-08-19): the Nav Menu
                        // widget's `menu` select is keyed by the menu's SLUG — introspected
                        // from the real control's default with a menu present. (First cut
                        // wired the term id; that was the flagged-unverified guess, and it
                        // was wrong.)
                        $menu_obj = wp_get_nav_menu_object($menu_id);
                        $el['settings']['menu'] = $menu_obj ? (string) $menu_obj->slug : (string) $menu_id;
                        // CREATED or REUSED — and they are different sentences to the customer.
                        // This line used to fire on both, because reuse also returns a valid id.
                        // The effect: `reused > 0` could never occur without `created > 0`, the
                        // reuse branch of the success note was DEAD, and a repeat import told the
                        // customer a permanent object had been created when none had. Review
                        // measured it end to end; my own runtime test missed it because I fed it
                        // `created=0, reused=1`, a shape this counter could never emit.
                        if (self::$menu_reused_in_run > $reused_before) {
                            $stats['reused']++;
                        } else {
                            $stats['created']++;
                        }
                    } else {
                        // COUNTED, AND THE EMPTY CASE SEPARATELY. `$stats` is only surfaced on
                        // header/footer conversions (ajax_convert:425) — on an ordinary page this
                        // was silent, and `fallback_html` can be '' (the engine emits
                        // `String(fallback.settings?.html || '')`), which leaves an EMPTY widget
                        // where the navigation was. That is the one entry on the #152 list that
                        // loses visible content outright.
                        $fallback_html = isset($payload['fallback_html']) ? (string) $payload['fallback_html'] : '';
                        if (trim($fallback_html) === '') { $navempty++; } else { $navfallback++; }
                        $el['widgetType'] = 'html';
                        $el['settings'] = array(
                            'html' => $fallback_html,
                            '_element_width' => 'auto',
                            // `_css_classes`, NOT `css_classes`. Elementor renders a WIDGET's
                            // custom classes from the underscored key (see the builder's own note);
                            // the engine writes the un-underscored one, so reading it is right and
                            // writing it back threw the classes away on the branch whose whole
                            // claim is "they render". The SVG fallback four lines away already
                            // keeps `_css_classes` correctly.
                            '_css_classes' => isset($el['settings']['css_classes'])
                                ? $el['settings']['css_classes']
                                : (isset($el['settings']['_css_classes']) ? $el['settings']['_css_classes'] : ''),
                        );
                        $stats['fallbacks']++;
                    }
                }
                if (!empty($el['elements']) && is_array($el['elements'])) {
                    $walk($el['elements']);
                }
            }
        };
        $walk($elements);
        self::report_drops(array('navfallback' => $navfallback, 'navempty' => $navempty,
                                 'navitem' => self::$last_nav_items_dropped));
        self::$last_nav_items_dropped = 0;
        $stats['reused'] = (int) self::$menu_reused_in_run;
        return $stats;
    }

    /**
     * Create a WordPress menu from an __hte_menu payload. Menus are WP core —
     * this works (and is tested) on free installs; only the RENDERING widget
     * needs Pro. Returns the menu term id.
     */
    /**
     * label|url|depth of every item, in order — the identity of a navigation.
     */
    private static function menu_payload_signature(array $items) {
        $parts = array();
        $walk = function ($list, $depth) use (&$walk, &$parts) {
            foreach ((array) $list as $it) {
                if (!is_array($it)) { continue; }
                // SIGN WHAT WILL BE STORED, NOT WHAT ARRIVED.
                //
                // The write path puts every item through `sanitize_text_field()` and
                // `esc_url_raw()`, and the comparison reads those stored values back. Signing
                // the RAW payload compared two different representations:
                // `esc_url_raw('about.html')` is `'http://about.html'`, so a static-site nav
                // could never match itself and every conversion minted another menu — review
                // measured 5 conversions of one page leaving 5 permanent menus, and 2 of 12
                // real corpus navigations failing to dedupe. Whitespace in a label and spaces
                // in a `tel:` did the same.
                // A NAV THAT UNLINKS THE CURRENT PAGE IS A DIFFERENT NAVIGATION, and dedupe
                // cannot honestly say otherwise — see #177's disclosure. `/` on the home page
                // and `#` on the about page really are different item lists, so a site using
                // that pattern gets one menu per page. I tried normalising the marker away and
                // it does not converge: the SAME item still signs by its url on every other
                // page. Anything that did converge would have to ignore urls entirely, which
                // would merge two different sites' `Home/About/Contact` navigations into one.
                // Measured exposure is in #177; refining this a fifth time is not the answer.
                $parts[] = $depth
                    . '|' . trim((string) sanitize_text_field(isset($it['label']) ? $it['label'] : ''))
                    . '|' . trim((string) esc_url_raw(isset($it['url']) ? $it['url'] : '#'));
                if (isset($it['children']) && is_array($it['children'])) {
                    $walk($it['children'], $depth + 1);
                }
            }
        };
        $walk($items, 0);
        return empty($parts) ? '' : md5(implode(chr(10), $parts));
    }

    /**
     * The same signature, computed from a menu that already exists in this site.
     */
    private static function existing_menu_signature($term_id) {
        $objects = wp_get_nav_menu_items((int) $term_id, array('post_status' => 'publish'));
        if (!is_array($objects)) { return ''; }
        $byId = array();
        foreach ($objects as $o) { $byId[(int) $o->ID] = $o; }
        $depthOf = function ($o) use ($byId) {
            $d = 0; $p = (int) $o->menu_item_parent;
            while ($p && isset($byId[$p]) && $d < 10) { $d++; $p = (int) $byId[$p]->menu_item_parent; }
            return $d;
        };
        $parts = array();
        foreach ($objects as $o) {
            $parts[] = $depthOf($o) . '|' . trim((string) $o->title) . '|' . trim((string) $o->url);
        }
        return empty($parts) ? '' : md5(implode(chr(10), $parts));
    }

    private static function create_wp_menu_from_payload(array $payload, $title) {
        $base = sanitize_text_field((isset($payload['name']) && $payload['name'] !== '') ? $payload['name'] : 'Imported menu');
        $name = $base . ' — ' . sanitize_text_field($title);
        $items_for_sig = (isset($payload['items']) && is_array($payload['items'])) ? $payload['items'] : array();
        // MATCH BY THE NAVIGATION, NOT BY THE PAGE TITLE.
        //
        // The first version keyed reuse on 'Imported menu — ' . $title, so only converting the
        // SAME page twice deduped. Ten pages of one site share one navigation and ten titles, so
        // they produced ten identical menus — exactly the accumulation this code's own comment
        // named as the thing to prevent, measured by review at 10 conversions -> 10 menus.
        //
        // A navigation IS its items, so the signature is the key. Scan every menu in the site:
        // if one already holds exactly these items in this order and depth, it is the menu this
        // conversion would have built. A different navigation never matches, so nothing is
        // silently merged.
        //
        // WHAT HAPPENS TO A MENU THE CUSTOMER HAS EDITED, measured on a real WordPress rather
        // than assumed: it is ADOPTED. The signature we stored is what we match on, and we do
        // not re-read the items, so renaming, reordering or repurposing an imported menu does
        // not stop a later conversion pointing at it.
        //
        // An earlier version of this comment claimed the opposite — that an edited menu 'stops
        // matching and a new one is created'. That was true of the re-derivation this replaced,
        // and storing the signature made it impossible; review caught the sentence surviving the
        // rewrite. The behaviour is deliberate: this menu is one WE created and marked, so
        // reusing it is reusing our own object rather than littering the customer's site with a
        // duplicate on every conversion. The cost is that a repurposed menu gets wired to a
        // later page whose HTML no longer matches it — disclosed in #177.
        // An EMPTY navigation is not a menu. Without this a payload with no items minted a new
        // empty menu on every call — three calls, three menus, none useful, none ever deleted.
        if (empty($items_for_sig)) {
            return 0;
        }
        // STORE THE SIGNATURE; NEVER RE-DERIVE IT.
        //
        // Deriving it by reading the menu back has now failed three review cycles running, each
        // time on a different representation: the raw-vs-sanitised mismatch (cycle 3), a
        // backslash that `wp_insert_post` consumes on write but `sanitize_text_field` keeps
        // (cycle 4), and any site whose plugins filter `wp_setup_nav_menu_item` — one
        // trailing-slash filter is enough. Every one of those minted a permanent menu on every
        // conversion, forever.
        //
        // The mechanism was wrong, not the tuning, so this changes KIND: the signature we
        // computed is written as term meta when the menu is created, and matched against that.
        // WordPress cannot alter a value it never renders. Reading items back survives only as
        // a fallback for menus made before this shipped.
        $sig = self::menu_payload_signature($items_for_sig);
        // A payload we cannot sign is a payload we cannot dedupe — and 'cannot dedupe' must
        // mean 'do not write', not 'write every time'. Malformed items (strings, ints) skipped
        // every part, produced an empty signature, bypassed the whole match and still created a
        // menu on each call: the exact opposite of the empty-items guard above it.
        if ($sig === '') {
            return 0;
        }
        {
            $all_menus = wp_get_nav_menus();
            if (is_array($all_menus)) {
                foreach ($all_menus as $m) {
                    $stored = get_term_meta((int) $m->term_id, '_htel_menu_sig', true);
                    $match = ($stored !== '' && $stored !== false)
                        ? ($stored === $sig)
                        : (self::existing_menu_signature($m->term_id) === $sig);
                    if ($match) {
                        self::$menu_reused_in_run++;
                        return (int) $m->term_id;
                    }
                }
            }
        }
        $existing = wp_get_nav_menu_object($name);
        if ($existing) {
            // REUSE, DON'T MINT. Converting ten pages of one site used to leave ten menus —
            // 'Imported menu — Home', then nine more with a random 4-character suffix — and
            // nothing in this plugin ever deletes one (#177). Measured on the corpus after the
            // Pro path moved to the default arm: 58 of 210 conversions create a menu, so an
            // agency importing an afternoon's work accumulates them silently.
            //
            // If a menu of this name already holds EXACTLY these items, it is the same menu
            // this conversion would have built: hand it back instead of duplicating it. The
            // signature is label+url+depth in order, so a genuinely different navigation under
            // a colliding name still gets its own menu and nothing is silently merged.
            $name .= ' ' . wp_generate_password(4, false, false);
        }
        $menu_id = wp_create_nav_menu($name);
        if (!is_wp_error($menu_id) && (int) $menu_id > 0) {
            // Written BEFORE the items, so a partial write still carries the identity of what
            // was attempted and a retry recognises it instead of minting a second menu.
            update_term_meta((int) $menu_id, '_htel_menu_sig', $sig);
        }
        if (is_wp_error($menu_id)) {
            return 0;
        }
        $items = (isset($payload['items']) && is_array($payload['items'])) ? $payload['items'] : array();
        $add = function ($item, $parent_db_id) use (&$add, $menu_id) {
            $db_id = wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title'     => sanitize_text_field(isset($item['label']) ? $item['label'] : ''),
                'menu-item-url'       => esc_url_raw(isset($item['url']) ? $item['url'] : '#'),
                'menu-item-type'      => 'custom',
                'menu-item-status'    => 'publish',
                'menu-item-parent-id' => (int) $parent_db_id,
            ));
            if (is_wp_error($db_id)) {
                // COUNTED — AND THE RETURN TAKES THE CHILDREN WITH IT. This closure recurses into
                // `$item['children']` below, so one failed item silently removes its whole subtree.
                // That is correct (a child with no parent is worse than no child) and it was
                // completely silent, which is not.
                self::$last_nav_items_dropped += 1 + self::count_menu_items($item);
                return;
            }
            if (!empty($item['children']) && is_array($item['children'])) {
                foreach ($item['children'] as $child) {
                    $add($child, $db_id);
                }
            }
        };
        foreach ($items as $item) {
            $add($item, 0);
        }
        return (int) $menu_id;
    }

    /**
     * The success note the customer reads after a conversion.
     *
     * EXTRACTED so it can be RUN in a test rather than grepped. Review's ship blocker was a
     * sentence that existed in this file and could never reach a customer: the menu note sat
     * inside the header/footer-only block, while the engine writes a Pro menu on ordinary page
     * conversions too — 58 of 210 corpus pages, every one silent. A file-position assertion
     * would not have caught that, and did not; only running the decision does.
     *
     * @param array $template    the converted template (its `type` decides the site-part copy)
     * @param array $menu_stats  found/created/fallbacks/reused from import_pro_nav_menus()
     * @return string            the note, '' when there is nothing to say
     */
    /**
     * The COMPLETE success note: site-part copy + menu sentence + typography sentence, composed
     * in one callable so a test can drive the whole thing - review cycle 3 found the wiring
     * lines in ajax_convert had no gate that could go red (the exact shape of the menu-note
     * lesson from the previous batch).
     */
    public static function full_success_note(array $template, array $menu_stats) {
        $note = self::build_success_note($template, $menu_stats);
        $typo = self::typography_conflict_note();
        if ('' !== $typo) {
            $note = ('' === $note) ? $typo : ($note . ' ' . $typo);
        }
        return $note;
    }

    /** '' or one sentence when a page kept its own heading styles over an existing preset. */
    /** Which strip fired: 'mismatch' (a differing preset exists) or 'nokit' (no usable kit). */
    private static $typography_strip_reason = '';

    public static function typography_conflict_note() {
        if (empty(self::$typography_mismatched_ids)) {
            return '';
        }
        // TWO truths, two sentences - review cycle 4 caught the kit-less branch borrowing the
        // mismatch copy and telling a broken-kit customer to hunt a preset that does not exist.
        if ('nokit' === self::$typography_strip_reason) {
            return esc_html__('Note: no usable Elementor kit was found, so this page keeps its own heading styling instead of using Site Settings presets.', 'aitoel-html-importer');
        }
        return esc_html__('Note: Site Settings already holds a different style for some heading levels on this page, so the page keeps its own heading styling instead of adopting them.', 'aitoel-html-importer');
    }

    private static function build_success_note(array $template, array $menu_stats) {
        $site_part_note = '';
    $landed_type = isset($template['type']) && in_array($template['type'], array('header', 'footer'), true)
        ? $template['type'] : '';
    $site_part_note = '';
    if ($landed_type !== '') {
        $type_label = ($landed_type === 'header')
            ? esc_html__('Header', 'aitoel-html-importer')
            : esc_html__('Footer', 'aitoel-html-importer');
        if (defined('ELEMENTOR_PRO_VERSION')) {
            /* translators: %s: "Header" or "Footer" */
            $site_part_note = sprintf(esc_html__('Imported as a %s template. Assign it site-wide under Templates → Theme Builder.', 'aitoel-html-importer'), $type_label);
        } else {
            $site_part_note = esc_html__('Imported as a template. The Theme Builder in Elementor Pro can assign it site-wide; without Pro, insert it at the top of your pages.', 'aitoel-html-importer');
        }
        if (((int) $menu_stats['fallbacks'] > 0 || !HTEL_Elementor_Check::is_pro_nav_menu_usable())
            && (int) $menu_stats['created'] === 0 && (int) $menu_stats['reused'] === 0) {
            $site_part_note .= ' ' . esc_html__('Menu note: without Elementor Pro\'s Menu widget, your navigation was imported as regular links — fully working and editable. With Pro active, converting again produces a native menu widget.', 'aitoel-html-importer');
        }
    }

    // A WRITE OUTSIDE THE TEMPLATE IS ALWAYS ANNOUNCED.
    //
    // This sentence used to live INSIDE the block above, which runs only for a header or
    // footer template — i.e. only when the customer had ticked the checkbox. The engine now
    // builds a Pro menu whenever it detects a page header, so on an ordinary page conversion
    // the menu was created and NOTHING said so: measured, 58 of 210 corpus pages on the Pro
    // default arm, every one silent. A menu is a permanent object in the customer's site that
    // this plugin never deletes, so it is announced wherever it is written.
        // Defend the keys: an incomplete stats array raised five `Undefined array key` notices.
        $menu_stats_all = array_merge(
            array('found' => 0, 'created' => 0, 'fallbacks' => 0, 'reused' => 0), $menu_stats);
    $menu_note = '';
    if ((int) $menu_stats_all['created'] > 0) {
        $menu_note = esc_html__('A WordPress menu was created from your navigation (Appearance → Menus) and connected to the Pro menu widget.', 'aitoel-html-importer');
        } elseif ((int) $menu_stats_all['reused'] > 0) {
$menu_note = esc_html__('Your navigation was connected to a WordPress menu that already matched it (Appearance → Menus) — no duplicate was created.', 'aitoel-html-importer');
        } elseif ((int) $menu_stats_all['fallbacks'] > 0) {
            // THE SAME CLASS AS THE CYCLE-2 BLOCKER, ONE BRANCH FURTHER OUT. A Pro conversion
            // whose menu widget had to fall back to plain links said nothing at all on an
            // ordinary page: the only sentence covering it lived in the site-part block. The
            // drop is recorded by `report_drops()`, which writes to error_log, never to a customer.
            $menu_note = esc_html__('Your navigation was imported as regular links rather than a menu widget — fully working and editable.', 'aitoel-html-importer');
        }
    if ($menu_note !== '') {
        $site_part_note = ($site_part_note === '') ? $menu_note : ($site_part_note . ' ' . $menu_note);
    }

        // ICONS KEPT AS MARKUP ARE ANNOUNCED. Exactly the nav-menu class one surface further out:
        // the fallback was real, deliberate and completely silent, so a page could arrive with
        // fifteen raw HTML widgets and the only available conclusion was that we convert badly.
        // The two causes get different sentences because only one is actionable — telling someone
        // to switch on a setting they already have on is worse than saying nothing.
        $svg_note = '';
        if (self::$svg_held > 0) {
            $svg_note = sprintf(
                /* translators: %d: number of icons */
                _n(
                    '%d icon was kept as markup rather than an editable image, because native SVG icon import is switched off on this site. It renders exactly as designed. A site administrator can switch it on; see the plugin FAQ for the one-line filter.',
                    '%d icons were kept as markup rather than editable images, because native SVG icon import is switched off on this site. They render exactly as designed. A site administrator can switch it on; see the plugin FAQ for the one-line filter.',
                    self::$svg_held, 'aitoel-html-importer'),
                self::$svg_held);
        } elseif (self::$svg_write > 0) {
            $svg_note = sprintf(
                /* translators: %d: number of icons */
                _n(
                    '%d icon could not be saved to your Media Library and was kept as markup instead. Icon import is switched on here, so this is a write failure rather than a setting — a security plugin blocking SVG uploads is the usual cause.',
                    '%d icons could not be saved to your Media Library and were kept as markup instead. Icon import is switched on here, so this is a write failure rather than a setting — a security plugin blocking SVG uploads is the usual cause.',
                    self::$svg_write, 'aitoel-html-importer'),
                self::$svg_write);
        }
        if ($svg_note !== '') {
            $site_part_note = ($site_part_note === '') ? $svg_note : ($site_part_note . ' ' . $svg_note);
        }

        return $site_part_note;
    }

    private static function save_elementor_template($template, $title, $fonts, $global_colors = array(), $global_fonts = array(), $global_typography = array()) {
        // Inject the Global Colors palette into the active kit FIRST, so the template's
        // __globals__ references resolve the moment the page renders. This is ATOMIC with
        // the page from the user's perspective (same request). ADDITIVE ONLY — it never removes
        // or modifies anything the kit already holds, ours or the customer's (dedup by _id, and
        // our ids are `hte`-prefixed so they cannot collide with Elementor's or the user's).
        // A dangling ref would render Elementor's DEFAULT color, not the inline value, so
        // this MUST run whenever the template carries our refs. No-op when the array is empty.
        // EACH LIST IS CALLED ON ITS OWN. Both injectors no-op on an empty array, so there is no
        // outer guard — and there must not be one. The previous version wrapped BOTH calls in
        // `if (!empty($global_colors))`, so a page yielding fonts and no colours wrote nothing.
        //
        // That defect survived a round of review because the fix was written as a COMMENT four
        // lines above the guard and the guard itself was never moved, and because the test for it
        // called `inject_global_fonts` through Reflection — below the level where the bug lived.
        // The test now goes through THIS function, which is what the AJAX handler calls.
        $colors_added = self::inject_global_colors($global_colors);
        self::inject_global_fonts($global_fonts);
        // #175: full typography presets - same contract as the palette. The template's
        // __globals__ typography refs render the kit DEFAULT until these land, so the
        // injection is atomic with the page. Additive only, own call, no outer guard
        // (the lesson four lines up applies verbatim).
        self::$typography_mismatched_ids = array();
        self::$typography_strip_reason = '';
        // P4 (review cycle 3): if the kit died between should_globalize() and here, the
        // injector no-ops, nothing records a mismatch, and the refs would DANGLE - rendering
        // the kit default (measured: Roboto 32 over authored Verdana 22). No kit means NO ref
        // may survive: treat every incoming id as mismatched so the strip below removes them
        // all and the page renders exactly as authored - the same fail-safe as a value
        // mismatch. Logged, unlike the colour channel, because silent was the finding.
        if (!empty($global_typography) && is_array($global_typography) && !self::active_kit_id()) {
            foreach ($global_typography as $p9) {
                if (isset($p9['_id']) && is_string($p9['_id'])) { self::$typography_mismatched_ids[] = $p9['_id']; }
            }
            self::$typography_strip_reason = 'nokit';
            error_log('[aitoel] typography presets arrived with no usable kit; refs stripped, page keeps inline styles');
        }
        self::inject_global_typography($global_typography);
        // A ref that survived a value MISMATCH would silently restyle this page with another
        // import's preset (review cycle 2, measured). Strip those refs from the content BEFORE
        // it is written; the widgets keep their inline typography and render as authored.
        if (!empty(self::$typography_mismatched_ids) && isset($template['content']) && is_array($template['content'])) {
            self::strip_mismatched_typography_refs($template['content']);
        }

        // A page can carry hundreds of `__globals__` colour references, and every one of them
        // renders Elementor's DEFAULT colour — not the author's — if the palette never reached the
        // kit. `inject_global_colors()` returns 0 both when there is nothing to do and when there
        // is no active kit, so a page could import with its colours silently replaced. The request
        // side now refuses to ask for globalize without a kit, so this should be unreachable; it
        // stays because "should be" is not a guarantee across a plugin/API version skew.
        // THE CONDITION IS "DID THE PALETTE LAND", not "is the option empty". A stale kit id keeps
        // the option truthy, which is exactly the case where the page comes back recoloured and
        // the log was silent.
        if (!empty($global_colors) && 0 === $colors_added && !self::active_kit_id()) {
            error_log('[aitoel] Global Colors arrived but there is no usable Elementor kit (the '
                . 'active-kit option is empty or names a post that no longer exists); the palette '
                . 'was not added and any global colour references will render Elementor defaults.');
        }

        // Create the library post
        // #144 — INSERT AS A DRAFT, PUBLISH ONLY ONCE THE CONTENT IS WRITTEN.
        //
        // The passes below need this post's id, so they cannot run before the insert. What they
        // CAN be denied is the ability to leave a published, empty template behind: review
        // measured ~14x the payload in peak memory inside this function, which on a 128M host is
        // a fatal, and it fires on exactly the pages the corpus says carry 5,883 KB of font. The
        // customer was left with a template in their library that opens to nothing.
        //
        // A draft plus a shutdown guard closes both halves: nothing is published until
        // `_elementor_data` exists, and a fatal — including memory exhaustion, which DOES run
        // shutdown functions — removes the orphan rather than leaving it.
        $post_id = wp_insert_post(array(
            // Slashed (#319): wp_insert_post() unslashes, and `$title` is already unslashed, so a
            // title "AC\DC" was saved as "ACDC".
            'post_title'  => wp_slash($title),
            'post_status' => 'draft',
            'post_type'   => 'elementor_library',
        ));
        if ($post_id && !is_wp_error($post_id)) {
            // NARROWER THAN THE DOCBLOCK ABOVE IMPLIES: this removes a draft whose
            // `_elementor_data` is EMPTY. A fatal in the window between that meta being written
            // and the publish leaves a draft WITH content, which this will not touch — correctly,
            // since it may be recoverable, but the sentence "removes the orphan on a fatal" was
            // broader than the code.
            //
            // UNMEASURED BY THE SUITE, DELIBERATELY RECORDED AS SUCH. Removing this reddens
            // nothing — a fatal cannot be raised inside a test run without ending it — yet review
            // demonstrated in a subprocess that it changes behaviour: ablated leaves 1 orphan
            // draft on both E_ERROR and real memory exhaustion, pristine leaves 0. A kernel
            // OOM-kill is out of scope; nothing runs then. It is kept because the cost is one
            // callback and the failure it catches is invisible — not because it is covered.
            register_shutdown_function(function () use ($post_id) {
                $e = error_get_last();
                if (!$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                    return;   // a clean exit; the normal path has already published or not
                }
                $post = get_post($post_id);
                if ($post && $post->post_status === 'draft'
                    && $post->post_type === 'elementor_library'
                    && '' === (string) get_post_meta($post_id, '_elementor_data', true)) {
                    wp_delete_post($post_id, true);
                }
            });
        }

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Decompose standalone SVG icons emitted by the converter (svgIcons option): each is a native
        // Image widget carrying a sanitized `__hte_svg` payload. Upload the SVG to the Media Library
        // and wire the Image url/id — or, when SVG hosting is disabled (default) or upload fails,
        // fall back to an inline-SVG HTML widget so it renders exactly like today. See import_svg_icons.
        // #283: this site decides which Heading Title styles move into the page stylesheet (see
        // apply_title_styles). FIRST, while the content is exactly what the engine recorded: the
        // passes below rewrite `data:` URIs anywhere in the content, the stylesheet widget included.
        $template = self::apply_title_styles($template);
        $content = self::import_svg_icons($template['content'], $post_id);
        // Pro nav-menu payloads: create the real WordPress menu(s) and wire the
        // widget, or rewrite to the carried fallback markup when the widget is
        // not genuinely usable here (see is_pro_nav_menu_usable — never render
        // the free tier's upsell stub in a customer's header).
        self::$last_menu_stats = self::import_pro_nav_menus($content, $title);

        // Host any embedded data: URI images (self-contained / "Save Page As" sources) as real
        // files in this site's Media Library, rewriting the data: URIs to their URLs. Without
        // this, a self-contained page imports blank: Elementor's template importer strips data:
        // images and a multi-MB embedded page is too heavy to render. See host_embedded_images.
        $content = self::host_embedded_images($content, $post_id);
        // AND THE FONTS. 65.4% of the carried stylesheet across the corpus is base64
        // @font-face payloads (#77); hosting them is what makes the converted page lighter
        // than the source instead of heavier. Runs AFTER the images so both share the
        // `_htel_source_hash` dedupe.
        $content = self::host_embedded_fonts($content, $post_id);

        // Pull REMOTE images into this site's Media Library. Measured on 60 real customer
        // pages: 21 hotlinked image URLs across 10 pages (17%) — every one of them a picture
        // that dies the day the source host removes it, renames it, or starts blocking
        // hotlinks, on a page the customer believes is finished. Strictly bounded and
        // strictly non-fatal: any failure leaves the original URL exactly as it was.
        // Disable via `add_filter('htel_import_remote_images', '__return_false')`.
        $content = self::import_remote_images($content, $post_id);

        // Set Elementor data — wp_slash is essential because update_post_meta runs wp_unslash
        $elementor_data = wp_json_encode($content);
        // AND IF THAT ENCODE FAILS, DO NOT PUBLISH. Both hosting passes check this exact call and
        // bail; this one wrote `false` into `_elementor_data` and published anyway — the empty
        // published template #144 exists to prevent, reached without any fatal at all. Byte-
        // identical in 1.3.35, so pre-existing rather than a regression, and no less wrong.
        if ($elementor_data === false) {
            // AND IT RETURNS AN ERROR, NOT THE POST ID. The first version of this guard returned
            // `$post_id`, and the caller treats any non-WP_Error as success — so the customer was
            // shown "Template created successfully!" with an edit link to a draft carrying no
            // `_elementor_data` at all. That converted "published empty" into "success message plus
            // empty draft", which is worse: the first is visibly broken, the second is a lie. The
            // draft goes with it, because a template nobody can use is not worth leaving behind.
            $reason = json_last_error_msg();
            error_log('[aitoel] the converted page could not be encoded for Elementor (JSON error: '
                . $reason . '). Nothing was added to your library.');
            wp_delete_post($post_id, true);
            return new WP_Error(
                'encode_failed',
                sprintf(
                    /* translators: %s: the JSON error reported by PHP */
                    __('The converted page could not be encoded for Elementor (%s). Nothing was added to your library.', 'aitoel-html-importer'),
                    $reason
                )
            );
        }
        update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));


        // Site-part landing (header mode, task #73): the API's envelope carries type
        // "header"/"footer" when the customer ticked "I'm converting a header or footer".
        // Landing with the matching template type is what makes Elementor Pro's Theme
        // Builder treat it as an assignable site part. Anything unrecognized (old API
        // responses, raw values) falls back to today's 'page' behavior, byte-identical.
        $tpl_type = isset($template['type']) && in_array($template['type'], array('header', 'footer'), true)
            ? $template['type'] : 'page';

        // Set Elementor meta
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', $tpl_type);
        update_post_meta($post_id, '_elementor_version', '3.21.0');

        if ($tpl_type === 'page') {
            // Use Elementor Canvas template — removes theme header/footer/title
            // so the converted page renders exactly as designed. Site parts are not
            // pages, so they get no page template at all.
            update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');
        }

        // Set page settings if present
        // Slashed, as `_elementor_data` is above: update_post_meta() unslashes, and a Custom CSS sent
        // with `.md\:flex` or `content:"\201C"` was stored without its backslashes (#319).
        if (!empty($template['page_settings']) && is_array($template['page_settings'])) {
            update_post_meta($post_id, '_elementor_page_settings', wp_slash($template['page_settings']));
        }

        // Generate the post CSS FOR REAL. The previous code hand-wrote an _elementor_css meta
        // with status:'' purely to carry the detected Google Fonts — but a present-yet-empty
        // meta tells Elementor CSS state already exists, so it never builds the file, and the
        // imported template renders with NO settings-derived styling (padding/colors/typography)
        // until a manual Regenerate CSS. Observed empirically on Elementor 4.2.2 (V4 scope,
        // 2026-08-09; elementor#35934) and the standing suspect for historic "messed-up import"
        // reports on newer Elementor.
        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            $post_css = \Elementor\Core\Files\CSS\Post::create($post_id);
            $post_css->update();
        }

        // Merge the converter-detected fonts INTO the generated meta rather than clobbering it:
        // Elementor only discovers fonts declared through typography controls, while ours come
        // from the source page's <link> tags — without this merge, Google Fonts stop loading.
        $generated_meta = get_post_meta($post_id, '_elementor_css', true);
        if (!is_array($generated_meta)) {
            // Elementor unavailable (guarded above) — keep the legacy shape so fonts still load.
            $generated_meta = array(
                'time'                 => time(),
                'icons'                => array(),
                'dynamic_elements_ids' => array(),
                'status'               => '',
            );
        }
        $generated_fonts = isset($generated_meta['fonts']) && is_array($generated_meta['fonts'])
            ? $generated_meta['fonts'] : array();
        $generated_meta['fonts'] = array_values(array_unique(array_merge($generated_fonts, array_values($fonts))));
        // Slashed (#319): this is the meta Elementor just wrote, read back, with the page's CSS in it
        // when the site prints CSS inline; written raw, WordPress stripped the backslashes it holds.
        update_post_meta($post_id, '_elementor_css', wp_slash($generated_meta));

        // Set template type taxonomy (matches the meta above — header/footer/page)
        wp_set_object_terms($post_id, $tpl_type, 'elementor_library_type');

        // #144 — PUBLISH LAST, once this is a usable Elementor document and not merely a post
        // with content. The first version published as soon as `_elementor_data` existed, four
        // statements before `_elementor_edit_mode`; review measured that on Elementor 4.2.2 a
        // template in that window reports `is_built_with_elementor() === false` and opens as a
        // bare Container. A fatal there left exactly the artefact this change exists to prevent,
        // and the shutdown guard could not remove it because it was no longer a draft.
        wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));

        return $post_id;
    }


    /**
     * Download remotely-hosted images referenced by the template into the Media Library and
     * repoint the template at the local copies.
     *
     * Bounded on purpose — an import must never hang or fail because someone else's image host
     * is slow: at most HTEL_REMOTE_IMAGE_MAX files, a per-file download timeout, and a total
     * wall-clock budget. Anything not fetched inside those bounds keeps its original URL, which
     * is exactly the behaviour customers have today. Images already served by this site are
     * skipped, as are non-image URLs.
     *
     * @param array $content  Elementor content tree.
     * @param int   $post_id  Post the images are attached to.
     * @return array Content, rewritten where a download succeeded.
     */
    /**
     * The absolute URLs held in the image fields the engine writes: an Image widget's `image`, a
     * container's `background_image` and `background_overlay_image`, and a video's poster
     * `image_overlay`. Links and video URLs are not among them.
     */
    private static function image_field_urls($elements) {
        $urls = array();
        foreach ((array) $elements as $el) {
            foreach (array('image', 'background_image', 'background_overlay_image', 'image_overlay') as $key) {
                $url = isset($el['settings'][$key]['url']) ? $el['settings'][$key]['url'] : null;
                if (is_string($url) && preg_match('#^https?://#i', $url)) {
                    $urls[] = $url;
                }
            }
            if (isset($el['elements'])) {
                $urls = array_merge($urls, self::image_field_urls($el['elements']));
            }
        }
        return $urls;
    }

    /**
     * Each URL replaced where it stands whole in the text. It ends where a URL ends in CSS or
     * markup — a quote, a bracket, a comma, white space, an escaped quote (`&quot;`) — and not where
     * a URL goes on: a path, a query, an `&amp;`. Nor is it replaced inside another URL.
     *
     * @param string $text
     * @param array  $urls from => to
     * @return string
     */
    private static function replace_whole_urls($text, $urls) {
        $url_char = 'A-Za-z0-9._\~:\/?#\[\]@!$*+=%\x80-\xFF-';
        foreach ($urls as $from => $to) {
            if (strpos($text, $from) === false) {
                continue;
            }
            $text = preg_replace_callback(
                '~(?<![' . $url_char . '])' . preg_quote($from, '~')
                    . '(?![' . $url_char . ']|&(?!quot;|apos;|#0*39;|#x0*27;|lt;|gt;))~',
                function () use ($to) {
                    return $to;
                },
                $text
            );
        }
        return $text;
    }

    private static function import_remote_images($content, $post_id) {
        if (!apply_filters('htel_import_remote_images', true, $post_id)) {
            return $content;
        }
        $json = wp_json_encode($content);
        if ($json === false || stripos($json, 'http') === false) {
            return $content;
        }

        $max_files    = (int) apply_filters('htel_remote_image_max', 40, $post_id);
        $file_timeout = (int) apply_filters('htel_remote_image_timeout', 10, $post_id);
        $budget       = (int) apply_filters('htel_remote_image_budget', 25, $post_id);
        if ($max_files < 1 || $budget < 1) {
            return $content;
        }

        // Every absolute image URL in the template, de-duplicated, source order preserved.
        // wp_json_encode escapes forward slashes, so the template literally holds
        // "https:\/\/host/pic.jpg". Scan a slash-unescaped COPY — a regex that excludes the
        // backslash (it must, or it swallows the rest of the JSON) would otherwise match none
        // of the real URLs. The rewrite map below is built with wp_json_encode, so it targets
        // the escaped form that is actually present.
        $scan = str_replace('\/', '/', $json);
        preg_match_all('#https?://[^"\s\x5C]+?\.(?:jpe?g|png|gif|webp|avif|svg)(?:\?[^"\s\x5C]*)?#i', $scan, $m);
        // An image field holds an image whatever its URL looks like (image CDNs such as Unsplash serve
        // without an extension), so its URL is fetched too; a file found only that way is named from its bytes.
        $by_extension = array();
        foreach ($m[0] as $url) {
            $by_extension[html_entity_decode($url, ENT_QUOTES)] = true;
        }
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $urls = array();
        foreach (array_merge($m[0], self::image_field_urls($content)) as $url) {
            $url = html_entity_decode($url, ENT_QUOTES);
            if (isset($urls[$url])) {
                continue;
            }
            $host = wp_parse_url($url, PHP_URL_HOST);
            if (!$host || ($home_host && strcasecmp($host, $home_host) === 0)) {
                continue; // already ours
            }
            $urls[$url] = true;
        }
        $urls = array_keys($urls);
        if (empty($urls)) {
            return $content;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $started = microtime(true);
        $map     = array();
        $whole   = array();
        $failed  = 0;
        $count   = 0;

        $capped = 0;
        // WRAPPED, LIKE ITS TWO SIBLINGS. Review measured this: a `\Throwable` from anything
        // hooked on `add_attachment` — a security or media plugin, which is exactly the shape
        // the #144 test uses — propagated out of this loop leaving every file already
        // sideloaded in the customer's Media Library referenced by nothing. 1 orphan per throw
        // here against 0 on the image and font passes. I closed this defect on the decode
        // branch, marked #151 fixed, and left the throw branch open: the fourth cycle running
        // where the class turned out to be one exit-path wider than I had looked.
        // AND THE LIST CANNOT BE THE ONLY RECORD, because `media_handle_sideload` CREATES the
        // attachment and throws inside the same call — so on the one path this guard exists for,
        // `$sideloaded[]` has not been appended yet. My first version recorded the file after the
        // call returned, wrapped the loop, and still left the orphan; the test said so. Snapshot
        // what this post already owned and treat anything new as ours. Same question as always:
        // at the moment this fires, does the value it reads exist yet?
        $known = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit',
            'posts_per_page' => -1, 'fields' => 'ids', 'post_parent' => $post_id));
        $known = is_array($known) ? array_flip($known) : array();
        try {
        foreach ($urls as $url) {
            if ($count >= $max_files || (microtime(true) - $started) > $budget) {
                // COUNTED. This break silently abandoned every remaining image on a page over the
                // 40-file cap or the 25-second budget, and the customer was told nothing at all —
                // the same class the two passes above it spent three review cycles closing, thirty
                // lines to the left and never looked at.
                $capped = count($urls) - $count;
                break;
            }
            $count++;
            $by_bytes = !isset($by_extension[$url]);
            $tmp = download_url($url, $file_timeout);
            if (is_wp_error($tmp)) {
                $failed++;
                continue;
            }
            $name = wp_basename(wp_parse_url($url, PHP_URL_PATH));
            if ($name === '' || strpos($name, '.') === false) {
                $name = 'image-' . $count . '.jpg';
            }
            if ($by_bytes) {
                // Refused here when the bytes are not an image, even for a user WordPress would let
                // upload a file of any type (unfiltered_upload).
                $ext = self::image_extension_from_signature((string) file_get_contents($tmp, false, null, 0, 64));
                if ($ext === '') {
                    wp_delete_file($tmp);
                    $failed++;
                    continue;
                }
                $base = wp_basename(wp_parse_url($url, PHP_URL_PATH));
                $name = ($base !== '' ? sanitize_file_name($base) : 'image-' . $count) . '.' . $ext;
            }
            $file_array = array('name' => $name, 'tmp_name' => $tmp);
            $attachment_id = media_handle_sideload($file_array, $post_id, null);
            if (is_wp_error($attachment_id)) {
                // wp_delete_file is the WP-blessed unlink wrapper (Plugin Check).
                wp_delete_file($tmp);
                $failed++;
                continue;
            }
            // NO FILE LIST HERE ANY MORE. Both exits sweep by attachment snapshot, so this array
            // was written and never read — and it cost a `get_attached_file()` per image to stay
            // that way. Removed rather than left as furniture.
            $wp_url = wp_get_attachment_url($attachment_id);
            if (!$wp_url) {
                $failed++;
                continue;
            }
            if ($by_bytes) {
                // Repointed wherever it stands whole — the field, and the page's own CSS carried
                // beside it (`url(…)` in the styles block) — but never where it begins or sits
                // inside a longer URL: the same photo at another size, a gallery page.
                $whole[$url] = $wp_url;
                continue;
            }
            // Rewrite the JSON-encoded form of the URL, which is what actually appears in
            // $json — a raw string replace would miss any escaped solidus.
            $map[trim(wp_json_encode($url), '"')] = trim(wp_json_encode($wp_url), '"');
        }

        self::$last_remote_images = array(
            'found'    => count($urls),
            'imported' => count($map) + count($whole),
            'failed'   => $failed,
        );

        } catch (\Throwable $e) {
            // NO `unwind_uploads` CALL HERE, AND ITS ABSENCE IS THE POINT. I wrote one, and
            // ablating it reddened NOTHING: every entry in `$sideloaded` comes from
            // `get_attached_file()`, so each one already HAS an attachment, and the snapshot
            // sweep below deletes every attachment this call created along with its file.
            // Two guards on one path make each other unmeasurable — the masked-guard shape,
            // and I had just spent a cycle being caught by it. The snapshot is strictly the
            // wider of the two: it also catches the orphan `media_handle_sideload` creates
            // and throws over, which no list can have recorded yet.
            self::sweep_new_attachments($post_id, $known);
            throw $e;   // swallowing it would be worse than the orphans; the caller decides
        }

        self::report_drops(array('remotefail' => $failed, 'remotecap' => $capped));

        if (empty($map) && empty($whole)) {
            return $content;
        }
        $decoded = json_decode(strtr($json, $map), true);
        if (!is_array($decoded)) {
            // AND THE THIRD PASS GETS THE SAME UNWIND THE OTHER TWO GOT. This branch returned the
            // original content while every sideloaded file stayed in the customer's Media Library,
            // referenced by nothing — the exact defect the image and font passes fixed, sitting
            // unfixed next door because I enumerated two functions and called it the class.
            // THE SAME SWEEP THE THROW PATH USES, NOT A NARROWER ONE. `unwind_uploads` skips any
            // attachment whose `get_attached_file()` came back falsy, while the line below says
            // "No files were left behind" without qualification. Two exits from one function
            // deserve one mechanism, and the wider of the two is the honest choice.
            // ONE MECHANISM FOR BOTH EXITS. There was an `unwind_uploads` call here as well and
            // ablating it reddened NOTHING — the sweep below already covered it. That is the
            // masked-guard shape for the second time in two cycles, in my own fix for the
            // first one. Removed; the sweep is the single measured defence on both paths.
            self::sweep_new_attachments($post_id, $known);
            self::report_drops(array('reverted_remote' => 1));
            return $content;
        }
        array_walk_recursive($decoded, function (&$value) use ($whole) {
            if (is_string($value)) {
                $value = self::replace_whole_urls($value, $whole);
            }
        });
        return $decoded;
    }

    /** What the last import did with Heading Title styles (#283); read by the tests and the render gate. */
    private static $last_title_styles = array('titles' => 0, 'moved' => 0, 'rules' => 0, 'reason' => '');

    /**
     * #283 — HEADING TITLE STYLES ARE DECIDED ON THIS SITE, AT IMPORT (George, 2026-09-18).
     *
     * The engine writes a styled word inside a Heading as `<span style="…">`, and a customer who
     * opens the Heading reads that CSS in the Title field. Whether a declaration can leave the Title
     * without changing the page depends on the WordPress that prints it, so the engine ships the
     * template unchanged plus a record (`htel_title_styles`, see src/builder/title-styles.ts) and this
     * site decides. A group of declarations moves into one rule in the page stylesheet only when this
     * site's own `wp_kses_post` hands every member back unchanged, and the class that carries them on
     * that tag. Such a declaration renders today,
     * and renders the same from the rule, which outranks every selector in the page's own stylesheet
     * as the inline style did (the engine ranks it above the most ids any of them carries, and never
     * below five). A declaration the site drops stays inline, exactly as the engine wrote it. Both
     * hold whether this Elementor prints the Title through `wp_kses_post` or unfiltered, so no
     * version is consulted.
     *
     * FAIL CLOSED: the template is imported exactly as the engine sent it when the record is missing
     * or malformed, when the stylesheet it names is not there, and when the importing user cannot
     * save unfiltered HTML (when that user next saves the page, Elementor strips the `<style>` tags
     * from the stylesheet widget — measured on both rigs — and the moved declarations would go with
     * it). A Title the record does not match byte for byte, or whose entry is malformed, is left
     * exactly as sent. The record itself is never stored.
     */
    private static function apply_title_styles($template) {
        self::$last_title_styles = array('titles' => 0, 'moved' => 0, 'rules' => 0, 'reason' => '');
        $rec = isset($template['htel_title_styles']) ? $template['htel_title_styles'] : null;
        unset($template['htel_title_styles']);
        $leave = function ($reason) use ($template) {
            self::$last_title_styles['reason'] = $reason;
            return $template;
        };
        if (!is_array($rec)) {
            return $leave('no-record');
        }
        if (!isset($rec['v']) || $rec['v'] !== 1 || empty($rec['titles']) || !is_array($rec['titles'])
            || !isset($rec['rank']) || !is_int($rec['rank']) || $rec['rank'] < 5 || $rec['rank'] > 64
            || !isset($rec['sheet']['kind']) || !isset($template['content']) || !is_array($template['content'])) {
            return $leave('malformed-record');
        }
        if (!current_user_can('unfiltered_html')) {
            return $leave('no-unfiltered-html');
        }
        // The stylesheet the record names must be there, as the engine left it.
        if ($rec['sheet']['kind'] === 'custom_css') {
            if (!isset($template['page_settings']['custom_css']) || !is_string($template['page_settings']['custom_css'])) {
                return $leave('no-sheet');
            }
        } elseif ($rec['sheet']['kind'] === 'html') {
            $sheet_widget = isset($rec['sheet']['widget']) ? $rec['sheet']['widget'] : '';
            if (!self::title_styles_has_sheet($template['content'], $sheet_widget)) {
                return $leave('no-sheet');
            }
        } else {
            return $leave('malformed-record');
        }

        $by_widget = array();
        foreach ($rec['titles'] as $entry) {
            if (isset($entry['widget']) && is_string($entry['widget']) && isset($entry['parts']) && is_array($entry['parts'])) {
                $by_widget[$entry['widget']] = $entry;
            }
        }
        $rules = array();
        $template['content'] = self::title_styles_walk($template['content'], $by_widget, $rec['rank'], $rules);
        // A Title changes only together with the rule that carries its styles: no rule, no change.
        if (empty($rules)) {
            return $leave('nothing-moved');
        }
        $appended = "\n" . implode('', $rules);
        if ($rec['sheet']['kind'] === 'custom_css') {
            $template['page_settings']['custom_css'] .= $appended;
        } else {
            $template['content'] = self::title_styles_append_html($template['content'], $sheet_widget, $appended);
        }
        self::$last_title_styles['rules'] = count($rules);
        self::$last_title_styles['reason'] = 'moved';
        return $template;
    }

    /** Every Heading the record names gets its rewritten Title; everything else is returned as it was. */
    private static function title_styles_walk($elements, $by_widget, $rank, &$rules) {
        foreach ($elements as $i => $el) {
            if (isset($el['id'], $el['widgetType'], $el['settings']['title']) && $el['widgetType'] === 'heading'
                && is_string($el['id']) && isset($by_widget[$el['id']])) {
                $elements[$i]['settings']['title'] = self::title_styles_rewrite($el['settings']['title'], $by_widget[$el['id']], $rank, $rules);
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $elements[$i]['elements'] = self::title_styles_walk($el['elements'], $by_widget, $rank, $rules);
            }
        }
        return $elements;
    }

    /**
     * One Title rewritten, or returned as it was when the record's text and tags are not this Title
     * exactly or any tag entry is malformed (then nothing in it moves). Rules are added once each:
     * tags with the same style value share a class and, on one site, a rule.
     */
    private static function title_styles_rewrite($title, $entry, $rank, &$rules) {
        $original = '';
        foreach ($entry['parts'] as $part) {
            if (is_string($part)) {
                $original .= $part;
            } elseif (isset($part['tag']) && is_string($part['tag'])) {
                $original .= $part['tag'];
            } else {
                return $title;
            }
        }
        if ($original !== $title) {
            return $title;
        }
        $out = '';
        $mine = array();
        $moved = 0;
        foreach ($entry['parts'] as $part) {
            if (is_string($part)) {
                $out .= $part;
                continue;
            }
            $tag = self::title_styles_tag($part, $rank, $mine, $moved);
            if ($tag === null) {
                return $title;
            }
            $out .= $tag;
        }
        foreach ($mine as $rule) {
            if (!in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }
        if ($out !== $title) {
            self::$last_title_styles['titles']++;
            self::$last_title_styles['moved'] += $moved;
        }
        return $out;
    }

    /**
     * One recorded tag: its groups, each moving only when this site keeps every member. Returns the
     * tag to write, or null when the entry is malformed or a moved declaration carries a character
     * that could end its rule (the whole Title is then left as it was).
     */
    private static function title_styles_tag($part, $rank, &$rules, &$moved_count) {
        foreach (array('tag', 'name', 'cls', 'head', 'tail', 'bare') as $key) {
            if (!isset($part[$key]) || !is_string($part[$key])) {
                return null;
            }
        }
        if (!isset($part['pieces']) || !is_array($part['pieces'])
            || !preg_match('/^hte-t[a-z0-9]{8}$/', $part['cls']) || !preg_match('/^[a-z][a-z0-9-]*$/', $part['name'])) {
            return null;
        }
        // The class carries the moved declarations: on a site whose filter strips it from this tag, a
        // Title printed through `wp_kses_post` would lose them (review cycle 1), so nothing moves.
        if (!self::site_keeps_class($part['name'], $part['cls'])) {
            return $part['tag'];
        }
        $groups = array();
        foreach ($part['pieces'] as $p) {
            if (!isset($p['t']) || !is_string($p['t'])) {
                return null;
            }
            if (array_key_exists('d', $p)) {
                if (!is_string($p['d']) || !isset($p['g']) || !is_int($p['g'])) {
                    return null;
                }
                $keeps = self::site_keeps_declaration($part['name'], $p['d']);
                $groups[$p['g']] = (isset($groups[$p['g']]) ? $groups[$p['g']] : true) && $keeps;
            }
        }
        $moved = array();
        $rest = array();
        foreach ($part['pieces'] as $p) {
            if (array_key_exists('d', $p) && !empty($groups[$p['g']])) {
                $moved[] = $p['d'];
            } else {
                $rest[] = $p['t'];
            }
        }
        if (empty($moved)) {
            return $part['tag'];
        }
        $body = implode(';', $moved);
        // The engine records none of these (its candidate values carry no brace or angle bracket);
        // a site whose filter lets everything through (`safecss_filter_attr_allow_css`) keeps them,
        // and the rule must still not end, or end the stylesheet, early.
        if (preg_match('/[{}<>]/', $body)) {
            return null;
        }
        $rest = implode(';', $rest);
        $rules[] = '.' . $part['cls'] . str_repeat(':not(#hte-i)', $rank) . '{' . $body . '}';
        $moved_count += count($moved);
        return preg_match('/^[ ;]*$/', $rest) ? $part['bare'] : $part['head'] . $rest . $part['tail'];
    }

    /**
     * Whether THIS site's own `wp_kses_post` (with every filter it runs) keeps the declaration exactly
     * as written: a one-declaration tag comes back byte for byte.
     */
    private static function site_keeps_declaration($name, $decl) {
        $probe = '<' . $name . ' style="' . $decl . '">x</' . $name . '>';
        return wp_kses_post($probe) === $probe;
    }

    /** Whether this site's `wp_kses_post` keeps a class attribute, and this class, on the tag. */
    private static function site_keeps_class($name, $cls) {
        $probe = '<' . $name . ' class="' . $cls . '">x</' . $name . '>';
        return wp_kses_post($probe) === $probe;
    }

    /** The stylesheet widget the record names: the HTML widget with that id, its html ending in `</style>`. */
    private static function title_styles_is_sheet($el, $id) {
        return isset($el['id'], $el['widgetType'], $el['settings']['html']) && $el['id'] === $id
            && $el['widgetType'] === 'html' && is_string($el['settings']['html'])
            && substr($el['settings']['html'], -8) === '</style>';
    }

    /** Whether the stylesheet widget the record names is anywhere in these elements. */
    private static function title_styles_has_sheet($elements, $id) {
        foreach ($elements as $el) {
            if (self::title_styles_is_sheet($el, $id)) {
                return true;
            }
            if (!empty($el['elements']) && is_array($el['elements']) && self::title_styles_has_sheet($el['elements'], $id)) {
                return true;
            }
        }
        return false;
    }

    /** The rules, appended inside the stylesheet widget just before its closing `</style>`. */
    private static function title_styles_append_html($elements, $id, $appended) {
        foreach ($elements as $i => $el) {
            if (self::title_styles_is_sheet($el, $id)) {
                $elements[$i]['settings']['html'] = substr($el['settings']['html'], 0, -8) . $appended . '</style>';
                return $elements;
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $elements[$i]['elements'] = self::title_styles_append_html($el['elements'], $id, $appended);
            }
        }
        return $elements;
    }

    /**
     * Replace embedded data: URI images in the Elementor content with real files in this site's
     * Media Library.
     *
     * Why: a "Save Page As" / self-contained source embeds every image as a base64 data: URI.
     * Elementor's UI template importer STRIPS data: images (KSES sanitisation) and a multi-MB
     * embedded page is too heavy to render (PHP memory) — so such a page imports blank. Uploading
     * each embedded image to the customer's OWN Media Library and rewriting the data: URI to its
     * URL makes the page portable (survives re-export / manual import), lightweight, and gives the
     * customer manageable media. Deduplicates repeated images.
     *
     * Fails open: on any decode/upload error the original data: URI is left in place (the plugin
     * applies _elementor_data directly, which renders data: fine — only the import path strips it).
     * Disable via `add_filter('htel_host_embedded_images', '__return_false')`.
     *
     * @param array $content  Elementor content array ($template['content'])
     * @param int   $post_id  Parent post — uploaded images are attached to it
     * @return array          Content with data: URIs swapped for Media Library URLs
     */
    private static function host_embedded_images($content, $post_id) {
        // JSON_UNESCAPED_SLASHES so base64 `/` chars stay raw (else the regex misses `\/`).
        $json = wp_json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strpos($json, 'data:image/') === false) {
            return $content; // nothing embedded (the fast path) OR wp_json_encode refused the document, which a 600-deep structure does. Nothing has been written at this point, so there is nothing to unwind and nothing to report — but the comment used to name only the first cause
        }
        if (!apply_filters('htel_host_embedded_images', true, $post_id)) {
            return $content; // opt-out
        }
        if (!function_exists('wp_upload_bits')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $ext_map = array('jpeg' => 'jpg', 'svg+xml' => 'svg', 'x-icon' => 'ico', 'vnd.microsoft.icon' => 'ico');
        $map = array();
        $counter = 0;
        $img_uploaded = array();
        $unregistered = 0;
        $tooheavy = 0;
        $notimage = 0;
        $svgempty = 0;
        $cutshort = 0;
        $toosmall = 0;
        $writefail = 0;
        $writewhy = '';
        // Thumbnail generation dominates import time on shared hosting: every registered size
        // (core + WooCommerce + theme can be 10+) is a GD re-encode PER IMAGE — an image-heavy
        // page took minutes. The template references the full-size URL, so intermediate sizes
        // only serve the Media Library UI; keep the core trio and skip the rest DURING our
        // import only. Sites that want every size can opt out of the cap via this filter.
        $limit_sizes = function ($sizes) {
            $keep = array_intersect_key($sizes, array_flip(array('thumbnail', 'medium', 'large')));
            return apply_filters('htel_import_image_sizes', $keep, $sizes);
        };
        // MY DOCBLOCK SAID THIS UNWIND RUNS ON "every exit that throws away the rewritten
        // content", ON BOTH PATHS. It was false: this loop had no try/catch/finally at all, so a
        // throw here left the file, the attachment AND `intermediate_image_sizes_advanced` still
        // registered for the rest of the request. Review injected an `add_attachment` listener and
        // measured 1 orphan file, 1 orphan attachment, filter still on — while the font path in
        // the same run left 0/0. Not a regression (1.3.34 has no unwind anywhere); a claim that
        // outran the code.
        add_filter('intermediate_image_sizes_advanced', $limit_sizes, 99);
        try {
        // #143 — THE SAME TRUNCATION HOLE, IN DEPLOYED CODE. Without the terminator this match
        // ends at the first character outside the base64 alphabet and the PREFIX is uploaded,
        // while `str_replace` leaves the remainder glued onto the new URL. Review reproduced it
        // in real WordPress: a 970-byte image written as 300 bytes, `url()` becoming
        // `…/htel-12425-1.png WlpaWlpa…`. Worse here than on the font path, because the floor is
        // 16 bytes rather than 64 and nothing downstream checks a signature at all.
        // #144 — BEFORE THE MATCH SET, NOT AFTER. `preg_match_all` with PREG_SET_ORDER copies
        // every match, and on a document this size that is the largest single allocation in the
        // pass. Checking after it is checking after the fatal.
        if (!self::pass_fits_this_host(strlen($json))) {
            self::report_drops(array('skipmem' => 1), array('skipmem' => 'Encoded size '
                . round(strlen($json) / 1048576, 1) . ' MB against PHP memory_limit '
                . ini_get('memory_limit') . '.'));
            return $content;   // the `finally` below removes the size filter
        }
        $img_pattern = '#data:image/([a-zA-Z0-9.+\-]+);base64,' . self::b64_payload() . '#';
        $img_found = preg_match_all($img_pattern, $json, $matches, PREG_SET_ORDER);
        if (false === $img_found) {
            self::report_drops(array('skipregex' => 1),
                array('skipregex' => 'Engine code ' . preg_last_error() . '.'));
            $img_found = 0;
        }
        if ($img_found) {
            foreach ($matches as $m) {
                $full = $m[0];
                if (isset($map[$full])) {
                    continue; // dedupe: the same image is often reused across sections
                }
                $mime  = strtolower($m[1]);
                // #144 — ask the host before decoding, not after. The image pass runs FIRST and
                // its JSON copy is still alive when the font pass runs, so an image that only
                // just fits leaves nothing for the fonts.
                if (self::too_big_for_this_host((int) (strlen($m[2]) * 3 / 4))) {
                    // COUNTED AND LOGGED BELOW. The first version refused in silence, on the one
                    // host class where a data:-heavy page is already recorded as OOMing at first
                    // CSS generation and rendering blank — so "the page renders" was not something
                    // this branch was entitled to assume, let alone leave unsaid.
                    $tooheavy++;
                    continue;
                }
                $bytes = base64_decode($m[2], true);
                if ($bytes === false || strlen($bytes) < 16) {
                    $toosmall++;
                    continue; // invalid/tiny — leave as data:
                }
                // THE SECOND DEFENCE. If the regex ever mistakes a cut payload for a whole one
                // again — and it has, three times — these bytes are still not written anywhere.
                if (self::looks_truncated($bytes)) {
                    // THE BRANCH THE CORPUS ACTUALLY HITS, and it was silent while I was
                    // congratulating myself for instrumenting one with no corpus hits at all.
                    // #143 records SIX cut payloads across the corpus and every one of them
                    // leaves through here. Reporting the rare shape and not the common one is
                    // the instance, not the class.
                    $cutshort++;
                    continue; // half an image; keep the data: URI, which at least renders
                }
                // Cross-conversion dedupe: re-converting the same page (an iterate-and-retry
                // workflow is normal) must not re-upload identical files and multiply the
                // Media Library. Attachments we created carry a content hash; reuse on match.
                // THE SIGNATURE FIRST, because everything below depends on it: the sanitiser
                // needs to know the payload is an SVG, and the dedupe hash has to be taken on
                // the bytes that will actually be written.
                // #146 — THE BYTES DECIDE WHERE THEY CAN, AND NOWHERE ELSE DOES THE BUILD NARROW.
                //
                // A recognised signature names the file, so a payload declared `image/jpeg` whose
                // bytes are a PNG is written `.png`. A payload PROVABLY prose is refused, which is
                // the defect this exists for. Anything else — an encoder none of us has heard of,
                // a BigTIFF, an SVG behind five thousand spaces — falls through to exactly what
                // the deployed build does. Three review cycles were spent finding formats a
                // whitelist refused; this shape cannot lose one.
                $ext = self::image_extension_from_signature($bytes);
                if ($ext === '') {
                    if (self::looks_like_text_not_image($bytes)) {
                        $notimage++;
                        continue;   // prose, a stray URL — never an image, whatever the mime said
                    }
                    // Unrecognised but binary: keep 1.3.35's behaviour rather than guess.
                    $ext = isset($ext_map[$mime]) ? $ext_map[$mime] : preg_replace('/[^a-z0-9]/', '', $mime);
                    if ($ext === '') {
                        $notimage++;
                        continue;
                    }
                }
                // A RECOGNISED SIGNATURE CAN STILL NAME A TYPE THIS HOST WILL NOT TAKE, and then
                // insisting on it is a narrowing of its own: review found `<svg>` bytes declared
                // `image/png` hosted by the deployed build (as an unrenderable `.png`, but hosted)
                // and refused here, because `.svg` is not allowed on a stock WordPress. If the
                // signature's extension is not permitted and the declared one is, take the
                // declared one — this build never hosts less than the last.
                if (!wp_check_filetype('probe.' . $ext)['type']) {
                    $fallback = isset($ext_map[$mime]) ? $ext_map[$mime] : preg_replace('/[^a-z0-9]/', '', $mime);
                    if ($fallback !== '' && wp_check_filetype('probe.' . $fallback)['type']) {
                        $ext = $fallback;
                    }
                }
                // SANITISE BEFORE HASHING, OR THE DEDUPE CAN NEVER MATCH. The lookup below
                // hashes `$bytes`; the SVG sanitiser used to run a hundred lines further down
                // and recompute the hash, so what was STORED was the sanitised bytes and what
                // was LOOKED UP was the raw ones. An SVG re-converted five times produced five
                // attachments. Pre-existing in 1.3.35 — and this change routes far more payloads
                // down that branch, so it stops being a curiosity and starts filling Media
                // Libraries. The fix is the ORDER, not the arithmetic.
                // SVG IS EXECUTABLE, AND THIS PATH WAS WRITING IT VERBATIM. On any site whose
                // `upload_mimes` allows SVG — a security or media plugin commonly adds it —
                // review got `<script>alert(document.cookie)</script>` and an `onload=` written
                // into the Media Library and served from the customer's own origin. Pre-existing
                // and byte-identical in 1.3.34 (#148), which makes it not a regression and does
                // not make it acceptable in the function this change ships.
                //
                // Sanitised rather than refused, because refusing would stop hosting SVGs the
                // deployed build hosts — the exact regression shape two review cycles failed on.
                // `sanitize_svg_payload` is the geometry allowlist `import_svg_icons` uses,
                // verified on 742 corpus icons and 13 attack vectors.
                // THE HASH IS TAKEN AFTER SANITISING, OR THE DEDUPE CAN NEVER MATCH. The lookup
                // used `sha1($rawBytes)` while the stored `_htel_source_hash` was the sanitised
                // bytes, so an SVG re-converted five times produced five attachments. Pre-existing
                // in 1.3.35 — and this change routes far MORE payloads down that branch, so it
                // stops being a curiosity and starts filling Media Libraries.
                if ($ext === 'svg') {
                    $clean = self::sanitize_svg_payload($bytes);
                    if ($clean === '' || $clean === null) {
                        // COUNTED, NOT SWALLOWED. This was the one discarding branch on this
                        // path with no counter and no log, while `$notimage` beside it has
                        // both — and this batch's own stated lesson was that the defect is
                        // the silence. Review named it the most likely production failure:
                        // on an SVG-enabled site a picture 1.3.35 hosted disappears from the
                        // page with nothing anywhere saying why, and Elementor's UI import
                        // strips `data:` URIs, so on that route it is gone rather than inline.
                        $svgempty++;
                        continue;   // nothing survived the allowlist; keep the data: URI
                    }
                    $bytes = $clean;
                }
                $hash = sha1($bytes);
                $existing = get_posts(array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'meta_key'       => '_htel_source_hash',
                    'meta_value'     => $hash,
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                ));
                if (!empty($existing)) {
                    $prior_file = get_attached_file($existing[0]);
                    $prior_url  = wp_get_attachment_url($existing[0]);
                    if ($prior_url && $prior_file && file_exists($prior_file)) {
                        $map[$full] = $prior_url;
                        continue;
                    }
                }
                $counter++;
                $upload = wp_upload_bits('htel-' . $post_id . '-' . $counter . '.' . $ext, null, $bytes);
                if (!empty($upload['error']) || empty($upload['url']) || empty($upload['file'])) {
                    // AND THIS IS THE EXIT A PERFECTLY VALID SVG TAKES ON A STOCK WORDPRESS,
                    // which is why the message carries the host's own reason instead of a
                    // guess: `.svg` is not an allowed upload type there, so `wp_upload_bits`
                    // refuses a file whose bytes were never the problem.
                    $writefail++;
                    if ($writewhy === '' && !empty($upload['error'])) {
                        $writewhy = (string) $upload['error'];
                    }
                    continue; // upload failed — keep data: (still renders via direct apply)
                }
                $img_uploaded[] = $upload['file'];
                // Register a real Media Library attachment so the customer can see/manage it.
                $attach_id = wp_insert_attachment(array(
                    'guid'           => $upload['url'],
                    // FROM THE SIGNATURE, like the filename. Deriving the name from the bytes and
                    // the mime from the declaration left a `.png` recorded as `image/jpeg`.
                    // `image/ico` is not a mime WordPress knows; 1.3.35 recorded `image/x-icon`
                    // and this recorded a type absent from `wp_get_mime_types()`.
                    'post_mime_type' => 'image/' . ('jpg' === $ext ? 'jpeg'
                        : ('svg' === $ext ? 'svg+xml' : ('ico' === $ext ? 'x-icon' : $ext))),
                    'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                    'post_status'    => 'inherit',
                ), $upload['file'], $post_id);
                // #147 — `wp_insert_attachment` RETURNS 0 ON FAILURE, NOT A WP_Error.
                //
                // The ledger said the consequence was a lost content hash and a re-upload on every
                // re-conversion. Measured, that overstates it: `update_metadata()` rejects an
                // object id of 0 before any hook runs, so the row was never written with or
                // without this check, and when the insert fails there is no attachment to dedupe
                // against anyway.
                //
                // I THEN WROTE THAT THIS CHECK HAS "NO OTHER OBSERVABLE EFFECT", AND THAT WAS
                // FALSE. Ablating the `> 0` alone reddens ONE assertion — not the two I then
                // claimed, which was a second wrong number in the correction to the first —
                // because this condition is what ROUTES a failed insert to the counter and
                // therefore to the log. It is load-bearing for the only part of #147 that changed
                // behaviour anyone can see.
                //
                // WHAT WAS ACTUALLY WRONG HERE IS THE SILENCE. The file is on disk and the page
                // points at it, but nothing appears in the Media Library, so the customer cannot
                // find or manage it and nothing says why. That is now counted and logged HERE.
                //
                // THE PARITY NOW EXISTS, AND THIS COMMENT WENT ON DENYING IT. It used to say the
                // font path's `else` for this same failure was an empty block "saying nothing" —
                // true when written, and false from the moment `$fontunreg++` went into that exact
                // `else`, in this same batch. Its own closing line was "asserting a parity that
                // does not exist is how a reader stops checking"; denying one that does is the same
                // fault pointing the other way, and it survived because I re-read the comment
                // instead of the code beside it.
                if (!is_wp_error($attach_id) && $attach_id > 0) {
                    if (function_exists('wp_generate_attachment_metadata')) {
                        // Non-fatal: thumbnails are a nicety; the URL works regardless.
                        @wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));
                    }
                    update_post_meta($attach_id, '_htel_source_hash', $hash);
                } else {
                    $unregistered++;
                }
                $map[$full] = $upload['url'];
            }
        }
        } catch (\Throwable $e) {
            self::unwind_uploads($post_id, $img_uploaded);
            throw $e;
        } finally {
            remove_filter('intermediate_image_sizes_advanced', $limit_sizes, 99);
        }

        // THE LOGS COME FIRST. They sat below the early return, so the one moment a customer most
        // needs the explanation — nothing was hosted at all — was the one moment this function
        // said nothing. Three assertions caught it; the code read perfectly.
        // REPORTED THROUGH THE SHARED TABLE, not five bespoke blocks that the font pass had no
        // way to share. `$writewhy` is the host's own words for the FIRST write failure and is
        // marked as such rather than presented as the reason for all of them.
        self::report_drops(
            array('notimage' => $notimage, 'cutshort' => $cutshort, 'toosmall' => $toosmall,
                  'writefail' => $writefail, 'svgempty' => $svgempty),
            array('writefail' => ($writewhy === '' ? '' : 'The first one was refused with: '
                      . $writewhy . ' Later ones may have failed for other reasons.'),
                  'svgempty' => (wp_check_filetype('probe.svg')['type']
                      ? 'They carry no drawable geometry we allow.'
                      : 'This site does not allow SVG uploads either, so they would have been left '
                        . 'in the page regardless.')));
        if ($tooheavy > 0) {
            self::report_drops(array('imgheavy' => $tooheavy),
                array('imgheavy' => 'PHP memory_limit is ' . ini_get('memory_limit') . '.'));
        }
        if ($unregistered > 0) {
            self::report_drops(array('imgunreg' => $unregistered));
        }
        // Swap every embedded data: URI for its hosted URL, then decode back to the array.
        if (empty($map)) {
            return $content;
        }
        $rewritten = strtr($json, $map);
        $decoded = json_decode($rewritten, true);
        if (!is_array($decoded)) {
            // The sibling of the font path's exit, enumerated rather than left for the next
            // review cycle to find. The caller gets the original content, so what was written is
            // referenced by nothing.
            self::unwind_uploads($post_id, $img_uploaded);
            // AND SAY SO. Everything hosted on this page is deleted here and the caller gets
            // its `data:` URIs back — a run indistinguishable, from the outside, from a page
            // that had nothing to host. The unwind was right; the silence was the same class
            // as every other drop on these two passes.
            self::report_drops(array('reverted' => 1));
            return $content;
        }
        return $decoded;
    }

    /**
     * Swap base64 `@font-face` payloads for hosted font files. #77.
     *
     * WHY THIS IS THE #77 FIX. A prospect declined to buy with the clearest statement of the
     * problem anyone has written: "the inline styling for each page created by AI tools is a
     * burden on site speed. Therefore using your plugin solves nothing." He was right, and until
     * `PHL/_css77-census.js` measured it nobody knew what the burden was made of. Across the whole
     * 210-page corpus the carried stylesheet is 28,935 KB, and **65.4% of it is base64 fonts
     * inside `@font-face`** — not the hover rules and author breakpoints the backlog predicted,
     * which are 0.5% between them. It is concentrated: 13 of 210 pages, but on an affected page
     * the fonts are 97-99% of the carrier, up to 5,883 KB on one. Those bytes sit in the post
     * content, so they are re-sent on every page view, cannot be cached separately, and are stored
     * in the customer's database.
     *
     * THE THING THAT WOULD HAVE MADE THIS A SILENT NO-OP. WordPress does not allow font uploads:
     * `wp_upload_bits()` runs `wp_check_filetype()` and woff/woff2/ttf/otf are not in
     * `get_allowed_mime_types()`. Measured in `PHL/_font-upload-probe.php` before a line of this
     * was written — and the image path next door RETURNS THE data: URI UNCHANGED when an upload
     * fails, so copying it would have shipped a feature that changed nothing and looked like it
     * worked. The `upload_mimes` filter below is what makes the upload possible, and it is removed again
     * immediately, including when something throws: a plugin that permanently teaches a site to
     * accept font uploads has made a security change nobody asked for.
     *
     * THE MIME IS NOT TRUSTED. Fonts are commonly served as `application/octet-stream`, which is
     * not font-specific at all, so the extension comes from the payload SIGNATURE — `wOF2`,
     * `wOFF`, `OTTO`, `\x00\x01\x00\x00`, `true` — and anything unrecognised is left alone.
     *
     * Disable via `add_filter('htel_host_embedded_fonts', '__return_false')`.
     *
     * @param array $content  Elementor content array
     * @param int   $post_id  Parent post — uploaded fonts are attached to it
     * @return array          Content with font data: URIs swapped for Media Library URLs
     */
    private static function host_embedded_fonts($content, $post_id) {
        $json = wp_json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strpos($json, ';base64,') === false) {
            return $content; // nothing embedded at all — fast path
        }
        if (!apply_filters('htel_host_embedded_fonts', true, $post_id)) {
            return $content;
        }
        if (!function_exists('wp_upload_bits')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // The mimes a font is plausibly encoded under. `octet-stream` is in the list precisely
        // because it says nothing; the signature check below is what decides.
        // THE MATCH MUST END AT A TERMINATOR, not just at the first character outside the base64
        // alphabet. Without the lookahead, a payload containing a stray space, newline or
        // base64url character was truncated at that point: the prefix decoded to 90 bytes of a
        // 364-byte font, that junk was uploaded, and the `url()` became a real URL with 400+
        // characters of leftover base64 glued to it — so on that input the `data:` URI did not
        // even survive, it turned into garbage.
        //
        // THE FIRST VERSION OF THIS COMMENT CLAIMED THE NEWLINE CASE WAS COVERED. IT WAS NOT.
        // The lookahead accepted any backslash, and a newline reaches this string as the escape
        // `\n`, so the wrapped-stylesheet shape — the one named first, and the realistic one —
        // was still hosted truncated. See `b64_payload()` for what the guard is now and why.
        // The test that guarded this used a SPACE, which was already safe, so it was green
        // throughout and its ablation went red for the wrong reason.
        $pattern = '#data:(font/[a-z0-9.+-]+|application/(?:x-)?font-[a-z0-9.+-]+'
            // `application/vnd.ms-fontobject` IS IN THIS LIST DELIBERATELY. I removed it once, on
            // the reasoning that a real EOT has no signature entry so matching it is a guaranteed
            // no-op. That was wrong: this function does not trust the mime — that is its design —
            // so the alternation also carries payloads whose LABEL is EOT and whose BYTES are a
            // woff2. Review built one and the deployed build wrote an 864-byte file. A genuine
            // EOT is still refused by the signature check, at no cost.
            . '|application/octet-stream|application/vnd\.ms-fontobject);base64,'
            . self::b64_payload() . '#i';
        // #144 — the same gate as the image path, before the same allocation.
        if (!self::pass_fits_this_host(strlen($json))) {
            self::report_drops(array('skipmem_font' => 1), array('skipmem_font' => 'Encoded size '
                . round(strlen($json) / 1048576, 1) . ' MB against PHP memory_limit '
                . ini_get('memory_limit') . '.'));
            return $content;
        }
        $found = preg_match_all($pattern, $json, $matches, PREG_SET_ORDER);
        // `preg_match_all` RETURNS FALSE, not 0, when it gives up, and a return of false here
        // means every font on the page silently stays inline — indistinguishable from a page
        // that had no fonts at all unless it is logged.
        //
        // THIS BRANCH IS NOW DEFENSIVE, AND THE HISTORY MATTERS. It was added because a
        // 1.6-million character run defeated PCRE's default `backtrack_limit` of 1,000,000.
        // The possessive quantifiers in `b64_payload()` then removed the backtracking that
        // caused it: review re-measured the same 1.6M run at 5.1 ms with `preg_last_error() = 0`.
        // So there is no input known to reach this branch today, it has NO TEST, and ablating it
        // reddens nothing. It is kept because the cost is one comparison and the failure it
        // guards against is silent — not because it is measured. Do not read it as covered.
        if (false === $found) {
            self::report_drops(array('skipregex_font' => 1),
                array('skipregex_font' => 'Engine code ' . preg_last_error() . '.'));
            return $content;
        }
        if (!$found) {
            return $content;
        }

        // #145 — ON MULTISITE, THE NETWORK ADMIN'S POLICY WINS.
        //
        // Core registers `check_upload_mimes` at priority 10 (ms-default-filters.php), and so did
        // this closure, so for the duration of the call we re-added fonts over whatever
        // `upload_filetypes` the network had been configured to allow. That is a plugin quietly
        // overriding a deliberate administrative restriction, on a surface where the restriction
        // is usually the point.
        //
        // Now the widening is INTERSECTED with the network's own list on multisite: a font type
        // the network already permits is added, one it does not is left out and its payload stays
        // inline. Single-site installs are unaffected — there is no network policy to respect.
        $allow_mimes = function ($mimes) {
            $permitted = self::network_permitted_types(
                is_multisite() ? get_site_option('upload_filetypes', 'jpg jpeg png gif') : null
            );
            return self::widen_font_mimes($mimes, $permitted);
        };
        // NO `wp_check_filetype_and_ext` FILTER. There was one and it was DEAD CODE:
        // `wp_upload_bits()` calls `wp_check_filetype()`, never `wp_check_filetype_and_ext()`.
        // Review proved it twice — removing it reddened NOTHING (19/19), and running each filter
        // alone showed `upload_mimes` succeeds on its own while the other on its own is refused.
        // It widened filetype detection for the duration of the window and bought exactly nothing,
        // which is the worst trade a security-adjacent filter can make.

        $map = array();
        $counter = 0;
        $fontsmall = 0;
        $fontsig = 0;
        $fontunreg = 0;
        $refused = 0;
        $oversize = 0;
        $tooheavy = 0;
        $uploaded_files = array();
        // THE WINDOW IS THE WHOLE LOOP, NOT ONE CALL, and the docblock used to imply otherwise.
        // Registering per upload would be tidier to describe but would add and remove a filter
        // once per payload; what actually matters is that it is removed in the `finally` below,
        // including on a throw, and that `wp_insert_attachment` firing `add_attachment` inside
        // this window means a listener could observe the widened set. Said plainly rather than
        // described as narrower than it is.
        add_filter('upload_mimes', $allow_mimes);
        try {
            foreach ($matches as $m) {
                $full = $m[0];
                if (isset($map[$full])) {
                    continue; // the same face is referenced by several @font-face blocks
                }
                // A SIZE CEILING. This pass holds a second full JSON copy of the template plus
                // the decoded payload, on top of the image pass's own copy, and it runs INSIDE
                // `save_elementor_template` AFTER the library post has been inserted — so an OOM
                // here leaves a published, empty template and a 500. Review measured +18 MB peak
                // on the corpus's worst carrier and +107 MB on a 20 MB payload, uncapped. A font
                // larger than this is not a webfont anyone should be serving inline.
                // UNMEASURED, DELIBERATELY. Ablating this reddens nothing, because a fixture
                // for it would carry an 8 MB payload into every run of the suite to prove one
                // comparison. It guards #144 (the pass peaks at ~14x the payload, AFTER the
                // library post is inserted), so it stays — but do not read it as covered.
                if (strlen($m[2]) > (8 * 1024 * 1024)) {
                    $oversize++;
                    continue;
                }
                // AND WHAT THIS PARTICULAR HOST CAN SURVIVE (#144). The ceiling above is a fixed
                // backstop; this one asks the machine. A payload that fits on a 512M host and
                // would kill a 128M one is refused only on the 128M one.
                if (self::too_big_for_this_host((int) (strlen($m[2]) * 3 / 4))) {
                    // A THIRD COUNTER, because this is a THIRD cause. It was folded into
                    // `$oversize` and reported as "exceeded the encoded-size ceiling", which is
                    // the exact defect the comment fifteen lines above claims to have fixed by
                    // splitting the first two apart. A log line that names the wrong cause sends
                    // whoever reads it to the wrong place.
                    $tooheavy++;
                    continue;
                }
                $bytes = base64_decode($m[2], true);
                if ($bytes === false || strlen($bytes) < 64) {
                    $fontsmall++;
                    continue;
                }
                $ext = self::font_extension_from_signature($bytes);
                if ($ext === '') {
                    // A TRUNCATED FONT LEAVES THROUGH HERE, and it left in silence while the
                    // image pass twenty lines away reported the same thing. #143 is the
                    // truncation class; reporting it on one half and not the other is how the
                    // last three cycles' worth of instance-fixes kept passing for class-fixes.
                    $fontsig++;
                    continue; // not a font, whatever the mime claimed
                }

                // Cross-conversion dedupe, the same mechanism the image path uses: re-converting
                // the same page must not fill the Media Library with identical faces.
                $hash = sha1($bytes);
                $existing = get_posts(array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'meta_key'       => '_htel_source_hash',
                    'meta_value'     => $hash,
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                ));
                if (!empty($existing)) {
                    $prior_file = get_attached_file($existing[0]);
                    $prior_url  = wp_get_attachment_url($existing[0]);
                    if ($prior_url && $prior_file && file_exists($prior_file)) {
                        $map[$full] = $prior_url;
                        continue;
                    }
                }

                $counter++;
                $upload = wp_upload_bits('htel-font-' . $post_id . '-' . $counter . '.' . $ext, null, $bytes);
                if (!empty($upload['error']) || empty($upload['url']) || empty($upload['file'])) {
                    // FAIL CLOSED AND LOUDLY. The page must still work, so the data: URI stays —
                    // but a silent failure here is exactly what this whole function exists to
                    // avoid, so it is counted and reported rather than swallowed.
                    $refused++;
                    continue;
                }
                // RECORD IT HERE, NOT AFTER `wp_insert_attachment`. The first version of the
                // unwind appended the attachment ID once the insert returned — and the throw
                // review injected happens INSIDE that insert, on the `add_attachment` hook, so
                // the id never came back and the list stayed empty. Ablating that unwind reddened
                // nothing, which is how I found out. The file path is known before the risk.
                $uploaded_files[] = $upload['file'];
                $mime = 'woff2' === $ext ? 'font/woff2'
                    : ('woff' === $ext ? 'font/woff' : ('ttf' === $ext ? 'font/ttf' : 'font/otf'));
                $attach_id = wp_insert_attachment(array(
                    'guid'           => $upload['url'],
                    'post_mime_type' => $mime,
                    'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                    'post_status'    => 'inherit',
                ), $upload['file'], $post_id);
                // NO wp_generate_attachment_metadata: it is an image function, a font has no
                // intermediate sizes, and calling it here buys nothing but a chance to fail.
                if (!is_wp_error($attach_id) && $attach_id > 0) {
                    update_post_meta($attach_id, '_htel_source_hash', $hash);
                } else {
                    // `wp_insert_attachment` returns 0 on failure, not a WP_Error, so the hash
                    // would never be stored and every re-conversion would re-upload the same
                    // face. The file is on disk and the URL works, so the page is fine; what is
                    // lost is the dedupe — and this block SAID that was worth saying rather than
                    // swallowing, while being empty. Review quoted the sentence back at me.
                    $fontunreg++;
                }
                $map[$full] = $upload['url'];
            }
        } catch (\Throwable $e) {   // \Throwable, not \Exception: a PHP Error is neither
            // A THROW MID-LOOP USED TO LEAVE THE FILES AND THE ATTACHMENT POSTS BEHIND, with
            // nothing referencing them: the content is discarded by the caller, so the customer
            // got junk in their Media Library from a conversion that never completed. Review
            // measured one orphan file and one orphan post. Unwind what this call created, then
            // let the exception continue — swallowing it would be worse than the orphans.
            self::unwind_uploads($post_id, $uploaded_files);
            throw $e;
        } finally {
            // ALWAYS, including on a throw. See the docblock.
            remove_filter('upload_mimes', $allow_mimes);
        }

        // TWO COUNTERS, BECAUSE THERE ARE TWO CAUSES. These were one number blaming "upload
        // permissions and any security plugin", which is wrong for a payload we refused ourselves
        // for being over the size ceiling — and a log line that names the wrong cause sends
        // whoever reads it to the wrong place.
        // ROUTED AT LAST. `fontrefused` went into the shared table in the previous batch and its
        // caller never landed — the edit that was meant to replace this block missed its anchor and
        // I did not notice, so the table carried a reason nothing could emit. A key with no caller
        // is the mirror image of a caller with no key, and the emitter now reports the second while
        // only a scan of the table finds the first.
        self::report_drops(
            array('fontrefused' => $refused, 'fontsmall' => $fontsmall, 'fontsig' => $fontsig,
                  'fontunreg' => $fontunreg),
            array('fontrefused' => (is_multisite()
                ? 'On a network, the first thing to check is Network Admin > Settings > Upload File '
                  . 'Types: this plugin deliberately does not widen it, so a font type missing from '
                  . 'that list is never written.'
                : 'Check upload permissions and any security plugin restricting file types.')));
        if ($tooheavy > 0) {
            self::report_drops(array('fontheavy' => $tooheavy),
                array('fontheavy' => 'PHP memory_limit is ' . ini_get('memory_limit') . '.'));
        }
        if ($oversize > 0) {
            // THE CEILING COUNTS BASE64 CHARACTERS, NOT DECODED BYTES, so it bites at about
            // 6 MB of actual font. Review logged a 6.05 MB font being refused by an "8 MB"
            // message. Measuring the encoded length is the right call — it is what the memory
            // cost is proportional to — but the message has to say so.
            self::report_drops(array('fontoversize' => $oversize));
        }
        if (empty($map)) {
            return $content;
        }
        $rewritten = strtr($json, $map);
        $decoded = json_decode($rewritten, true);
        if (!is_array($decoded)) {
            // THE SAME CLASS AS THE THROW PATH, and it was not covered. The caller gets the
            // ORIGINAL content back — still carrying its data: URIs — so every file written above
            // is referenced by nothing: junk in the customer's Media Library from work that was
            // discarded. Review forced this exit with an `upload_dir` filter and measured a
            // 106 KB font and one attachment left behind.
            self::unwind_uploads($post_id, $uploaded_files);
            // AND SAY SO. Everything hosted on this page is deleted here and the caller gets
            // its `data:` URIs back — a run indistinguishable, from the outside, from a page
            // that had nothing to host. The unwind was right; the silence was the same class
            // as every other drop on these two passes.
            self::report_drops(array('reverted_font' => 1));
            return $content;
        }
        return $decoded;
    }

    /**
     * The file extension a font's own bytes say it is, or '' if the bytes are not a font.
     *
     * The mime on a data: URI is not evidence — `application/octet-stream` is the common encoding
     * for a webfont and means nothing. These four signatures are the whole of what WordPress will
     * store once the filters above are in place.
     */
    private static function font_extension_from_signature($bytes) {
        $head = substr($bytes, 0, 4);
        // THESE TWO WERE THE EXACT DEFECT REVIEW FOUND ON THE OTHER BRANCH, LEFT IN PLACE.
        // Cycle 1 was told `true` is both a legal sfnt tag and an English word, and I gated the
        // sfnt branch on its table directory — and walked past the two branches beside it, which
        // still returned on four ASCII characters alone. Cycle 2 uploaded
        // "wOF2 story: this is a README, not a font at all." into the Media Library as an
        // 849-byte `font/woff2`. Same finding, second location: I fixed the instance I was
        // handed instead of the class it named.
        //
        // WOFF and WOFF2 both carry the TOTAL FILE LENGTH as a big-endian uint32 at offset 8
        // (W3C WOFF 1.0 §3, WOFF2 §4). Requiring it to equal the payload we actually hold
        // rejects prose, and — the reason it is the right check rather than merely a check —
        // rejects a TRUNCATED font, which is what the truncation defect above produces.
        if ('wOF2' === $head || 'wOFF' === $head) {
            if (!self::looks_like_woff($bytes)) {
                return '';
            }
            return 'wOF2' === $head ? 'woff2' : 'woff';
        }

        // THE AMBIGUOUS ONES NEED THE STRUCTURE, NOT JUST THE MAGIC. `true` is a legal sfnt
        // version tag AND an ordinary English word: review uploaded
        // "true story: this is a README, not a font" and a JSON document beginning `true,[...]`
        // into the Media Library as `font/ttf`, with two controls isolating the cause to exactly
        // those four bytes. `OTTO` and the 00 01 00 00 tag are far less likely to collide but get
        // the same treatment, for one reason: a four-byte prefix is not evidence, and this
        // function is the only thing standing between `application/octet-stream` and a customer's
        // Media Library.
        //
        // So read the sfnt TABLE DIRECTORY. `numTables` at offset 4 must be sane, and the
        // directory it declares (12-byte header + 16 bytes per table) must fit inside the
        // payload. Prose cannot satisfy that; a real font always does.
        if ('OTTO' === $head || "\x00\x01\x00\x00" === $head || 'true' === $head || 'ttcf' === $head) {
            if (!self::looks_like_sfnt($bytes)) {
                return '';
            }
            // A TrueType COLLECTION is not a .ttf. WordPress has no mime for it and Elementor
            // cannot use one, so it is refused rather than mis-extensioned.
            if ('ttcf' === $head) { return ''; }
            return 'OTTO' === $head ? 'otf' : 'ttf';
        }
        return '';
    }

    /**
     * Does this payload carry a plausible sfnt table directory?
     *
     * Offsets 4-5 are `numTables`. A real font has at least one and never thousands, and its
     * directory occupies 12 + 16*numTables bytes, which must fit in the file. Four ASCII
     * characters cannot pass this; every genuine font does.
     */
    /**
     * The base64 payload, plus the guard that says it is WHOLE.
     *
     * TWO EARLIER VERSIONS ASKED THE WRONG QUESTION, IN OPPOSITE DIRECTIONS, AND BOTH SHIPPED
     * PAST A GREEN SUITE.
     *
     * v1 asked "is the next character one of `)`, `'`, `\"`, or a backslash?". Too permissive: a
     * backslash is also how `wp_json_encode` writes a newline INSIDE the payload, so a stylesheet
     * wrapped at 76 columns was hosted truncated — 300 bytes written for an 864-byte font.
     *
     * v2 narrowed it to "a backslash only before a quote". Too narrow, and worse than v1: review
     * measured it refusing **18 whole payloads to prevent 1 real truncation**, taking three corpus
     * pages from fully hosted to zero. Payloads legitimately end at a space before an `alt=`, at
     * `&gt;`, at `:`, and at `\\"` when markup was JSON-encoded twice.
     *
     * Both asked WHICH CHARACTER FOLLOWS. The question that separates the cases is WHETHER THE
     * PAYLOAD CONTINUES:
     *
     *     ...AAA
AAAA      base64 resumes after the break            a WRAP     refuse
     *     ...AAA\\"         a quote follows                           an END     host
     *     ...AAA alt="x"    an attribute follows                      an END     host
     *
     * So the guard is a NEGATIVE lookahead: refuse only when one break token is followed by a
     * further run of base64 long enough to be a continuation. Everything else is an end, which is
     * what the deployed plugin has always done and what customers already depend on.
     *
     * THE ATOMIC QUANTIFIERS ARE LOAD-BEARING, not tidiness. With an ordinary `+` the engine
     * backtracks to a SHORTER run that satisfies the lookahead and uploads an even smaller
     * fragment: measured at 399 characters on a payload that should not match at all. Possessive
     * matching makes the whole match fail at that position instead, which is the only correct
     * outcome.
     *
     * ONE definition, used by both `data:` matchers, because three review cycles were each spent
     * on this guard being right in one of the two places.
     *
     * A BREAK CAN BE MORE THAN ONE TOKEN, which is what cycle 4 caught: matching a single token
     * meant a newline followed by one space of INDENTATION walked straight past, and 8 of 12
     * break shapes still wrote a truncated file. Hence the `+`.
     *
     * WHY 16. It is the whole discriminator, so it should not read as a magic number. It sits in
     * the gap between two populations:
     *   - a wrapped payload's CONTINUATION. Encoders wrap at 64 (PEM) or 76 (`base64 -w 76`, and
     *     everything MIME-derived) columns, and the check fires at the FIRST break, where the
     *     rest of the payload still follows. So a real continuation is essentially never under 64.
     *   - whatever ordinarily FOLLOWS a finished payload in markup: an attribute name terminated
     *     by `=` (`alt` 3, `srcset` 6, `loading` 7, `referrerpolicy` 14), an entity, a tag.
     * 16 sits between them with ~4x margin below the wrap width and two characters above the
     * longest attribute name. Wrong too low, a payload followed by a space and a 16+ character
     * unpunctuated word is left inline — FAIL-SAFE, the page still renders. Wrong too high, a
     * payload wrapped at under 16 columns is hosted truncated — UNSAFE — which is why the number
     * sits nearer the attribute end of the gap. Review found both boundary cases synthetically
     * and neither occurs in 210 corpus pages.
     *
     * The number stops being load-bearing once a path can judge the BYTES: a truncated font
     * already fails `looks_like_woff` / `looks_like_sfnt`, and a truncated image now fails
     * `looks_truncated`, whatever this pattern decided.
     */
    private static function b64_payload() {
        return '([A-Za-z0-9+/]++={0,2}+)'
            . '(?!(?:\\\\[bfnrt]|\\\\u00[01][0-9a-fA-F]|\\\\\\\\|\xc2\xa0|[ \t_-])+[A-Za-z0-9+/_-]{16})';
    }

    /**
     * Are these bytes PROVABLY an incomplete file?
     *
     * Four review cycles were spent making one regex draw the line between "the payload ended"
     * and "the payload was cut", and it was wrong in three different directions before this: no
     * guard, then too permissive, then too narrow, then blind to a two-character break. Each time
     * the regex was the ONLY thing standing between a truncated file and the customer's Media
     * Library, so each miss shipped a broken image and a `url()` with leftover base64 glued to it.
     *
     * The font path has not had that problem since the signature checks went in, because a
     * truncated font fails `looks_like_woff` (the header declares a length) or `looks_like_sfnt`
     * (a table points past the end) regardless of what the regex thought. This gives the image
     * path the same second defence, and demotes the regex from a guarantee to an optimisation.
     *
     * IT IS A TRUNCATION TEST, NOT A VALIDITY TEST, and the difference is not academic: review
     * WAS MOTIVATED BY A CORPUS MEASUREMENT THAT DOES NOT REPRODUCE — see the note further
     * down before quoting any number from it.
     *
     * A payload can also be neither truncated nor an image — 19 bytes of a mangled URL, say. Those
     * bytes are not cut short; they were never a picture. Judging that is `image_extension_from_
     * signature()` and `looks_like_text_not_image()`, not this function's.
     *
     * NOT JUDGED AT ALL, and named so nobody reads silence as coverage: TIFF, AVIF, HEIC (all in
     * WordPress's default allowed types) and SVG. GIF is judged on a bounded tail rather than
     * exactly; sfnt truncations that land inside a font's trailing padding are accepted, because
     * the format has no length field to contradict them.
     *
     * DELIBERATELY A POSITIVE TEST FOR TRUNCATION, NOT A WHITELIST OF GOOD FILES. It returns true
     * only for a format we recognise whose own trailer or length field says bytes are missing.
     * Anything unrecognised passes exactly as it does today, so this cannot refuse an image the
     * deployed build hosts — which is the regression review measured on the last attempt, and the
     * reason this is shaped the way it is.
     */
    /**
     * Delete the files and attachments THIS call wrote, when its work is being discarded.
     *
     * ONE definition, used by every exit that throws away the rewritten content EXCEPT the
     * remote pass's two, which call `sweep_new_attachments()` instead: `media_handle_sideload`
     * creates the attachment and can throw inside the same call, so no file list can have
     * recorded it yet. Two mechanisms, named here because the previous version of this sentence
     * claimed one and was wrong from the moment the snapshot went in — the same overreach this
     * docblock was already corrected for once. Review found the
     * throw path covered and the json_decode-failure path not — the same class of exit, the same
     * orphans, and a fix that had been applied to one of two places. That is the third time in
     * this file's history; a shared function is the only shape that cannot drift.
     *
     * Matching on the attachment's own file path, not on a filename prefix, means an earlier
     * successful conversion of the same post is never touched.
     */
    /**
     * Delete every attachment this post gained since `$known` was taken, file included.
     *
     * ONE DEFINITION, USED BY BOTH OF THE REMOTE PASS'S EXITS. I wrote the snapshot on the throw
     * path, then wrote it again on the decode path, and left an `unwind_uploads` call beside each —
     * which made the `unwind_uploads` call unmeasurable on both, twice in two cycles. Ablation said
     * so both times (0 red) and I only noticed the second one because the sweep is in the repo now.
     *
     * The snapshot is strictly wider than the file list: `media_handle_sideload` CREATES the
     * attachment and can throw inside the same call, so on the one path the guard exists for, no
     * list can have recorded it yet.
     */
    /**
     * Walk a ZIP's entries and decide what each one is. No WordPress, by design.
     *
     * Returns the page's bytes, name and directory; the image and stylesheet entries; and the two
     * counts the customer is told about — paths refused for climbing out of the archive, and
     * further pages ignored because we import one per ZIP.
     */
    private static function scan_zip_entries($zip) {
        $zipunsafe = 0;
        $ziphtml = 0;
            // Find the HTML file and collect image + CSS files
            $html_content = '';
            $html_filename = '';
            $html_dir = ''; // Directory prefix of the HTML file inside the ZIP
            $image_entries = array();
            $css_entries = array();
            $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif');

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                $basename = basename($entry);
                $entry_ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

                // Skip macOS resource forks and hidden files.
                // DELIBERATELY NOT COUNTED, and this line is the justification review asked for:
                // `__MACOSX` and dot-files are archive metadata, not content anybody put in the
                // page. Reporting them would train customers to ignore this log, which costs more
                // than the nothing it would tell them.
                if (strpos($entry, '__MACOSX') !== false || strpos($basename, '.') === 0) {
                    continue;
                }

                // Block path traversal attacks (e.g., ../../../wp-config.php).
                // ANCHORED TO SEGMENTS, NOT ANY '..' SUBSTRING. `strpos($entry, '..')` also matches
                // `assets/photo..final.jpg` and `v1.2..3/logo.svg` — ordinary filenames. Silently
                // dropping those was bad; TELLING the customer their file "tries to escape the archive"
                // is worse, and this batch exists to make the reporting true.
                if (self::is_unsafe_zip_path($entry)) {
                    // COUNTED. A refusal on principle is still a file the customer put in the archive
                    // and did not get back, and this one is worth them knowing about: it is also what a
                    // badly-built zip looks like.
                    $zipunsafe++;
                    continue;
                }

                // Find the first HTML file
                // CAPTURE ON CONTENT, NOT ON FILENAME. A zero-byte first `.html` used to claim the
                // slot, so a real second page was counted as "ignored", never read, and the customer
                // was told "No .html or .htm file found" — false, and the page was right there.
                // `false` IS NOT CONTENT. Testing `!== ''` fixed the zero-byte-first-page bug and
                // introduced a narrower one: an UNREADABLE first entry makes `getFromIndex` return
                // FALSE, and `false !== ''` is true — so the slot stayed unclaimed, every later page
                // was counted as ignored, and the customer got BOTH "no HTML file found" (there were
                // two) AND "we imported the first and ignored the rest" (we imported nothing). Two
                // false lines from one loose comparison. One test, used by both branches.
                $have_page = (is_string($html_content) && $html_content !== '');
                if ($have_page && ($entry_ext === 'html' || $entry_ext === 'htm')) {
                    // We import ONE page per ZIP, first found. Every other page in the archive is
                    // dropped on the floor, and until now nothing said so.
                    $ziphtml++;
                }
                if (!$have_page && ($entry_ext === 'html' || $entry_ext === 'htm')) {
                    $html_content = $zip->getFromIndex($i);
                    $html_filename = $basename;
                    $html_dir = dirname($entry);
                    if ($html_dir === '.') {
                        $html_dir = '';
                    }
                }

                // Collect image files
                if (in_array($entry_ext, $image_extensions, true)) {
                    $image_entries[] = array(
                        'index'    => $i,
                        'path'     => $entry,      // Full path inside ZIP
                        'basename' => $basename,
                        'ext'      => $entry_ext,
                    );
                }

                // Collect CSS files. We inline these into the HTML before sending
                // to the API so the converter sees the full layout — without this,
                // any <link rel="stylesheet" href="assets/styles.css"> reference
                // is dead (the API has no way to fetch relative paths from the
                // user's machine), and pages with external stylesheets convert
                // missing 50%+ of their CSS (display:grid, flex layouts, etc.).
                if ($entry_ext === 'css') {
                    $css_entries[] = array(
                        'index'    => $i,
                        'path'     => $entry,
                        'basename' => $basename,
                    );
                }
            }
        return array(
            'html_content'  => $html_content,
            'html_filename' => $html_filename,
            'html_dir'      => $html_dir,
            'image_entries' => $image_entries,
            'css_entries'   => $css_entries,
            'zipunsafe'     => $zipunsafe,
            'ziphtml'       => $ziphtml,
        );
    }

    /**
     * Does this ZIP entry name try to climb out of the archive?
     *
     * EXTRACTED SO IT CAN BE TESTED WITHOUT WORDPRESS, and because the first version was
     * `strpos($entry, '..') !== false` — which also matches `assets/photo..final.jpg` and
     * `v1.2..3/logo.svg`. Dropping those silently was the old bug; TELLING their owner the file
     * "tries to escape the archive" is a worse one, and this batch exists to make the reporting
     * true. Segments only: a bare `..`, a leading `../`, an interior `/../`, or a trailing `/..`.
     */
    private static function is_unsafe_zip_path($entry) {
        $seg = str_replace(chr(92), '/', (string) $entry);   // chr(92): a backslash escape dies in transit
        return ($seg === '..'
            || strpos($seg, '../') === 0
            || strpos($seg, '/../') !== false
            || substr($seg, -3) === '/..');
    }

    private static function sweep_new_attachments($post_id, $known) {
        $now = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit',
            'posts_per_page' => -1, 'fields' => 'ids', 'post_parent' => $post_id));
        foreach ((is_array($now) ? $now : array()) as $att) {
            if (!isset($known[$att])) { wp_delete_attachment($att, true); }
        }
    }

    private static function unwind_uploads($post_id, $files) {
        if (empty($files)) { return; }
        $mine = array_flip($files);
        $atts = get_posts(array(
            'post_type' => 'attachment', 'post_status' => 'inherit',
            'post_parent' => $post_id, 'posts_per_page' => -1, 'fields' => 'ids',
        ));
        foreach ($atts as $aid) {
            $f = get_attached_file($aid);
            if ($f && isset($mine[$f])) {
                wp_delete_attachment($aid, true);
                unset($mine[$f]);
            }
        }
        // wp_delete_file, not unlink: it is the WP-blessed wrapper (it applies the
        // `wp_delete_file` filter, which antivirus and backup extensions hook, then unlinks
        // itself). wp.org's own plugin-check reports a raw unlink() as an ERROR, and the
        // build serving wp.org before 1.3.37 had none — this one entered in 1.3.35 and the
        // fix had already been made once, two lines up the same file, and then lost.
        foreach (array_keys($mine) as $f) { if (file_exists($f)) { wp_delete_file($f); } }
    }

    /**
     * How far into a payload either half of the image decision is willing to look.
     *
     * ONE constant, because two were one too many: the signature probe used 4096 and the
     * text-refusal used 512, and a payload whose markup sat in the gap was positively refused
     * while the deployed build hosted it. Cycle 4 measured the boundary at exactly 4096.
     */
    const SIG_WINDOW = 8192;

    /** Leading whitespace and NULs, which a generator can emit by the thousand before any markup. */
    private static function strip_leading_space($bytes) {
        // UNBOUNDED. Bounding the strip at a window meant that when the whole window was
        // whitespace the result was the EMPTY STRING, and an empty string satisfied every test
        // downstream: `looks_like_text_not_image('')` computed `0 !== 0` as false and returned
        // "provably prose". Twenty thousand NUL bytes followed by binary was refused as text.
        // Cycle 4 moved this boundary from 4096 to 16384; moving it again would only move the
        // input that trips it.
        return ltrim($bytes, " " . chr(9) . chr(10) . chr(11) . chr(12) . chr(13) . chr(0));
    }

    /**
     * Are these bytes recognisably TEXT that is not an image?
     *
     * #146, FOURTH ATTEMPT, AND THE THIRD DIRECTION. The first three were whitelists: name every
     * image format, refuse what is left. Each pass added formats — BMP, ICO, TIFF, AVIF, HEIC,
     * SVG, then compatible brands, then leading whitespace — and each pass a reviewer found
     * another encoding the DEPLOYED build hosts and this one refused. BigTIFF. An SVG behind 4096
     * spaces. The list was never going to close, because "is this an image" has no finite answer
     * and every miss is a regression against customers.
     *
     * So the question is inverted, the way `looks_truncated()` is inverted. That function does not
     * ask "is this whole"; it asks "can I PROVE this is cut". This one does not ask "is this an
     * image"; it asks "can I PROVE this is prose". Everything unproven falls through to what the
     * deployed build does, so an unknown encoder can cost us a missed optimisation and can never
     * cost a customer a working file.
     *
     * The defect #146 exists for — 19 bytes of a mangled `placehold.co` URL written out as a
     * `.jpg` — is text, and text is a thing you can positively identify: printable ASCII, no NUL
     * bytes, no control characters outside whitespace. A real image fails that within its first
     * few dozen bytes; a URL or a sentence does not.
     */
    private static function looks_like_text_not_image($bytes) {
        $len = strlen($bytes);
        if ($len < 8) { return false; }
        // THE SAME WINDOW THE SIGNATURE TEST USES, AND THE SAME LEADING-WHITESPACE SKIP.
        //
        // They disagreed — the signature scanned 4096 bytes after an ltrim, this scanned 512 from
        // byte 0 — and the gap between them was a positive refusal. At exactly 4096 whitespace
        // bytes the signature probe came back empty while this function saw 512 printable
        // characters with no markup in them and declared the payload prose. An SVG the deployed
        // build hosts became a refusal, which is the narrowing this whole inversion exists to make
        // impossible. Two windows is one window too many.
        $sample = substr(self::strip_leading_space($bytes), 0, self::SIG_WINDOW);
        // AN EMPTY SAMPLE IS NOT EVIDENCE OF ANYTHING. A payload that is nothing but whitespace
        // and NULs cannot be PROVEN to be prose, and this function's whole contract is that it
        // only ever returns true on proof. Without this the loop below runs zero times, the
        // printable count equals the length trivially, and the payload is refused.
        if ($sample === '') { return false; }
        if (strpos($sample, chr(0)) !== false) { return false; }   // NULs mean binary
        $printable = 0;
        $n = strlen($sample);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($sample[$i]);
            if ($c === 9 || $c === 10 || $c === 13 || ($c >= 32 && $c <= 126)) { $printable++; }
        }
        if ($printable !== $n) { return false; }                   // any binary byte: not prose
        // MARKUP ANYWHERE, NOT MARKUP IN THE WINDOW. An SVG whose `<svg` sits behind 9,000
        // `<`-free printable bytes is hosted by the deployed build — the sanitiser salvages the
        // element and writes a working 116-byte file — and was refused here, because the window
        // saw only prose. Every cycle that answered this by MOVING the window moved the input
        // that trips it instead: 4096, then 16384, now none. The scan is over the whole payload,
        // which costs one strpos on a string already in memory.
        $lower = strtolower($bytes);
        if (strpos($lower, '<svg') !== false || strpos($lower, '<?xml') !== false) { return false; }
        return true;
    }

    /**
     * The extension an IMAGE's own bytes say it is, or '' if the bytes are not an image we know.
     *
     * #146. The image path derived its extension from the DECLARED mime and never read a byte, so
     * `data:image/png;base64,<prose>` wrote prose into the Media Library as a `.png`. This is the
     * finding cycles 1 and 2 raised on the FONT path, in the third place it applies.
     *
     * THE CORPUS NUMBER THAT MOTIVATED IT DOES NOT REPRODUCE, and that is worth writing down
     * rather than quietly dropping. An earlier ledger entry said two corpus payloads were 19
     * bytes of a mangled `placehold.co` URL saved as `.jpg` and `.webp`. Re-measured across all
     * 210 pages: 108 base64 image URIs, smallest decoded payload 315 bytes, and ZERO
     * mime-versus-signature mismatches. So the measured benefit on today's corpus is nil; what
     * this closes is a class that the corpus happens not to contain right now.
     *
     * DELIBERATELY GENEROUS. Narrowing this matcher is how cycles 3 and 6 failed, so every format
     * WordPress allows by default is recognised here — png jpg gif webp bmp ico tiff avif heic —
     * plus SVG, which is text. What is left over is genuinely unrecognisable, and refusing it only
     * means the payload stays inline and the page still renders.
     *
     * THE EXTENSION COMES FROM THE SIGNATURE, NOT THE MIME. A payload declared `image/png` whose
     * bytes are a JPEG is written `.jpg`, because the bytes are the thing that has to be true.
     */
    /**
     * The network's allowed extensions as a list, or NULL when there is no network policy.
     *
     * #145, second attempt. The first extracted only the INTERSECTION and left the option read
     * inside the closure, so the half that actually carried the bug was unreachable from a
     * single-site test: review replaced `$permitted` with `null` — undoing the entire fix — and
     * the suite stayed green at 41/41. The docblock claimed the guard was measurable. It was not.
     *
     * Passing NULL means single-site: no policy to respect. Passing the raw option string means
     * multisite, and it is parsed here where a test can hand it anything an administrator might
     * plausibly have typed.
     *
     * Core splits `upload_filetypes` on a single space; splitting on any whitespace, comma or
     * semicolon is strictly more permissive about the ADMIN's formatting and never about ours.
     */
    public static function network_permitted_types($raw) {
        if ($raw === null) { return null; }
        // A filter can make `upload_filetypes` an array. Casting one to a string warns and yields
        // "Array", which parses to a single token and permits nothing -- fail-safe, and noisy in a
        // way that blames the wrong thing.
        // `array_filter` first: a NESTED array still warned and yielded the token `array`, which
        // is the instance-not-class shape this file keeps relearning.
        if (is_array($raw)) { $raw = implode(' ', array_filter($raw, 'is_scalar')); }
        // The cast lives HERE, not at the call site: an object `upload_filetypes` threw before it
        // ever reached this method's guards.
        if (!is_scalar($raw)) { return array(); }
        return array_values(array_filter(preg_split('/[\s,;]+/', strtolower((string) $raw))));
    }

    /**
     * Add the font types to an `upload_mimes` set, respecting a network policy when there is one.
     *
     * #145. Core registers `check_upload_mimes` at priority 10 (ms-default-filters.php) and so
     * does our closure, so on multisite we were re-adding fonts over whatever `upload_filetypes`
     * the network admin had configured — a plugin quietly overriding a deliberate administrative
     * restriction, on the one surface where the restriction is usually the entire point.
     *
     * `$permitted` is NULL on single-site (there is no network policy to respect, so all four are
     * added) and the network's own list on multisite (so a type it does not allow is left out and
     * that payload simply stays inline).
     *
     * Pure and static ON PURPOSE: the multisite branch was reported UNMEASURED because nobody had
     * a network to run it on, and a guard nobody can measure is a guard nobody has. This one is
     * measurable from a single-site test by passing the list in.
     */
    public static function widen_font_mimes($mimes, $permitted = null) {
        $want = array(
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
        );
        // ANYTHING THAT IS NOT A LIST MEANS "no policy", not a fatal. The first version kept the
        // bad value in the else branch, so a string or a bool reached `in_array()` and threw.
        // A NON-ARRAY IS NOT "no policy". It was treated as null, so a string or a bool added all
        // four types — fail-OPEN on the one surface where the whole point is to obey a
        // restriction. Only an explicit null means single-site.
        if ($permitted !== null && !is_array($permitted)) { $permitted = array(); }
        $permitted = is_array($permitted) ? array_map('strtolower', array_filter($permitted, 'is_scalar')) : null;
        foreach ($want as $ext => $mime) {
            if ($permitted === null || in_array($ext, $permitted, true)) {
                $mimes[$ext] = $mime;
            }
        }
        return $mimes;
    }

    /**
     * Bytes of headroom left under `memory_limit`, or PHP_INT_MAX when there is no limit.
     *
     * #144. The 8 MB ceiling capped the PAYLOAD and not the COST: review measured peak RSS of
     * 113 MB for an 8 MB payload — about 14x — because this pass holds the encoded JSON, the
     * rewritten copy and the decoded bytes at once, on top of whatever the caller already has.
     * On a 128M host that is a fatal.
     *
     * A fixed lower ceiling was the obvious fix and the wrong one: a CJK webfont is legitimately
     * several megabytes, and a number small enough to protect a 128M host would refuse fonts a
     * 512M host handles comfortably. So the limit is read from the host instead. Same payload,
     * different answer per site, which is what "will this fit" actually depends on.
     */
    /**
     * ONE TABLE, ONE EMITTER, BOTH PASSES — because instrumenting one pass and missing the other
     * is now the defect three review cycles running have caught me at. Cycle 9 instrumented one
     * branch and I wrote it up as the class; cycle 10 found three more on the image pass; cycle 11
     * found the SAME two on the font pass, where a TRUNCATED FONT — the #143 class this batch
     * exists to report — was dropped in silence while the image half reported it.
     *
     * Counting continues to be local to each pass. What is shared is the REASON and the WORDS, so
     * a new drop reason is added in one place and both passes gain the line. A promise to remember
     * has now failed three times; a shape that cannot drift is the only thing left to try.
     *
     * Every line answers the same three questions, because a customer reading a log is asking
     * them: what happened, is anything lost, and is it worth reporting.
     */
    private static function report_drops($drops, $extra = array()) {
        // EACH ENTRY SAYS WHETHER THE CAVEAT APPLIES TO IT. Appending it to all nine was wrong
        // twice over: on `fontunreg` nothing is left in the page (the line says so itself), and on
        // the font lines it promised missing PICTURES when the symptom is a fallback typeface.
        // `delivery_data_uri_import_strip` is a record about images.
        $q = chr(39);
        $lines = array(
            'notimage' => array('caveat' => true, 'kind' => 'img',
                'line' => '%d embedded payload(s) were left inline because they do not read as an '
                    . 'image. If a picture is missing from your imported page, this line is the '
                    . 'reason and it is worth reporting; the payload is still in the page.'),
            'cutshort' => array('caveat' => true, 'kind' => 'img',
                'line' => '%d image(s) were left inline because the bytes we hold do not contain '
                    . 'the end of the file — the payload stops before the format' . $q . 's own end '
                    . 'marker, or carries more trailing data than the format allows. We do not save '
                    . 'half an image. The payload is still in the page. If the picture looks '
                    . 'complete to you, that is worth reporting: it means we cut it, not your '
                    . 'source.'),
            'toosmall' => array('caveat' => true, 'kind' => 'img',
                'line' => '%d payload(s) were left inline because they were too short to be an '
                    . 'image or could not be decoded at all.'),
            'writefail' => array('caveat' => true, 'kind' => 'img',
                'line' => '%d image(s) could not be written to your Media Library and were left in '
                    . 'the page instead. A common cause is a file type your WordPress does not '
                    . 'allow to be uploaded (SVG is not allowed by default), in which case the '
                    . 'image itself was fine.'),
            'svgempty' => array('caveat' => true, 'kind' => 'img',
                'line' => '%d SVG payload(s) were left inline because nothing survived our SVG '
                    . 'safety filter. The payload is still in the page.'),
            'fontsmall' => array('caveat' => true, 'kind' => 'font',
                'line' => '%d embedded font(s) were left inline because they were too short to be '
                    . 'a font or could not be decoded at all.'),
            'fontsig' => array('caveat' => true, 'kind' => 'font',
                'line' => '%d embedded font(s) were left inline because their bytes do not read as '
                    . 'a font we recognise — this includes a font that arrived CUT SHORT. If text '
                    . 'on your imported page falls back to a different typeface, this line is the '
                    . 'reason and it is worth reporting; the payload is still in the page.'),
            'fontunreg' => array('caveat' => false, 'kind' => 'font',
                'line' => '%d hosted font(s) could not be registered in the Media Library. The '
                    . 'page points at them and renders correctly, but they will not appear under '
                    . 'Media, and converting this page again will upload them a second time.'),
            'remotefail' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d image(s) hosted elsewhere on the web could not be copied into your '
                    . 'Media Library and are still loaded from their original address. They render '
                    . 'today and they will break if that site removes them.'),
            'remotecap' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d image(s) hosted elsewhere were not copied because this page reached '
                    . 'our per-page limit or time budget. They are still loaded from their original '
                    . 'address. This pass keeps no record between runs, so converting the page again '
                    . 'starts from the first image and will copy the same ones a second time rather '
                    . 'than continuing from here.'),
            // THREE KEYS, NOT ONE, BECAUSE THREE PASSES EMIT THIS. A single `reverted` row was
            // hardcoded `kind => 'img'`, so the FONT pass's revert told a customer whose TYPEFACE
            // fell back to go looking for missing pictures — the exact string an assertion twenty
            // lines up says must never appear on a font line. And on the remote pass "everything
            // hosted on this page was UNDONE" was simply false: that pass returns its INPUT, which
            // is the font pass's output, so the image and font hosting survives untouched.
            // THE EIGHT THAT USED TO BYPASS THIS TABLE. They were direct `error_log` calls, so
            // "one table both passes emit through" was true of the reasons I happened to add and
            // not of the ones that were already there — the same overreach, one layer up. Every one
            // of them describes a payload LEFT IN THE PAGE, so every one needs the caveat, and the
            // two font lines were the most reassuring strings in the batch: "Nothing is wrong with
            // the page" on a pass whose own caveat says the text will fall back to another typeface.
            'imgheavy' => array('caveat' => true, 'kind' => 'img',
                'line' => '%d image(s) were left inline because hosting them needs more memory than '
                    . 'this site has left. The page still imports and renders from the inline '
                    . 'copies; raising PHP memory_limit would let them become Media Library files.'),
            'imgunreg' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d hosted image(s) could not be registered in the Media Library. The page '
                    . 'points at them and renders correctly, but they will not appear under Media, '
                    . 'so they cannot be managed or reused. Usually a plugin filtering attachment '
                    . 'creation.'),
            'fontrefused' => array('caveat' => true, 'kind' => 'font',
                'line' => '%d embedded font(s) could not be written to the Media Library and were '
                    . 'left in the page instead.'),
            'fontheavy' => array('caveat' => true, 'kind' => 'font',
                'line' => '%d embedded font(s) were left inline because hosting them needs more '
                    . 'memory than this site has left. The page still imports and renders from the '
                    . 'inline copies; raising PHP memory_limit would let them become Media Library '
                    . 'files.'),
            'fontoversize' => array('caveat' => true, 'kind' => 'font',
                'line' => '%d embedded font(s) were left inline because they exceed the size we are '
                    . 'willing to move, deliberately — a webfont that large is not one anyone should '
                    . 'be serving inline.'),
            'skipmem' => array('caveat' => true, 'kind' => 'img',
                'line' => 'the images this page embedded were all left in it, because processing '
                    . 'them needs more memory than this site has left. The page still imports.'),
            'skipmem_font' => array('caveat' => true, 'kind' => 'font',
                'line' => 'the fonts this page embedded were all left in it, because processing '
                    . 'them needs more memory than this site has left. The page still imports.'),
            'skipregex' => array('caveat' => true, 'kind' => 'img',
                'line' => 'the images this page embedded were all left in it, because scanning the '
                    . 'page defeated our pattern engine. That is our limit, not a fault in your '
                    . 'page, and it is worth reporting.'),
            'skipregex_font' => array('caveat' => true, 'kind' => 'font',
                'line' => 'the fonts this page embedded were all left in it, because scanning the '
                    . 'page defeated our pattern engine. That is our limit, not a fault in your '
                    . 'page, and it is worth reporting.'),
            // ---- #152: the surfaces outside the three hosting passes ----------------------
            // Four review cycles found me closing this class one function at a time. The
            // enumeration that finally covered it was a reviewer's, not mine, and these are its
            // rows. Everything here was silent in 1.3.36 and every version before it.
            'zipunsafe' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d file(s) in your ZIP were skipped because their path tries to escape '
                    . 'the archive. That is a shape we refuse on principle; if those files were '
                    . 'meant to be in your page, re-zip the folder rather than the paths.'),
            'zipimg' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d image(s) in your ZIP could not be imported and are not in your Media '
                    . 'Library. If the page referenced them, that reference is unchanged and will '
                    . 'not resolve, so those pictures will be missing; images the page never used '
                    . 'are simply absent.'),
            'zipcss' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d stylesheet(s) in your ZIP could not be read and were not inlined. The '
                    . 'page will import with those styles missing.'),
            'ziphtml' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d further HTML file(s) in your ZIP were ignored. We import one page per '
                    . 'ZIP and take the first one we find; upload the others separately.'),
            'kitcolorcap' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d colour(s) from this page were not added to your Site Settings because '
                    . 'the per-page limit was reached. Nothing was removed; the page itself is '
                    . 'unaffected and still carries those colours directly.'),
            // `kind` says FONT even though `caveat` is false. Harmless today; the moment anyone
            // flips the caveat it would promise missing PICTURES to a customer whose FONT list was
            // truncated — verbatim the defect recorded as fixed for `reverted` eight rows down.
            'kitfontcap' => array('caveat' => false, 'kind' => 'font',
                'line' => '%d font family(ies) from this page were not added to your Site Settings '
                    . 'because the per-page limit was reached. Nothing was removed; the page itself '
                    . 'is unaffected.'),
            'kitmalformed' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d Site Settings entry(ies) arrived without a usable id or value and were '
                    . 'skipped. Worth reporting: that is our bug, not your page.'),
            'svgdropped' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d inline SVG icon(s) were removed because nothing survived our SVG '
                    . 'safety filter. They are gone from the page rather than left as raw markup, '
                    . 'which is deliberate: what did not survive the filter is what we will not put '
                    . 'on your site.'),
            // TWO REASONS, NOT ONE. Both end with the icon as inline markup, but the customer can
            // only act on the first — and telling someone to switch on a setting they already have
            // on is worse than saying nothing. `svgheld` is the site setting; `svgwrite` is a write
            // that failed with the setting already enabled.
            'svgheld' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d icon(s) were kept as inline markup instead of becoming editable Image '
                    . 'widgets, because native SVG icon import is switched off on this site. They '
                    . 'render exactly as designed; they are simply not editable through Elementor '
                    . 'image controls. It is off by default because writing SVG into a Media Library '
                    . 'is a security decision that belongs to the site owner rather than to us. An '
                    . 'administrator can switch it on with add_filter(' . $q . 'htel_import_svg_icons'
                    . $q . ', ' . $q . '__return_true' . $q . '); every icon is re-sanitised before '
                    . 'it is saved.'),
            'svgwrite' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d icon(s) could not be written to your Media Library and were kept as '
                    . 'inline markup instead. Native icon import IS switched on here, so this is a '
                    . 'write failure rather than a setting — a security plugin blocking SVG uploads '
                    . 'is the usual cause. The icons still render. Worth reporting if it repeats.'),
            'navfallback' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d navigation menu(s) could not be built as a real Elementor menu and '
                    . 'were imported as plain markup instead. They render, but they are not editable '
                    . 'through Elementor menu controls.'),
            'navempty' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d navigation menu(s) could not be imported at all and their widget is '
                    . 'empty. This is the one on this list that loses visible content, and it is '
                    . 'worth reporting.'),
            'navitem' => array('caveat' => false, 'kind' => 'img',
                'line' => '%d menu item(s) could not be created, and any items nested under them '
                    . 'were dropped with them.'),
            'reverted' => array('caveat' => true, 'kind' => 'img',
                'line' => 'the images this page embedded were hosted and then UNDONE, and the page '
                    . 'was returned with them still embedded, because the rewritten document could '
                    . 'not be read back. No files were left behind.'),
            'reverted_font' => array('caveat' => true, 'kind' => 'font',
                'line' => 'the fonts this page embedded were hosted and then UNDONE, and the page '
                    . 'was returned with them still embedded, because the rewritten document could '
                    . 'not be read back. No files were left behind.'),
            'reverted_remote' => array('caveat' => false, 'kind' => 'img',
                'line' => 'images this page loads from other sites were copied into your Media '
                    . 'Library and then UNDONE, because the rewritten document could not be read '
                    . 'back. No files were left behind, and they are still loaded from their '
                    . 'original address. Anything this page had EMBEDDED was hosted normally and is '
                    . 'unaffected.'),
        );
        $caveat = array(
            'img'  => ' (A payload left in the page is imported correctly by this plugin. '
                . 'Elementor' . $q . 's own template importer strips embedded images, so if you '
                . 'import the JSON that way instead, those pictures will be missing.)',
            'font' => ' (A payload left in the page is imported correctly by this plugin. '
                . 'Elementor' . $q . 's own template importer strips embedded data, so if you '
                . 'import the JSON that way instead, the text will fall back to another typeface.)',
        );
        foreach ($drops as $key => $count) {
            if (empty($count)) { continue; }
            // AN UNKNOWN KEY USED TO VANISH, because this loop walked the TABLE and not the counts
            // — so a typo in a caller silenced the very report it was adding, inside the mechanism
            // built to end silent drops. Review found it by passing `elephant`. It says something
            // now rather than nothing.
            if (!isset($lines[$key])) {
                error_log('[aitoel] ' . (int) $count . ' payload(s) were left in the page for a '
                    . 'reason this build has no wording for ('
                    . preg_replace('/[^a-z0-9_]/i', '', (string) $key)
                    . '). That is our bug, not yours, and it is worth reporting.');
                continue;
            }
            $spec = $lines[$key];
            $n = (int) $count;
            // A NEGATIVE COUNT MEANS SILENCE, NOT A CLAMP. Clamping printed "0 payload(s) were
            // left inline" — a drop report asserting zero drops, which is a worse line than no
            // line. Review found it; my own assertion had been treating it as a control.
            if ($n <= 0) { continue; }
            $msg = (strpos($spec['line'], '%d') === false)
                ? $spec['line']
                : sprintf($spec['line'], $n);
            if (isset($extra[$key]) && $extra[$key] !== '') { $msg .= ' ' . $extra[$key]; }
            if (!empty($spec['caveat'])) { $msg .= $caveat[$spec['kind']]; }
            error_log('[aitoel] ' . $msg);
        }
    }
    private static function memory_headroom($rawOverride = null) {
        // THE OVERRIDE EXISTS SO THE PARSER CAN BE TESTED. `ini_set` silently refuses a limit below
        // current usage, so every attempt to drive this through the environment measured whatever
        // the host already had — review found the one assertion near it returns PHP_INT_MAX on this
        // container and cannot fail. Production never passes this argument.
        // AND THE GUARD GOES ABOVE THE CAST, not below it. I put it below first, where `$raw` is
        // already a string and `is_scalar` is therefore always true — a guard placed where it
        // cannot fire, in the batch whose failing criterion is guards that cannot go red. The
        // cast itself is what warns on an array and throws on an object.
        if ($rawOverride !== null && !is_scalar($rawOverride)) { return PHP_INT_MAX; }
        $raw = trim((string) ($rawOverride === null ? ini_get('memory_limit') : $rawOverride));
        // PHP treats ANY negative limit as unlimited, not just the string '-1'. Reading `-2` as
        // zero headroom would silently disable all hosting on a host that had disabled the limit.
        if ($raw === '' || (int) $raw < 0) { return PHP_INT_MAX; }
        $unit = strtolower(substr($raw, -1));
        $n = (int) $raw;
        if ($unit === 'g') { $n *= 1024 * 1024 * 1024; }
        elseif ($unit === 'm') { $n *= 1024 * 1024; }
        elseif ($unit === 'k') { $n *= 1024; }
        $used = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        $left = $n - $used;
        return $left > 0 ? $left : 0;
    }

    /**
     * Would hosting a payload of this size plausibly exhaust the host?
     *
     * THE MULTIPLIER IS 3 AND THE HISTORY IS IN THE FUNCTION BODY. This summary said "14x is
     * review's measurement" while the code multiplied by 6, and then by 3 — a stale number in a
     * docblock one commit after another stale number was withdrawn from the docblock below for
     * exactly that reason. Read the body; it carries the arithmetic and why it changed twice.
     *
     * The 0.8 leaves room for whatever runs after this pass. Refusing is FAIL-SAFE: the payload
     * stays inline, the page still renders, and the customer is told why in the log rather than
     * getting a 500.
     */
    private static function too_big_for_this_host($decodedBytes) {
        $headroom = self::memory_headroom();
        if ($headroom === PHP_INT_MAX) { return false; }
        // THREE, AND THE ARITHMETIC WAS DOUBLE-COUNTING.
        //
        // 14 was review's peak for the WHOLE PASS; I applied it per payload and it refused work
        // 1.3.35 completes. 6 was inside the measured single-payload range and STILL refused it:
        // review measured a 5.75 MB font — the corpus's heaviest carrier — hosted by the deployed
        // build on a 128M host at a peak of 97.7 MB, thirty megabytes to spare, and refused here.
        //
        // The mistake is that `memory_headroom()` is read AFTER the prologue. By the time this
        // runs, the encoded document and the match set are already allocated and already counted
        // against the limit; what remains to be spent on one payload is the decode and the upload
        // buffer, not the whole pass again. Multiplying the payload by the whole-pass factor while
        // measuring headroom that has already paid for most of it charges the same memory twice.
        //
        // Three is what is left to spend, and it lets the heaviest page in the corpus through on
        // PHP's own default limit, which is the case that matters.
        return ($decodedBytes * 3) > ($headroom * 0.8);
    }

    /**
     * Can this pass afford to RUN AT ALL on this host?
     *
     * The per-payload check above cannot prevent the fatal it exists for, because the expensive
     * allocations happen BEFORE any payload is looked at: review measured +32 MB spent on an 8 MB
     * document before the first per-payload decision — +10.7 MB for `wp_json_encode` and +21.3 MB
     * for `preg_match_all` with PREG_SET_ORDER, which copies every match. At 2-4 MB of headroom
     * the pass fatalled while the per-payload guard was still voting "refuse".
     *
     * So the document is measured before the match set is built. Three times the encoded length is
     * the measured prologue cost with room to spare; below that the pass returns the content
     * untouched and says so, which is the same outcome as refusing every payload individually but
     * arrives before the allocation rather than after it.
     *
     * HOW OFTEN THIS FIRES — AND THE FIRST TABLE I PUT HERE WAS WRONG, so it is withdrawn rather
     * than corrected in place, because a number in a docblock gets quoted.
     *
     * I computed cutoffs from a 59.3 MB baseline measured in a bare wp-cli process. Review
     * measured usage AT THE MOMENT THIS GATE RUNS as 82-93 MB, so the real cutoff on a 128M host
     * is about 10.7 MB encoded, not the 18.3 MB I published — and the table said nothing at all
     * about the per-payload gate, which fires far earlier. Two gates, one table, describing
     * neither.
     *
     * What is measured and still true: this gate did not fire on any corpus page, and the
     * per-payload gate is now calibrated against the heaviest carrier the census found (5.75 MB
     * of font) completing on PHP's default 128M. Anyone wanting a cutoff table should measure it
     * at the line where the gate runs, not at the top of a fresh process.
     */
    private static function pass_fits_this_host($encodedLength) {
        $headroom = self::memory_headroom();
        if ($headroom === PHP_INT_MAX) { return true; }
        return ($encodedLength * 3) < ($headroom * 0.8);
    }

    private static function image_extension_from_signature($bytes) {
        $len = strlen($bytes);
        if ($len < 12) { return ''; }
        $head = substr($bytes, 0, 12);

        if (substr($head, 0, 8) === chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) { return 'png'; }
        if (substr($head, 0, 3) === chr(255) . chr(216) . chr(255)) { return 'jpg'; }
        if (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') { return 'gif'; }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') { return 'webp'; }
        // BMP declares its total size at offset 2. Without that, "BM" is a two-byte matcher and
        // review got `bmp` back for the string "BMW dealership listing…" — #146's guarantee was
        // resting on `looks_truncated()` rejecting it a few lines later, which is a different
        // guard doing this one's job.
        if (substr($head, 0, 2) === 'BM' && $len >= 6) {
            $declared = ord($bytes[2]) | (ord($bytes[3]) << 8)
                | (ord($bytes[4]) << 16) | (ord($bytes[5]) << 24);
            // `<=`, NOT `===`. `looks_truncated()` fifty lines below already learned this — its
            // own comment records that an equality test cost three whole WEBPs — and I did not
            // carry it across. A real GD-encoded BMP with one trailing byte, and one whose header
            // leaves the size field at 0, are both hosted by the deployed build and were both
            // refused here. Short of its declared size is a truncation; longer, or silent, is not.
            if ($declared === 0 || $declared <= $len) { return 'bmp'; }
        }
        // TIFF, either byte order.
        if (substr($head, 0, 4) === 'II' . chr(42) . chr(0) || substr($head, 0, 4) === 'MM' . chr(0) . chr(42)) { return 'tiff'; }
        // ISO-BMFF FIRST. AVIF and HEIC put a `ftyp` box at offset 4, and a box whose size field
        // happens to be 0x00000100 begins with the same four bytes ICO does — review built one and
        // got `ico` back.
        //
        // THE ORDER IS DEFENCE IN DEPTH, NOT THE FIX, and ablating it reddens nothing: the ICO
        // count check below reads bytes 4-5, which for any ISO-BMFF file are `f` and `t` from
        // `ftyp` — 29,798 — so an ISO-BMFF can never satisfy it whatever the order. Said plainly
        // rather than left to look like the thing that closed the finding.
        if (substr($bytes, 4, 4) === 'ftyp') {
            // READ THE COMPATIBLE BRANDS, NOT ONLY THE MAJOR ONE. An AVIF whose major brand is
            // `mif1` was written `.heic` with a matching `post_mime_type` — a file whose extension
            // and declared type both disagree with its bytes, on a path where the data: fallback
            // has already been removed. And `msf1` (HEIF) was refused where the deployed build
            // hosts it. The brand list lives in bytes 16.. as well as at offset 8.
            $brands = substr($bytes, 8, 4) . ' ' . substr($bytes, 16, 32);
            if (strpos($brands, 'avif') !== false || strpos($brands, 'avis') !== false) { return 'avif'; }
            foreach (array('heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs',
                'mif1', 'msf1', 'mif2') as $b) {
                if (strpos($brands, $b) !== false) { return 'heic'; }
            }
        }
        // ICO and CUR: reserved 0, then type 1 or 2, then an image COUNT. The count was not
        // checked, so a 64-byte blob beginning 00 00 01 00 was written as an `.ico`.
        if (ord($bytes[0]) === 0 && ord($bytes[1]) === 0 && ord($bytes[3]) === 0
            && (ord($bytes[2]) === 1 || ord($bytes[2]) === 2)) {
            $count = ord($bytes[4]) | (ord($bytes[5]) << 8);
            if ($count >= 1 && $count <= 255 && $len >= 6 + (16 * $count)) { return 'ico'; }
        }
        // SVG is text. The first version looked at 256 bytes and required `<?xml` there before it
        // would read further, so three shapes 1.3.35 hosts were refused: `<svg` past byte 256 with
        // no XML declaration, a DOCTYPE-first document, and UTF-16. Narrowing against the deployed
        // build is the regression two review cycles were failed for, so the rule is now simply:
        // if the head looks like markup at all, look at the whole thing.
        // SKIP THE LEADING WHITESPACE FIRST. A 256-byte probe taken from byte 0 sees nothing but
        // indentation on a document that opens with 300 spaces or a run of newlines and tabs, and
        // the deployed build hosts those. Same for UTF-16 WITHOUT a BOM, which no signature marks
        // and which shows itself as alternating NULs.
        $lead = self::strip_leading_space($bytes);
        $probe = strtolower(substr($lead, 0, self::SIG_WINDOW));
        $bom = substr($bytes, 0, 2);
        $utf16 = ($bom === chr(255) . chr(254) || $bom === chr(254) . chr(255));
        if (!$utf16 && $len >= 32) {
            // BOM-less UTF-16: every other byte of ASCII text is NUL.
            $sample = substr($bytes, 0, 64);
            $nuls = substr_count($sample, chr(0));
            $utf16 = ($nuls >= (strlen($sample) / 3));
        }
        // THE CLAIM HERE WAS TOO WIDE, AND IT CARRIED THE WORD "MEASURED". I wrote that this branch
        // "changes no outcome today" because review found a UTF-16 SVG refused by the sanitiser in
        // both builds. That holds only for a payload DECLARED `image/svg+xml`. A UTF-16LE SVG
        // declared `image/png` takes a different road: WITH this branch the signature reads `svg`,
        // the sanitiser refuses it and it stays inline; WITHOUT it the signature reads '' and the
        // MIME names the file, so 1.3.35 writes a 200-byte `.png` that renders as nothing. Measured
        // by ablating `if ($utf16)` to `if (false)`, one payload, SVG uploads enabled. The branch
        // DOES change an outcome — it turns a broken file into a payload left in place, which is
        // the whole of #146. Kept also because the sanitiser is not this function's to rely on.
        if ($utf16) {
            $flat = strtolower(str_replace(chr(0), '', $bytes));
            if (strpos($flat, '<svg') !== false) { return 'svg'; }
        }
        if (strpos($probe, '<svg') !== false) { return 'svg'; }
        // NO `<`-IN-THE-WINDOW PRECONDITION. It meant `<svg` behind 9,000 `<`-free printable bytes
        // returned '' from this function and the extension came from the MIME instead — the same
        // outcome as the deployed build, so not a regression, but the commit claimed "it reads the
        // whole payload now" and that was true only of `looks_like_text_not_image`. One strpos on
        // a string already in memory buys the claim being true of both.
        if (strpos(strtolower($bytes), '<svg') !== false) { return 'svg'; }
        return '';
    }

    private static function looks_truncated($bytes) {
        $len = strlen($bytes);
        if ($len < 12) { return false; }
        // CONTAINS the terminator, not ENDS WITH it. A PNG with metadata appended after IEND is
        // an ordinary PNG, and a JPEG can carry padding after EOI; requiring the terminator to be
        // the last bytes refused 22 assertions' worth of perfectly good fixtures the first time
        // this ran. A file that was cut short does not contain its terminator at all, which is the
        // only thing this function is entitled to conclude.
        $has = function ($sig) use ($bytes) {
            return false !== strpos($bytes, $sig);
        };
        // chr(), NEVER a string escape. Every attempt in this repo to write these
        // signatures as a double-quoted hex escape has died on the way through a shell
        // and left VALID PHP matching the wrong bytes: the last one encoded 0x89 as
        // two UTF-8 bytes and put a raw 0x1A into the source. chr() has no escape to lose.
        $PNG  = chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10);
        $IEND = 'IEND' . chr(174) . chr(66) . chr(96) . chr(130);
        if (substr($bytes, 0, 8) === $PNG) {
            return !$has($IEND);
        }
        // JPEG: SOI ... EOI.
        if (substr($bytes, 0, 3) === chr(255) . chr(216) . chr(255)) {
            return !$has(chr(255) . chr(217));
        }
        // GIF: the trailer must be PRESENT, not last.
        //
        // This branch has now been wrong in both directions. It began excluded, on the argument
        // that a containment test on a one-byte trailer cannot fail — true of containment, and
        // not a reason to skip the format, since a truncated GIF has no trailer anywhere. Then it
        // required the trailer to be the LAST byte, and review measured the cost: a whole GIF
        // with a single trailing NUL is refused by that test and hosted byte-identically by the
        // build customers run today. Trailing bytes after a terminator are ordinary — it is the
        // reason PNG and JPEG beside this use containment — and the same argument was simply not
        // made for GIF.
        //
        // Containment can only ever produce a FALSE WHOLE, never a false cut. For a SECOND
        // defence standing behind a pattern, that is the correct direction to fail in: a leak
        // costs one truncated file that the pattern should have caught, and a false cut costs a
        // working image on a build that already hosts it.
        if (substr($bytes, 0, 4) === 'GIF8') {
            // A BOUNDED TAIL, not the whole file. Plain containment was measured at 95% BLIND on
            // real GIFs — 192 of 202 cut points accepted on a 22 KB image — because a 0x3B turns
            // up by chance almost anywhere in the pixel data. Requiring the LAST 0x3B to fall in
            // the final 16 bytes keeps the direction (a whole GIF, with or without a few bytes of
            // padding, still passes) and takes the blindness from ~95% to roughly the chance of a
            // 0x3B landing in 16 bytes of noise.
            //
            // What it gives up: a GIF carrying MORE than 15 bytes of trailing data is called
            // truncated and left inline. That is fail-safe — the page renders from the data: URI —
            // and it is the trade this branch has now been round twice, so it is written down
            // rather than rediscovered.
            $last = strrpos($bytes, chr(59));
            return ($last === false) || ($last < $len - 16);
        }
        // WEBP and ICO both DECLARE THEIR OWN SIZE, the same kind of field the WOFF branch reads.
        // These two plus GIF are exactly the formats WordPress accepts by default, so leaving them
        // unjudged left the second defence covering the formats least likely to arrive.
        //
        // RIFF....WEBP: bytes 4-7 are a LITTLE-endian uint32 holding (file size - 8).
        if (substr($bytes, 0, 4) === 'RIFF' && $len >= 12 && substr($bytes, 8, 4) === 'WEBP') {
            $declared = ord($bytes[4]) | (ord($bytes[5]) << 8)
                | (ord($bytes[6]) << 16) | (ord($bytes[7]) << 24);
            // GREATER THAN, not "not equal to". A file SHORTER than it declares is truncated; a
            // file longer has trailing bytes, which is not the same thing and which the deployed
            // build hosts. Review found three whole WEBPs refused by the equality test.
            return ($declared + 8) > $len;
        }
        // BMP declares its total size as a little-endian uint32 at offset 2, and WordPress allows
        // `bmp` by default. It was judged by nothing: 0 of 200 cut points caught.
        if (substr($bytes, 0, 2) === 'BM' && $len >= 6) {
            $declared = ord($bytes[2]) | (ord($bytes[3]) << 8)
                | (ord($bytes[4]) << 16) | (ord($bytes[5]) << 24);
            if ($declared > 0) { return $declared > $len; }
        }
        // ICO/CUR: a 6-byte header (reserved, type 1 or 2, count), then 16-byte directory entries
        // whose last two little-endian uint32s are the image's size and its offset in the file.
        if ($len >= 6 && ord($bytes[0]) === 0 && ord($bytes[1]) === 0
            && (ord($bytes[2]) === 1 || ord($bytes[2]) === 2) && ord($bytes[3]) === 0) {
            $count = ord($bytes[4]) | (ord($bytes[5]) << 8);
            if ($count < 1 || $count > 64) { return false; }   // not an icon we can judge
            if ($len < 6 + (16 * $count)) { return true; }     // the directory itself is cut off
            for ($i = 0; $i < $count; $i++) {
                $e = 6 + (16 * $i);
                $size = ord($bytes[$e + 8]) | (ord($bytes[$e + 9]) << 8)
                    | (ord($bytes[$e + 10]) << 16) | (ord($bytes[$e + 11]) << 24);
                $off = ord($bytes[$e + 12]) | (ord($bytes[$e + 13]) << 8)
                    | (ord($bytes[$e + 14]) << 16) | (ord($bytes[$e + 15]) << 24);
                if ($off + $size > $len) { return true; }
            }
            return false;
        }
        // NO WOFF BRANCH. There was one, and it was UNREACHABLE: this function is called only
        // from `host_embedded_images`, whose pattern matches `data:image/…`, and a contrived
        // `data:image/woff2` is refused by `wp_check_filetype` before it could arrive. Review
        // ablated it and reddened nothing — a branch no ablation can redden is exactly the shape
        // this file has already shipped four times, so it is deleted rather than left to read as
        // coverage. The font path judges its own fonts in `looks_like_woff`.
        return false; // not a format we can judge — treat as whole, exactly as today
    }

    private static function looks_like_sfnt($bytes) {
        $len = strlen($bytes);
        if ($len < 12) { return false; }
        $numTables = (ord($bytes[4]) << 8) | ord($bytes[5]);
        if ($numTables < 1 || $numTables > 512) { return false; }
        if ($len < 12 + (16 * $numTables)) { return false; }

        // AND EVERY TABLE MUST FIT. Checking only that the DIRECTORY fits was review's third
        // HIGH: a real 964-byte TTF truncated to 300 bytes still has a plausible directory in
        // its first 92, so it passed, and a 300-byte "font" was written to the Media Library
        // with the leftover base64 glued onto the url(). The commit that added the check even
        // claimed a truncated font could not get through here, which was true of the WOFF
        // branch — where the header declares a total length — and false of this one.
        //
        // Each directory entry is tag(4) checksum(4) offset(4) length(4). A font that arrived
        // whole has every table inside the file; a truncated one points past the end.
        for ($i = 0; $i < $numTables; $i++) {
            $e = 12 + (16 * $i);
            $offset = (ord($bytes[$e + 8]) << 24) | (ord($bytes[$e + 9]) << 16)
                | (ord($bytes[$e + 10]) << 8) | ord($bytes[$e + 11]);
            $length = (ord($bytes[$e + 12]) << 24) | (ord($bytes[$e + 13]) << 16)
                | (ord($bytes[$e + 14]) << 8) | ord($bytes[$e + 15]);
            if ($offset < 0 || $length < 0 || $offset + $length > $len) { return false; }
        }
        return true;
    }

    /**
     * Is this a WOFF or WOFF2 container, structurally — not just by its first four characters?
     *
     * Both formats put the TOTAL FILE LENGTH as a big-endian uint32 at offset 8 (W3C WOFF 1.0 §3,
     * WOFF2 §4) and `numTables` as a uint16 at offset 12. The length is the load-bearing check:
     * it is a value prose cannot satisfy by accident, and — unlike a magic-number test — it is
     * FALSE for a font that arrived truncated, which is exactly what a payload cut short by the
     * matcher produces. So this rejects both of the defects review found, from one field.
     *
     * Exactly equal, not "at least": a WOFF says how long it is, and a file that disagrees with
     * its own header is not one we should be writing into someone's Media Library.
     */
    private static function looks_like_woff($bytes) {
        if (strlen($bytes) < 44) { return false; } // WOFF's own header is 44 bytes
        $declared = (ord($bytes[8]) << 24) | (ord($bytes[9]) << 16)
            | (ord($bytes[10]) << 8) | ord($bytes[11]);
        if ($declared !== strlen($bytes)) { return false; }
        $numTables = (ord($bytes[12]) << 8) | ord($bytes[13]);
        return $numTables >= 1 && $numTables <= 512;
    }

    /**
     * Decompose converter-emitted standalone SVG icons into native Elementor Image widgets.
     *
     * The converter's `svgIcons` option rewrites each standalone `<svg>` icon HTML widget into an
     * Image widget whose settings carry a first-pass-sanitized SVG in a `__hte_svg` field (and an
     * empty image url/id). Here we finish the job at import time:
     *   • ENABLED (filter `htel_import_svg_icons` true): re-sanitize the SVG (Elementor's own vetted
     *     sanitizer when available, else a strict fallback), write it to the Media Library, and set
     *     the Image widget's url/id → a native, editable, vector Image.
     *   • DISABLED (default) or on any failure: rewrite the widget back into an HTML widget holding
     *     the inline SVG, so it renders exactly like the pre-decomposition output — never blank,
     *     never a dangling empty Image, no lock-in.
     *
     * Uploading a real SVG is a security surface, so it is OFF by default and is the site owner's
     * explicit opt-in (`add_filter('htel_import_svg_icons', '__return_true')`). Even enabled, every
     * payload is re-sanitized here regardless of the converter's first pass (defense in depth).
     *
     * @param array $content  Elementor content array ($template['content'])
     * @param int   $post_id  Parent post — uploaded SVGs are attached to it
     * @return array          Content with __hte_svg widgets resolved to Image (or HTML fallback)
     */
    private static function import_svg_icons($content, $post_id) {
        // RESET BEFORE ANY EXIT. These were reset further down, past the `no __hte_svg` fast-path
        // return — so a page with no icons at all kept the PREVIOUS conversion's count and reported
        // icons it never had. Caught by this file's own control, which is the whole reason it holds
        // one. Every early return below is now covered.
        self::$svg_held = 0;
        self::$svg_write = 0;
        if (!is_array($content)) {
            return $content;
        }
        $json = wp_json_encode($content);
        if ($json === false || strpos($json, '__hte_svg') === false) {
            return $content; // no decomposed icons — fast path
        }
        // STILL OFF BY DEFAULT — both known defects are fixed, but the acceptance gate has not run.
        // SECURITY: solved. The sanitizer is a geometry ALLOWLIST (svg_geometry_allowlist + the
        //   converter's sanitizeSvgAllowlist), verified on 742 corpus icons and 13 attack vectors.
        // SIZING: fixed (2026-07-31). A hosted icon used to take its file's viewBox size instead of
        //   the size the page gave it; the converter now carries the COMPUTED size onto the file,
        //   re-measured across the corpus (sienna 44/44, maquette 3/3, eclipsemarketing 9/9).
        // What is missing is the visual-qa-reviewer PASS that failed this feature last time —
        // measuring payload sizes is not that gate. Flip the default only once the reviewer passes.
        // Opt in meanwhile with: add_filter('htel_import_svg_icons', '__return_true').
        $enabled = (bool) apply_filters('htel_import_svg_icons', false, $post_id);
        $allow_svg = null;
        if ($enabled) {
            if (!function_exists('wp_upload_bits')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            // Permit an `.svg` filename through wp_upload_bits (which runs wp_check_filetype) for the
            // DURATION of this import only — scoped so we never flip on SVG uploads site-wide. Our
            // payloads are re-sanitized above; this just lets the write land.
            $allow_svg = function ($mimes) {
                $mimes['svg'] = 'image/svg+xml';
                return $mimes;
            };
            add_filter('upload_mimes', $allow_svg);
        }
        $counter = 0;
        // #152: the SVG fallback used to write the RAW payload when the sanitiser rejected it.
        $svgdropped = 0;
        // The HTML fallback was SILENT. An icon that could have been an editable Image widget went
        // out as inline markup and nothing said so — which is how a page arrives with fifteen raw
        // HTML widgets and the customer concludes the conversion is poor. Counted by CAUSE, because
        // only one of the two is something they can act on.
        $svgheld = 0;   // native icon import is switched off on this site
        $svgwrite = 0;  // it is switched ON, but the file could not be written
        $walk = function (&$els) use (&$walk, &$counter, $post_id, $enabled, &$svgdropped, &$svgheld, &$svgwrite) {
            if (!is_array($els)) {
                return;
            }
            $drop_keys = array();
            foreach ($els as $key => &$el) {
                if (is_array($el)
                    && isset($el['widgetType']) && $el['widgetType'] === 'image'
                    && isset($el['settings']['__hte_svg']) && is_string($el['settings']['__hte_svg'])
                    && $el['settings']['__hte_svg'] !== ''
                ) {
                    $svg = self::sanitize_svg_payload($el['settings']['__hte_svg']);
                    $wired = false;
                    if ($enabled && $svg !== '') {
                        $counter++;
                        $upload = wp_upload_bits('htel-svg-' . $post_id . '-' . $counter . '.svg', null, $svg);
                        if (empty($upload['error']) && !empty($upload['url']) && !empty($upload['file'])) {
                            $attach_id = wp_insert_attachment(array(
                                'guid'           => $upload['url'],
                                'post_mime_type' => 'image/svg+xml',
                                'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                                'post_status'    => 'inherit',
                            ), $upload['file'], $post_id);
                            if (!is_wp_error($attach_id)) {
                                if (function_exists('wp_generate_attachment_metadata')) {
                                    @wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));
                                }
                                $el['settings']['image'] = array('url' => $upload['url'], 'id' => $attach_id, 'source' => 'library');
                                unset($el['settings']['__hte_svg']);
                                $wired = true;
                            }
                        }
                    }
                    if (!$wired) {
                        // Fallback: render the inline SVG as an HTML widget (identical to pre-decompose
                        // output). Drop the empty Image url/id + payload so nothing dangles.
                        $el['widgetType'] = 'html';
                        $keep = array();
                        foreach (array('_css_classes', '_element_width', 'motion_fx_motion_fx_scrolling') as $k) {
                            if (isset($el['settings'][$k])) {
                                $keep[$k] = $el['settings'][$k];
                            }
                        }
                        // NEVER THE RAW PAYLOAD. This fell back to `__hte_svg` verbatim when the
                        // sanitiser returned nothing — so the one input the safety filter rejected
                        // outright was the one input written into the page unfiltered, inverting
                        // the defence the filter exists to provide. `svg_geometry_allowlist` returns
                        // '' when the bytes carry no matched `<svg>...</svg>` pair, which is a
                        // TRUNCATED SVG, and it also returns '' for the shapes it strips to nothing.
                        // Pre-existing since 2026-07-22 and flagged by review; the widget is dropped
                        // now instead, and the customer is told.
                        if ($svg === '') {
                            $svgdropped++;
                            $drop_keys[] = $key;
                            continue;
                        }
                        // Reached the HTML fallback with a usable icon: say WHY, so the notice can
                        // be acted on rather than merely noticed.
                        if ($enabled) { $svgwrite++; } else { $svgheld++; }
                        $keep['html'] = $svg;
                        $el['settings'] = $keep;
                    }
                }
                if (isset($el['elements']) && is_array($el['elements'])) {
                    $walk($el['elements']);
                }
            }
            unset($el);
            // PRUNE AFTER THE LOOP, NOT INSIDE IT. This walk iterates BY REFERENCE, so an
            // early `return` would abandon the rest of the level rather than drop one widget —
            // which is what my first attempt did. Collect the keys, then remove and reindex.
            foreach ($drop_keys as $k) { unset($els[$k]); }
            if (!empty($drop_keys)) { $els = array_values($els); }
        };
        $walk($content);
        if ($allow_svg !== null) {
            remove_filter('upload_mimes', $allow_svg); // scope the SVG allowance to this import only
        }
        self::report_drops(array('svgdropped' => $svgdropped, 'svgheld' => $svgheld,
                                 'svgwrite' => $svgwrite));
        self::$svg_held  = $svgheld;
        self::$svg_write = $svgwrite;
        return $content;
    }

    /**
     * Strict second-pass SVG sanitizer (defense in depth over the converter's first pass). Prefers
     * Elementor's own vetted sanitizer when the class is available; otherwise strips the script-
     * execution surfaces by hand: <script>, event-handler attributes, <foreignObject>, and
     * `javascript:`/external `href`/`xlink:href` (local `#` refs are kept). Returns '' if the input
     * doesn't look like an <svg> after cleaning (caller then uses the HTML fallback).
     */
    private static function sanitize_svg_payload($svg) {
        $svg = (string) $svg;
        $svg_class = '\\Elementor\\Core\\Files\\File_Types\\Svg';
        // Elementor's `sanitizer()` is an INSTANCE method; `file_sanitizer_can_run()` is a static
        // guard (needs the DOM/libxml surface). Use it when it can run — it is the vetted layer.
        if (class_exists($svg_class)
            && (!method_exists($svg_class, 'file_sanitizer_can_run') || call_user_func(array($svg_class, 'file_sanitizer_can_run')))
            && method_exists($svg_class, 'sanitizer')
        ) {
            $clean = ( new $svg_class() )->sanitizer($svg);
            if (is_string($clean) && stripos($clean, '<svg') !== false) {
                return $clean;
            }
        }
        // Fallback: GEOMETRY ALLOWLIST (mirrors the converter's sanitizeSvgAllowlist).
        // Rebuild the icon from the elements/attributes an icon actually needs and drop everything
        // else BY CONSTRUCTION — an allowlist has no "vector we didn't think of", unlike the
        // previous blocklist. Verified against 742 real corpus icons: 0 emptied, 0 shapes lost.
        return self::svg_geometry_allowlist($svg);
    }

    /**
     * Rebuild an icon SVG from a geometry allowlist. Anything not listed is dropped; elements whose
     * content IS the payload take their subtree with them, while other unlisted wrappers are merely
     * unwrapped (their children still face the allowlist), so a linked icon keeps its shapes.
     *
     * @param string $svg  raw SVG markup
     * @return string      safe SVG, or '' if nothing usable survived
     */
    private static function svg_geometry_allowlist($svg) {
        if (!preg_match('#<svg\b[\s\S]*</svg\s*>#i', (string) $svg, $m)) { return ''; }
        $doc = $m[0];

        $allowed_els = array('svg','g','title','desc','path','circle','ellipse','rect','line',
            'polyline','polygon','defs','lineargradient','radialgradient','stop','clippath','mask','text','tspan');
        $drop_subtree = array('script','style','foreignobject','iframe','object','embed','audio','video',
            'animate','animatetransform','animatemotion','set','handler','listener','use','image','link','meta','filter');
        $allowed_attrs = array('xmlns','viewbox','width','height','preserveaspectratio','transform','version',
            'id','class','d','cx','cy','r','rx','ry','x','y','x1','y1','x2','y2','dx','dy','points','pathlength',
            'fill','fill-opacity','fill-rule','clip-rule','clip-path','mask','opacity','color',
            'stroke','stroke-width','stroke-linecap','stroke-linejoin','stroke-miterlimit',
            'stroke-dasharray','stroke-dashoffset','stroke-opacity','stop-color','stop-opacity','offset',
            'gradientunits','gradienttransform','spreadmethod','fr','clippathunits','maskunits','maskcontentunits',
            'vector-effect','shape-rendering','paint-order','style',
            'font-family','font-size','font-weight','font-style','text-anchor','dominant-baseline','letter-spacing',
            'role','aria-hidden','aria-label','focusable');
        $tag_case  = array('lineargradient' => 'linearGradient', 'radialgradient' => 'radialGradient', 'clippath' => 'clipPath');
        $attr_case = array('viewbox' => 'viewBox', 'preserveaspectratio' => 'preserveAspectRatio',
            'gradientunits' => 'gradientUnits', 'gradienttransform' => 'gradientTransform',
            'spreadmethod' => 'spreadMethod', 'clippathunits' => 'clipPathUnits',
            'maskunits' => 'maskUnits', 'maskcontentunits' => 'maskContentUnits', 'pathlength' => 'pathLength');

        $value_safe = function ($v) {
            if (preg_match('#[<>]#', $v)) { return false; }
            if (preg_match('#javascript:|vbscript:|data:#i', $v)) { return false; }
            if (preg_match('#expression\s*\(|behavior\s*:|@import#i', $v)) { return false; }
            if (preg_match_all('#url\s*\(([^)]*)\)#i', $v, $mm)) {
                foreach ($mm[1] as $inner) {
                    $inner = trim(trim($inner), "'\"");
                    if (strpos($inner, '#') !== 0) { return false; }
                }
            }
            return true;
        };

        $out = '';
        $skip_depth = 0;
        $skip_tag = '';
        $offset = 0;
        if (!preg_match_all('#</?\s*([a-zA-Z][\w:.-]*)((?:"[^"]*"|\'[^\']*\'|[^>])*?)(/?)>#', $doc, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return '';
        }
        foreach ($tags as $t) {
            $full = $t[0][0];
            $name = strtolower($t[1][0]);
            $raw_attrs = $t[2][0];
            $self_close = ($t[3][0] === '/');
            $is_close = (strpos($full, '</') === 0);
            $pos = $t[0][1];

            if (!$skip_depth) {
                $text = substr($doc, $offset, $pos - $offset);
                if (trim($text) !== '') { $out .= str_replace(array('<', '>'), '', $text); }
            }
            $offset = $pos + strlen($full);

            if ($skip_depth) {
                if (!$is_close && $name === $skip_tag && !$self_close) { $skip_depth++; }
                elseif ($is_close && $name === $skip_tag) { $skip_depth--; }
                continue;
            }
            if (!in_array($name, $allowed_els, true)) {
                if (in_array($name, $drop_subtree, true) && !$is_close && !$self_close) {
                    $skip_depth = 1; $skip_tag = $name;
                }
                continue;
            }
            $tag_out = isset($tag_case[$name]) ? $tag_case[$name] : $name;
            if ($is_close) { $out .= '</' . $tag_out . '>'; continue; }

            $kept = array();
            if (preg_match_all('#([a-zA-Z_:][\w:.-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#', $raw_attrs, $as, PREG_SET_ORDER)) {
                foreach ($as as $a) {
                    $key = strtolower($a[1]);
                    $val = isset($a[3]) && $a[3] !== '' ? $a[3] : (isset($a[4]) && $a[4] !== '' ? $a[4] : (isset($a[5]) ? $a[5] : ''));
                    if (!in_array($key, $allowed_attrs, true)) { continue; }
                    if ($key === 'style') {
                        $decls = array();
                        foreach (explode(';', $val) as $d) {
                            $d = trim($d);
                            if ($d !== '' && $value_safe($d)) { $decls[] = $d; }
                        }
                        if (!$decls) { continue; }
                        $val = implode(';', $decls);
                    } elseif (!$value_safe($val)) { continue; }
                    $out_key = isset($attr_case[$key]) ? $attr_case[$key] : $key;
                    $kept[] = $out_key . '="' . str_replace('"', '&quot;', $val) . '"';
                }
            }
            $out .= '<' . $tag_out . ($kept ? ' ' . implode(' ', $kept) : '') . ($self_close ? '/' : '') . '>';
        }

        if (stripos($out, '<svg') === false) { return ''; }
        if (!preg_match('#\bxmlns=#i', $out)) {
            $out = preg_replace('#^<svg\b#i', '<svg xmlns="http://www.w3.org/2000/svg"', $out, 1);
        }
        return trim($out);
    }

    /**
     * Handle ZIP file upload: extract HTML file, import images to Media Library,
     * replace relative image paths in HTML with WordPress media URLs.
     *
     * @param array  $file   $_FILES['zip_file'] data
     * @param string $title  Template title (may be updated from HTML filename)
     * @return array|WP_Error  Array with 'html', 'title', 'imported_images' on success
     */
    private static function handle_zip_upload($file, $title) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'ZIP file upload failed');
        }

        // Defense-in-depth: ensure this really is a PHP-handled upload and
        // the tmp_name wasn't injected via a malicious client. Our caller
        // already sanitized the $_FILES fields, but we verify the path is a
        // real upload handle before opening it.
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('upload_error', 'Invalid upload');
        }

        // 50MB limit for ZIP files
        if ($file['size'] > 50 * 1024 * 1024) {
            return new WP_Error('too_large', 'ZIP file exceeds 50MB limit');
        }

        // Name was already sanitized by caller; this is just the extension check.
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            return new WP_Error('invalid_type', 'Only .zip files are accepted');
        }

        // Check ZipArchive support
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', 'Server does not support ZIP extraction. Please upload an HTML file instead.');
        }

        // DELEGATED SO IT CAN BE TESTED AT ALL. Everything below the upload checks operates on a
        // path, but it lived inside a function guarded by `is_uploaded_file()`, which is true only
        // for a real POST — so 314 lines that write files into a customer's Media Library had never
        // been executed by any test, and that is exactly why they still carried six silent
        // discards. The split changes no behaviour; it makes the behaviour reachable.
        return self::process_zip_archive($file['tmp_name'], $title);
    }

    /**
     * The body of a ZIP import: find the page, sideload its images, inline its stylesheets.
     *
     * Separated from `handle_zip_upload()` only so it can be driven from a test with a real archive
     * on disk. It takes a PATH and trusts the caller to have validated the upload.
     */
    /**
     * Every key a ZIP entry might be reachable by, for one `href`/`src` written in the page.
     *
     * THE ONE THAT WAS MISSING IS THE QUERY STRING. A page carrying
     * `<link rel="stylesheet" href="css/well.css?v=2">` was matched against ZIP entry names with
     * `array($href, ltrim($href, './'))`, so the lookup asked for an entry literally named
     * `css/well.css?v=2`, found nothing, and returned the tag untouched — the customer got a page
     * with no styling at all and no drop was reported, because as far as the routine could tell no
     * stylesheet had been referenced. `?v=`, `?ver=` and `?1234` are what every static-site
     * generator writes to bust a cache, so this is not a rare shape.
     *
     * The bare path is ADDED, never substituted: an absolute URL keeps its query and is offered
     * unchanged, and a bare `?v=2` with no path contributes no empty key — an empty candidate
     * would match the first entry of any lookup that happens to be keyed by the empty string.
     */
    /**
     * Every way a stylesheet at $css_path can spell $target, which is keyed relative to the PAGE.
     *
     * #193. `$path_map` is built from paths relative to the HTML page — `images/a.png` — and the
     * CSS rewrite matches those keys literally inside `url(...)`. A stylesheet is not the page:
     * `css/style.css` addresses that same image as `url(../images/a.png)`, which matches no key,
     * so the reference is left pointing at a file the customer's WordPress does not have. The
     * image sits in the archive they uploaded and does not arrive, and nothing is reported.
     *
     * MEASURED on 51 real customer archives before this was built: `url(../…)` appears in 3 of
     * them, 179 references — against 16 for the cache-busting query string that 1.3.41 shipped
     * for. `srcset` and `@import`, which #193 named first, appear in NONE of the 51, so they are
     * deliberately not built here.
     *
     * The page-relative form is always offered too: these are a SUPERSET, so nothing that
     * resolved before stops resolving.
     */
    /**
     * Point every `url()` in one stylesheet at the Media Library file it now lives in.
     *
     * $path_map is keyed relative to the HTML PAGE. A stylesheet is not the page, so each key is
     * tried in every spelling THIS stylesheet could use (#193) — `images/a.png` becomes
     * `../images/a.png` from `css/style.css`, and `css/bg.png` becomes a bare `bg.png`.
     *
     * The QUERY is dropped and the FRAGMENT is carried: `url(a.png?v=2#gear)` becomes the
     * Media Library URL plus `#gear`. A cache-buster addresses the file we just imported, so it
     * has served its purpose; a fragment SELECTS part of it — an icon out of an SVG sprite — and
     * dropping it would silently change which icon renders (#190).
     */
    private static function rewrite_css_urls($css_content, $css_path, $path_map) {
        if (empty($path_map) || $css_content === '' || $css_content === null) {
            return $css_content;
        }
        foreach ($path_map as $relative_path => $wp_url) {
            foreach (self::css_relative_variants($css_path, $relative_path) as $spelling) {
                $escaped = preg_quote($spelling, '/');
                $css_content = preg_replace(
                    '/(url\s*\(\s*[\'"]?)' . $escaped . '(?:\?[^\'")#]*)?((?:#[^\'")]*)?)([\'"]?\s*\))/i',
                    '${1}' . $wp_url . '${2}${3}',
                    $css_content
                );
            }
        }
        return $css_content;
    }

    private static function css_relative_variants($css_path, $target) {
        $out = array($target);

        $dir = trim(str_replace(chr(92), '/', dirname($css_path)), '/');
        // A stylesheet at the archive root addresses everything exactly as the page does, and
        // must NOT be given a `../` form — that would climb out of what the customer uploaded.
        if ($dir === '' || $dir === '.') {
            return $out;
        }

        $dir_segments = explode('/', $dir);
        $target_segments = explode('/', trim($target, '/'));

        // Drop the directories the two genuinely share. The last target segment is the FILENAME
        // and is never a shared directory, which is what the second bound below protects.
        $shared = 0;
        while ($shared < count($dir_segments)
            && $shared < count($target_segments) - 1
            && $dir_segments[$shared] === $target_segments[$shared]) {
            $shared++;
        }

        $climb = count($dir_segments) - $shared;
        $rest = implode('/', array_slice($target_segments, $shared));
        $relative = str_repeat('../', $climb) . $rest;

        $out[] = $relative;
        // `./../images/a.png` is legal CSS and some generators emit it.
        $out[] = './' . $relative;

        return array_values(array_unique($out));
    }

    private static function asset_href_candidates($href) {
        $out = array($href);
        $trimmed = ltrim($href, './');
        if ($trimmed !== '') { $out[] = $trimmed; }
        $bare = preg_replace('/[?#].*$/', '', $href);
        if ($bare !== '') {
            $out[] = $bare;
            $bare_trimmed = ltrim($bare, './');
            if ($bare_trimmed !== '') { $out[] = $bare_trimmed; }
        }
        return array_values(array_unique(array_filter($out, function ($x) { return $x !== ''; })));
    }

    private static function process_zip_archive($zip_path, $title) {
        // #152. Every one of these was a bare `continue` in a 314-line function that no test had
        // ever executed, on the plugin whose stated lesson is that the defect is the silence.
        $zipunsafe = 0;
        $zipimg = 0;
        $zipcss = 0;
        $ziphtml = 0;
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return new WP_Error('zip_open', 'Could not open ZIP file');
        }

        // SCANNED BY A METHOD THAT NEEDS NO WORDPRESS. The loop below decides which entry is the
        // page, which are images, which are stylesheets, which paths are refused and how many
        // extra pages were ignored — all of it `ZipArchive` and string work. Splitting it out is
        // what lets those decisions be tested when the container is down, which on 2026-08-29
        // meant every gate at once.
        $scan = self::scan_zip_entries($zip);
        $html_content   = $scan['html_content'];
        $html_filename  = $scan['html_filename'];
        $html_dir       = $scan['html_dir'];
        $image_entries  = $scan['image_entries'];
        $css_entries    = $scan['css_entries'];
        $zipunsafe     += $scan['zipunsafe'];
        $ziphtml       += $scan['ziphtml'];

        if (empty($html_content)) {
            $zip->close();
            // REPORT BEFORE BAILING. The emission at the end of this function is unreachable from
            // here, so the one case where `zipunsafe` matters most — a badly-built archive with
            // nothing importable in it — said nothing at all.
            self::report_drops(array('zipunsafe' => $zipunsafe, 'ziphtml' => $ziphtml));
            return new WP_Error('no_html', 'No .html or .htm file found in the ZIP');
        }

        // Update title from HTML filename if not set
        if (empty($title) || $title === 'Imported Page') {
            $title = pathinfo($html_filename, PATHINFO_FILENAME);
        }

        // Import images and build path replacement map
        $imported_images = array();
        $path_map = array(); // relative_path => wordpress_url

        if (!empty($image_entries)) {
            // Need these for media uploads
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            foreach ($image_entries as $img) {
                $image_data = $zip->getFromIndex($img['index']);
                if (empty($image_data)) {
                    $zipimg++;
                    continue;
                }

                // Write to a temp file for WordPress upload
                $tmp_file = wp_tempnam($img['basename']);
                file_put_contents($tmp_file, $image_data);

                // Determine MIME type
                $mime_types = array(
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    'svg'  => 'image/svg+xml',
                    'ico'  => 'image/x-icon',
                    'bmp'  => 'image/bmp',
                    'avif' => 'image/avif',
                );
                $mime = isset($mime_types[$img['ext']]) ? $mime_types[$img['ext']] : 'image/jpeg';

                // Upload to Media Library
                $file_array = array(
                    'name'     => $img['basename'],
                    'type'     => $mime,
                    'tmp_name' => $tmp_file,
                    'error'    => 0,
                    'size'     => strlen($image_data),
                );

                $attachment_id = media_handle_sideload($file_array, 0, $img['basename']);

                // Clean up temp file if sideload failed. Use wp_delete_file()
                // per Plugin Check — it's the WP-blessed wrapper around unlink()
                // with filter hooks for extensions/antivirus plugins.
                if (is_wp_error($attachment_id)) {
                    wp_delete_file($tmp_file);
                    $zipimg++;
                    continue;
                }

                $wp_url = wp_get_attachment_url($attachment_id);
                if (!$wp_url) {
                    // THE ORPHAN GOES WITH IT — BUT NEVER A FILE SOMEONE ELSE OWNS.
                    // `media_handle_sideload` always creates a NEW attachment, so deleting the post
                    // is safe. The FILE is a different question: a media or dedupe plugin can
                    // repoint the new attachment at an EXISTING file, and `wp_delete_attachment($id,
                    // true)` deletes whatever `get_attached_file()` points at by then. Review
                    // planted a customer file, repointed the attachment at it, and measured this
                    // line DELETING IT — the only line in this batch that destroys data, destroying
                    // the wrong thing.
                    //
                    // The rule that actually protects: delete the file only when no OTHER
                    // attachment claims it. A repointed file necessarily has its own attachment, so
                    // this refuses exactly the case review built, and still cleans up the ordinary
                    // one where the file is ours alone.
                    $rel = get_post_meta($attachment_id, '_wp_attached_file', true);
                    $shared = 0;
                    if ($rel !== '') {
                        global $wpdb;
                        $shared = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
                            . " AND meta_value = %s AND post_id <> %d",
                            $rel,
                            $attachment_id
                        ));
                    }
                    if ($shared > 0) {
                        // DETACH BEFORE DELETING. `wp_delete_attachment($id, false)` does NOT mean
                        // "delete the post and keep the file" — there is no such option; whenever it
                        // deletes the post it deletes the files too. Review's assertion caught this
                        // version of the fix still destroying the planted file. Clearing the file
                        // meta first leaves it nothing to delete, so the post goes and the file that
                        // belongs to someone else stays.
                        delete_post_meta($attachment_id, '_wp_attached_file');
                        delete_post_meta($attachment_id, '_wp_attachment_metadata');
                    }
                    wp_delete_attachment($attachment_id, true);
                    $zipimg++;
                    continue;
                }

                $imported_images[] = array(
                    'original_path' => $img['path'],
                    'wp_url'        => $wp_url,
                    'attachment_id' => $attachment_id,
                );

                // Build all possible relative path variations that the HTML might use
                $img_path = $img['path'];

                // 1. Full path as-is (e.g., "website/assets/photo.jpg")
                $path_map[$img_path] = $wp_url;

                // 2. Relative to the HTML file's directory
                if (!empty($html_dir) && strpos($img_path, $html_dir . '/') === 0) {
                    $relative = substr($img_path, strlen($html_dir) + 1);
                    $path_map[$relative] = $wp_url;
                    $path_map['./' . $relative] = $wp_url;
                }

                // 3. Just the basename (e.g., "photo.jpg")
                $path_map[$img['basename']] = $wp_url;

                // 4. Common subfolder patterns (assets/, images/, img/)
                $parent_dir = dirname($img_path);
                $parent_basename = basename($parent_dir);
                if (!empty($parent_basename) && $parent_basename !== '.') {
                    $path_map[$parent_basename . '/' . $img['basename']] = $wp_url;
                    $path_map['./' . $parent_basename . '/' . $img['basename']] = $wp_url;
                }
            }
        }

        // Sort path_map by length descending so longest matches replace first
        // (used by both CSS-content rewrite and HTML-attribute rewrite below).
        if (!empty($path_map)) {
            uksort($path_map, function($a, $b) {
                return strlen($b) - strlen($a);
            });
        }

        // Inline CSS files into the HTML. Two-step:
        //   (a) Read each CSS, rewrite its url() refs using path_map so
        //       images sideloaded above are reachable from the inlined CSS.
        //   (b) Replace each <link rel="stylesheet" href="..."> tag in the
        //       HTML with a <style>...</style> block containing the rewritten
        //       CSS. Skip absolute URLs (Google Fonts, CDNs) — they stay as
        //       <link> tags so the API/browser can fetch them normally.
        $css_lookup = array(); // path-variation => rewritten CSS body
        if (!empty($css_entries)) {
            foreach ($css_entries as $css) {
                $css_content = $zip->getFromIndex($css['index']);
                if ($css_content === false) {
                    $zipcss++;
                    continue;
                }
                if ($css_content === '') {
                    // AN EMPTY STYLESHEET IS NOT AN UNREADABLE ONE. A zero-byte `reset.css` is
                    // ordinary, nothing is lost by skipping it, and telling the customer their
                    // styles are missing would be a false alarm — which costs more than silence.
                    continue;
                }

                // (a) Rewrite url(...) inside the CSS using image path_map. One function, so a
                //     test can drive it without WordPress, ZipArchive or a filesystem — the end
                //     to end path needs all three and could not verify #193 on its own.
                $css_content = self::rewrite_css_urls($css_content, $css['path'], $path_map);

                // Build all path variations the <link href> might use
                $css_path = $css['path'];
                $variations = array($css_path, $css['basename']);
                if (!empty($html_dir) && strpos($css_path, $html_dir . '/') === 0) {
                    $rel = substr($css_path, strlen($html_dir) + 1);
                    $variations[] = $rel;
                    $variations[] = './' . $rel;
                }
                $parent_basename = basename(dirname($css_path));
                if (!empty($parent_basename) && $parent_basename !== '.') {
                    $variations[] = $parent_basename . '/' . $css['basename'];
                    $variations[] = './' . $parent_basename . '/' . $css['basename'];
                }
                foreach (array_unique($variations) as $var) {
                    $css_lookup[$var] = $css_content;
                }
            }
        }

        $zip->close();

        // (b) Replace <link rel="stylesheet" href="..."> with <style>...</style>
        // for any href whose path matches an extracted CSS file.
        if (!empty($css_lookup)) {
            $html_content = preg_replace_callback(
                '/<link\b[^>]*>/i',
                function ($m) use ($css_lookup) {
                    $tag = $m[0];
                    // Must be rel="stylesheet" (skip preload, prefetch, icon, etc.)
                    if (!preg_match('/\brel\s*=\s*["\']?stylesheet["\']?/i', $tag)) {
                        return $tag;
                    }
                    // Extract href
                    if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $h)) {
                        return $tag;
                    }
                    $href = $h[1];
                    // Skip absolute URLs (Google Fonts, CDNs, etc.) — they can
                    // be fetched normally and shouldn't be inlined.
                    if (preg_match('#^(https?:)?//#i', $href)) {
                        return $tag;
                    }
                    // Try the href as-is and with leading "./" stripped
                    $candidates = self::asset_href_candidates($href);
                    foreach ($candidates as $c) {
                        if (isset($css_lookup[$c])) {
                            return "<style data-htel-inlined=\"" . esc_attr($href) . "\">\n" . $css_lookup[$c] . "\n</style>";
                        }
                    }
                    return $tag;
                },
                $html_content
            );
        }

        // Replace relative image paths in HTML with WordPress URLs
        if (!empty($path_map)) {
            foreach ($path_map as $relative_path => $wp_url) {
                // Replace in src="...", url('...'), url("..."), and url(...)
                $escaped = preg_quote($relative_path, '/');

                // src="path" and href="path" and poster="path"
                //
                // THE OPTIONAL SUFFIX IS THE POINT. Requiring the attribute to end right after
                // the path meant src="images/a.jpg?v=2" matched nothing and kept pointing at a
                // file the customer's WordPress does not have -- the same cache-buster that was
                // losing whole stylesheets (#190). Both url() sites get it too, because a
                // url("img/a.png?v=2") inside the inlined stylesheet fails the same way.
                //
                // THE QUERY IS DROPPED AND THE FRAGMENT IS CARRIED, and the difference is not
                // pedantry. `?v=2` VERSIONS a file, and what replaces it is a different file in
                // the Media Library, so the version means nothing there. `#gear` SELECTS one --
                // it names which symbol of an SVG sprite to draw, or which region of a raster.
                // Dropping it renders the whole sprite sheet at full size where an icon belonged,
                // which is a worse outcome than the 404 this fix was written to remove, and it
                // looks like an engine bug rather than a missing file. Found by review.
                $html_content = preg_replace(
                    '/((?:src|href|poster|data-src|data-bg)\s*=\s*["\'])' . $escaped . '(?:\?[^"\'#]*)?((?:#[^"\']*)?)(["\'])/i',
                    '${1}' . $wp_url . '${2}${3}',
                    $html_content
                );

                // url('path'), url("path"), url(path) in CSS
                $html_content = preg_replace(
                    '/(url\s*\(\s*[\'"]?)' . $escaped . '(?:\?[^\'")#]*)?((?:#[^\'")]*)?)([\'"]?\s*\))/i',
                    '${1}' . $wp_url . '${2}${3}',
                    $html_content
                );
            }
        }

        self::report_drops(array('zipunsafe' => $zipunsafe, 'zipimg' => $zipimg,
                                 'zipcss' => $zipcss, 'ziphtml' => $ziphtml));

        return array(
            'html'            => $html_content,
            'title'           => $title,
            'imported_images' => $imported_images,
        );
    }

    /**
     * AJAX handler for feedback reports ("Report a problem" button).
     */
    public static function ajax_feedback() {
        check_ajax_referer('htel_convert_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => esc_html__('Permission denied', 'aitoel-html-importer')));
        }

        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        if (empty($description)) {
            wp_send_json_error(array('message' => esc_html__('Please describe the problem', 'aitoel-html-importer')));
        }

        // Retrieve the HTML from the transient (stored during conversion)
        $html = get_transient('htel_last_conversion_html');
        if (empty($html)) {
            wp_send_json_error(array('message' => esc_html__('No recent conversion found. Please convert a page first, then report.', 'aitoel-html-importer')));
        }

        $api_url = HTEL_License::get_api_url() . '/api/feedback';
        $domain = HTEL_License::get_domain();

        $response = wp_remote_post($api_url, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'html'        => $html,
                'description' => $description,
                'domain'      => $domain,
            )),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                /* translators: %s: technical error message from WordPress HTTP API */
                'message' => sprintf(esc_html__('Could not send feedback: %s', 'aitoel-html-importer'), $response->get_error_message()),
            ));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success'])) {
            wp_send_json_error(array('message' => isset($body['error']) ? $body['error'] : esc_html__('Failed to send feedback', 'aitoel-html-importer')));
        }

        // Clear the transient after successful report
        delete_transient('htel_last_conversion_html');

        wp_send_json_success(array('message' => esc_html__('Thank you! Your feedback helps us improve the converter.', 'aitoel-html-importer')));
    }

    /**
     * Build a diagnostic error message for connection failures.
     *
     * The default WordPress error message for blocked outbound HTTP is
     * misleading: "A valid URL was not provided" actually means a security
     * plugin's `pre_http_request` filter rejected the request, not that the
     * URL is malformed. We do three things to produce actionable diagnostics:
     *
     * 1. Run a tiny GET against /api/health from the SAME WordPress install.
     *    If that succeeds (200), the convert request specifically failed —
     *    points at body-size or timeout-based filters (SG Security typical).
     *    If that also fails, outbound HTTPS is fully blocked.
     *
     * 2. Detect known security/optimisation plugins that intercept HTTP
     *    (SG Security, LiteSpeed Cache, Wordfence, iThemes Security, Solid
     *    Security, MalCare). Mention the active one by name with targeted
     *    fix steps.
     *
     * 3. Mention WP_HTTP_BLOCK_EXTERNAL constant if defined.
     */
    private static function build_connection_error_diagnostic($err, $code, $api_url, $request_body_size) {
        // Probe 0: is the API URL the customer is sending to actually well-formed?
        // The v1.3.14 customer bug: a stored htel_api_url option of '' produces a
        // bare-path URL that wp_remote_post rejects with "A valid URL was not provided"
        // / "Non è stato fornito un URL valido". The old diagnostic blamed the
        // firewall (because /api/health probe ALSO uses the broken URL → also fails
        // → "fully blocked"). Now we check the URL itself first.
        $url_is_malformed = empty($api_url)
            || !is_string($api_url)
            || !preg_match('#^https?://[^\s/]+#i', $api_url)
            || !filter_var($api_url, FILTER_VALIDATE_URL);
        // Cross-check the error message: WP returns the same code (http_request_failed)
        // for both invalid URL and outbound block, but distinct error text.
        $err_text = is_string($err) ? strtolower($err) : '';
        $err_indicates_invalid_url = strpos($err_text, 'valid url') !== false
            || strpos($err_text, 'url valid') !== false      // Italian: "URL valido"
            || strpos($err_text, 'url valide') !== false     // French: "URL valide"
            || strpos($err_text, 'gültig') !== false         // German: "gültig"
            || strpos($err_text, 'no se proporcion') !== false; // Spanish

        if ($url_is_malformed || $err_indicates_invalid_url) {
            $diag  = "Could not connect to conversion service.\n\n";
            $diag .= "Technical error: " . $err . " (code: " . $code . ")\n";
            $diag .= "Configured API URL: " . ($api_url === '' ? '(empty)' : $api_url) . "\n\n";
            $diag .= "DIAGNOSIS: the API URL stored in your plugin settings is empty or\n";
            $diag .= "  malformed. WordPress rejected the conversion request because the\n";
            $diag .= "  URL has no scheme/host. THIS IS NOT A FIREWALL OR SECURITY ISSUE.\n\n";
            $diag .= "FIX (1 minute, no plugin update needed):\n";
            $diag .= "  1. WordPress admin → AI to Elementor → Settings\n";
            $diag .= "  2. Find the \"API URL\" field\n";
            $diag .= "  3. Set it to: " . HTEL_API_URL . "\n";
            $diag .= "  4. Save Changes\n";
            $diag .= "  5. Retry the conversion — it will work.\n\n";
            $diag .= "Plugin v1.3.15+ auto-corrects this: invalid URL is detected and the\n";
            $diag .= "default is used automatically. Update the plugin to avoid this in future.\n\n";
            $diag .= "Still stuck? Email support@aitoelementor.com with this entire message.";
            return $diag;
        }

        // Probe 1: tiny GET against /api/health to see if outbound HTTPS works at all
        $health_url = HTEL_License::get_api_url() . '/api/health';
        $health = wp_remote_get($health_url, array('timeout' => 8));
        $health_works = !is_wp_error($health) && wp_remote_retrieve_response_code($health) === 200;

        // Probe 2: detect known security/optimisation plugins that filter HTTP
        $known_filters = array(
            'sg-security/sg-security.php'                    => array('SG Security',         'Site Tools → Security → Outgoing Connections — add api.aitoelementor.com to the allowlist'),
            'sg-security/index.php'                          => array('SG Security',         'Site Tools → Security → Outgoing Connections — add api.aitoelementor.com to the allowlist'),
            'sg-cachepress/sg-cachepress.php'                => array('SG Optimizer',        'SG Optimizer → Misc → Disable "Block requests to external resources" or whitelist api.aitoelementor.com'),
            'litespeed-cache/litespeed-cache.php'            => array('LiteSpeed Cache',     'LiteSpeed Cache → Toolbox → Heartbeat → disable Heartbeat throttling, OR temporarily deactivate the plugin during conversion'),
            'wordfence/wordfence.php'                        => array('Wordfence',           'Wordfence → Firewall → All Firewall Options → Allowlisted URLs — add /wp-admin/admin-ajax.php with parameter "html"'),
            'better-wp-security/better-wp-security.php'      => array('iThemes Security',    'iThemes Security → Settings → Security Check — pause; OR Solid Security → Lockouts → exempt your IP'),
            'ithemes-security-pro/ithemes-security-pro.php'  => array('iThemes Security Pro','iThemes Security → Settings — disable "Block tabnabbing" / "Limit external requests"'),
            'solid-security/solid-security.php'              => array('Solid Security',      'Solid Security → Settings → exempt api.aitoelementor.com from outbound restrictions'),
            'solid-security-pro/solid-security-pro.php'      => array('Solid Security Pro',  'Solid Security → Settings → exempt api.aitoelementor.com'),
            'malcare-security/malcare.php'                   => array('MalCare',             'MalCare → Site Settings — disable Auto-Block during conversion'),
            'wpvivid-backuprestore/wpvivid-backuprestore.php'=> array('WPvivid',             '(unlikely culprit, but bundles HTTP filters — try deactivating during conversion)'),
        );
        $detected = array();
        if (function_exists('get_option')) {
            $active = get_option('active_plugins', array());
            // Multisite: also network-active
            if (function_exists('get_site_option')) {
                $network = get_site_option('active_sitewide_plugins', array());
                if (is_array($network)) $active = array_merge($active, array_keys($network));
            }
            foreach ($known_filters as $slug => $info) {
                if (in_array($slug, $active, true)) $detected[] = $info;
            }
        }

        $diag = "Could not connect to conversion service.\n\n";
        $diag .= "Technical error: " . $err . " (code: " . $code . ")\n";
        $diag .= "Request body size: " . round($request_body_size / 1024, 1) . " KB\n\n";

        // Most actionable diagnosis first
        if ($health_works) {
            $diag .= "DIAGNOSIS: outbound HTTPS to api.aitoelementor.com IS working — a small\n";
            $diag .= "  /api/health request just succeeded from your server. The conversion\n";
            $diag .= "  request specifically is being blocked, almost certainly by a security\n";
            $diag .= "  plugin's request-body filter (large payload, longer timeout).\n\n";
        } else {
            $diag .= "DIAGNOSIS: outbound HTTPS to api.aitoelementor.com is fully blocked\n";
            $diag .= "  on your server. Even a tiny /api/health request fails.\n\n";
        }

        if (!empty($detected)) {
            $diag .= "DETECTED SECURITY PLUGIN" . (count($detected) > 1 ? "S" : "") . ":\n";
            foreach ($detected as $info) {
                list($name, $fix) = $info;
                $diag .= "  • {$name}\n    Fix: {$fix}\n";
            }
            $diag .= "\n";
        }

        $diag .= "Other things to try:\n";
        $diag .= "  • Add to wp-config.php (if not present):\n";
        $diag .= "      define('WP_ACCESSIBLE_HOSTS', 'api.aitoelementor.com,*.aitoelementor.com');\n";
        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
            $diag .= "  • WP_HTTP_BLOCK_EXTERNAL is currently TRUE in wp-config.php — the\n";
            $diag .= "    WP_ACCESSIBLE_HOSTS allowlist above is required for our API to be reached.\n";
        }
        $diag .= "  • If you're on managed hosting, ask support to whitelist api.aitoelementor.com (port 443)\n";
        $diag .= "  • Temporarily deactivate any security/firewall plugin and retry\n\n";
        $diag .= "Still stuck? Email support@aitoelementor.com with this entire message.";

        return $diag;
    }
}
