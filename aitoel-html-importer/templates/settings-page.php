<?php
/**
 * Settings page template.
 *
 * Included from HTEL_Admin::render_settings_page(). Template-local
 * variables are prefixed with `$htel_` per Plugin Check — even though
 * PHP scopes them to the calling method, the scanner treats top-level
 * `include`d variables as potentially global and flags unprefixed
 * names. Prefixing makes the intent explicit and passes the lint.
 */

defined('ABSPATH') || exit;

$htel_key           = HTEL_License::get_key();
$htel_status        = HTEL_License::get_status();
$htel_domain        = get_option('htel_license_domain', '');
$htel_expires       = get_option('htel_license_expires', '');
$htel_is_active     = ($htel_status === 'active' && !empty($htel_key));
$htel_all_domains   = HTEL_License::get_domains();
// Monthly conversion usage returned by the API on validate/convert calls.
// Shape: ['used' => int, 'limit' => int|null, 'remaining' => int|null, 'tier' => string|null]
// limit === null means unlimited (Agency or grandfathered).
$htel_usage         = HTEL_License::get_usage();
$htel_tier          = HTEL_License::get_tier();
?>

<div class="wrap htel-settings-wrap">
    <h1><?php esc_html_e('AI to Elementor — Settings', 'aitoel-html-importer'); ?></h1>

    <div class="htel-card">
        <h2><?php esc_html_e('License', 'aitoel-html-importer'); ?></h2>

        <div id="htel-license-status" class="htel-license-status <?php echo esc_attr($htel_is_active ? 'active' : 'inactive'); ?>">
            <?php if ($htel_is_active) : ?>
                <span class="htel-status-dot active"></span>
                <strong><?php esc_html_e('Active', 'aitoel-html-importer'); ?></strong>
                <?php if ($htel_domain) : ?>
                    — <?php echo esc_html($htel_domain); ?>
                <?php endif; ?>
                <?php if ($htel_expires) : ?>
                    <span class="htel-expires">
                        <?php
                        // $htel_expires comes from an option populated by the
                        // license API response — treat as untrusted per WP.org
                        // review. Escape LATE at the echo point.
                        $htel_exp_date = date_i18n(get_option('date_format'), strtotime($htel_expires));
                        printf(
                            /* translators: %s: formatted expiration date */
                            esc_html__('Expires: %s', 'aitoel-html-importer'),
                            esc_html($htel_exp_date)
                        );
                        ?>
                    </span>
                <?php endif; ?>
            <?php else : ?>
                <span class="htel-status-dot inactive"></span>
                <strong><?php esc_html_e('Inactive', 'aitoel-html-importer'); ?></strong>
            <?php endif; ?>
        </div>

        <?php if ($htel_is_active) : ?>
            <?php if ($htel_tier) : ?>
                <p style="margin-top:10px; font-size:13px; color:#555;">
                    <?php
                    printf(
                        /* translators: %s: tier name (Solo / Pro / Agency) */
                        esc_html__('Plan: %s', 'aitoel-html-importer'),
                        '<strong>' . esc_html($htel_tier) . '</strong>'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($htel_usage) : ?>
                <?php
                // Monthly usage display. Three possible states:
                //   1. limit = null → Agency / grandfathered: "N used, unlimited"
                //   2. remaining > 0 → normal: "N / M used this month"
                //   3. remaining = 0 → at cap: red warning + link to upgrade
                $htel_used  = isset($htel_usage['used']) ? (int) $htel_usage['used'] : 0;
                $htel_limit = array_key_exists('limit', $htel_usage) ? $htel_usage['limit'] : null;
                $htel_rem   = array_key_exists('remaining', $htel_usage) ? $htel_usage['remaining'] : null;
                ?>
                <?php if ($htel_limit === null) : ?>
                    <p style="margin-top:6px; font-size:13px; color:#555;">
                        <?php
                        printf(
                            /* translators: %d: number of conversions this month */
                            esc_html(_n('%d conversion this month', '%d conversions this month', $htel_used, 'aitoel-html-importer')),
                            (int) $htel_used
                        );
                        echo ' · <span style="color:#16a34a;">' . esc_html__('unlimited', 'aitoel-html-importer') . '</span>';
                        ?>
                    </p>
                <?php elseif ($htel_rem > 0) : ?>
                    <p style="margin-top:6px; font-size:13px; color:#555;">
                        <?php
                        printf(
                            /* translators: 1: conversions used this month, 2: monthly limit */
                            esc_html__('%1$d / %2$d conversions used this month', 'aitoel-html-importer'),
                            (int) $htel_used,
                            (int) $htel_limit
                        );
                        echo ' · ';
                        printf(
                            /* translators: %d: number of conversions remaining */
                            esc_html__('%d left before reset on the 1st', 'aitoel-html-importer'),
                            (int) $htel_rem
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p style="margin-top:6px; font-size:13px; color:#b42318;">
                        <?php
                        printf(
                            /* translators: 1: conversions used this month, 2: monthly limit */
                            esc_html__('%1$d / %2$d conversions used this month — service quota reached, resets on the 1st.', 'aitoel-html-importer'),
                            (int) $htel_used,
                            (int) $htel_limit
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (! empty($htel_all_domains)) : ?>
                <?php
                // Show site list. When max_domains is set (Solo=1, Pro=3),
                // include "X of Y sites" + remaining-slots hint. When 0
                // (unlimited / Agency / grandfathered), just list the
                // active sites without a cap.
                $htel_domains_limit = HTEL_License::get_domains_limit();
                $htel_count         = count($htel_all_domains);
                $htel_remaining     = $htel_domains_limit > 0
                    ? max(0, $htel_domains_limit - $htel_count)
                    : 0;

                $htel_domain_tags = array();
                foreach ($htel_all_domains as $htel_d) {
                    $htel_domain_tags[] = '<code>' . esc_html($htel_d) . '</code>';
                }

                if ($htel_domains_limit > 0) {
                    // Capped tier — "X of Y allowed sites: ..."
                    $htel_prefix = sprintf(
                        /* translators: 1: number of sites used, 2: max sites allowed */
                        esc_html__('Active on %1$d of %2$d allowed sites: ', 'aitoel-html-importer'),
                        (int) $htel_count,
                        (int) $htel_domains_limit
                    );
                } else {
                    // Unlimited tier — "Active on N site(s): ..."
                    $htel_prefix = sprintf(
                        /* translators: %d: number of sites the license is active on */
                        esc_html(_n('Active on %d site: ', 'Active on %d sites: ', $htel_count, 'aitoel-html-importer')),
                        (int) $htel_count
                    );
                }
                ?>
                <p style="margin-top:6px; font-size:13px; color:#555;">
                    <?php
                    echo wp_kses(
                        $htel_prefix . implode(', ', $htel_domain_tags),
                        array('code' => array())
                    );
                    ?>
                    <?php if ($htel_remaining > 0) : ?>
                        <br>
                        <span style="color:#16a34a;">
                            <?php
                            printf(
                                esc_html(
                                    /* translators: %d: number of remaining activation slots */
                                    _n(
                                        'You can activate this license on %d more site.',
                                        'You can activate this license on %d more sites.',
                                        $htel_remaining,
                                        'aitoel-html-importer'
                                    )
                                ),
                                (int) $htel_remaining
                            );
                            ?>
                        </span>
                    <?php elseif ($htel_domains_limit > 0 && $htel_remaining === 0) : ?>
                        <br>
                        <span style="color:#b42318;">
                            <?php
                            printf(
                                /* translators: %d: domain cap on this tier */
                                esc_html__('You\'ve reached the %d-site limit on your plan.', 'aitoel-html-importer'),
                                (int) $htel_domains_limit
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <div id="htel-license-form">
            <?php if ($htel_is_active) : ?>
                <p>
                    <?php esc_html_e('License key:', 'aitoel-html-importer'); ?>
                    <code><?php echo esc_html(substr($htel_key, 0, 8) . '****' . substr($htel_key, -4)); ?></code>
                </p>
                <button type="button" id="htel-deactivate-btn" class="button">
                    <?php esc_html_e('Deactivate License', 'aitoel-html-importer'); ?>
                </button>
                <button type="button" id="htel-refresh-btn" class="button">
                    <?php esc_html_e('Refresh Status', 'aitoel-html-importer'); ?>
                </button>
            <?php else : ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="htel-license-key"><?php esc_html_e('License Key', 'aitoel-html-importer'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="htel-license-key" class="regular-text"
                                   placeholder="HTE-XXXX-XXXX-XXXX-XXXX"
                                   value="<?php echo esc_attr($htel_key); ?>" />
                            <button type="button" id="htel-activate-btn" class="button button-primary">
                                <?php esc_html_e('Activate', 'aitoel-html-importer'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>

        <div id="htel-license-message" class="htel-message" style="display:none;"></div>
    </div>

    <?php
    /*
     * Conversion service disclosure (WP.org Guideline 6, "Software as a Service is permitted").
     * A plugin whose core function is performed by an external service MUST identify that
     * service, say what is sent to it, and link to its terms and privacy policy. Until now the
     * plugin linked only to the HTML guidelines page and ToS §9.4, and never named the service
     * or linked to it — a genuine disclosure gap.
     *
     * DELIBERATELY NOT AN UPGRADE PROMPT. WP.org review previously flagged this plugin as
     * Trialware (Guideline 5) because the UI showed "upgrade for unlimited conversions" CTAs and
     * a "1 free conversion" badge, which read as features locked behind a licence (see 280e006).
     * Three rules keep this block on the right side of that ruling, and they must hold for any
     * future edit:
     *   1. It renders UNCONDITIONALLY — same markup for free, licensed and quota-exhausted
     *      users. A licence- or state-conditioned element reads as a paywall prompt, an
     *      always-present one reads as disclosure. (CORRECTED 2026-09-19: this used to say the
     *      flagged CTA "appeared only when the quota ran out". It did not — the removed banner
     *      was on the Convert page for EVERY unlicensed site, and admin.js only changed it to
     *      "USED" when the quota ran out; see `4b8a685^:templates/admin-page.php`. `280e006` is a
     *      pre-rewrite SHA and no longer exists.)
     *   2. No pricing, no plan names, no "upgrade"/"unlimited"/"pro", no badge, no button
     *      styling. Plain descriptive text and plain links.
     *   3. It describes where processing happens — it does not invite a purchase.
     */
    ?>
    <div class="htel-card" style="margin-top:24px;">
        <h2><?php esc_html_e('Conversion service', 'aitoel-html-importer'); ?></h2>
        <p style="color:#444; font-size:13px; line-height:1.6; margin:0 0 8px;">
            <?php esc_html_e('Conversions are performed by AI to Elementor, an external service operated by Internet Wizard Ltd. Nothing is converted on your own server.', 'aitoel-html-importer'); ?>
        </p>
        <p style="color:#444; font-size:13px; line-height:1.6; margin:0 0 8px;">
            <?php
            printf(
                /* translators: %s: the API hostname the plugin sends conversion requests to. */
                esc_html__('When you convert a page, the plugin sends the HTML you submit, the template title and your site\'s domain to %s, which returns the Elementor template. The service meters how many conversions each site may make.', 'aitoel-html-importer'),
                '<code>api.aitoelementor.com</code>'
            );
            ?>
        </p>
        <p style="color:#444; font-size:13px; line-height:1.6; margin:0;">
            <a href="<?php echo esc_url('https://aitoelementor.com/'); ?>" target="_blank" rel="noopener noreferrer">aitoelementor.com</a>
            &middot;
            <a href="<?php echo esc_url('https://aitoelementor.com/terms-and-conditions/'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms of service', 'aitoel-html-importer'); ?></a>
            &middot;
            <a href="<?php echo esc_url('https://aitoelementor.com/privacy-policy/'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy policy', 'aitoel-html-importer'); ?></a>
        </p>
    </div>

    <div class="htel-card" style="margin-top:24px;">
        <h2><?php esc_html_e('Data &amp; Privacy', 'aitoel-html-importer'); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields('htel_settings'); ?>

            <!-- Conversion Quality Sampling — opt-OUT per ToS §9.4.
                 QUIETER, AND TRUE. The blue callout was dropped 2026-09-08 so this reads as an
                 ordinary settings note rather than an alert — that is a presentation choice and a
                 fair one. What was NOT dropped is the substance: the old copy said "a random sample
                 (about 20%)", and from 2026-09-08 the service retains every conversion while the
                 capture window is open. Leaving the 20% sentence in place would have made the
                 plugin state something untrue about what we do, which is a different thing
                 entirely from choosing where to put it. If the window closes, this wording is still
                 accurate — "a copy of the HTML you convert" covers both rates. -->
            <div style="margin-bottom:14px;">
                <div style="color:#666; font-size:12px; line-height:1.55; margin-bottom:10px;">
                    <?php esc_html_e('We retain a copy of the HTML you convert for up to 90 days, to identify and fix bugs that affect all customers. It is stored with your site\'s domain and a timestamp only — never with your license key or email, and deleted automatically.', 'aitoel-html-importer'); ?>
                    <br>
                    <a href="https://aitoelementor.com/terms-and-conditions/#9.4" target="_blank" rel="noopener"><?php esc_html_e('Full terms (ToS §9.4)', 'aitoel-html-importer'); ?></a>
                    &middot;
                    <a href="mailto:privacy@aitoelementor.com?subject=Erasure request&amp;body=Please delete all samples collected from domain: <?php echo esc_attr(rawurlencode(HTEL_License::get_domain())); ?>" target="_blank"><?php esc_html_e('Request deletion', 'aitoel-html-importer'); ?></a>
                </div>
                <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                    <input type="checkbox" name="htel_sampling_opt_out" value="1"
                        <?php checked(get_option('htel_sampling_opt_out', false)); ?>
                        style="margin-top:3px;" />
                    <span style="color:#111; font-size:13px;">
                        <?php esc_html_e('Opt out of sampling. My HTML will not be retained for quality analysis.', 'aitoel-html-importer'); ?>
                    </span>
                </label>
            </div>

            <!-- Site Styles: Global Colors + Global Fonts -->
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; margin-top:16px; padding-top:14px; border-top:1px solid #eee;">
                <input type="checkbox" name="htel_globalize" value="1"
                    <?php checked(get_option('htel_globalize', false)); ?>
                    style="margin-top:3px;" />
                <span style="color:#111; font-size:13px;">
                    <?php esc_html_e('Add the page\'s colours and fonts to my Elementor Site Settings.', 'aitoel-html-importer'); ?>
                    <br>
                    <span style="color:#666;">
                        <?php esc_html_e('Off by default. This adds entries to your site\'s Global Colors and Global Fonts, which apply site-wide — including to pages this plugin did not create. Nothing is ever removed and nothing you already have is changed, including entries added by an earlier conversion. Each page adds up to 12 colours and 4 fonts, and they ACCUMULATE: converting a whole site can leave dozens of entries in your Site Settings, where the list is not paginated. Importing the same page twice adds none. Entries stay if you uninstall the plugin.', 'aitoel-html-importer'); ?>
                    </span>
                </span>
            </label>

            <!-- Legacy opt-IN telemetry (still supported) -->
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; margin-top:16px; padding-top:14px; border-top:1px solid #eee;">
                <input type="checkbox" name="htel_telemetry_opt_in" value="1"
                    <?php checked(get_option('htel_telemetry_opt_in', false)); ?>
                    style="margin-top:3px;" />
                <span>
                    <strong><?php esc_html_e('Share every conversion (additional opt-in)', 'aitoel-html-importer'); ?></strong>
                    <br>
                    <span style="color:#666; font-size:13px;">
                        <?php esc_html_e('Stronger than sampling: when enabled, every page you convert is retained (not just 20%). Useful if you want to report a persistent issue or actively contribute to improving the engine. Independent of the opt-out above.', 'aitoel-html-importer'); ?>
                    </span>
                </span>
            </label>

            <?php submit_button(__('Save', 'aitoel-html-importer'), 'secondary', 'submit', true); ?>
        </form>
    </div>

</div>
