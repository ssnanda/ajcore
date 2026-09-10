# Reviews & Testimonials

## Architecture and integration points

`ajcore.php` loads `modules/reviews/bootstrap.php`. The module uses the existing `ajforms` top-level menu and `manage_options` administrator capability. It introduces no separate plugin, custom database table, schema migration, or site-specific configuration. Module code follows AJ Core's prefixed PHP classes and explicit `require_once` loading.

`AJCore_Review_Provider` separates authorization, account/location discovery, fetch, and revocation from storage and administration. The initial implementation uses only official Google endpoints:

- OAuth authorization: `accounts.google.com/o/oauth2/v2/auth`.
- Token exchange/refresh and revocation: `oauth2.googleapis.com`.
- Authorized identity: `openidconnect.googleapis.com/v1/userinfo`.
- Business accounts: `mybusinessaccountmanagement.googleapis.com/v1/accounts`.
- Locations: `mybusinessbusinessinformation.googleapis.com/v1/accounts/{id}/locations` and `v1/locations/{id}`.
- Reviews: `mybusiness.googleapis.com/v4/accounts/{id}/locations/{id}/reviews`.

No scraper, unofficial endpoint, third-party widget, Places API request, review reply, or write to a Google listing is implemented. Normal frontend rendering reads local valid snapshots only.

AJNanda owns block markup, layouts, styling, patterns, and carousel behavior. It uses the functions in `public-api.php`, never this module's options or post metadata. Other themes can consume the same functions. The Google and manual collections stay separate even in the combined PHP result.

The existing shared encryption functions were moved without behavioral changes from `ajcore.php` to `includes/settings-encryption.php`. The reviews vault rejects the legacy helper's plaintext fallback. This preserves existing consumers while making the new feature fail closed.

## Requirements and Google setup

Use a WordPress version supporting the repositories' existing native block architecture. The feature uses PHP 7.4-compatible application syntax; the existing project may require a newer version. WordPress 6.4+ supplies custom-meta revision history; title and content use normal WordPress revisions. PHP sodium and MySQL/MariaDB named locks are required. SQLite and database proxies that do not preserve connection-scoped locks are unsupported and fail closed.

1. Obtain Google Business Profile API access for the appropriate business/tool provider Google Cloud project. A business must be verified, and the connecting Google account must own or be authorized to manage the selected location. An ordinary Maps API key is not sufficient.
2. Enable the Google My Business API (review v4), My Business Account Management API, and My Business Business Information API in the approved project. Confirm usable quota. API approval and OAuth consent verification are separate requirements.
3. Configure a Web application OAuth client and consent screen, including applicable privacy policy, terms, authorized domains, test users during testing, and production verification. Request `https://www.googleapis.com/auth/business.manage`, `openid`, and `email`. This integration reads reviews; it does not use the broader scope to change listings.
4. In **AJ Core → Reviews & Testimonials → Settings**, copy the exact displayed authorized redirect URI to the Google OAuth client's redirect list. Its shape is `https://your-site.example/wp-admin/admin-post.php?action=ajcore_reviews_oauth`; use the actual site-specific URI, including any subdirectory. Use HTTPS, including behind a correctly configured reverse proxy.
5. Save the client ID and client secret. The secret field is always blank when rendered; leaving it blank retains an existing saved secret. Changing either credential disconnects the old integration. Do not place credentials in code or block attributes.
6. Choose **Connect Google Account**, approve access, and return in the same browser and WordPress user session within ten minutes. The page displays the authorized Google email when Google provides a verified email.
7. Choose **Find Locations**. One request walks every authorized account (first ten) and lists every location it can see as a single dropdown labelled *location — account*; choose one and press **Use This Location**. The list expires after 15 minutes; press Find Locations again if it has gone. The selected account and location identifiers remain visible in administration.
8. Choose **Test Connection**, then **Sync Now**. Test Connection validates access to the selected location; Sync Now additionally exercises the reviews API. On the **Reviews & Testimonials** tab, select reviews and set their display order. No review is selected automatically, regardless of rating.

No live credentials are supplied with the repositories. Fixtures contain clearly synthetic, nonfunctional token strings only. Google OAuth test-mode refresh tokens can have limited lifetimes; use Google's current production verification guidance rather than relying on development consent indefinitely.

