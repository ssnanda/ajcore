# Product catalog: categories, search, pagination, product pages

Products are still synced Stripe prices. The site-side catalog layer lives in the
`ajcore_product_catalog` option. Helpers are in `includes/product-catalog.php`.

## Admin: AJ Core › Shop

AJ Core › **Shop** (`page=ajforms-products`; tab slugs unchanged):

| Tab | What it holds |
|---|---|
| Shop listing (`tab=products`) | Shortcode builder, which products show and their order |
| Product pages (`tab=catalog`) | Category, listing page and product page per product; auto-create |
| Display (`tab=ui`) | Per page, search box and placeholder, category filter (chips, dropdown or off), counts, "All" label, card links and label, tablet columns (≤980px), phone columns and filter (≤800px) |
| Settings | Link to Settings › Product Catalog (edit products in Stripe, In shop, import/export packages) |

- **Category.** Chosen per product in the Catalog table (dropdown, or "+ New category…").
  A choice made there wins over Stripe metadata `category`; "— None —" means no category on
  purpose. Products never set there follow Stripe `category` (comma-separate several). The
  header **Edit** opens the category editor (name, slug, order, delete by clearing the name).
- **Listing page.** Per product (column), default in the column header (auto-detected as the
  first published page containing `[ajcore_products` unless chosen). It is the parent of the
  product page (its address) and where the page's Back link and "Add to Cart" return. A
  required product (pair) follows its parent's listing page.
- **Product page.** **Create page**, the bulk "Create pages for selected" (in-shop products
  only, re-checked on the server) and auto-create (setting "New in-shop products") make a page
  containing only `[ajcore_product price_id="…"]`, at the standard address
  (`ajcore_product_catalog_page_slug()`: Stripe `page_slug` / `service_slug`, else derived from
  the name). A product is handled once: a trashed or unlinked page is not recreated.

## Product page template

On the product's own page, `[ajcore_product]` renders the full page live from the synced
Stripe product (`render_product_page_template()`); elsewhere it is the plain buy box
(`layout="page|box"` forces either). Sections without data are omitted.

| Section | Stripe source |
|---|---|
| Heading | metadata `display_title`, else the product name (via `the_title`) |
| Intro | metadata `summary` |
| Hero image | first of `images` (copied to the Media Library after each sync) |
| What's included | `marketing_features` |
| Details | `description`, metadata `rich_description` |
| How it works | metadata `steps` (one per line) |
| FAQ (+ FAQPage schema) | metadata `faq_1_q` / `faq_1_a` … `faq_10_*` |
| What clients say | featured testimonials / Google reviews (AJ Core > Reviews) |
| Side: buy box, At a glance, Good to know, Related | price; `turnaround`, `audience`, `requirements`; `billing_note`, `state_fees_note`, `important_note`; same-category products with pages |
| SEO title / description | `seo_title`, `seo_description` (only when the page has none of its own) |

All of these are editable in Settings > Product Catalog > Edit > "Product page" (written to
Stripe metadata, max 500 characters each).

## Product packages (import / export)

Settings > Product Catalog > **Import** (next to New): JSON files in the `ajcore.product` 1.0
format (`admin/trait-ajcore-product-packages.php`).

1. Choose file(s); optionally pick the product to apply a single file to.
2. Validate: format/version, `operation: enrich` only, no prices, Stripe limits after merge
   (15 features ≤ 80 chars, 50 metadata keys ≤ 40 chars, values ≤ 500), image Base64 +
   SHA-256 + real type (WebP/PNG/JPEG) + ≤ 2 MB + ≤ 4000 px.
3. Match: chosen product, else `target.product_id`, else a unique exact active name. The
   `expected_default_price` must equal the live default price (warning only when the product
   was picked by hand).
4. Preview: field-by-field (image, description, features add/replace, each metadata key);
   untick anything. Dependency keys (`*requires*`, `*required*`, `*dependency*`) are never
   changed. Empty fields mean no change.
5. Save: LIVE mode needs an explicit tick. Each product is re-fetched and skipped if it
   changed since the preview. The image goes to the Media Library (reused by SHA-256) and its
   public HTTPS URL is prepended to the product's images (max 8). One Stripe update per
   product with an idempotency key, then products re-sync.

**Export** (button under Edit in each row) downloads the product as a package, with its first image
embedded when a local copy exists (otherwise `image_urls`, not importable). **Download blank
template** is in the import panel. "create" packages are refused: create the product in the
editor first.

## Shortcodes

