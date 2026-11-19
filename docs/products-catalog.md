# Product catalog: categories, search, pagination, product pages

Products are still synced Stripe prices. The site-side catalog layer lives in the
`ajcore_product_catalog` option. Helpers are in `includes/product-catalog.php`.

## Admin: AJ Core › Products

| Tab | What it holds |
|---|---|
| Products | Existing shortcode builder, selection/order, dependencies |
| Catalog | Categories (name, slug, order), categories per product, linked product page, listing page |
| UI | Per page, search box and placeholder, category filter (chips, dropdown or off), counts, "All" label, card links and label, tablet columns (≤980px), phone columns and filter (≤800px) |

- **Category source.** Checkboxes on the Catalog tab are used first. A product with none
  checked falls back to Stripe product metadata `category`, `categories` or `ajcore_category`
  (comma-separated names or slugs that match a defined category).
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