For agency/tool-provider deployment, resolve the Google project ownership model with Google. The Business Profile policies contain separate rules about supplemental client projects and indirect programmatic access; do not simply require every agency client to create its own project as a workaround.

## Administration

Two tabs, in this order: **Reviews & Testimonials** (everything you publish, and the tab the screen opens on) and **Settings** (everything you configure). The earlier seven-tab layout (Overview, Google Connection, Review Inbox, Featured Reviews, Manual Testimonials, Display Settings, Sync History) is gone; its `?tab=` links redirect to whichever of the two now holds that content, so old bookmarks keep working.

- **Settings** — two columns (stacked on narrow screens). The left column is Google: a **Connection status** checklist showing whether the OAuth client is saved, the Google account connected and a location selected, with the resolved value for each; the redirect URI and credentials form; then **Business location** — Connect/Reconnect, **Find Locations**, Test Connection and Disconnect, with buttons hidden until they can do anything — and the sync status panel. The right column is the Rate Us prompt and display options. Explanatory prose has been removed from this screen; the reference for each field is this document. **Find Locations**, **Use This Location** and **Test Connection** each report their own outcome in the notice at the top of the page instead of a generic "Operation completed". The sync status table adds a **Last Google refusal** row whenever a Google call returns a non-2xx status: the time, the HTTP status, Google's `error.status` enum, the first `reason` and `metadata.service` from `error.details`, and the endpoint host and path. Those fields are enum-shaped and validated against `^[A-Za-z0-9_./-]{1,120}$`; no message text, response body, or credential ever reaches the option (`ajcore_reviews_api_error`, non-autoloading, one record, overwritten). It is cleared at the start of every operation that calls Google and on disconnect, so it always describes the most recent attempt. The last ten sync-history rows sit in a collapsed **Recent sync activity** block; the option still keeps at most 50 entries of timestamp, safe status/error code, processed count, duration, and trigger. The UI translates the safe code into a fixed summary. API bodies, reviewer data, exception messages, and credentials are never logged there.
- **Reviews & Testimonials** — one list holding both sources. Checkboxes at the top choose which are shown (**Google reviews**, **Manual testimonials**, and **Only what is published on the site**, which replaces the old Featured Reviews tab); all sources show by default. **Sync Google Reviews Now** lives here, next to Add / All Manual Testimonial links. Google entries stay read-only — original reviewer/text/rating/date, attribution/source link when available, retrieval and expiry — with feature/unfeature and display order, twenty per page. Manual entries show status, an excerpt, and an Edit link into WordPress's permanent testimonial editor.

HTTP mutations are POST-only with a WordPress nonce and `manage_options`. OAuth uses a random one-time state bound to site storage, user, WordPress session, expiry, and a PKCE verifier; the callback checks administrator permission. Malformed or replayed states fail before exchanging the authorization code. The callback clears the state even for denied authorization. A new authorization requires location selection again.

## Synchronization and retention

The recurring event `ajcore_reviews_sync` runs every **2,419,200 seconds (28 days)**. Choosing a location creates one event and an hourly cleanup event, idempotently. A successful manual or scheduled sync resets the next regular event to 28 days after that success. Frontend requests never fetch Google content.

A per-site MySQL named lock excludes overlapping syncs and concurrent credential, connection, or moderation operations. There is no expiring time-based lease that can let a second sync overlap a slow first sync. Locks release in `finally` and on database disconnect. Sync checks lock ownership before publishing after network activity. Unsupported locking returns a safe busy error rather than proceeding unlocked.

The provider traverses all review pages (50 per page), deduplicates by a hash of location and stable review ID, and checks the result against Google's total. It replaces the complete encrypted snapshot only after all pages, normalization, count checks, and storage succeed. This removes reviews absent from the newly retrieved collection. Equal-rating reviews are treated identically. Feature selection and order survive successful refreshes while the source IDs still exist; new IDs are not automatically featured. Removals are detected at the next successful sync, not in real time.

Bounds: 90-second request budget, at most 12 seconds per HTTP request, 200 review pages, 2 MiB per HTTP response, and 8 MiB of normalized snapshot data. The deadline is checked between calls, so the final in-flight call may finish up to 12 seconds after it. Large/changing collections fail safely without publishing a partial snapshot. A large business exceeding these limits needs a separately designed resumable synchronization implementation.