- `[ajcore_products]` uses the UI tab defaults. Overrides: `per_page`, `search="yes|no"`,
  `filter="chips|dropdown|none"`, `link="yes|no"`, `category="slug,slug"`.
  - `category` limits the listing. Required add-ons of the shown products stay in the markup,
    hidden, so cart auto-add still works.
- `[ajcore_product]` is the buy box for a product page. With no `price_id`, it uses the price
  linked to the current page.
  - Mode follows the saved builder mode.
  - In cart mode, the button goes to `listing?ajp_add=price_…`, and the listing adds the price
    to its cart. So there is one cart, not one per page.
  - Options: `price_id`, `mode`, `show` (default `price,button`), `details`, `button`, `back="no"`.

## Behaviour

- Filtering, search and paging run client-side over the rendered cards. Every product is in
  the HTML, so crawlers see them all.
- State is kept in `?ajp_q`, `?ajp_cat` and `?ajp_pg`, so a filtered view can be linked.
- **Search** covers the name, summary, description, rich description and nickname, and
  ignores accents. All terms must match.
- **Mobile:**
  - chips scroll sideways, or show as a dropdown if that's set
  - the pager collapses to Prev · n / N · Next
  - the search input is 16px, so iOS doesn't zoom in
  - changing page scrolls to the toolbar, below any fixed or sticky header and the fixed mobile cart
- **Theme hooks:** `--ajp-accent`, `--ajp-radius` and `--ajp-scroll-offset` on
  `.ajcore-products-wrap`. AJNanda maps them in `style.css`.
- **Defaults leave an existing listing unchanged:** per page 0 (all) and search off. The
  category filter only appears once categories are assigned.

## Editing products in Stripe (Settings › Product Catalog)

- **Opening the editor:** go to **Settings › Product Catalog** and use **Edit** in a product's
  Stripe IDs column, or **New product**. Products are edited only here; **AJ Core › Shop**
  controls the website listing.
- **Loading:** the editor reads the product and its prices live from Stripe. It never uses the
  cache.
- **Product fields you can edit:**
  - name, active, description, statement descriptor, unit label, tax code, URL
  - default price, images, marketing features, shippable, package dimensions
  - metadata (free key/value; an empty value removes the key)
- **Price fields you can edit:**
  - active, nickname, lookup key, metadata
  - tax behavior, only while it's still unspecified, because Stripe locks it once set
- **Price fields Stripe won't change:** amount, currency and interval are shown read-only.
  To change them, add a new price, optionally make it the default, and archive the old one.
- **Saving:**
  1. Any new price is created first.
  2. Only the product fields that differ from a fresh read are sent.
  3. Then the price changes are sent.
  4. Then the products cache is re-synced.
- **Live mode:** a red banner warns that saves change the live catalog.
- **Hiding fields later:** every field row has `data-field="<stripe field>"`, e.g.
  `price.lookup_key`. Hiding is CSS or a setting; the save logic skips fields that aren't
  posted.

## Settings › Product Catalog list (inline)

Each product row has four parts:

| Column | What's in it |
|---|---|
| On the website | Name, description, and what's included. These save to Stripe. Included items are Stripe `marketing_features`. Also shows category chips and a collapsed "Portal-only label & description" (opens if it's in use). |
| Prices & add-ons | A chip for each price, and "Auto-adds to cart" (the dependency). The "why" note only appears once an add-on is picked. |
| If they already have it | Choice cards: Block, Allow again, Ask us, Upgrade. "Upgrades from" and the request fields only appear for their option. |
| Right column | "In portal" switch (Add Services), order, Save (appears on change), and All fields (the editor). |

- **Saving:** only changed rows are sent. Stripe fields are compared with a fresh read
  first, and both product copies re-sync afterwards.
- **What's included on the website:** tick **What's included** in AJ Core › Shop › Fields
  to show, so the generated shortcode has `show="…,features,…"`. `[ajcore_product]` shows it
  by default.

## Shop vs catalog

- **Settings › Product Catalog** is on every site, with or without the Client Portal. It holds:
  - Stripe fields
  - **In shop**, per price
  - Auto-adds (dependencies)
  - the portal rules, shown only when the portal is on
- **In shop** writes the public shop selection (`ajcore_public_product_display_settings.selected_prices`):
  - An empty selection means the whole active catalog.
  - The first In shop change turns "whole catalog" into an explicit list of every active price.
  - Unrelated edits never write the selection.
- **AJ Core › Shop** shows only In shop prices, for order and layout. **Save Order** keeps
  the selection.
- The dependency cards on the Products page have been removed. Settings › Product Catalog is
  the only editor.
