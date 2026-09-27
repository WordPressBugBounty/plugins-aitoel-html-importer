<?php
/**
 * Uninstall handler — clean up plugin data.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('htel_license_key');
delete_option('htel_license_status');
delete_option('htel_license_domain');
delete_option('htel_license_domains');
delete_option('htel_license_domains_limit');
delete_option('htel_license_expires');
delete_option('htel_license_last_check');
delete_option('htel_api_url');
delete_option('htel_telemetry_opt_in');
delete_option('htel_sampling_opt_out');
delete_option('htel_globalize');
// The name the globalize setting carried before 2026-08-27. Never settable through the UI (there
// was no register_setting and no checkbox), so the orphan is inert — but an uninstall that leaves
// rows behind is still an uninstall that lied.
delete_option('htel_map_global_colors');

// Review named ONE leftover. Enumerating the class found three more: these are written by the
// licence check and the Elementor-widget check, and neither had ever been on this list.
// `tests/test-uninstall-covers-every-option.php` now fails if a new one is added without a line
// here, which is the only reason the next one will not be found the same way.
delete_option('htel_license_usage');
delete_option('htel_missing_widgets');
delete_transient('htel_last_conversion_html');
// `class-updater.php` writes this one as `set_transient(self::CACHE_KEY, ...)`, so the
// coverage test below could not see it — it only matched literal first arguments. An
// uninstalled PAID install leaked `_transient_htel_update_manifest` forever. The free wp.org
// build excludes class-updater.php entirely and never had it.
delete_transient('htel_update_manifest');