Temporary transport/429/5xx failures and inconsistent pagination retry after **15, 30, 60, 120, and 240 minutes**, then stop until the next scheduled/manual cycle. Auth, malformed-data, and access errors require administrator intervention. No blocking backoff sleeps occur.

Every snapshot and row expires at retrieval start plus **2,462,400 seconds (28½ days)**. A refresh that fails does not reset any retrieval timestamp or expiry. Public reads reject expired snapshots and individual rows even if cron has not run; the overall rating/location summary expires with its snapshot. A stale, still-valid snapshot can remain visible with its original as-of date. Expired data is never shown as current synchronized content.

Encrypted WordPress transients store Google review content and location summaries; account/location picker results and pending OAuth state are also encrypted short-lived transients. WordPress core transient cleanup continues independently of AJ Core when the plugin is deactivated. With a persistent object cache, its TTL mechanism supplies expiry. An hourly module cleanup removes expired temporary data and obsolete selections while active.

**Operational requirement:** WordPress cannot guarantee physical deletion on a server where neither cron nor requests run. Run WordPress cron regularly using the host scheduler, verify core expired-transient cleanup, and monitor missed jobs. The 28½-day deadline gives core's daily cleanup headroom below 30 days; disabled/failed cron, frozen servers, or cache persistence can still break physical retention. Exclude these Google-content transients from long-lived database/object-cache backups, replicas used for archives, staging copies, exports, and HTTP debug logging. Restoring an old database must never republish an expired snapshot.

Exclude pages containing Google review blocks from page/CDN/static caches. AJNanda sets `DONOTCACHEPAGE` and no-store headers, but a proxy or an early WordPress cache can respond before PHP executes. Connect `ajcore_reviews_content_changed` to any cache purge integration if applicable. Never persist rendered Google HTML. The theme also removes an open collection at expiry, but JavaScript is not a substitute for server/cache retention controls.

## Data inventory, lifecycle, and privacy

All new options are per-site and non-autoloaded:

| Storage key | Data and lifecycle |
|---|---|
| `ajcore_reviews_credentials` | Encrypted OAuth client ID/secret, access/refresh tokens, token expiry, authorized email; removed on disconnect/uninstall |
| `ajcore_reviews_config` | Administrator-selected account/location resource identifiers; removed on disconnect/uninstall |
| transient `ajcore_reviews_snapshot` | Encrypted normalized reviews plus provider rating/count/location summary, retrieval/expiry; max 28½ days |
| transient `ajcore_reviews_choices` | Encrypted account/location picker choices; 15 minutes |
| transient `ajcore_reviews_oauth` | Encrypted state hash, user/session binding and PKCE verifier; 10 minutes, single-use |
| `ajcore_reviews_selection` | Business selection/order keyed by hashed review identity; pruned on successful replacement/cleanup |
| `ajcore_reviews_sync_meta` | Last-success timestamp, safe last-error code, bounded failure count; no review content |
| `ajcore_reviews_history` | Last 50 content-free operational entries |
| `ajcore_reviews_display` | Business-authored fallback and default order |
| `ajcore_testimonial` posts + `_ajcore_testimonial` meta | Permanent, administrator-owned testimonials, independent of Google |

Without an external object cache, WordPress stores transients under `_transient_...` and `_transient_timeout_...` options. The shared pre-existing `ajcore_settings_encryption_key` remains owned by AJ Core's general encryption helper.

Sodium secretbox encrypts and authenticates data with random nonces. Reviews refuse plaintext ciphertext, missing sodium, malformed keys, corrupt ciphertext, and invalid JSON. The shared key is stored in the same WordPress database: this protects against accidental option disclosure, not an attacker with complete database access. Protect database credentials, backups, and administrator access. Preserve the shared key securely during authorized migrations; changing it can make existing AJ Core secrets unreadable.

Only fixed Google HTTPS endpoints are called; redirects are disabled on HTTP requests carrying tokens. API errors become fixed error codes. No raw provider error body or exception message is returned to the browser or stored in history. Exclude OAuth callback query strings and token endpoints from external request/debug logging as well.

Disconnect attempts Google's revocation endpoint, then removes local credentials, selection/config, and all temporary Google content even if revocation fails. Failure is visible in the admin result and history; remove access manually at your Google account's third-party connections page if needed. Manual testimonials and display settings survive disconnection.

