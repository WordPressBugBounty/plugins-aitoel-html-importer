=== AI to Elementor — HTML Importer for Elementor ===
Contributors: georget777
Tags: elementor, html import, ai, page builder, import
Requires at least: 5.8
Requires Plugins: elementor
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.49
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert any HTML page into a fully editable Elementor template with one click. Built for AI-generated pages and hand-written HTML alike.

== Description ==

**AI to Elementor — HTML Importer for Elementor** converts HTML code into native Elementor templates — no copy-pasting into HTML widgets, no broken layouts. Your HTML becomes real Elementor containers, headings, text editors, images, buttons, and videos that you can edit visually.

*This plugin is an independent project and is not affiliated with, endorsed by, or sponsored by Elementor Ltd. "Elementor" is a trademark of Elementor Ltd. This plugin is a conversion bridge **for** Elementor.*

**Perfect for:**

* AI-generated pages from ChatGPT, Lovable, bolt.new, v0, or any code generator
* Static HTML websites you want to migrate to WordPress + Elementor
* Landing pages built in code that need to become editable templates
* Agencies converting client HTML mockups into Elementor sites

**How it works:**

1. Paste the HTML for **one page**, or upload that page as an .html/.zip file
2. Click "Convert to Elementor"
3. Open your new template in the Elementor editor
4. Repeat for each additional page

**Important — convert one page at a time:** This is a page converter, not a
whole-site importer. Each conversion turns a single HTML page into one editable
Elementor template. To migrate a multi-page site, convert each page separately
(e.g. index.html, about.html, contact.html — one at a time). A .zip may contain
one page's HTML plus its images and CSS, but it should not contain an entire
website.

