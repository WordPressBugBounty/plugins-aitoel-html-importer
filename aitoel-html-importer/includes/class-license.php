<?php
defined('ABSPATH') || exit;

/**
 * License activation, deactivation, and validation.
 */
class HTEL_License {

    /**
     * Get the stored license key.
     */
    public static function get_key() {
        return get_option('htel_license_key', '');
    }

    /**
     * Get the API base URL.
     *
     * Defense against the F1 customer regression: if `wp_options.htel_api_url`
     * exists but is empty or malformed (saved that way by an admin clearing
     * the field, by an import/migration, or by a buggy settings handler),
     * `get_option()` returns the empty/malformed value — NOT the default.
     * Concatenating `'' . '/api/convert'` produces a URL with no scheme,
     * which `wp_remote_post()` rejects with "A valid URL was not provided",
     * and our connection-error diagnostic then misdiagnoses it as a firewall
     * block.
     *
     * Three layers of defense:
     *   1. Validate before returning (this function)
     *   2. Auto-heal: delete the bad option if we encounter it, so it
     *      cannot persist into a future page load and trigger the same
     *      invalid-URL path twice. After deletion, get_option() naturally
     *      uses the constant default.
     *   3. The settings sanitize callback (htel_sanitize_api_url in
     *      class-admin.php) refuses to PERSIST an empty/malformed value
     *      in the first place, so the auto-heal path here is mostly a
     *      cleanup for legacy bad state from prior plugin versions.
     *
     * Trailing slashes stripped so callers can safely append `/api/...`.
     *
     * Tests in wp-plugin/tests/test-empty-api-url.php enforce that this
     * function NEVER returns an empty or malformed URL, regardless of
     * what's in wp_options. Do not regress that contract.
     */
    public static function get_api_url() {
        $stored = get_option('htel_api_url', null);

        // Fast path: option exists and is well-formed
        if (is_string($stored) && $stored !== ''
            && preg_match('#^https?://[^\s/]+#i', $stored)
            && filter_var($stored, FILTER_VALIDATE_URL)) {
            return rtrim($stored, '/');
        }

        // Auto-heal: if the option exists but is bad, delete it so future
        // reads use the default cleanly. Only delete if it actually exists
        // (don't fight an already-absent option).
        if ($stored !== null) {
            delete_option('htel_api_url');
        }

        return HTEL_API_URL;
    }

    /**
     * Get the site domain (normalized).
     */
    public static function get_domain() {
        $url = home_url();
        $parsed = wp_parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        // Strip www. prefix
        $host = preg_replace('/^www\./', '', strtolower($host));
        return $host;
    }

    /**
     * Get cached license status.
     */
    public static function get_status() {
        return get_option('htel_license_status', 'inactive');
    }

    /**
     * Get the list of domains this license is activated on (normalized).
     */
    public static function get_domains() {
        $domains = get_option('htel_license_domains', array());
        return is_array($domains) ? array_values(array_filter(array_map('strval', $domains))) : array();
    }

    /**
     * Get the per-license max-domains cap as last reported by the API.
     *
     *   0 (or null) = unlimited (Agency / grandfathered)
     *   positive int = hard cap (Solo=1, Pro=3, custom)
     *
     * The plugin caches the server's `domains_limit` on every successful
     * validate / activate / deactivate response. Templates check the
     * value to decide between "X of Y sites" (capped) and "Active on N
     * sites" (unlimited) display.
     */
    public static function get_domains_limit() {
        $stored = get_option('htel_license_domains_limit', null);
        if ($stored === null || $stored === '' || $stored === false) return 0;
        $n = (int) $stored;
        return $n > 0 ? $n : 0;
    }

    /**
     * Get the monthly conversion usage block as returned by /api/license/
     * validate (or /api/convert). Structure:
     *   ['used' => int, 'limit' => int|null, 'remaining' => int|null,
     *    'tier' => 'Solo'|'Pro'|'Agency'|null]
     * `limit` / `remaining` = null means unlimited (Agency tier or
     * grandfathered customer). Null whole-return means we haven't
     * received a usage response from the API yet.
     */
    public static function get_usage() {
        $usage = get_option('htel_license_usage', null);
        return is_array($usage) ? $usage : null;
    }