Ordinary deactivation unschedules this integration and discards only pending OAuth state. It keeps testimonials, settings, credentials, and reviews within the independently enforced transient expiry. Reactivation schedules per-site events lazily. Multisite uses each site's options, encryption key, resource selection, and lock; configure each subsite separately. Network deactivation/uninstall iterates sites in batches.

Uninstall removes this integration's options, tokens, temporary content and schedules across sites. It deliberately does not remove unrelated AJ Core data or the shared encryption key. **Manual testimonials remain by default.** Reinstall to manage them, or explicitly opt into permanent testimonial deletion by defining `AJCORE_DELETE_MANUAL_TESTIMONIALS` as `true` in `wp-config.php` before uninstall. Disconnect Google before uninstall if you want to confirm remote revocation; uninstall performs local cleanup without needing to load plugin code or make remote requests.

## Manual testimonials

Use the title for the display name and editor for the text. Metadata supports initials, organization/relationship, optional 1–5 rating, exact calendar date, a Media Library image attachment ID, source label/URL, display order, featured flag, and private notes. Images are referenced from the Media Library; no remote review avatar is copied into permanent media.

WordPress manages draft/published/private/trash status and title/content revisions. Registered metadata opts into WordPress 6.4+ meta revisions. Only administrators can edit or publish these records. Comments, public archives, individual frontend URLs, public REST, feeds, search inclusion, and XML sitemap inclusion are disabled. Public queries return only published, unpassworded, featured records with name and text; they never expose notes.

Use **Featured** plus **Publish** to make a record eligible. Optional invalid ratings/dates/images are discarded. Manual text is rendered as plain text without executing shortcodes. No Google review is converted automatically. If independently authorized material is entered manually, it is administrator-managed content; do not misrepresent it as a live Google-synchronized review. Source labels and text remain the administrator's responsibility. Neither collection automatically emits Review/AggregateRating schema.

## Public PHP and REST interfaces

Stable v1 functions, available when AJ Core is active:

```php
if ( function_exists( 'ajcore_get_featured_google_reviews' ) ) {
    $reviews = ajcore_get_featured_google_reviews( 6, 'manual' );
    // Escape at output; never persist the result or render it into discovery/export indexes.
}
```

| Function | Contract |
|---|---|
| `ajcore_get_featured_google_reviews($limit = 6, $order = 'manual')` | Valid featured rows only; limit 1–50; `manual` order then stable ID, or `date` newest first |
| `ajcore_get_google_review_count()` | Count of all valid locally synchronized rows, including unfeatured; distinct from Google's full total |
| `ajcore_get_google_location_summary()` | Valid provider `title`, `location`, `rating`, `total`, `maps_url`, `write_url`; empty after expiry |
| `ajcore_get_reviews_status()` | Safe `state`, `valid_count`, `last_success`, `expires_at`, `stale`; no credentials/errors |
| `ajcore_get_reviews_last_sync()` | Unix last-success timestamp or 0 |
| `ajcore_get_featured_testimonials($limit = 6, $order = 'manual')` | Published, featured manual DTOs, ordered by numeric display order or date; stable ID ties |
| `ajcore_get_review_collections($limit = 6, $order = 'manual')` | Separate `google` and `manual` arrays; no blended rating or merged identities |
| `ajcore_get_reviews_display_settings()` | Sanitized plain-text `fallback` and `order` (`manual`/`date`) |

Google DTOs include `kind=google`, `id`, hashed `key`, `name`, `avatar`, `profile_url`, integer `rating`, unchanged `text`, `language`, `translated_text`, `translation_status`, `date`, `updated_at`, `relative_date`, `source_url`, `report_url`, `location`, `retrieved_at`, `expires_at`, and selection `order`. Missing optional Google fields are empty strings, not fabricated values. Manual DTOs have `kind=manual`, ID, name, text, initials, organization, rating, date, avatar, source label/URL and order. Only these allowlisted fields leave the interfaces.

`GET /wp-json/ajcore/v1/reviews/status` is for authenticated users with `edit_posts`; normal WordPress REST authentication/nonces apply. It returns only the safe status DTO with `Cache-Control: no-store, private`. There is deliberately no public review-content REST endpoint or REST mutation interface. AJNanda uses WordPress's authenticated server-side block-renderer endpoint for real-data previews, and suppresses content in ordinary post REST rendering.

Hooks:

