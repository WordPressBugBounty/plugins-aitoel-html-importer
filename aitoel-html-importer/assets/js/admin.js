/**
 * HTML to Elementor — Admin JavaScript
 */
(function($) {
    'use strict';

    var uploadedFileContent = null;
    var uploadedFile = null; // Raw file reference for ZIP uploads

    /**
     * UTF-8-safe base64 encoder. The native btoa() requires Latin-1 input;
     * any character outside U+00FF (Greek/Italian quotes, em-dashes, emoji,
     * etc.) throws InvalidCharacterError. The unescape(encodeURIComponent(...))
     * trick percent-encodes the string into UTF-8 byte sequences first, then
     * unescape() interprets each %XX as a Latin-1 byte that btoa() accepts.
     *
     * Used to encode the HTML body before POSTing to admin-ajax — bypasses
     * shared-host WAFs (mod_security, Imunify360) that pattern-match POST
     * bodies for HTML/script signatures and 403 the request before it
     * reaches WordPress.
     */
    function utf8ToBase64(str) {
        return btoa(unescape(encodeURIComponent(String(str || ''))));
    }

    /**
     * Turn a jQuery $.ajax `error` xhr into a human-readable diagnostic.
     *
     * The plugin's PHP handlers already send structured JSON errors with a
     * `data.message` field on any code-path they control. If that's what we
     * got back, use it verbatim — it's the richest info available.
     *
     * The JS error path fires when:
     *   - The browser never reached WP admin-ajax (status=0 / CORS / offline)
     *   - Admin-ajax returned non-2xx (usually a PHP fatal, timeout, 413, or
     *     a security-plugin / WAF interception page)
     *   - The response body isn't valid JSON
     *
     * In all of those the generic "Connection error. Please try again." hid
     * the cause from the customer AND from us, making triage expensive. This
     * helper unpacks the xhr into an actionable message: status code, likely
     * cause, and (when available) a sanitized snippet of the response body
     * that usually names the culprit (Fatal error / Wordfence / Sucuri /
     * nginx 413 / etc.).
     *
     * Returns a string with line-separators as `\n` — callers render with
     * `.html()` plus `<br>` conversion so the error panel shows structure.
     */
    function describeAjaxError(xhr, defaultMsg) {
        // Best case: our PHP handler returned a structured JSON error.
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }

        var status = xhr ? xhr.status : 0;
        var statusText = (xhr && xhr.statusText) ? String(xhr.statusText) : '';
        var lines = [];

        if (status === 0) {
            lines.push('The browser could not reach your WordPress admin endpoint — the request was dropped before your server answered.');
            lines.push('Most common cause: an image-heavy page. base64-embedded images make the upload too large, so your host (or a security firewall) drops the oversized request. Fix: for pages with images, use the ZIP upload (HTML + images) — it imports the images to your Media Library and keeps the upload small.');
            lines.push('Otherwise check your network, disable any browser extension that blocks requests, then refresh and retry.');
        } else if (status === 504 || status === 502) {
            lines.push('Your web server returned HTTP ' + status + (statusText ? ' (' + statusText + ')' : '') + ' while the conversion was running.');
            lines.push('This almost always means your host killed the PHP process because it ran too long. AI-generated pages can take 40–90 seconds the first time.');
            lines.push('Fix: ask your host to raise PHP max_execution_time (and nginx proxy_read_timeout if applicable) to at least 180 seconds, then retry.');
        } else if (status === 413) {
            lines.push('Your web server rejected the upload as too large (HTTP 413).');
            lines.push('Fix: ask your host to raise client_max_body_size / post_max_size / upload_max_filesize to at least 10 MB.');
        } else if (status === 403) {
            lines.push('The request was blocked (HTTP 403).');
            lines.push('Usually a security plugin (Wordfence / Sucuri / iThemes) or a host-level firewall is filtering admin-ajax or the aitoelementor.com API. Whitelist api.aitoelementor.com and /wp-admin/admin-ajax.php for your IP.');
        } else if (status === 400) {
            lines.push('WordPress rejected the request (HTTP 400 Bad Request from admin-ajax).');
            lines.push('This almost always means a security plugin (Wordfence / Sucuri / iThemes) inspected the HTML you submitted, treated tags inside it as a possible XSS attack, and stripped fields out of the request before WordPress could process it.');
            lines.push('Fix in Wordfence: Firewall → All Firewall Options → Allowlisted URLs → add /wp-admin/admin-ajax.php with parameter name "html" (POST body). Or pause the WAF (set to Learning Mode) for one conversion.');
            lines.push('Fix in Sucuri / iThemes: temporarily disable the WAF or whitelist your own IP, then run the conversion.');
        } else if (status >= 500) {
            lines.push('WordPress returned HTTP ' + status + (statusText ? ' (' + statusText + ')' : '') + '. This is a PHP fatal error or crash on your site — NOT the conversion API (which is on a separate server).');
            lines.push('Open /wp-admin → Tools → Site Health → Info → Server, check PHP error log, or enable WP_DEBUG to see the underlying error.');
        } else if (statusText === 'timeout') {
            lines.push('The browser timed out waiting for WordPress.');
            lines.push('Very large HTML files may need the PHP max_execution_time raised on your host (default is often 30 seconds; set to 180).');
        } else if (status >= 400) {
            lines.push('HTTP ' + status + (statusText ? ' ' + statusText : '') + ' from WordPress admin-ajax.');
        } else {
            lines.push(defaultMsg || 'The request failed for an unknown reason.');
        }

        // When the server sent back an HTML / text body (e.g. PHP fatal page,
        // WAF block page, Apache 504 page), strip tags and include a short
        // snippet — it almost always names the exact culprit.
        if (xhr && xhr.responseText) {
            var snippet = String(xhr.responseText)
                .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                .replace(/<[^>]+>/g, ' ')
                .replace(/&nbsp;/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            if (snippet && snippet.length > 10) {
                lines.push('Server said: "' + snippet.slice(0, 300) + (snippet.length > 300 ? '…' : '') + '"');
            }
        }

        return lines.join('\n');
    }

    /**
     * Make a bare mention of the conversion service's own host clickable.
     *
     * Service messages arrive as plain text, so a message like "…see the plans at
     * aitoelementor.com." rendered the host as inert text and the reader had to retype it by
     * hand. This is a usability fix to how ANY service message is rendered — it is not a
     * promotional element: it adds no wording, is not conditional on licence or quota state,
     * and only ever makes text the service already sent reachable.
     *
     * SECURITY: runs on the ALREADY-ESCAPED string, and the href is constructed here from a
     * hardcoded origin plus a strictly-validated path — never taken from the message body. A
     * spoofed or tampered message therefore cannot choose the destination.
     */
    function linkifyServiceHost(escapedHtml) {
        return escapedHtml.replace(
            /(?:https?:\/\/)?(?:www\.)?aitoelementor\.com(\/[^\s<)",;]*)?/gi,
            function (match, pathPart) {
                var path = pathPart || '/';
                // Only a plain path may survive; anything else falls back to the site root.
                if (!/^\/[A-Za-z0-9\-._~!$&'()*+,;=:@%/]*$/.test(path)) { path = '/'; }
                return '<a href="https://aitoelementor.com' + path + '" target="_blank" rel="noopener noreferrer">'
                    + match + '</a>';
            }
        );
    }

    /** Escape + render a message (which may contain \n) into an element as HTML. */
    function renderMessage($target, message) {
        var escaped = $('<div>').text(String(message || '')).html();
        $target.html(linkifyServiceHost(escaped.replace(/\n/g, '<br>')));
    }

    /**
     * A quota response is not a failure — nothing broke, the site used the conversions included
     * with it. Presented as a red "Conversion Failed" beside a "Try Again" button that cannot
     * succeed, it misdescribes the situation and offers an action guaranteed to fail.
     *
     * This ONLY REMOVES inaccuracy: it retitles, de-escalates the colour, and hides the dead
     * button. It adds no call to action, no button, no link, and no wording about plans or
     * pricing — the message text still comes from the service verbatim. That distinction is what
     * keeps it clear of the Trialware finding in 280e006, which was about the plugin ADDING
     * upgrade prompts and a "1 free conversion" badge of its own.
     *
     * Reasons come from class-converter.php, the same mechanism already used for
     * js_rendered_page. Anything unrecognised falls back to the normal error presentation, so a
     * new server-side reason can never silently soften a genuine failure.
     */
    function applyQuotaPresentation(reason) {
        var isQuota = (reason === 'free_limit_reached' || reason === 'monthly_limit_reached');
        var $heading = $('#htel-error-heading');
        // .attr(), not .data(): jQuery camel-cases data keys, so `.data('quota-text')` is not a
        // reliable way to read `data-quota-text`. Reading the attribute directly is unambiguous,
        // and a silent miss here would just leave the wrong heading in place.
        var attr = isQuota ? 'data-quota-text' : 'data-default-text';
        var text = $heading.attr(attr);
        if (text) { $heading.text(text); }
        $('#htel-result-error').toggleClass('htel-quota', isQuota);
        $('#htel-error-message').toggleClass('htel-quota', isQuota);
        // Retrying cannot clear a quota — offering it invites a second identical dead end.
        $('#htel-retry-btn').toggle(!isQuota);
    }

    /**
     * SHOW A PANEL *AND TAKE THE USER TO IT*. Showing it is not the same as them seeing it.
     *
     * 1.3.43 added a 481px panel between the Convert button and both the progress bar and the
     * result box. Measured in the QA WordPress on 2026-09-10: the result box landed 618px below
     * the button, against 117px with that panel hidden — and nothing scrolled. So a customer
     * clicked Convert, the button flickered and sat back down, and the answer was off-screen.
     * Reported from a live install as "I clicked convert and nothing happened".
     *
     * Every outcome was affected, not just failures: "Template Created!" with its Open-in-Editor
     * button, the quota refusal that names the plans page, and the compatibility notice that tells
     * a prospect their CSS never arrived — all rendered correctly, all unseen.
     *
     * SCROLLING, NOT MOVING THE PANEL. Where that panel sits is a product decision; a layout that
     * only works while nothing is inserted above the results is the defect. This holds whatever
     * gets added later, which is the point.
     *
     * `block:'center'` because the admin bar is fixed and 'start' tucks the first ~32px under it.
     * Guarded: `scrollIntoView` options are ignored on older browsers, and a bare call still
     * reveals the panel, which is the whole requirement.
     */
    function revealPanel($el) {
        $el.show();
        var node = $el.get(0);
        if (!node || typeof node.scrollIntoView !== 'function') { return; }
        try { node.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        catch (e) { node.scrollIntoView(); }
    }

    // ─── Tab switching ───
    $(document).on('click', '.htel-tab', function() {
        var tab = $(this).data('tab');
        $('.htel-tab').removeClass('active');
        $(this).addClass('active');
        $('.htel-tab-content').removeClass('active');
        $('#htel-tab-' + tab).addClass('active');
    });

    // ─── File upload ───
    var $uploadArea = $('#htel-upload-area');
    var $fileInput = $('#htel-file-input');

    $(document).on('click', '#htel-upload-area .htel-upload-placeholder', function(e) {
        e.stopPropagation();
        $('#htel-file-input')[0].click();
    });

    $uploadArea.on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
    }).on('drop', function(e) {
        var files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) handleFile(files[0]);
    });

    $fileInput.on('change', function() {
        if (this.files.length > 0) handleFile(this.files[0]);
    });

    $(document).on('click', '.htel-remove-file', function(e) {
        e.stopPropagation();
        uploadedFileContent = null;
        uploadedFile = null;
        $fileInput.val('');
        $uploadArea.find('.htel-upload-placeholder').show();
        $uploadArea.find('.htel-upload-info').hide();
    });

    function handleFile(file) {
        var ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'html' && ext !== 'htm' && ext !== 'zip') {
            alert('Only .html, .htm, and .zip files are accepted');
            return;
        }

        // ZIP files: 50MB limit (contains images)
        var maxSize = ext === 'zip' ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
        if (file.size > maxSize) {
            alert(ext === 'zip' ? 'ZIP file exceeds 50MB limit' : 'File exceeds 5MB limit');
            return;
        }

        if (ext === 'zip') {
            // Store the raw file for FormData upload
            uploadedFile = file;
            uploadedFileContent = null;
            $('#htel-file-name').text(file.name + ' (ZIP — images will be imported)');
            $uploadArea.find('.htel-upload-placeholder').hide();
            $uploadArea.find('.htel-upload-info').show();

            var $title = $('#htel-title');
            if (!$title.val()) {
                $title.val(file.name.replace(/\.zip$/i, ''));
            }
        } else {
            // HTML file: read as text
            uploadedFile = null;
            var reader = new FileReader();
            reader.onload = function(e) {
                uploadedFileContent = e.target.result;
                $('#htel-file-name').text(file.name);
                $uploadArea.find('.htel-upload-placeholder').hide();
                $uploadArea.find('.htel-upload-info').show();

                var $title = $('#htel-title');
                if (!$title.val()) {
                    $title.val(file.name.replace(/\.(html|htm)$/i, ''));
                }
            };
            reader.readAsText(file);
        }
    }

    // ─── Convert ───
    $(document).on('click', '#htel-convert-btn', function() {
        var html = '';
        var isZip = false;
        var activeTab = $('.htel-tab.active').data('tab');

        if (activeTab === 'paste') {
            html = $('#htel-html-input').val();
        } else if (uploadedFile) {
            isZip = true;
        } else {
            html = uploadedFileContent;
        }

        if (!isZip && (!html || !html.trim())) {
            alert('Please paste HTML or upload a file first');
            return;
        }

        // Auto-detect an image-heavy single-file upload. base64-embedded images bloat the
        // browser->WP-admin POST past what shared hosts / WAFs (mod_security, Imunify360) and
        // post_max_size accept, so the request is DROPPED before it reaches the converter — the
        // customer sees "could not reach your WordPress admin endpoint" (status 0) on big pages
        // while small pages succeed (the "it works sometimes" report). Steer them to the ZIP flow
        // up front, which imports images to the Media Library and keeps the upload small.
        if (!isZip && html) {
            var dataImgs = (html.match(/["']data:image\//gi) || []).length;
            var tooBig = html.length > 3 * 1024 * 1024;
            if (dataImgs >= 3 || tooBig) {
                var proceed = window.confirm(
                    'This page has ' + (dataImgs >= 3 ? dataImgs + ' embedded images' : 'a large amount of content') +
                    ' and may be too big to upload as a single file. Large uploads are often dropped by your host or firewall before they reach the converter.\n\n' +
                    'Recommended: upload it as a ZIP (the HTML plus its images) instead — the images go into your Media Library and the upload stays small.\n\n' +
                    'OK = try as-is anyway,  Cancel = stop so you can zip it.'
                );
                if (!proceed) { return; }
            }
        }

        var title = $('#htel-title').val() || 'Imported Page';

        // Show progress — and go there, so the customer watches the conversion happen instead of
        // watching a button they cannot tell apart from a dead one.
        $('#htel-result').hide();
        revealPanel($('#htel-progress'));
        updateProgress(10, isZip ? 'Uploading ZIP and importing images...' : 'Sending HTML to conversion service...');
        $('#htel-convert-btn').prop('disabled', true).text('Converting...');

        // Simulate progress while waiting
        var progressInterval = setInterval(function() {
            var $fill = $('.htel-progress-fill');
            var current = parseInt($fill.css('width')) / $fill.parent().width() * 100;
            if (current < 80) {
                var msg = current < 20 ? (isZip ? 'Extracting ZIP and uploading images...' : 'Analyzing HTML structure...') :
                    current < 50 ? (isZip ? 'Importing images to Media Library...' : 'Converting to Elementor format...') :
                    current < 70 ? 'Converting to Elementor format...' : 'Saving template...';
                updateProgress(current + 3, msg);
            }
        }, 2000);

        // Build AJAX request
        var ajaxConfig = {
            url: htel_ajax.ajax_url,
            method: 'POST',
            timeout: 180000,
            success: function(response) {
                clearInterval(progressInterval);
                updateProgress(100, 'Done!');

                setTimeout(function() {
                    $('#htel-progress').hide();

                    if (response.success) {
                        var data = response.data;
                        var info = 'Created template with ' + data.element_count + ' elements';
                        if (data.font_count > 0) info += ', ' + data.font_count + ' fonts';
                        if (data.image_count > 0) info += ', ' + data.image_count + ' images imported to Media Library';
                        info += '.';
                        $('#htel-result-info').text(info);
                        // Site-part usage note (Pro vs non-Pro) — string is localized in PHP.
                        if (data.site_part_note) {
                            $('#htel-result-info')
                                .append($('<br/>'))
                                .append($('<strong/>').text(data.site_part_note));
                        }
                        // Fragment auto-suggest: the grader spotted a lone header/footer and
                        // the box was NOT ticked — offer to redo it as a site part.
                        var hasFragmentIssue = data.compatibility && data.compatibility.issues &&
                            data.compatibility.issues.some(function (i) { return i.id === 'fragment'; });
                        if (hasFragmentIssue && !$('#htel-intent-sitepart').is(':checked')) {
                            $('#htel-fragment-suggest').show();
                        }
                        $('#htel-edit-link').attr('href', data.edit_url);
                        $('#htel-library-link').attr('href', data.library_url);
                        $('#htel-result-success').show();
                        $('#htel-result-error').hide();

                        // Flexbox Container feature OFF → the new (container-based)
                        // template renders BLANK on the front end. Warn prominently
                        // and offer a one-click enable to admins.
                        if (data.containers_active === false) {
                            $('#htel-container-warning').show();
                            if (data.can_enable_containers) {
                                $('#htel-enable-containers-btn').show();
                                $('#htel-container-warning-noperm').hide();
                            } else {
                                $('#htel-enable-containers-btn').hide();
                                $('#htel-container-warning-noperm').show();
                            }
                        } else {
                            $('#htel-container-warning').hide();
                        }

                        // Missing widgets (Elementor 4 legacy case): the template uses widget
                        // types this site does not register → those blocks render EMPTY.
                        // Uses .text() (XSS-safe).
                        if (data.missing_widgets && data.missing_widgets.length) {
                            $('#htel-missing-widgets-list').text(data.missing_widgets.join(', '));
                            $('#htel-missing-widgets-warning').show();
                        } else {
                            $('#htel-missing-widgets-warning').hide();
                        }

                        // Compatibility heads-up: the conversion service flags features that may
                        // not convert perfectly (Divi, JS frameworks, canvas, video, forms, heavy
                        // raw-HTML). null on a clean page → show nothing. Each issue lists what's
                        // wrong + how to make the page convert cleanly. Uses .text() (XSS-safe).
                        var compat = data.compatibility;
                        if (compat && compat.issues && compat.issues.length) {
                            $('#htel-compat-headline').text(compat.headline || 'Some parts may not convert perfectly');
                            $('#htel-compat-message').text(compat.message || '');
                            var $ul = $('#htel-compat-issues').empty();
                            $.each(compat.issues, function (i, issue) {
                                var $li = $('<li>').css('margin-bottom', '8px');
                                $('<span>').text(issue.reason || '').appendTo($li);
                                if (issue.fix) {
                                    $li.append('<br>');
                                    $('<em>').text('Fix: ' + issue.fix).css('color', '#6b4b00').appendTo($li);
                                }
                                $ul.append($li);
                            });
                            $('#htel-compat-notice').show();
                        } else {
                            $('#htel-compat-notice').hide();
                        }

                        // Show "Report a problem" link
                        $('#htel-feedback-section').show();
                        $('#htel-feedback-form').hide();
                        $('#htel-feedback-sent').hide();

                        // Per WP.org Guideline 5 (Trialware): the result screen
                        // adds no upgrade prompt of its own. The external
                        // conversion service enforces its own quota; the plugin
                        // relays its messages verbatim. (The "Go Premium" link
                        // added in 1.3.46 is an item in the admin sidebar menu,
                        // registered in includes/class-admin.php; nothing in
                        // this result handling adds or shows it.)
                    } else {
                        // renderMessage (not .text()) so multi-line service messages keep their
                        // structure and a bare mention of the service host is reachable. Still
                        // escaped first — see linkifyServiceHost.
                        renderMessage($('#htel-error-message'), response.data.message || 'Unknown error');
                        applyQuotaPresentation(response.data && response.data.reason);
                        $('#htel-result-success').hide();
                        $('#htel-result-error').show();
                    }
                    revealPanel($('#htel-result'));
                }, 500);
            },
            error: function(xhr) {
                clearInterval(progressInterval);
                $('#htel-progress').hide();

                var msg = describeAjaxError(xhr, 'Connection error. Please try again.');
                renderMessage($('#htel-error-message'), msg);
                // Reset the panel: a transport failure IS a failure. Without this, the softened
                // presentation from a previous quota response would persist on the shared panel
                // and a genuine error would render as a neutral notice with no retry button.
                applyQuotaPresentation(null);
                $('#htel-result-success').hide();
                $('#htel-result-error').show();
                revealPanel($('#htel-result'));
            },
            complete: function() {
                $('#htel-convert-btn').prop('disabled', false).text('Convert to Elementor');
            }
        };

        // Site-part intent (header mode). One checkbox covers header AND footer per the
        // approved copy; which one it IS comes from the markup/title — a <footer> tag or a
        // footer-ish title means footer, everything else declared is a header.
        var sitePartIntent = '';
        if ($('#htel-intent-sitepart').is(':checked')) {
            // NO BACKSLASH ESCAPE HERE, DELIBERATELY. This line shipped as /<footer\b/i, and that
            // \b did not survive whatever wrote the file: the byte on disk was 0x08, a literal
            // BACKSPACE, so the first alternative could never match and footer detection ran on
            // the TITLE alone. A customer ticking the site-part box and uploading a footer got a
            // HEADER template unless they happened to name the page 'footer'. Found by a sweep
            // for control bytes, not by reading the line — it looks correct in every editor.
            // `[^a-z]` is the same word boundary with nothing for a heredoc to eat.
            var looksFooter = /<footer[^a-z]/i.test(html || '') || /footer/i.test(title || '');
            sitePartIntent = looksFooter ? 'footer' : 'header';
        }
        $('#htel-fragment-suggest').hide();

        if (isZip) {
            var formData = new FormData();
            formData.append('action', 'htel_convert');
            formData.append('nonce', htel_ajax.convert_nonce);
            formData.append('title', title);
            formData.append('zip_file', uploadedFile);
            if (sitePartIntent) { formData.append('intent', sitePartIntent); }
            ajaxConfig.data = formData;
            ajaxConfig.processData = false;
            ajaxConfig.contentType = false;
        } else {
            // Encode HTML body as base64 before sending. Many shared-hosting
            // providers (especially cPanel hosts running mod_security or
            // Imunify360) pattern-match POST bodies for HTML/script signatures
            // and 403 the request at the Apache layer before it reaches
            // WordPress. The customer's WordPress sees nothing — the firewall
            // intercepts upstream. Base64 is opaque to those rules.
            //
            // The PHP handler decodes html_b64 transparently and proceeds
            // through the normal conversion pipeline. Body grows ~33%, which
            // is fine — most servers allow 8MB+ POST bodies.
            ajaxConfig.data = {
                action: 'htel_convert',
                nonce: htel_ajax.convert_nonce,
                html_b64: utf8ToBase64(html),
                title: title
            };
            if (sitePartIntent) { ajaxConfig.data.intent = sitePartIntent; }
        }

        $.ajax(ajaxConfig);
    });

    // Retry button
    $(document).on('click', '#htel-retry-btn', function() {
        $('#htel-result').hide();
        $('#htel-convert-btn').trigger('click');
    });

    // ─── Feedback / Report a problem ───
    $(document).on('click', '#htel-report-link', function(e) {
        e.preventDefault();
        $('#htel-feedback-form').toggle();
    });

    $(document).on('click', '#htel-send-feedback', function() {
        var description = $('#htel-feedback-text').val().trim();
        if (!description) {
            alert('Please describe the problem.');
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Sending...');
        $.post(htel_ajax.ajax_url, {
            action: 'htel_feedback',
            nonce: htel_ajax.convert_nonce,
            description: description
        }, function(response) {
            if (response.success) {
                $('#htel-feedback-form').hide();
                $('#htel-feedback-sent').show().text(response.data.message);
            } else {
                alert(response.data.message || 'Failed to send feedback.');
            }
            $btn.prop('disabled', false).text('Send Report');
        }).fail(function(xhr) {
            alert(describeAjaxError(xhr, 'Connection error. Please try again.'));
            $btn.prop('disabled', false).text('Send Report');
        });
    });

    // One-click "Enable Flexbox Containers". Shared by the admin notice button
    // and the post-conversion warning button. Flips Elementor's container
    // experiment via AJAX, then reloads so the UI reflects the new state.
    $(document).on('click', '.htel-enable-containers', function() {
        var $btn = $(this);
        var $status = $btn.closest('p').find('.htel-enable-containers-status').first();
        if (!$status.length) $status = $('.htel-enable-containers-status').first();
        var original = $btn.text();
        $btn.prop('disabled', true).text('Enabling…');
        $status.text('');
        $.post(htel_ajax.ajax_url, {
            action: 'htel_enable_containers',
            nonce: htel_ajax.convert_nonce
        }).done(function(response) {
            if (response && response.success) {
                $status.css('color', '#1a7f37').text((response.data && response.data.message) || 'Enabled.');
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                var msg = (response && response.data && response.data.message) ||
                    'Could not enable automatically. Please enable it in Elementor → Settings → Features.';
                $status.css('color', '#b32d2e').text(msg);
                $btn.prop('disabled', false).text(original);
            }
        }).fail(function(xhr) {
            $status.css('color', '#b32d2e').text(describeAjaxError(xhr, 'Request failed. Enable it manually in Elementor → Settings → Features.'));
            $btn.prop('disabled', false).text(original);
        });
    });

    function updateProgress(percent, text) {
        $('.htel-progress-fill').css('width', percent + '%');
        if (text) $('#htel-progress-text').text(text);
    }

    // ─── License activation ───
    $(document).on('click', '#htel-activate-btn', function() {
        var key = $('#htel-license-key').val().trim();
        if (!key) {
            showLicenseMessage('Please enter a license key', 'error');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Activating...');

        $.ajax({
            url: htel_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'htel_activate_license',
                nonce: htel_ajax.license_nonce,
                license_key: key
            },
            success: function(response) {
                if (response.success) {
                    showLicenseMessage('License activated! Reloading...', 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showLicenseMessage(response.data.message || 'Activation failed', 'error');
                }
            },
            error: function(xhr) {
                showLicenseMessage(describeAjaxError(xhr, 'Connection error. Please try again.'), 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Activate');
            }
        });
    });

    // ─── License deactivation ───
    // Previously this unconditionally showed "License deactivated" even when
    // the server refused (e.g. trying to deactivate from a domain that wasn't
    // bound). The plugin wiped local state so the user thought it worked,
    // while server-side the real bindings stayed — which burned their 2-domain
    // slots and made new activations fail mysteriously. Now we differentiate:
    //   - success (response.success === true) → deactivated, reload
    //   - error with bound_domains → show them so user knows where the real
    //     bindings are and can deactivate from those sites
    //   - generic error → show the server message
    $(document).on('click', '#htel-deactivate-btn', function() {
        if (!confirm('Are you sure you want to deactivate this license?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Deactivating...');

        $.ajax({
            url: htel_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'htel_deactivate_license',
                nonce: htel_ajax.license_nonce
            },
            success: function(response) {
                if (response && response.success) {
                    var data = response.data || {};
                    var msg = data.message || 'License deactivated';
                    // Stale-cache path: server said "not bound here" but we
                    // wiped local state because the cache was wrong. Inform
                    // the user plus show where the real bindings live (if any).
                    if (data.was_stale && data.bound_domains && data.bound_domains.length) {
                        msg += '\n\nThe license IS still active on:\n  • ' +
                            data.bound_domains.join('\n  • ') +
                            '\n\nTo free a slot, click Deactivate from one of those sites.';
                    }
                    showLicenseMessage(msg + '\n\nReloading...', 'success');
                    setTimeout(function() { location.reload(); }, 2500);
                    return;
                }
                var errData = (response && response.data) ? response.data : {};
                var errMsg = errData.message || 'Deactivation failed';
                if (errData.bound_domains && errData.bound_domains.length) {
                    errMsg += '\n\nThe license is currently active on:\n  • ' +
                        errData.bound_domains.join('\n  • ') +
                        '\n\nTo free a slot, deactivate from one of those sites ' +
                        '(or contact support with your license key).';
                }
                showLicenseMessage(errMsg, 'error');
            },
            error: function(xhr) {
                showLicenseMessage(describeAjaxError(xhr, 'Connection error. Please try again.'), 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Deactivate License');
            }
        });
    });

    // ─── License refresh / sync with server ───
    // Uses htel_refresh_license_state (not the legacy htel_validate_license)
    // because the new endpoint uses /api/license/bindings as the source of
    // truth and actively syncs the WP options to match. If the server says
    // this site isn't bound, local cache gets wiped so the user falls back
    // to the activation form — fixes the stale-cache bug where a site
    // remained "connected" locally after being deactivated elsewhere.
    $(document).on('click', '#htel-refresh-btn', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Syncing...');

        $.ajax({
            url: htel_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'htel_refresh_license_state',
                nonce: htel_ajax.license_nonce
            },
            success: function(response) {
                if (response && response.success) {
                    var data = response.data || {};
                    showLicenseMessage(data.message || 'Synced.', 'success');
                    // If the license was cleared (this site wasn't bound) OR is
                    // active (just re-synced metadata), reload to reflect the
                    // fresh state in the settings UI.
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    var errData = (response && response.data) ? response.data : {};
                    showLicenseMessage(errData.message || 'License sync failed', 'error');
                }
            },
            error: function(xhr) {
                showLicenseMessage(describeAjaxError(xhr, 'Connection error — could not reach license server.'), 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Refresh Status');
            }
        });
    });

    function showLicenseMessage(msg, type) {
        var $el = $('#htel-license-message');
        // Preserve newlines (deactivate errors include a bulleted bound-domain
        // list) by converting \n to <br> and escaping the rest of the text.
        var escaped = (msg + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        $el.html(escaped.replace(/\n/g, '<br>')).removeClass('success error').addClass(type).show();
        if (type === 'success') {
            setTimeout(function() { $el.fadeOut(); }, 5000);
        }
    }

    // Fragment banner: one click ticks the site-part box and re-runs the conversion.
    $(document).on('click', '#htel-fragment-yes', function () {
        $('#htel-intent-sitepart').prop('checked', true);
        $('#htel-fragment-suggest').hide();
        $('#htel-convert-btn').trigger('click');
    });

})(jQuery);