    /**
     * Get the tier label ('Solo' / 'Pro' / 'Agency') if known.
     */
    public static function get_tier() {
        $u = self::get_usage();
        return $u && !empty($u['tier']) ? $u['tier'] : '';
    }

    /**
     * Check if license is valid for conversion — with offline fallback.
     * If API is unreachable, allows conversions for up to 7 days using cached status.
     * Returns true if conversion should be allowed, false otherwise.
     */
    public static function is_valid_for_conversion() {
        $key = self::get_key();
        if (empty($key)) return false;

        $status = self::get_status();
        if ($status !== 'active') return false;

        // Check local expiry
        $expires = get_option('htel_license_expires', '');
        if (!empty($expires) && strtotime($expires) < time()) {
            update_option('htel_license_status', 'expired');
            return false;
        }

        // Try to validate with API (non-blocking, cached)
        $last_check = get_option('htel_license_last_check', 0);
        $check_interval = 12 * HOUR_IN_SECONDS; // Re-validate every 12 hours

        if ((time() - $last_check) > $check_interval) {
            $result = self::api_request('/api/license/validate', array(
                'license_key' => $key,
                'domain'      => self::get_domain(),
            ));

            if (!empty($result['valid'])) {
                update_option('htel_license_last_check', time());
                update_option('htel_license_status', 'active');
                if (!empty($result['expires_at'])) {
                    update_option('htel_license_expires', $result['expires_at']);
                }
                if (isset($result['domains']) && is_array($result['domains'])) {
                    update_option('htel_license_domains', $result['domains']);
                }
                // Use array_key_exists so a server-reported `null`
                // (Agency / grandfathered = unlimited) overwrites any
                // stale legacy value (e.g. "2" from before tiers).
                // We store as int — null casts to 0 which the template
                // reads as "unlimited."
                if (array_key_exists('domains_limit', $result)) {
                    update_option('htel_license_domains_limit', (int) $result['domains_limit']);
                }
                // Persist the monthly usage block from the server so the
                // settings page can render "X / Y conversions this month"
                // without hitting the API on every page load.
                if (isset($result['usage']) && is_array($result['usage'])) {
                    update_option('htel_license_usage', $result['usage']);
                }
                return true;
            }

            // API returned invalid — license was revoked/expired server-side
            if (isset($result['valid']) && $result['valid'] === false) {
                $new_status = isset($result['status']) ? $result['status'] : 'expired';
                update_option('htel_license_status', $new_status);
                return false;
            }

            // API unreachable — use grace period (7 days from last successful check)
            $grace_period = 7 * DAY_IN_SECONDS;
            if ((time() - $last_check) > $grace_period) {
                return false; // Grace period exceeded
            }
        }

        return true;
    }

    /**
     * Make an API request.
     */
    private static function api_request($endpoint, $body = array()) {
        $url = self::get_api_url() . $endpoint;

        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return array('success' => false, 'error' => 'Invalid response from server');
        }

