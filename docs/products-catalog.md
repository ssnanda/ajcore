# Product catalog: categories, search, pagination, product pages

Products are still synced Stripe prices. The site-side catalog layer lives in the
`ajcore_product_catalog` option. Helpers are in `includes/product-catalog.php`.

## Admin: AJ Core › Products

| Tab | What it holds |
|---|---|
| Products | Existing shortcode builder, selection/order, dependencies |
| Catalog | Categories (name, slug, order), categories per product, linked product page, listing page |
| UI | Per page, search box and placeholder, category filter (chips, dropdown or off), counts, "All" label, card links and label, tablet columns (≤980px), phone columns and filter (≤800px) |

- **Category source: Stripe.** Set product metadata `category` in Stripe (comma-separate
  several, e.g. `Formation, Compliance`), then sync. New names become categories
  automatically. The Catalog tab only sets display name and order. Checkboxes appear only
  for products with no metadata. Stripe's own "Product category" column is its tax
  classification (`tax_code`), which the API exposes only as a tax code, so it isn't used.
- **Create page.** Makes a **draft** page under the listing page, filled with:
  - the Stripe summary
  - `[ajcore_product price_id="…"]`
  - the rich description

  It also links the page and sets AJNanda's page meaning to "A service".
- **Listing page.** Auto-detected as the first published page containing
  `[ajcore_products`, unless one is chosen.

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
  Stripe IDs column, or **New product**. Products are edited only here; **AJ Core › Products**
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
- **What's included on the website:** tick **What's included** in AJ Core › Products › Fields
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
- **AJ Core › Products** shows only In shop prices, for order and layout. **Save Order** keeps
  the selection.
- The dependency cards on the Products page have been removed. Settings › Product Catalog is
  the only editor.
