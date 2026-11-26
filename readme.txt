=== Leadstart SEO ===
Contributors: leadstartorg
Tags: seo, instant indexing, indexnow, schema, geo
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Donate link: https://leadstart.org/donate

SEO, search engine indexing, schema, GEO, analytics and Content AI for WordPress.

== Description ==

Leadstart SEO helps you manage search visibility, optimize metadata, generate structured data and notify search engines of public content changes.

The plugin is designed for blogs, stores, and pretty much any WordPress website, with support for posts, pages and public custom post types.

= Search Engine Optimization =

* Local SEO analysis of saved content.
* Custom SEO titles and meta descriptions.
* Native WordPress excerpt support for pages and public custom post types.
* Automatic description fallback using saved excerpts.
* Canonical URL and robots directive settings.
* Open Graph metadata for social sharing.
* Per-post SEO configuration.

= Search Engine Indexing =

* Google Indexing API for eligible pages.
* IndexNow for notifying participating search engines of public content changes.
* Optional Bing Webmaster URL Submission API and fallback.
* Manual, bulk and automatic submissions.
* Google URL Inspection and notification metadata.
* Submission history, local quota monitoring and retries.

Search engines determine when and whether submitted content is indexed.

= Structured Data =

You can generate Schema.org JSON-LD for posts, pages and public custom post types, including articles, jobs, events and products. Configure schema mappings and factual properties in the administration interface and post editor.

= Generative Engine Optimization (GEO) =

* Public post type selection.
* Saved SEO descriptions and excerpts.
* Navigation-aware content discovery.
* Per-post priority and exclusion controls.
* Optional local candidate-ID caching.
* Optional AI crawler restrictions.

The llms.txt document is generated through WordPress without requiring a separate external service and is used by AI crawlers depending on their implementation.

= Analytics =

You can retrieve reports from Google Analytics 4 and Google Search Console, including website traffic, search performance, landing pages and geographic information.

The Analytics module is reporting-only and does not add tracking scripts or analytics cookies. You can use Google Site Kit with Leadstart SEO.

= Content AI =

You can use the native WordPress AI Client for SEO titles, descriptions, excerpts, headings, keywords and other suggestions. Configure a provider through WordPress Connectors. Provider fees may apply.

= Privacy =

The plugin does not send developer usage statistics or operational logs. No Leadstart account or subscription is required.

== Installation ==

1. Upload the ZIP under Plugins > Add New > Upload Plugin. Activate per site; network-wide activation is not supported. Requires WordPress 7.0+, PHP 8.1+ and OpenSSL for Google authentication and encrypted credentials. PHP DOM is recommended.
2. Back up SEO settings and metadata before switching from another SEO plugin. Deactivate it before enabling Leadstart SEO metadata and schema. Settings are not imported from other SEO plugins. Site Kit can remain active.
3. Go to Leadstart SEO > Settings, enable SEO metadata output and select excerpt settings and post types. Edit a post using the Leadstart SEO panel. Use SEO Analysis to review saved content.
4. Enable JSON-LD in Settings. Go to Schema, select and save post type mappings, and enter required factual fields in the post editor. Incomplete required fields prevent output. Verify the published page source.
5. For Google services, enable the APIs in Google Cloud and create a service account. Grant verified ownership in Search Console for indexing, property access for Search Console reports and GA4 Viewer access for analytics. Save the service account JSON in Settings and test each connection. See https://developers.google.com/search/apis/indexing-api/v3/prereqs
6. Enable IndexNow in Settings and save with a blank key to generate one. Verify the public key URL with Test connection. If your server cannot route .txt requests to WordPress, host a matching static key file and set its relative path. To use Bing fallback, configure a Bing Webmaster API key and enable legacy submission.
7. In Instant Indexing, select a provider and action and enter one same-site URL per line. Google updates require eligible public posts marked in the editor. Review History before enabling automatic submissions for selected post types.
8. For Analytics, enter the Search Console property or numeric GA4 property ID in Settings and test. Open Analytics, select a source, report and period, then Get report.
9. For Content AI, configure a provider in WordPress Settings > Connectors and enable Content AI in Leadstart SEO Settings. Open Content AI, select a task, provide input and consent, then Generate suggestion.
10. In GEO, enable llms.txt and select public post types. Review priority and exclusions. Check /llms.txt while logged out. Your server must route it to WordPress. Disable competing generators, back up any static file before relocating it, and review custom content access restrictions.

