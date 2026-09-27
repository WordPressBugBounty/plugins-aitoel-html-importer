<?php defined('ABSPATH') || exit; ?>

<div class="wrap htel-convert-wrap">
    <h1><?php esc_html_e('HTML to Elementor', 'aitoel-html-importer'); ?></h1>

    <?php if (!HTEL_Elementor_Check::is_active()): ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Elementor must be installed and activated to use this tool.', 'aitoel-html-importer'); ?></p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php
    // Per WP.org Guideline 5 (Trialware): the plugin must not present
    // "upgrade" / "free X uses left" framing inside its UI. All access
    // control is enforced by the external conversion service (see
    // serviceware framing in readme.txt). The settings page surfaces
    // service tier / monthly usage for users who chose to connect a paid
    // subscription, but the main conversion screen stays neutral.
    ?>

    <div class="htel-card">
        <h2><?php esc_html_e('Convert HTML to Elementor Template', 'aitoel-html-importer'); ?></h2>
        <p class="description">
            <?php esc_html_e('Paste your HTML code or upload an .html file. The converter will create an Elementor template you can edit and use on any page.', 'aitoel-html-importer'); ?>
        </p>

        <div class="htel-form">
            <!-- Template title -->
            <div class="htel-field">
                <label for="htel-title"><?php esc_html_e('Template Title', 'aitoel-html-importer'); ?></label>
                <input type="text" id="htel-title" class="regular-text" placeholder="My Template" value="" />
            </div>

            <!-- Input method tabs -->
            <div class="htel-tabs">
                <button type="button" class="htel-tab active" data-tab="paste">
                    <?php esc_html_e('Paste HTML', 'aitoel-html-importer'); ?>
                </button>
                <button type="button" class="htel-tab" data-tab="upload">
                    <?php esc_html_e('Upload File', 'aitoel-html-importer'); ?>
                </button>
            </div>

            <!-- Paste tab -->
            <div class="htel-tab-content active" id="htel-tab-paste">
                <textarea id="htel-html-input" rows="16"
                          placeholder="<?php esc_attr_e('Paste your HTML code here...', 'aitoel-html-importer'); ?>"></textarea>
            </div>

            <!-- Upload tab -->
            <div class="htel-tab-content" id="htel-tab-upload">
                <div class="htel-upload-area" id="htel-upload-area">
                    <input type="file" id="htel-file-input" accept=".html,.htm,.zip" style="display:none;" />
                    <div class="htel-upload-placeholder">
                        <span class="dashicons dashicons-upload"></span>
                        <p><?php esc_html_e('Drop one page\'s .html or .zip file here or click to browse', 'aitoel-html-importer'); ?></p>
                        <p class="description"><?php esc_html_e('ZIP files: include ONE page\'s HTML plus its images and CSS (not an entire website). Images are imported to your Media Library automatically. To convert several pages, upload and convert them one at a time.', 'aitoel-html-importer'); ?></p>
                    </div>
                    <div class="htel-upload-info" style="display:none;">
                        <span class="dashicons dashicons-media-code"></span>
                        <span id="htel-file-name"></span>
                        <button type="button" class="htel-remove-file">&times;</button>
                    </div>
                </div>
            </div>

            <!-- Site-part intent (header mode) — copy approved 2026-08-14 -->
            <div class="htel-sitepart" style="margin:12px 0 4px;">
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="htel-intent-sitepart" style="margin-top:3px;" />
                    <span>
                        <strong><?php esc_html_e("I'm converting a header or footer (site part)", 'aitoel-html-importer'); ?></strong><br>
                        <span class="description"><?php esc_html_e('Converts as an Elementor site part instead of a page — sticky behavior kept, menu button working. Paste the fragment together with your site\'s <style>/CSS so it keeps its look.', 'aitoel-html-importer'); ?></span>
                    </span>
                </label>
            </div>

            <!-- Fragment auto-suggest banner (shown when the conversion detects a lone header/footer) -->
            <div id="htel-fragment-suggest" class="notice notice-info" style="display:none;margin:10px 0;padding:10px 14px;">
                <p style="margin:0 0 8px;">
                    <?php esc_html_e('This looks like a header, footer or menu on its own. Convert it as a site part? Or convert the full page for best results.', 'aitoel-html-importer'); ?>
                </p>
                <button type="button" class="button" id="htel-fragment-yes">
                    <?php esc_html_e("Yes, it's a header/footer — convert again", 'aitoel-html-importer'); ?>
                </button>
            </div>

            <!-- Convert button -->
            <div class="htel-actions">
                <button type="button" id="htel-convert-btn" class="button button-primary button-hero">
                    <?php esc_html_e('Convert to Elementor', 'aitoel-html-importer'); ?>
                </button>
            </div>
        </div>

        <?php
        // "How it works" — the full workflow (AI prompt, paste vs ZIP, native images).
        // v1.3.27: collapsed below the converter per user feedback — the open panel pushed
        // the paste box below the fold. Native <details> keeps it JS-free.
        ?>
        <details class="htel-howto" style="margin:16px 0 0 0;">
            <summary style="cursor:pointer; font-size:13px; color:#2271b1; user-select:none;">
                <?php esc_html_e('Not sure how to convert? Click here for the step-by-step guide.', 'aitoel-html-importer'); ?>
            </summary>
            <div style="margin:10px 0 4px 0; padding:14px 16px; background:#f4f7fb; border-left:4px solid #2271b1; border-radius:3px; font-size:13px; line-height:1.6;">
                <strong style="display:block; margin-bottom:10px; font-size:14px;">
                    <?php esc_html_e('How it works — from a design to an editable Elementor page', 'aitoel-html-importer'); ?>
                </strong>

                <p style="margin:0 0 4px 0;"><strong><?php esc_html_e('1. Get your page as ONE HTML file', 'aitoel-html-importer'); ?></strong></p>
                <p style="margin:0 0 8px 0;">
                    <?php esc_html_e('One page per conversion (for example index.html) — this is not a whole-site importer. If an AI built your design (Claude, ChatGPT, Gemini…), ask it for a single self-contained file. A prompt that works well:', 'aitoel-html-importer'); ?>
                </p>
                <code style="display:block; margin:0 0 12px 0; padding:8px 10px; background:#fff; border:1px solid #dcdcde; border-radius:3px; user-select:all; cursor:copy;">
                    <?php esc_html_e('Export this design as ONE self-contained HTML file: all CSS inside the file, plain HTML and CSS only (no React, no build steps), images as normal <img> tags.', 'aitoel-html-importer'); ?>
                </code>

                <p style="margin:0 0 4px 0;"><strong><?php esc_html_e('2. Paste it — or upload a ZIP if the page is image-heavy', 'aitoel-html-importer'); ?></strong></p>
                <ul style="margin:0 0 10px 18px; padding:0; list-style:disc;">
                    <li style="margin-bottom:4px;">
                        <?php esc_html_e('File under ~4 MB: just paste the HTML below (embedded images are fine).', 'aitoel-html-importer'); ?>
                    </li>
                    <li style="margin-bottom:4px;">
                        <?php esc_html_e('Bigger / lots of images: save the page as “Webpage, Complete” (or export the HTML plus its images folder), zip it, and upload the ZIP — big single files can be rejected by your hosting before they ever reach the converter.', 'aitoel-html-importer'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Your images become native either way:', 'aitoel-html-importer'); ?></strong>
                        <?php esc_html_e('every image in the page is uploaded to your Media Library as a real attachment and the template is wired to those files — so in Elementor they are normal images you can swap, crop and edit.', 'aitoel-html-importer'); ?>
                    </li>
                </ul>

                <p style="margin:0 0 4px 0;"><strong><?php esc_html_e('3. Convert', 'aitoel-html-importer'); ?></strong></p>
                <p style="margin:0 0 10px 0;">
                    <?php esc_html_e('Click “Convert to Elementor” and open the result straight in the editor. Converting several pages? Repeat one page at a time — each becomes its own template under Templates → Saved Templates.', 'aitoel-html-importer'); ?>
                </p>

                <p style="margin:0; color:#5a6478;">
                    <?php
                    printf(
                        /* translators: %s: link to the HTML guidelines page (opens in new tab) */
                        esc_html__('Cleaner HTML converts more accurately — full guidelines: %s', 'aitoel-html-importer'),
                        '<a href="https://aitoelementor.com/ai-guidelines/" target="_blank" rel="noopener noreferrer">aitoelementor.com/ai-guidelines</a>'
                    );
                    ?>
                </p>
            </div>
        </details>
    </div>

    <?php
    // FREE DESIGN SKILL — a resource panel, deliberately NOT an upgrade CTA.
    //
    // WP.org flagged this plugin as Trialware (Guideline 5) in 2026 for "upgrade for unlimited
    // conversions" CTAs and a "1 free conversion" badge; 1.3.17 removed all of that from this
    // screen. The three rules that keep a block compliant are documented in settings-page.php:
    // render unconditionally, no plan names or button styling, describe rather than invite a
    // purchase. This panel obeys all three — it renders for every user regardless of licence
    // state, names no plan, and links to a file that costs nothing.
    //
    // Do NOT add quota framing ("convert more pages"), plan names, or a styled button here.
    // That is the exact shape that was removed, on the exact screen it was removed from.
    ?>
    <div class="htel-card htel-skill-panel" style="margin-top:16px;">
        <h2 style="margin-top:0;"><?php esc_html_e('Use our amazing, world-class design skill — FREE', 'aitoel-html-importer'); ?></h2>
        <p style="color:#14562a; font-size:14px; font-weight:600; line-height:1.5; margin:0 0 10px;">
            <?php esc_html_e('Free? Absolutely. No opt-in required — no email, no account, nothing to install.', 'aitoel-html-importer'); ?>
        </p>
        <p style="color:#444; font-size:13px; line-height:1.6; margin:0 0 10px;">
            <?php esc_html_e('One text file you hand to Claude once. It writes the page for a real business — designed for that industry rather than the same AI-looking layout, written the way a copywriter writes, and structured so search engines and AI assistants can quote it. Then convert the file it gives you, here.', 'aitoel-html-importer'); ?>
        </p>
        <ul style="margin:0 0 10px 18px; padding:0; list-style:disc; color:#444; font-size:13px; line-height:1.6;">
            <li style="margin-bottom:4px;">
                <strong><?php esc_html_e('Designed for the business, not the template.', 'aitoel-html-importer'); ?></strong>
                <?php esc_html_e('It reads the visual codes of the category first, then commits to one art direction instead of hedging between three.', 'aitoel-html-importer'); ?>
            </li>
            <li style="margin-bottom:4px;">
                <strong><?php esc_html_e('Copy a competitor could not publish.', 'aitoel-html-importer'); ?></strong>
                <?php esc_html_e('Numbers, names, places and timeframes instead of adjectives — every line tested against “could the business next door publish this?”', 'aitoel-html-importer'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Written to be found and quoted.', 'aitoel-html-importer'); ?></strong>
                <?php esc_html_e('One search intent per page, one H1, headings that make sense read alone, a self-contained 40-60 word answer under each that an AI engine can quote, a real skip link and a heading order that never skips a level.', 'aitoel-html-importer'); ?>
            </li>
        </ul>
        <p style="margin:0; font-size:13px;">
            <?php
            printf(
                /* translators: %s: link to the free design skill download page (opens in new tab) */
                esc_html__('Download it free: %s', 'aitoel-html-importer'),
                '<a href="https://aitoelementor.com/skill/" target="_blank" rel="noopener noreferrer">aitoelementor.com/skill</a>'
            );
            ?>
        </p>
    </div>

    <!-- Progress / Result area -->
    <div id="htel-progress" class="htel-card" style="display:none;">
        <div class="htel-progress-bar">
            <div class="htel-progress-fill"></div>
        </div>
        <p id="htel-progress-text"><?php esc_html_e('Converting...', 'aitoel-html-importer'); ?></p>
    </div>

    <div id="htel-result" class="htel-card" style="display:none;">
        <div id="htel-result-success" style="display:none;">
            <h3><?php esc_html_e('Template Created!', 'aitoel-html-importer'); ?></h3>
            <p id="htel-result-info"></p>
            <div class="htel-result-actions">
                <a id="htel-edit-link" href="#" class="button button-primary" target="_blank">
                    <?php esc_html_e('Open in Elementor Editor', 'aitoel-html-importer'); ?>
                </a>
                <a id="htel-library-link" href="#" class="button" target="_blank">
                    <?php esc_html_e('View in Templates Library', 'aitoel-html-importer'); ?>
                </a>
            </div>

            <?php // Shown only when the site has Elementor's Flexbox Container feature OFF —
                  // converted templates use containers and would render blank otherwise. ?>
            <div id="htel-container-warning" style="display:none; margin-top:18px; padding:14px 16px; background:#fcf3e3; border-left:3px solid #d98500; border-radius:3px; font-size:13px; line-height:1.55;">
                <strong style="display:block; margin-bottom:6px; color:#8a5300;">
                    <?php esc_html_e('Action needed: enable Flexbox Containers', 'aitoel-html-importer'); ?>
                </strong>
                <p style="margin:0 0 10px 0;">
                    <?php esc_html_e('Your template was created, but Elementor’s Flexbox Container feature is turned OFF on this site. Converted pages use containers, so this page will appear BLANK on the front end until you enable it.', 'aitoel-html-importer'); ?>
                </p>
                <p style="margin:0;">
                    <button type="button" id="htel-enable-containers-btn" class="button button-primary htel-enable-containers">
                        <?php esc_html_e('Enable Flexbox Containers', 'aitoel-html-importer'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-settings#tab-experiments')); ?>" target="_blank" rel="noopener" style="margin-left:10px;">
                        <?php esc_html_e('or do it manually in Elementor → Settings → Features', 'aitoel-html-importer'); ?>
                    </a>
                    <span class="htel-enable-containers-status" style="margin-left:10px;"></span>
                </p>
                <p id="htel-container-warning-noperm" style="display:none; margin:8px 0 0 0; color:#8a5300;">
                    <?php esc_html_e('Ask a site administrator to enable it at Elementor → Settings → Features → Flexbox Container.', 'aitoel-html-importer'); ?>
                </p>
            </div>

            <?php // Missing-widget warning — shown only when the imported template uses widget
                  // types this site's Elementor does NOT register (Elementor 4 "legacy widgets").
                  // Those blocks render EMPTY in the editor and on the page until enabled.
                  // Populated by admin.js from data.missing_widgets ([] → hidden). ?>
            <div id="htel-missing-widgets-warning" style="display:none; margin-top:18px; padding:14px 16px; background:#fcf3e3; border-left:3px solid #d98500; border-radius:3px; font-size:13px; line-height:1.55;">
                <strong style="display:block; margin-bottom:6px; color:#8a5300;">
                    <?php esc_html_e('Heads up: some widgets are not active on this site', 'aitoel-html-importer'); ?>
                </strong>
                <p style="margin:0 0 10px 0;">
                    <?php esc_html_e('Your template was created, but it uses Elementor widgets that are not active here:', 'aitoel-html-importer'); ?>
                    <code id="htel-missing-widgets-list"></code>.
                    <?php esc_html_e('Those blocks will appear EMPTY in the editor and on the page.', 'aitoel-html-importer'); ?>
                </p>
                <p style="margin:0;">
                    <?php esc_html_e('Newer Elementor versions ship these classic widgets as “legacy”. Enable them under', 'aitoel-html-importer'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=elementor-settings#tab-experiments')); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e('Elementor → Settings → Features', 'aitoel-html-importer'); ?>
                    </a>
                    <?php esc_html_e('(look for Legacy / Classic widgets or Nested Elements), then reload the editor.', 'aitoel-html-importer'); ?>
                </p>
            </div>

            <?php // Compatibility heads-up — shown only when the converter flagged features that may
                  // not convert perfectly (Divi, JS frameworks, canvas, video, forms, etc.). The
                  // template still imported; this lists each issue + how to make the page convert
                  // cleanly. Populated by admin.js from data.compatibility (null → hidden). ?>
            <div id="htel-compat-notice" style="display:none; margin-top:18px; padding:14px 16px; background:#fcf3e3; border-left:3px solid #d98500; border-radius:3px; font-size:13px; line-height:1.55;">
                <strong id="htel-compat-headline" style="display:block; margin-bottom:6px; color:#8a5300;"></strong>
                <p id="htel-compat-message" style="margin:0 0 10px 0;"></p>
                <ul id="htel-compat-issues" style="margin:0; padding-left:18px;"></ul>
            </div>

            <div class="htel-result-howto" style="margin-top:18px; padding:14px 16px; background:#f4f7fb; border-left:3px solid #2271b1; border-radius:3px; font-size:13px; line-height:1.55;">
                <strong style="display:block; margin-bottom:6px; font-size:13px;">
                    <?php esc_html_e("How to use this template", 'aitoel-html-importer'); ?>
                </strong>
                <p style="margin:0 0 8px 0;">
                    <?php
                    // Two usage paths customers miss most often. Spelling them out
                    // here saves a surprising amount of support email — Elementor's
                    // "Add Section" (+) button only shows SECTION-type templates,
                    // but we save converted output as PAGE-type (which is correct
                    // for full-page designs). The template IS saved, it just lives
                    // in a different place than customers expect.
                    esc_html_e('Use the blue button above to open it in Elementor right away — the fastest path to preview and tweak.', 'aitoel-html-importer');
                    ?>
                </p>
                <p style="margin:0 0 6px 0;">
                    <strong><?php esc_html_e('To insert it into an existing page:', 'aitoel-html-importer'); ?></strong>
                </p>
                <ol style="margin:0 0 6px 18px; padding:0;">
                    <li><?php esc_html_e('Edit that page in Elementor.', 'aitoel-html-importer'); ?></li>
                    <li>
                        <?php
                        printf(
                            /* translators: 1: folder icon label, 2: tab name */
                            esc_html__('Click the %1$s icon (not the %2$s button — that only lists Section-type templates).', 'aitoel-html-importer'),
                            '<strong>' . esc_html__('folder', 'aitoel-html-importer') . '</strong>',
                            '<strong>+</strong>'
                        );
                        ?>
                    </li>
                    <li>
                        <?php
                        printf(
                            /* translators: %s: tab name */
                            esc_html__('Switch to the %s tab, find your template, click Insert.', 'aitoel-html-importer'),
                            '<strong>' . esc_html__('My Templates', 'aitoel-html-importer') . '</strong>'
                        );
                        ?>
                    </li>
                </ol>
                <p style="margin:8px 0 0 0; color:#5a6478;">
                    <?php esc_html_e('All your converted templates live under Templates → Saved Templates in the WordPress sidebar.', 'aitoel-html-importer'); ?>
                </p>
            </div>
        </div>
        <?php
        /*
         * Both headings are rendered here (as data-* attributes) rather than being built in JS,
         * so every string stays translatable through the normal PHP text-domain path.
         *
         * Why a second heading exists: a quota response is NOT a failure. Nothing broke — the
         * site used the conversions included with it. Labelling that "Conversion Failed" in red,
         * next to a "Try Again" button that cannot succeed, misdescribes the one moment the
         * customer is deciding what to do next.
         *
         * This is corrective ONLY. No CTA, no extra button, no pricing or plan wording is added
         * here — see the Trialware note in admin.js.
         */
        ?>
        <div id="htel-result-error" style="display:none;">
            <h3 id="htel-error-heading"
                data-default-text="<?php esc_attr_e('Conversion Failed', 'aitoel-html-importer'); ?>"
                data-quota-text="<?php esc_attr_e('Conversion limit reached', 'aitoel-html-importer'); ?>"><?php
                esc_html_e('Conversion Failed', 'aitoel-html-importer');
            ?></h3>
            <p id="htel-error-message"></p>
            <button type="button" id="htel-retry-btn" class="button">
                <?php esc_html_e('Try Again', 'aitoel-html-importer'); ?>
            </button>
        </div>
        <div id="htel-feedback-section" style="display:none; margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb;">
            <a href="#" id="htel-report-link" style="font-size:13px; color:#666;">
                <?php esc_html_e('Something not right? Report a problem', 'aitoel-html-importer'); ?>
            </a>
            <div id="htel-feedback-form" style="display:none; margin-top:12px;">
                <textarea id="htel-feedback-text" rows="3" style="width:100%; margin-bottom:8px;"
                    placeholder="<?php esc_attr_e('Describe what looks wrong (e.g., missing section, wrong colors, broken layout)...', 'aitoel-html-importer'); ?>"></textarea>
                <button type="button" id="htel-send-feedback" class="button">
                    <?php esc_html_e('Send Report', 'aitoel-html-importer'); ?>
                </button>
            </div>
            <p id="htel-feedback-sent" style="display:none; color:#16a34a; font-size:13px;"></p>
        </div>
    </div>

    <?php
    // Upgrade-CTA card removed in v1.3.17 per WP.org Guideline 5 (Trialware).
    // The plugin no longer presents trialware-style upgrade prompts. External
    // service quota messages are relayed through the standard error panel.
    ?>
</div>