**For the best results,** follow our HTML guidelines — cleaner, well-structured
HTML converts far more accurately (especially for AI-generated pages):
[https://aitoelementor.com/ai-guidelines/](https://aitoelementor.com/ai-guidelines/)

**Features:**

* Converts HTML to native Elementor widgets (not HTML widgets)
* Preserves fonts, colors, spacing, and layout
* Supports containers, sections, headings, text, images, buttons, videos, icons, and more
* ZIP upload with automatic image import to Media Library
* Google Fonts detection and registration
* **Works with Elementor 4** — converted pages import as classic elements, which Elementor 4 officially supports and lets you edit alongside its new Atomic elements (verified on Elementor 4.2)

**What it costs (straight answer):**

The plugin is free and every feature is unlocked — nothing is disabled in the
code. The conversion itself runs on an external service (see below), and that
service is metered:

* **Free tier: one (1) free conversion per website**, no sign-up and no key
  required. Use it to try the plugin on a real page and see the quality before
  you decide anything.
* **Paid tiers (optional):** if you need to convert more pages, the service
  operator offers subscription plans with higher monthly conversion quotas at
  [aitoelementor.com](https://aitoelementor.com). You enter the key in Settings.

This is a service quota, not a crippled plugin — the plugin code is identical on
the free and paid tiers. When you reach the free limit the plugin keeps working
and simply tells you the quota is reached; nothing breaks or disappears.

== External Service: AI to Elementor Conversion API ==

This plugin sends HTML content to the AI to Elementor conversion service for processing. The conversion is performed by a Node.js-based engine that runs on the service's external servers — it is not feasible to ship the engine inside a PHP plugin (the engine is ~17 MB of bundled JavaScript that uses headless-browser APIs, a CSS-tree parser, and other Node-only dependencies that cannot run in PHP).

**What the plugin sends to the service:**

* Your HTML content (the page you want to convert)
* The template title you choose
* Your site's domain (used by the service to associate usage with your account)
* Your subscription key, if you have entered one in Settings (optional — without a key the service allows one free conversion per site)

**What the service keeps:**

* A copy of the HTML you submitted, retained for up to 90 days to identify and fix conversion bugs, then deleted automatically. Stored with your site's domain and a timestamp only — never with your subscription key or email. Not shared with third parties and not used to train any model offered to others. This applies on the free tier as well as paid plans, and you can opt out in Settings &gt; Data &amp; Privacy or request deletion at privacy@aitoelementor.com. Full terms: Section 9.4 of the Terms of Service linked below.

**What the service returns:**

* Elementor-compatible JSON template data, which the plugin saves as an Elementor template on your site
* A list of Google Fonts detected in your HTML, which the plugin registers via Elementor

**Service operator and policies:**

* Service URL: [https://api.aitoelementor.com](https://api.aitoelementor.com)
* Operator website: [https://aitoelementor.com](https://aitoelementor.com)
* Terms of Service: [https://aitoelementor.com/terms-and-conditions/](https://aitoelementor.com/terms-and-conditions/)
* Privacy Policy: [https://aitoelementor.com/privacy-policy](https://aitoelementor.com/privacy-policy)

The service has a free tier that allows one (1) conversion per website with no key, and optional paid subscription tiers with higher monthly conversion quotas. Both tiers use the same plugin code; the subscription is purely a service-side relationship with the operator and is not a condition of using the plugin. The plugin works fully on the free tier without any subscription. Quotas (if reached) are enforced by the external service and reported back to the plugin as plain error messages.

The service URL can be changed in **Settings → AI to Elementor** if you operate your own compatible conversion endpoint.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` (or install directly from the WordPress Plugins screen → Add New)
2. Activate the plugin through the "Plugins" menu in WordPress
3. Ensure Elementor is installed and activated
4. Go to "AI to Elementor" > "Convert" in the admin menu
5. Paste HTML or upload a file and click "Convert to Elementor"

== Frequently Asked Questions ==

= Do I need Elementor Pro? =

No. The plugin works with the free version of Elementor.

= Does it work with Elementor 4 (the Atomic editor)? =

Yes. Converted pages import as classic elements, which Elementor 4 officially supports
alongside Atomic — you edit them exactly as before, and classic elements have no
deprecation date. Verified on Elementor 4.2. If your site ships a widget your template
uses as "legacy" (off by default), the plugin tells you exactly which one and how to
enable it after the import.

= What HTML can I convert? =

Any valid HTML page — from AI code generators, static websites, landing page builders, or hand-coded HTML. The converter handles modern CSS layouts including flexbox and grid.

= How do I get the best conversion quality? =

Cleaner, well-structured HTML converts more accurately — semantic tags, sensible class names, and standard CSS layouts all help, while heavily-nested or obfuscated markup (common in some AI exports) is harder to map. We publish a short set of HTML guidelines with concrete do's and don'ts here: https://aitoelementor.com/ai-guidelines/ — worth a look before converting AI-generated pages.

= Can I import a whole website at once? =

No — and this is important. The plugin converts **one page per conversion**, not an entire site in a single step. Each conversion produces one Elementor template from one HTML page. If you have a multi-page website, convert each page on its own: paste or upload index.html and convert it, then about.html, then contact.html, and so on. If you upload a .zip, it should contain a single page's HTML plus that page's images and CSS — not a full-site export. Trying to convert a whole site in one go will not work as expected.

= My converted page is blank / empty on the front end — why? =

The most common cause is that Elementor's **Flexbox Container** feature is turned off on your site. Converted templates are built from containers, so with that feature disabled the page renders blank (even though the template imported correctly). The plugin now detects this and shows a warning with a one-click **Enable Flexbox Containers** button (for administrators). You can also enable it manually at **Elementor → Settings → Features → Flexbox Container** (set it to Active and save). After enabling, reload the page — it will render.

= My icons came in as markup instead of editable images — why? =

Icons drawn as inline SVG can be imported two ways. By default they are kept as markup: they render
exactly as designed, but they are not editable through Elementor image controls, and the import
notice tells you how many were kept that way.

The alternative is to save each icon into your Media Library as a real file, which makes it an
ordinary Elementor Image widget. That is off by default on purpose — writing SVG into a Media
Library is a security decision that belongs to the site owner, not to a plugin. Every icon is
re-sanitised through a geometry allowlist and Elementor's own SVG sanitizer before anything is
written, and the permission is scoped to the import itself rather than switching on SVG uploads
site-wide.

To enable it, add this to a small plugin or your theme functions.php:

`add_filter( 'htel_import_svg_icons', '__return_true' );`

Convert the page again afterwards — the setting applies at import time, so pages already imported
keep the markup they arrived with.

= What happens to images in my HTML? =

If you upload a ZIP file containing your HTML and images folder, all images are automatically imported to your WordPress Media Library and the paths are updated in the template.

= How many conversions do I get for free? =

The conversion service includes one (1) free conversion per website — no sign-up or key required — so you can try it on a real page first. If you need to convert more, the service operator offers optional paid subscription plans with higher monthly quotas; you enter the key in Settings. The plugin itself requires no subscription and locks no features — the quota is on the external conversion service, and the plugin works the same on both tiers.

= Where does my HTML go? =

To the conversion service at `api.aitoelementor.com`. See the "External Service" section above for full details, including the operator's Terms of Service and Privacy Policy links.

= Where are my converted templates? =

Templates are saved to the Elementor Template Library. Go to "Templates" > "Saved Templates" in your WordPress admin, or use the direct link provided after conversion.

= Which AI page generators does it work with? =

It works with the HTML output of any code generator — ChatGPT, Claude, Lovable, bolt.new, v0, Cursor, and Anthropic Stitch are all supported, including the offline/bundled export formats some of them produce. It also works with plain hand-written HTML and static site exports. If it's valid HTML, the converter can process it.

= What happens after I use my 1 free conversion? =

The plugin keeps working — you simply see a plain message from the service saying you've used your free conversion for this site. Nothing in the plugin is disabled or locked. If you need to convert more pages you can optionally subscribe to a paid plan on the service operator's website and paste the key into Settings.

= Is my HTML kept or shared? =

Your HTML is sent to the conversion service to perform the conversion, and the service also keeps a copy for up to 90 days to find and fix conversion bugs. It is stored with your site's domain and a timestamp only — never with your subscription key or email — and is deleted automatically. It is not shared with third parties and is not used to train any model offered to others. This applies on the free tier as well as paid plans.

You can switch it off: untick sampling in the plugin's Settings > Data &amp; Privacy, and nothing further is kept. You can also ask for anything already collected from your site to be deleted by emailing privacy@aitoelementor.com with your domain. Full terms are in Section 9.4 of the operator's Terms of Service, linked in the "External Service" section above.

The plugin does not send anything in the background — it only contacts the service when you click Convert, enter a key, or save Settings.

= The conversion failed or my page looks off — what do I do? =

The result screen auto-diagnoses common causes (security/optimization plugins like Wordfence or SG Security blocking the request, outbound HTTPS blocked, or request-body size). Follow the on-screen guidance for your specific setup. For best fidelity, upload a ZIP that includes your HTML plus its images and CSS files so the converter has the full page.

== Screenshots ==

1. Main conversion screen — paste HTML or upload a file
2. Successful conversion with template link
3. Converted template in the Elementor editor
4. Settings page — service connection and data preferences

== Changelog ==

= 1.3.49 =
* Fixed: backslashes were removed from what the plugin saved. A backslash in CSS - an escaped
  class name like `.md\:flex`, or a quote mark written `content:"\201C"` - was lost from a
  converted page's settings, which broke that CSS. With "Add the page's colours and fonts to my
  Elementor Site Settings" turned on, a conversion also removed the backslashes from your own Site
  Settings, their Custom CSS for example. A template title lost its backslash too. These values
  are now saved exactly as they are.
* Applies to pages you convert from now on. A backslash already removed from your Site Settings
  is not put back: if your Site Settings Custom CSS uses backslashes, check it.

= 1.3.48 =
* Fixed: an image whose web address has no file extension (Unsplash's, for example) stayed linked
  to the site it came from after import. When such an image arrives as an Image widget or a
  background, it is now copied into your Media Library like any other image, and your page's own
  styles are pointed at the copy too. A file that turns out not to be an image is left where it was.
* Applies to pages you convert from now on. Templates you have already imported are not changed.

= 1.3.47 =
* Improved: less CSS in a converted heading's Title field. When a word inside a heading was styled
  (a coloured accent word, say), opening the Heading showed that styling as code, like
  `<span style="color:#ff6b35">`. The plugin now moves it into the page's own stylesheet and
  leaves a class name in its place - but only where it can tell the page looks exactly the same:
  it asks your WordPress's own filter about each piece of styling, and whatever it cannot move
  safely stays in the Title as before. A page that carries its own scripts is imported exactly as
  before. The page itself looks exactly as it did.
* Applies to pages you convert from now on, by a user WordPress lets save unfiltered HTML
  (administrators and editors on a standard site; network administrators on a multisite).
  Templates you have already imported are not changed.
* Worth knowing: a heading copied to another page keeps its class but not this page's
  stylesheet, so its moved styling stays behind - as many of the page's other converted elements
  already do.

= 1.3.46 =
* New: a "Go Premium" link in the AI to Elementor menu, under Settings, for sites without an
  active licence. Clicking it opens the plans on aitoelementor.com in a new tab. Nothing else
  changes: conversions work exactly as before, and the link is not shown once a licence is active.

= 1.3.45 =
* Fixed: after clicking Convert, the page now takes you to the result. It was always being
  shown - the template link, the conversion limit notice, the "your CSS file is missing"
  warning - but 1.3.43 added a panel between the button and the result area, which left every
  outcome around 600 pixels below the button with nothing scrolling you there. Clicking Convert
  looked like it did nothing, whether the conversion had succeeded or been refused.
* The progress bar is scrolled into view as soon as the conversion starts, so you watch it run
  instead of watching a button you cannot tell apart from a dead one.

= 1.3.44 =
* Improved: when icons on your page are kept as markup instead of becoming editable images, the
  import now says so and how many. This already happened — some pages arrived with a dozen icons
  as raw HTML — but nothing explained why, so the only available conclusion was that the
  conversion had gone badly. It had not: keeping them as markup is deliberate, because saving SVG
  files into a Media Library is a security decision that belongs to you rather than to a plugin.
* The notice tells the two cases apart: the setting being switched off, which you can change, and
  a write that failed even though it is switched on. The FAQ explains how to enable native icon
  import if you want it; icons are re-sanitised before anything is saved either way.

= 1.3.43 =
* The import screen now points to the free design skill: one text file you hand to Claude, which
  writes the page for a real business before you convert it. Nothing installs on your site, and
  there is no account or email to give.
* Privacy, stated plainly: the conversion service keeps a copy of the HTML you submit for up to
  90 days to find and fix conversion bugs, stored with your site domain and a timestamp only,
  never with your subscription key or email, then deleted automatically. It is not shared with
  third parties and is not used to train any model offered to others. You can switch it off in
  Settings > Data & Privacy, or ask for anything already collected to be deleted by emailing
  privacy@aitoelementor.com. This applies on the free tier as well as paid plans, and the paid
  build of 1.3.42 did not carry this disclosure.

= 1.3.42 =
* ZIP uploads: an image referenced by a stylesheet from its own folder — for example a
  stylesheet in css/ using ../images/photo.jpg, which is how most site builders lay a site out —
  is now found in your archive and imported. Previously that reference was left pointing at a
  file that was never uploaded, so the image did not appear and nothing said why. Backgrounds
  in the same stylesheet were the usual casualty.
* The same fix covers a stylesheet referring to a file sitting beside it, such as url(bg.png).

= 1.3.41 =
* ZIP uploads: a stylesheet, image or background referenced with a cache-busting query string
  (for example style.css?v=2) is now found inside your archive and imported. Previously the
  reference was left pointing at a file that was never uploaded, and the page arrived unstyled
  with nothing reported.
* ZIP uploads: a reference that selects part of a file — for example an icon out of an SVG
  sprite, sprite.svg#gear — keeps that selection when the file is imported. Only the cache-busting
  part of the reference is dropped.

= 1.3.40 =
* Global Typography: with “Add the page's colours and fonts to my Elementor Site Settings”
  enabled, headings and body text now arrive as full Global Font presets (size, weight, line
  height, responsive values) that your widgets reference — edit “Heading 2” once in Site
  Settings and every matching heading follows. Pages whose headings vary keep their individual
  styling untouched. If your Site Settings already hold a different style for a heading level,
  new pages keep their own styling for that level instead of adopting it, and the import notice
  says so.

= 1.3.39 =
* Elementor Pro: converting the same navigation again now reuses the WordPress menu it already
  created instead of adding a duplicate with a random suffix. Menus carry an identity marker, so
  reuse works even for menus you have since renamed or reordered.
* You are now always told when a conversion creates or reuses a WordPress menu — previously the
  notice only appeared for header/footer template imports.
* Fixed: an empty or malformed navigation payload could create a blank menu on every conversion.

= 1.3.38 =
* Housekeeping: one internal file-deletion call now goes through WordPress's own wrapper, so backup and antivirus plugins that watch file deletions see it. No change to what is imported or how.

= 1.3.37 =
* When part of your page cannot be imported, the plugin now tells you in your site's error log instead of dropping it silently. That covers ZIP uploads (files skipped for an unsafe path, images or stylesheets we could not read, extra pages we did not import), colours and fonts beyond the per-page limit for Site Settings, inline SVG icons removed by our safety filter, and navigation menus that could not be rebuilt.
* Fixed: an SVG icon our safety filter rejected was written into the page unfiltered. It is now removed instead, and you are told.
* Fixed: when a page could not be prepared for Elementor, you were shown "Template created successfully" and given a link to an empty template. You now get an error and nothing is added to your library.
* Fixed: an image from a ZIP that could not be finished is no longer left behind in your Media Library with nothing pointing at it.
* Fixed: a ZIP containing a file named with two dots in a row, such as photo..final.jpg, was skipped as if it were unsafe.
* Fixed: if the first page in a ZIP was empty or unreadable, you were told no page was found even when a good one was there.

= 1.3.36 =
* Fixed: on a site with a small memory limit, a page carrying large embedded fonts could fail part-way through and leave an empty template in your library. The work is now checked against the memory your host actually has before it starts, and if it will not fit the page still converts with the fonts left where they were.
* Fixed: a payload that claims to be an image but is not one is no longer saved to your Media Library as a broken file. Images are identified by their own contents rather than by what the page calls them, so a PNG mislabelled as a JPEG is saved correctly as a PNG.
* Fixed: on WordPress multisite, your network administrator's list of allowed upload types is now respected.
* When something cannot be saved, the conversion now says so in your site's error log instead of leaving it silently. That covers images and fonts we could not read, files your host refused to store, images copied from other sites that could not be fetched, and anything left over from a conversion that had to be undone.
* Fixed: if a conversion was interrupted part-way through, files it had already copied into your Media Library could be left behind with nothing pointing at them. They are now removed.

= 1.3.35 =
* Fonts embedded in your page are now saved to your Media Library as real font files instead of being carried inside the page itself. On pages that embed their fonts this is the bulk of what gets imported - on our own test pages it moved 5.3 MB out of the page content, up to 1.4 MB from a single page. Those bytes used to be stored in your database and re-sent on every page view.
* A payload that is not actually a font is left exactly where it was, so nothing that merely claims to be a font ends up in your Media Library.
* Fixed: when a page carried an image encoded across several lines - which is how many editors and "Save Page As" captures write them - only part of the image was saved and the picture broke. The whole image is saved now, and anything we cannot read completely is left alone rather than saved in part. This affected images in every previous version.
* Converting the same page twice no longer adds a second copy of the same file.

= 1.3.34 =
* Fixed: when you ticked "I'm converting a header or footer" and uploaded a FOOTER, it was
  imported as a header template unless the page title happened to contain the word "footer".
  Footers are now recognised from the markup, which is what the checkbox always implied. If you
  imported a footer since 1.3.31 and it landed as a header, re-import it — the content was never
  wrong, only the template type.
* Site Styles: with the new "Add this page's colours and fonts to my Site Settings" option ticked,
  the palette and the font families found on the page are added to your Elementor kit as Global
  Colors and Global Fonts. Nothing is ever removed or overwritten — entries you renamed or
  recoloured are left exactly as they are, and importing the same page twice adds nothing. Up to
  12 colours and 4 fonts per page; they accumulate, so converting a whole site can leave dozens of
  entries in a list Elementor does not paginate. The option is OFF by default.
* Fixed: a font family whose own name contains a comma — "PT Sans, Narrow" is a real one — was
  cut short at the comma, so your text fell back to a default font. It now survives intact,
  everywhere it is used.

= 1.3.33 =
* Fixed: the plugin could be offered an update to an OLDER version than the one installed, and installing it would downgrade the plugin. The update check now refuses any version that is not newer than the one you are running, whatever the update service says.
* Fixed: after an update, the previous update check was still cached and could keep offering a version that no longer applied. The cached answer is now discarded as soon as the installed version changes.

= 1.3.32 =
* Fixed: 1.3.31 installed a plugin folder with no plugin file inside it, so WordPress deactivated it with "Plugin file does not exist". The release archive had been built with a Windows tool that writes the wrong path separator; the files were all present but under one long name instead of in folders. If you installed 1.3.31, delete the plugin from the Plugins screen and install this version — your settings, licence and imported templates are untouched, they live in the database and not in the plugin folder.
* Everything listed under 1.3.31 below is in this release too.

= 1.3.31 =
* New: convert a header or footer on its own. Tick "I'm converting a header or footer (site part)" and the result comes back as a header or footer template, with the navigation built from real Elementor widgets instead of a block of raw HTML. Assigning it as your site's header needs Elementor Pro's Theme Builder; without Pro you still get a fully editable template in your library, you just cannot set it as the site header.
* New: if you paste something that looks like a header or footer on its own, the plugin now offers to convert it as a site part instead of quietly treating it as a whole page.
* Improved: a navigation menu written as a list now comes across as separate links, instead of being read as a single menu item.
* Improved: pages that used to come out wider than the original now match their source. In our regression set of 183 real pages, thirteen that overflowed horizontally — the worst by 420 pixels — no longer do, and none got worse.
* Improved: percentage spacing such as `padding: 0 6%` keeps its percentage instead of being converted to pixels.
* Fixed: a decorative corner badge drawn in CSS — a "SALE" or "OUR PICK" ribbon — is no longer painted across the whole card it sits on.
* Fixed: when our rendering service is busy, you are now told to try again shortly instead of being told your page is built by JavaScript. Those are two different problems and only one of them is yours to fix.

= 1.3.30 =
* Fixed: a conversion is no longer counted against your quota unless the template actually reaches your site. Previously the count was taken the moment the conversion service finished, so an import that failed afterwards — a security plugin blocking the response, a server error part-way through saving — still used up an attempt. On the free tier that could leave you unable to try again after a single failed attempt.

= 1.3.29 =
* Improved: when part of a page is stored inside a `<script>` template and assembled by JavaScript as the page loads, the converter now tells you so instead of quietly leaving that section out. The notice names what was missed and how to bring it across, so a partial result is explained rather than surprising.
* Developer: groundwork for importing inline SVG icons as real icons rather than embedded markup. The feature is off by default and imports are unchanged; it can be enabled with `add_filter('htel_import_svg_icons', '__return_true')` while it completes visual review.
* Security: the SVG sanitizer now uses a geometry allowlist — only known-safe drawing elements and attributes survive — instead of blocking a list of known-bad ones. Applies wherever SVG markup is handled.

= 1.3.28 =
* Improved: image-heavy pages import much faster. Embedded images still become real Media Library attachments, but the import now generates only the core thumbnail sizes (thumbnail, medium, large) instead of every size registered on your site — on shared hosting with WooCommerce or theme sizes this was the difference between seconds and minutes. Developers can restore extra sizes via the `htel_import_image_sizes` filter.
* Improved: re-converting the same page no longer re-uploads identical images. Attachments created by the importer carry a content fingerprint, and later conversions reuse the existing file — faster repeat imports and no duplicate images piling up in your Media Library.

= 1.3.27 =
* Improved: the "How it works" guide is now collapsed below the converter ("Not sure how to convert? Click here for the step-by-step guide.") so the paste box is visible immediately. Same content, one click away — nothing about the conversion itself changed.

= 1.3.26 =
* New: a "How it works" guide right on the converter screen. It walks you through the full workflow — how to get your page as one HTML file (including a ready-to-copy prompt for AI builders like Claude and ChatGPT), when to paste the HTML versus uploading a ZIP for image-heavy pages, and how every image in your page is imported into the Media Library as a normal, editable attachment. Nothing about the conversion itself changed.
* Improved: clearer guidance before conversion replaces the previous separate notices (one page at a time, HTML guidelines) — same rules, one tidy panel.
* Improved: image-heavy pastes are detected before sending. If your HTML embeds several large images (or is over ~3 MB), the plugin now suggests the ZIP upload path first — very large single requests can be rejected by your hosting before they ever reach the converter, which used to look like a plugin failure.
* Improved: clearer error message when the request cannot reach your site's admin endpoint (previously a misleading generic failure).
* Internal: groundwork for optional SVG icon handling on import. Off by default — no behavior change in this release.

= 1.3.25 =
* New: missing-widget detection. On newer Elementor versions (Elementor 4+), some classic widgets used by converted templates — for example the Accordion behind FAQ sections — may be shipped as "legacy" and not active, which makes those blocks appear EMPTY in the editor and on the page. Right after a conversion the plugin now checks every widget your template uses against the widgets actually active on your site, and if any are missing it tells you exactly which ones and how to enable them (Elementor → Settings → Features). A dismissible reminder also appears in your Templates library, and it clears itself automatically once the widgets are enabled. Informational only — it never blocks a conversion.

= 1.3.24 =
* New: compatibility notice. After a conversion, if your page has features that don't translate perfectly to Elementor (for example it was built with another page builder, uses a JavaScript slider/carousel, or relies on scripts to draw content), the plugin now shows a short heads-up explaining what to expect and how to get a cleaner result. Fully compatible pages convert silently as before — the notice is informational and never blocks a conversion.
* Improved: much better fidelity on "Elementor-compatible HTML" from AI generators and on icon-heavy pages — hero overlays, full-width footers, pricing tables, and icon-font glyphs (Font Awesome / Tabler) now convert far more faithfully.

= 1.3.23 =
* New: self-contained pages whose images are embedded as data: URIs (e.g. "Save Page As" exports) now import correctly. On conversion the plugin uploads each embedded image into your Media Library and rewrites the page to reference the hosted files. Previously such pages could import blank — Elementor strips embedded images on import and a multi-megabyte embedded page is too heavy to render. This also makes converted pages far lighter and gives you managed, reusable media.

= 1.3.22 =
* New: detects when Elementor's Flexbox Container feature is turned OFF and warns you. Converted templates use containers, so with that feature disabled a converted page renders blank on the front end — the most common "why is my page empty?" cause. The plugin now shows a clear notice (and, right after a conversion, an inline warning) with a one-click "Enable Flexbox Containers" button for administrators, plus a manual path for everyone else.

= 1.3.21 =
* Confirmed compatible with WordPress 7.0 ("Tested up to" bumped to 7.0). No code changes.

= 1.3.20 =
* Added a link to our HTML guidelines (https://aitoelementor.com/ai-guidelines/) on the conversion screen and in the readme/FAQ. Cleaner, well-structured HTML converts more accurately — these guidelines help users (especially with AI-generated pages) get the best results. Guidance only, no functional change.

= 1.3.19 =
* Clearer guidance: the converter works on one page at a time, not whole sites. The conversion screen now shows a "Convert one page at a time" notice, the upload area explains that a .zip should contain a single page (HTML + its images/CSS) rather than an entire website, and the readme adds a dedicated FAQ. No functional change — this prevents the common mistake of trying to import a full multi-page site in a single conversion.

= 1.3.18 =
* Better conversion quality (automatic — the engine runs on the service, so there's nothing to reinstall). Noticeably improved fidelity on: AI-generated pages, custom font weights and italics, image galleries built with CSS Grid, hero sections with background images and overlays, scroll/entrance animations, icons, and offline/bundled exports from tools like Anthropic Stitch and Lovable.
* Internationalization: every visible message in the plugin can now be translated (18+ previously English-only strings are now localizable).
* No breaking changes — existing connections, keys, and behavior are unchanged.
* Technical details on the 17 engine fixes in this release are listed at aitoelementor.com.

= 1.3.17 =
* WordPress.org compliance pass per the Plugin Directory Guidelines (Guideline 5: Trialware / Guideline 6: Serviceware). All in-plugin "upgrade" call-to-action UI has been removed from the main conversion screen, settings page, and converter error messages. The plugin no longer presents trialware-style framing. Service quota enforcement remains on the external conversion API (per Guideline 6, which permits external service dependencies); error messages now relay the service's quota message neutrally without an "Upgrade →" button. The README's "External Service" section now documents the plugin–service relationship explicitly, including why the engine must run externally, what is sent to the service, what is returned, and links to the service operator's Terms of Service and Privacy Policy.

= 1.3.16 =
* Defense-in-depth hardening for the API URL setting. On top of v1.3.15's read-time fallback, this release adds: (a) settings-save sanitize callback that refuses to persist an empty or malformed URL and surfaces an admin notice if the user tries; (b) auto-heal — when the read-time fallback encounters a bad stored option, that option is now deleted so it cannot resurface on later page loads; (c) plugin activation hook that clears stale bad options from prior versions on update. Three independent layers must all fail before a customer can hit the empty-URL bug — making the F1 regression structurally impossible.
* Added a regression test suite (37 assertions across 5 files) that runs against a real WordPress install in docker. Every customer-reported bug since v1.3.12 now has a permanent test that breaks if the fix regresses. New release process gates publish on the test suite passing — no fix can disappear silently in a refactor again.
* No customer-facing change. If your conversion works on v1.3.15, it works the same on v1.3.16.

= 1.3.15 =
* Fixes "A valid URL was not provided" error: if the stored API URL setting is empty, malformed, or missing scheme, the plugin now falls back to the default (https://api.aitoelementor.com) automatically. Affected sites where the API URL field was accidentally cleared on the Settings page or wiped by an import/migration. Previously the plugin would send a request with no scheme/host and WordPress would reject it with `http_request_failed`, while the connection-error diagnostic incorrectly blamed the customer's firewall. The diagnostic now distinguishes "URL is malformed" (configuration issue, surfaced clearly) from "outbound is blocked" (firewall, real diagnosis).
* Adds workaround for shared-hosting Web Application Firewalls (mod_security on cPanel hosts, Imunify360, BitNinja). These WAFs scan POST request bodies for HTML/script signatures at the Apache layer and 403 the conversion request before it reaches WordPress, returning a generic "Apache 403 Forbidden" page that customers couldn't diagnose. The plugin now base64-encodes the HTML payload before POSTing to admin-ajax — the encoded body is opaque to pattern-based WAFs, so the request passes through. The PHP handler decodes transparently. No customer action required; conversions on previously-blocked hosts now work without contacting the host or whitelisting.

= 1.3.14 =
* ZIP upload now extracts and inlines external CSS files (e.g. assets/styles.css) into the HTML before sending to the converter. Previously the plugin only sideloaded images and dropped any .css files in the ZIP; the converter then saw a dead `<link rel="stylesheet" href="assets/styles.css">` reference and missed the bulk of layout rules (grid, flex, aspect-ratio, etc.). Pages with separate stylesheets now convert with their full CSS — typically a 30-50% improvement in visual fidelity for sites that split inline + external CSS. Image url() references inside the inlined CSS are rewritten to the new WP Media Library URLs so they continue to resolve. Absolute stylesheet URLs (Google Fonts, CDNs) are left as `<link>` tags and not inlined.

= 1.3.12 =
* Conversion Failed panel now auto-diagnoses security/optimisation plugins blocking the request. The error message:
  - Probes /api/health from your server to distinguish "outbound HTTPS fully blocked" from "convert request specifically blocked by request-body filter" (the SG Security pattern).
  - Detects active SG Security, SG Optimizer, LiteSpeed Cache, Wordfence, iThemes / Solid Security, MalCare and prints the exact menu path / fix step for the detected plugin.
  - Notes the request body size so you can tell at a glance whether the failure is size-related.
  - Surfaces WP_HTTP_BLOCK_EXTERNAL state if it's set in wp-config.php.
  Previously this fell through to generic "Possible causes" bullets that customers had to triage themselves.

= 1.3.11 =
* Conversion Failed panel now recognizes HTTP 400 from admin-ajax as a security plugin (Wordfence / Sucuri / iThemes) stripping fields out of the POST body, and prints the exact Wordfence allowlist steps inline. Previously this case fell through to a bare "HTTP 400 Bad Request" message with no guidance.

= 1.3.10 =
* Settings page now shows the active service-side plan name and monthly usage when a subscription key is connected. Customers without a subscription continue to use the service's free tier (one conversion per site) as before.

= 1.3.8 =
* After a successful conversion, the result screen now tells you exactly where your template lives and how to insert it into an existing page. The "+ / Add Section" menu in Elementor only shows Section-type templates, but converted pages are saved as Page-type templates — which caused some customers to think their template wasn't saved at all. It was; it's in Templates → Saved Templates, and the step-by-step for inserting via the folder icon + "My Templates" tab is now spelled out on the success panel.
* No other changes.

= 1.3.7 =
* Actionable error messages. If conversion fails because of a timeout, firewall block, memory exhaustion, or other host-side issue, the Conversion Failed screen now names the actual cause (HTTP status, server message, typical fix) instead of showing the generic "Connection error. Please try again." The same improvement applies to subscription-key activation, deactivation, and refresh buttons.
* No engine changes.

= 1.3.5 =
* Renamed to "AI to Elementor — HTML Importer for Elementor" (slug `aitoel-html-importer`) to avoid trademark confusion per WordPress.org plugin review guidance. Internal class/function names and the plugin directory are unchanged.
* Security: sanitize all `$_FILES` fields at the request boundary (html_file and zip_file) before any downstream use; verify uploads with `is_uploaded_file()`. The derived filename-based template title is now `sanitize_text_field()`-filtered before being sent to the API.
* Security: escape `$exp_date` (from the service API's `expires_at`) when echoing into the settings page. Other echo sites in the settings template migrated to `esc_attr()`, `wp_kses()` with an explicit allowlist, or `esc_html()` — escaping late at the output boundary in every case.
* No behavior changes for converting; existing users can update without re-connecting.

= 1.3.3 =
* Added Conversion Quality Sampling disclosure in Settings > Data & Privacy.
* New opt-out checkbox for sample capture (per ToS §9.4).
* Existing connections are grandfathered — sampling only applies to new subscriptions made after 2026-04-15.

= 1.0.0 =
* Initial release
* HTML to Elementor conversion via external service
* Paste HTML or upload .html files
* Optional service subscription connection
* Progress bar with status updates

== Upgrade Notice ==

= 1.3.49 =
Backslashes in CSS and titles are now kept when a page is converted, including in your own Site Settings when the page's colours and fonts are added to them.

= 1.3.48 =
Images whose web address has no file extension, such as Unsplash's, are now copied into your Media Library when they arrive as Image widgets or backgrounds.

= 1.3.47 =
Less CSS in converted headings' Title fields: where the page looks exactly the same, their styling moves into the page's stylesheet.

= 1.3.46 =
Adds a "Go Premium" link to the plugin's own menu for sites without an active licence (clicking it opens aitoelementor.com in a new tab). No other changes.

= 1.3.22 =
Detects when Elementor's Flexbox Container feature is off (the usual cause of a blank converted page) and offers a one-click fix.

= 1.3.21 =
Confirmed compatible with WordPress 7.0.

= 1.3.20 =
Adds a link to the HTML guidelines in the converter and FAQ to help you get the most accurate conversions.

= 1.3.19 =
Adds clear "one page at a time" guidance in the converter UI and FAQ. Convert each page of a site separately — this is a page converter, not a whole-site importer.

= 1.3.18 =
Engine upgrade (server-side, automatic for everyone) + internationalization fixes. Conversion fidelity now noticeably improved across all customer page types — particularly for AI-generated HTML, sites with custom font weights / italic variants, gallery layouts using CSS Grid + aspect-ratio, hero sections with brightness-filtered background images, and pages from the Anthropic Stitch / Lovable offline-export format. 18+ user-facing error messages are now translatable. No re-connection required.

= 1.3.17 =
WordPress.org compliance pass — all in-plugin "upgrade" call-to-action UI removed. No functional changes for either public-tier or subscribed users; the external conversion service continues to enforce its own quotas. Settings page now surfaces service plan / usage info neutrally without a CTA button.