- `ajcore_reviews_provider`: return an object implementing `AJCore_Review_Provider`. Provider implementations must honor the normalization/temporary-storage contract and supply fixed safe errors. This is a trusted PHP extension point, not an endpoint accepting user providers. The existing UI is specifically for Google OAuth; a different authorization protocol needs a corresponding admin adapter.
- `ajcore_reviews_content_changed`: emitted after successful replacement, selection change, location change, authorization change, and disconnect; purge external caches if any. No secret or content is passed to the hook.

No content-mutating filter is supplied for Google text, and TTL cannot be extended by a filter. AJ Core had no WP-CLI command convention, so no new command family was introduced; existing `wp cron event run ajcore_reviews_sync` can execute the same scheduled handler when manually requested.

## Google policy and API limitations

Reviewed against the official sources on 2026-09-05:

- [Business Profile API policies](https://developers.google.com/my-business/content/policies): temporary storage maximum, prohibition on manipulation/aggregation, project ownership/access, attribution, and no implied endorsement.
- [Review resource](https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews) and [review listing](https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews/list).
- [Business Information metadata](https://developers.google.com/my-business/reference/businessinformation/rest/v1/accounts.locations#Metadata): supplied business Maps URI and new-review URI.
- [Business Profile OAuth](https://developers.google.com/my-business/content/implement-oauth) and [web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server).
- [Google Maps attribution guidance](https://developers.google.com/maps/documentation/places/web-service/policies): used conservatively for presentation; this integration does not call Places API or inherit its storage allowances.

**Policy question requiring Google follow-up before production:** a public business-curated collection sourced from stored Business Profile content must be assessed against Google's limited-storage/performance-only and no-manipulation/aggregation rules. Technical expiry, an unchanged text display, and attribution do not by themselves establish Google's approval of this use case. No claim of Google compliance certification or partnership is made.

The documented GBP review resource does **not** supply individual public review URLs, reviewer profile URLs, reporting URLs, separate original-language/translation fields, or relative-time strings. Optional slots retain those fields when a compliant provider supplies them; the current Google response usually leaves them empty. Google `reviewReplyUrl` is for replying and is not repurposed as a public source URL. The theme links to the supplied business Maps URI with an accurate label when an individual review URL is unavailable. Do not fabricate a review deep link from the review ID. The direct-individual-review-link requirement therefore remains limited by Google's API.

The provider's overall rating and total are displayed as received, never recalculated from a featured subset. Text is not rewritten or summarized. Theme truncation only clips presentation, and native expansion reveals the unchanged full text. Selection and ordering are disclosed. No Google content is saved as WordPress posts, attached media, static HTML, schema, search/discovery feeds, or LLM files.

Google rules, response shapes, approval requirements, and API behavior can change. Recheck the linked policies periodically and before releasing this feature to additional sites.

## Troubleshooting and verification

- `credentials_required`: verify the saved OAuth web-client ID/secret; a Maps API key cannot replace them.
- `oauth_state_invalid`: reconnect in the same logged-in browser; avoid using a callback URL from another site, user, or expired session.
- `authorization_failed` / `refresh_token_required`: reconnect with consent and offline access; check consent testing/production state.
- `access_denied`: check GBP project approval, API enablement/quota and authorized location ownership. Test Connection and Sync Now exercise different endpoints.
- `invalid_location`: reload account/location choices after their 15-minute expiry and save the selected location.
- `temporary_error` / `transport_error`: inspect network availability and API quota without logging tokens; review bounded retries.
- `snapshot_changed`: retry after a changing collection settles; the old snapshot retains its original expiry.
- `sync_limit`: the current bounded synchronous fetch cannot handle the collection in budget; do not raise TTL to mask it.
- `busy`: another operation holds the per-site DB lock, or the database/proxy does not support named locks.
- `encryption_unavailable` / `encryption_key_invalid`: enable sodium or securely restore the original shared key; do not substitute plaintext storage.
- No frontend cards: connect/sync/feature Google reviews, or publish and feature manual testimonials. Expired, draft, unfeatured, and malformed records never display.

See the [verification report](reviews-implementation-report.md) for exact manual commands, scope, and results. No live API credentials are needed by automated tests; all HTTP is blocked or mocked. Do not run the PHPUnit suite against a production WordPress database.

## Rating-neutral Rate Us prompt

The Settings tab also includes **Enable the Rate Us header prompt**, its label,
a private-feedback HTTPS URL, and an optional Google Write a Review HTTPS URL.
The default is disabled. Where the stars sit on the page is a theme concern, set
in AJNanda's Customizer, not here.

**Create the feedback page and form** builds the private destination for you:
an AJ Forms form titled *What Can We Improve* (an optional experience rating, a
required "What can we improve?" textarea, and optional name/email) plus a
published page at `/what-can-we-improve/` that renders it with `[ajforms]`. It is
idempotent — an existing form of that title or page on that slug is adopted
rather than duplicated, and a page that already renders some AJ Forms form is left
untouched — and it fills the private-feedback URL in for you when that field is
empty. Before the page exists the suggested URL is only a placeholder, so nobody
saves a link to a 404. Submissions land in AJ Core's own leads storage like any
other form; nothing is ingested into reviews or published as a testimonial. Any
later publication requires permission and administrator review. If AJ Forms
storage is unavailable the button is replaced by a note and the URL is entered by
hand.

The Google Write a Review field shows an example of the expected
`https://search.google.com/local/writereview?placeid=…` shape; left empty, the
connected location supplies its own link.

All five ratings expose the same private-feedback and Google-review destinations.
There are no low/high rating thresholds, hidden Google choices, automatic
redirects, or rating-dependent URLs. Clicking a star only changes the explanation;
it does not submit, track, or prefill a rating on Google or the feedback form.

`ajcore_get_review_prompt_settings()` is the public interface for AJNanda or another
theme. It returns `enabled`, `available`, `label`, `feedback_url`,
`google_review_url`, and `expires_at`. Both URLs must be available to display the
prompt. Invalid/non-HTTPS URLs and URLs containing username/password credentials
are rejected. The existing administrator capability, POST nonce, and shared lock
protect saving. The values extend the existing `ajcore_reviews_display` option;
there is no migration or new table. The content-changed hook fires on save so a
site's cache integration can purge old navigation.

The optional Google URL is an administrator-managed navigation link, independent
of connection state. Without that override, the interface uses only the valid
synchronized location's `write_url`, with its snapshot expiry in `expires_at`.
It never calls Google while rendering. If that snapshot expires and no override
exists, the prompt becomes unavailable rather than showing only private feedback.
AJNanda supplies cache-bypass headers and open-page expiry removal for this case.

A manually configured feedback URL and Google URL keep the invitation usable
without OAuth; they do not connect a Google account or synchronize any reviews.
Configure real site URLs through administration, not in the reusable repositories.
For a non-AJNanda theme, see the integration example in AJNanda's review guide.

## Rate Us page

Next to the feedback-page card, **Create the Rate Us page** publishes a page at
`/rate-us/` holding the `[ajcore_rate_us]` shortcode. It is idempotent in the same
way: an existing page on that slug is adopted, and one that does not already carry
the shortcode has it appended rather than having its content replaced.

The shortcode renders two things:

1. The same five stars as the header prompt, at page scale — one to four stars go
   to the private feedback page, the fifth to Google, with the identical
   left-to-right hover fill. There are no rating thresholds, no prefilled rating,
   and no tracking; the destinations come from the same two settings. The stars
   work whether or not the header prompt bar itself is enabled.
2. A **Visit Us** block: a keyless Google Maps embed for the business address
   beside the standard welcome paragraph, the address (linked to Google Maps
   directions) and the phone number (a `tel:` link).

Address and phone default to the theme settings the prompt bar already reads —
`seo_business_address` and `seo_business_phone`, which AJNanda's Search & AI
profile writes. Attributes: `heading`, `intro`, and `visit="no"` to render the
stars alone. Filters: `ajcore_rate_us_business` (name/address/phone),
`ajcore_rate_us_map_url` (paste a place-specific embed URL instead of the derived
one; non-HTTPS values are discarded) and `ajcore_rate_us_visit_intro`.

Markup and styles are self-contained so the page renders on any theme. With the
two destination URLs unset the stars are omitted entirely and only administrators
see a note saying which fields to fill in. When the Google link comes from the
synchronized snapshot rather than an override, the page sends `DONOTCACHEPAGE` and
no-store headers so a cached copy cannot outlive the snapshot's expiry.

`ajcore_get_review_destinations()` resolves those two URLs independently of the
header prompt's on/off state and returns `available`, `label`, `feedback_url`,
`google_review_url` and `expires_at`.