        return $body;
    }

    /**
     * AJAX: Activate license.
     */
    public static function ajax_activate() {
        check_ajax_referer('htel_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Unauthorized', 'aitoel-html-importer')));
        }

        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        if (empty($key)) {
            wp_send_json_error(array('message' => esc_html__('Please enter a license key', 'aitoel-html-importer')));
        }

        $domain = self::get_domain();

        // Activate with API
        $result = self::api_request('/api/license/activate', array(
            'license_key' => $key,
            'domain'      => $domain,
        ));

        if (!empty($result['success'])) {
            update_option('htel_license_key', $key);
            update_option('htel_license_status', 'active');
            update_option('htel_license_domain', $domain);

            // Store all activated domains + limit for settings UI
            if (isset($result['domains']) && is_array($result['domains'])) {
                update_option('htel_license_domains', $result['domains']);
            }
            if (array_key_exists('domains_limit', $result)) {
                update_option('htel_license_domains_limit', (int) $result['domains_limit']);
            }

            // Get expiry info
            $validate = self::api_request('/api/license/validate', array(
                'license_key' => $key,
                'domain'      => $domain,
            ));
            if (!empty($validate['expires_at'])) {
                update_option('htel_license_expires', $validate['expires_at']);
            }
            if (isset($validate['domains']) && is_array($validate['domains'])) {
                update_option('htel_license_domains', $validate['domains']);
            }
            if (isset($validate['usage']) && is_array($validate['usage'])) {
                update_option('htel_license_usage', $validate['usage']);
            }
            if (array_key_exists('domains_limit', $validate)) {
                update_option('htel_license_domains_limit', (int) $validate['domains_limit']);
            }

            wp_send_json_success(array('message' => esc_html__('License activated successfully', 'aitoel-html-importer')));
        } else {
            $error = isset($result['error']) ? $result['error'] : esc_html__('Activation failed', 'aitoel-html-importer');
            wp_send_json_error(array('message' => $error));
        }
    }

    /**
     * AJAX: Deactivate license.
     *
     * Two flows the user can hit:
     *   A) The site's own domain matches a bound domain → server unbinds it
     *      cleanly, local state wiped, all good.
     *   B) The site's own domain is NOT one of the bound domains (e.g. user
     *      installs the plugin on a 3rd site to try it, clicks Deactivate,
     *      expecting it'll free up one of the real bindings on sites A/B).
     *      The server correctly refuses. PREVIOUSLY the plugin wiped local
     *      state anyway and returned "License deactivated" — so the user
     *      thought it worked while server-side nothing changed. They then
     *      couldn't add a new domain because the 2-slot cap was still used.
     *
     * New behavior:
     *   - Only wipe local state if the server actually unbound a domain
     *   - If the server reports the domain isn't bound, surface the real
     *     error (including the list of domains that ARE bound) so the user
     *     knows they need to deactivate from one of THOSE sites.
     */
    public static function ajax_deactivate() {
        check_ajax_referer('htel_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Unauthorized', 'aitoel-html-importer')));
        }

        $key = self::get_key();
        $domain = self::get_domain();

        if (empty($key)) {
            wp_send_json_error(array('message' => esc_html__('No license key found', 'aitoel-html-importer')));
        }

        $result = self::api_request('/api/license/deactivate', array(
            'license_key' => $key,
            'domain'      => $domain,
        ));

        $is_success = !empty($result['success']);
        $error_msg = isset($result['error']) ? $result['error'] : '';

        // Wipe local state when:
        //   A) Server confirms deactivation (normal path)
        //   B) Server says this domain isn't actually bound, OR the license key
        //      is invalid. In both cases our LOCAL "activated" cache is stale/wrong
        //      and keeping it just prevents the user from re-activating. Their
        //      real bindings (if any) live on different sites anyway.
        //
        // Keep local state ONLY on genuine unknown-status errors (connection/
        // timeout failures) so an offline blip doesn't log them out.
        $server_says_not_bound = !$is_success && (
            (isset($result['bound_domains']) && is_array($result['bound_domains'])) ||
            stripos($error_msg, 'not activated') !== false ||
            stripos($error_msg, 'invalid license') !== false
        );

        if ($is_success || $server_says_not_bound) {
            delete_option('htel_license_key');
            update_option('htel_license_status', 'inactive');
            delete_option('htel_license_domain');
            delete_option('htel_license_expires');
            delete_option('htel_license_domains');
            delete_option('htel_license_domains_limit');
        }

        if ($is_success) {
            wp_send_json_success(array(
                'message' => isset($result['message']) ? $result['message'] : esc_html__('License deactivated', 'aitoel-html-importer'),
                'remaining_domains' => isset($result['domains']) ? $result['domains'] : array(),
            ));
        }

        if ($server_says_not_bound) {
            // Local state was stale — we cleared it. Tell the user the license
            // isn't bound here AND show them where the real bindings are so
            // they know where to act (or that there aren't any left).
            $msg = esc_html__('This site is not activated on the license. Local cache cleared.', 'aitoel-html-importer');
            $bound = isset($result['bound_domains']) ? $result['bound_domains'] : array();
            wp_send_json_success(array(
                'message' => $msg,
                'was_stale' => true,
                'bound_domains' => $bound,
            ));
        }

        // Genuine error — surface it, keep local state.
        wp_send_json_error(array(
            'message' => $error_msg ?: esc_html__('Deactivation failed (could not reach license server)', 'aitoel-html-importer'),
            'bound_domains' => isset($result['bound_domains']) ? $result['bound_domains'] : array(),
        ));
    }

    /**
     * AJAX: Refresh license state against the server.
     * Syncs local WP options to match the server's source-of-truth.
     * Fixes the stale-cache bug where a site that was deactivated elsewhere
     * still shows "connected" locally because validate never ran again.
     */
    public static function ajax_refresh_state() {
        check_ajax_referer('htel_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Unauthorized', 'aitoel-html-importer')));
        }

        $key = self::get_key();
        if (empty($key)) {
            wp_send_json_error(array('message' => esc_html__('No license key on this site', 'aitoel-html-importer')));
        }

        $domain = self::get_domain();
        $bindings = self::api_request('/api/license/bindings', array('license_key' => $key));

        if (empty($bindings['success'])) {
            $err = isset($bindings['error']) ? $bindings['error'] : esc_html__('Could not reach license server', 'aitoel-html-importer');
            wp_send_json_error(array('message' => $err));
        }

        $server_domains = isset($bindings['domains']) && is_array($bindings['domains']) ? $bindings['domains'] : array();
        $server_status = isset($bindings['status']) ? $bindings['status'] : 'inactive';
        $expires = isset($bindings['expires_at']) ? $bindings['expires_at'] : '';
        $limit = isset($bindings['domains_limit']) ? (int) $bindings['domains_limit'] : 2;

        $is_bound_here = in_array($domain, $server_domains, true);

        if (!$is_bound_here || $server_status !== 'active') {
            // Wipe — server says this site isn't a valid binding.
            delete_option('htel_license_key');
            update_option('htel_license_status', 'inactive');
            delete_option('htel_license_domain');
            delete_option('htel_license_expires');
            delete_option('htel_license_domains');
            delete_option('htel_license_domains_limit');
            wp_send_json_success(array(
                'message' => esc_html__('License state cleared — this site is not activated on the server.', 'aitoel-html-importer'),
                'bound_domains' => $server_domains,
                'status' => $server_status,
                'cleared' => true,
            ));
        }

        // Server agrees: this site IS bound. Re-sync cached metadata.
        update_option('htel_license_status', 'active');
        update_option('htel_license_domain', $domain);
        update_option('htel_license_domains', $server_domains);
        update_option('htel_license_domains_limit', $limit);
        if ($expires) update_option('htel_license_expires', $expires);

        wp_send_json_success(array(
            /* translators: %1$d: number of activated sites; %2$d: total sites allowed by the license */
            'message' => sprintf(esc_html__('License synced. Active on %1$d of %2$d sites.', 'aitoel-html-importer'), count($server_domains), $limit),
            'bound_domains' => $server_domains,
            'status' => $server_status,
            'cleared' => false,
        ));
    }

    /**
     * AJAX: Validate license (refresh status).
     */
    public static function ajax_validate() {
        check_ajax_referer('htel_license_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Unauthorized', 'aitoel-html-importer')));
        }

        $key = self::get_key();
        $domain = self::get_domain();

        if (empty($key)) {
            update_option('htel_license_status', 'inactive');
            wp_send_json_error(array('message' => esc_html__('No license key found', 'aitoel-html-importer')));
        }

        $result = self::api_request('/api/license/validate', array(
            'license_key' => $key,
            'domain'      => $domain,
        ));

        if (!empty($result['valid'])) {
            update_option('htel_license_status', 'active');
            if (!empty($result['expires_at'])) {
                update_option('htel_license_expires', $result['expires_at']);
            }
            if (isset($result['domains']) && is_array($result['domains'])) {
                update_option('htel_license_domains', $result['domains']);
            }
            if (isset($result['domains_limit'])) {
                update_option('htel_license_domains_limit', (int) $result['domains_limit']);
            }
            wp_send_json_success(array(
                'status'     => 'active',
                'expires_at' => isset($result['expires_at']) ? $result['expires_at'] : '',
                'domains'    => isset($result['domains']) ? $result['domains'] : array(),
                'domains_limit' => isset($result['domains_limit']) ? (int) $result['domains_limit'] : 2,
            ));
        } else {
            $status = isset($result['status']) ? $result['status'] : 'invalid';
            update_option('htel_license_status', $status);
            wp_send_json_error(array(
                'message' => isset($result['error']) ? $result['error'] : 'License is not valid',
                'status'  => $status,
            ));
        }
    }
}