== External services ==

Google OAuth and Indexing API: service account email and a signed assertion are sent to Google OAuth; authorized URLs and notification types are sent to Google Indexing API for configured submissions. https://developers.google.com/search/apis/indexing-api/v3/using-api ; https://policies.google.com/terms ; https://policies.google.com/privacy

Google Search Console, URL Inspection and GA4 Data API: sends property identifiers, date ranges, metrics/dimensions and inspected URLs for requested reports. Read-only scopes are used. https://developers.google.com/webmaster-tools ; https://developers.google.com/analytics/devguides/reporting/data/v1 ; https://policies.google.com/privacy

IndexNow: sends site host, public verification key/key location and changed URLs when enabled. https://www.indexnow.org/documentation ; https://www.indexnow.org/terms ; https://privacy.microsoft.com/privacystatement

Bing Webmaster API: sends verified site URL, submitted URLs and API key in the documented request query when explicitly enabled. https://learn.microsoft.com/en-us/bingwebmaster/ ; https://www.microsoft.com/en-us/servicesagreement ; https://privacy.microsoft.com/privacystatement

WordPress Content AI: selected provider receives prompt text and any explicitly selected saved post or local image only on authorized requests. Provider terms, costs and retention depend on the connector selected by the site administrator. https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/

GEO: locally generates a public Markdown document from published post titles, same-site URLs and saved SEO descriptions/excerpts. No GEO data is sent to a remote service by this plugin. Visitors and crawlers can retrieve the public document. The format is a proposal, not a ranking or citation guarantee: https://llmstxt.org/

Local data: credentials (encrypted or constants), settings, private queue/history (terminal records approximately 30 days), temporary report cache (15 minutes), temporary admin results (2 minutes), and post-level metadata. The plugin does not send developer usage statistics or operational logs. Credential encryption does not protect against a compromised hosting environment.

== Frequently Asked Questions ==

= Do I need another SEO plugin? =

No, we provide our own SEO metadata, analysis, structured data and indexing functionality.

= Can I use Leadstart SEO with Rank Math, All in One SEO (AIOSEO) or Yoast SEO? =

No compatibility, we do not provide integration or support at this time.

= Can I use Google Site Kit with Leadstart SEO? =

Yes, we provide reporting-only analytics and do not add tracking scripts.

= Does the plugin support custom post types? =

Yes, administrators can configure excerpt support, schema mappings, GEO inclusion and eligible indexing options for public custom post types.

= How are excerpts used for SEO? =

Saved excerpts can be used for meta descriptions, Open Graph descriptions and applicable structured data when the corresponding settings are enabled.

Important note: Manually entered SEO descriptions take precedence.

= Does instant indexing guarantee that my content appears in search results? =

No, search engines determine crawling, indexing and search visibility.

= Can I submit every page to Google's Indexing API? =

No, Google's Indexing API is intended for only specific eligible pages. You can learn more on what is acceptable: https://developers.google.com/search/apis/indexing-api/v3/quickstart

= What is llms.txt? =

A text file (Markdown-based format) for organizing website information for artificial intelligence. The llms.txt file does not guarantee visibility, results or improved rankings.

= Does Leadstart SEO require a subscription? =

No subscription is required. 

External service providers may charge for their services, including AI usage.

= Does Content AI automatically modify my content? =

No, you can review and apply suggestions yourself.

= Does Leadstart SEO collect usage statistics? =

The plugin does not send developer usage statistics or operational logs. 

= Why is my llms.txt document not available? =

Please check that GEO is enabled and that your site is public.
Check that /llms.txt routes to WordPress. 

== Changelog ==

= 1.0 =
* Sanitized custom Schema JSON values before storage and when read for output. Validated nested URLs, dates and typed values; retained supported job description HTML.
* Tightened form input and credential validation.
* Added GEO priority, navigation content selection, candidate-ID caching and cache invalidation.
* Added optional AI crawler restrictions and additional content eligibility checks.
* Added developer hooks for descriptions, schema and GEO.
* Added native excerpts for public post types and excerpt-based SEO descriptions.
* Added configurable llms.txt generation and per-post exclusions.
* Included indexing, SEO analysis, schema, analytics and Content AI modules.

= Earlier development versions (1.0.0–1.0.3) =
* Introduced direct Google and Bing API integration, IndexNow, local SEO analysis, schema, reporting and WordPress Content AI.
* Improved credential handling, output escaping and distribution packaging.
