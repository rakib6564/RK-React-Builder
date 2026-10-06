# RK React Builder

A focused visual page builder for WordPress, shipped as **one installable plugin** (no Node, Docker or SSH needed), with an
optional headless mode (Node/Docker frontend) for teams that want it.

> **Just want to install it?** Read [INSTALL.md](INSTALL.md): upload the ZIP in _Plugins → Add New → Upload_, activate, done.

A focused visual page builder for WordPress. An editor picks a WordPress page, arranges blocks (hero,
heading, text, image, CTA, live services/portfolio grids, spacer, divider), tweaks a global theme, previews, saves a
**draft**, and deliberately **publishes**. The public site is server-rendered from the same document model.

- **Builder** — Vite + React + TypeScript, registry-driven blocks, Zod validation, undo/redo, keyboard reorder, local
  draft recovery, revision history, media picker, conflict handling.
- **Plugin** — `wp-plugin/rk-builder`: storage, REST, permissions, revisions, locks, CPTs, preview links, **PHP public
  rendering**, SEO metadata, cache purging, settings, setup wizard and migration tool. The built React editor ships
  inside it (`assets/`) and runs in wp-admin on the WordPress login cookie + REST nonce.
- **Media upload & site export/import** (WordPress-hosted editor) — upload images straight from the media picker; administrators
  can export every builder page, the theme and the media they use as one JSON file and import it on another site
  (see [Site export / import](#site-export--import)).
- **Server (optional, headless mode)** — Node/Express: public SSR, session-protected WordPress proxy, cache, metrics.

Details: [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [DEPLOYMENT.md](DEPLOYMENT.md) ·
[OPERATIONS.md](OPERATIONS.md) · [PRODUCTION_IMPLEMENTATION_PLAN.md](PRODUCTION_IMPLEMENTATION_PLAN.md)

## Two ways to run it

|              | **All-in-one (default)**                        | Headless (optional)                               |
| ------------ | ----------------------------------------------- | ------------------------------------------------- |
| Needs        | WordPress hosting only                          | + a Node/Docker host                              |
| Editor       | wp-admin → RK Builder                           | wp-admin (nonce) or `/builder` on the Node server |
| Public pages | PHP, inside your theme or a standalone template | Node SSR on its own domain                        |
| Auth         | WordPress login + REST nonce                    | same, or Node session + app password proxy        |
| Install      | upload one ZIP                                  | see [DEPLOYMENT.md](DEPLOYMENT.md)                |

Build the installable ZIP yourself: `pnpm install --frozen-lockfile && pnpm build:plugin` → `dist/rk-builder-all-in-one.zip`
(also published by the _Release plugin_ workflow when you push a `vX.Y.Z` tag that matches the plugin version).

## Developer quick start (no WordPress needed)

```bash
nvm use            # Node 24 (.nvmrc); >=22.12 works
corepack enable    # pnpm 10.4.1 is pinned in package.json
pnpm install --frozen-lockfile
cp .env.example .env

pnpm dev:mock-wp   # terminal 1 — in-memory WordPress API on :8099 (user "editor", app password "mock-app-password")
pnpm dev           # terminal 2 — Node server :3001 (public site, API) + Vite :3000 (builder)
```

- Builder: <http://localhost:3000/builder> — password from `BUILDER_EDITOR_PASSWORD` in `.env`
  (`change-me-please-123` in `.env.example`).
- Public site: <http://localhost:3001/> (Home is published in the mock). Pages: `/about` is a draft → 404 until you publish.
- `?demo=1` (e.g. `/builder?page=42&demo=1`) shows an **explicit** offline content fixture; it is never a fallback.

## With real WordPress

1. Install `wp-plugin/rk-builder` (zip: `wp-plugin/rk-builder-wp-plugin.zip`) and activate it. It registers the
   `service` and `portfolio` post types, `service_cat`/`portfolio_cat`, and the REST API.
2. Create an **application password** for the editing user (Users → Profile → Application Passwords).
3. Set in `.env`: `WORDPRESS_PUBLIC_URL`, `WORDPRESS_API_URL` (`https://cms.example.com/wp-json/`),
   `WORDPRESS_APP_USER`, `WORDPRESS_APP_PASSWORD`, `BUILDER_EDITOR_PASSWORD`, `PUBLIC_SITE_URL`, `REVALIDATE_SECRET`.
4. In `wp-config.php` let WordPress purge the frontend and allow your origin:
   ```php
   define( 'RK_BUILDER_REVALIDATE_URL',    'https://www.example.com/api/revalidate' );
   define( 'RK_BUILDER_REVALIDATE_SECRET', '<same value as REVALIDATE_SECRET>' );
   define( 'RK_BUILDER_ALLOWED_ORIGINS',   'https://www.example.com' );   // only if a browser calls WP cross-origin
   ```

See [DEPLOYMENT.md](DEPLOYMENT.md) for the embedded wp-admin ("nonce") mode and production hardening.

## Reusable blocks

Any content block can be saved once and used on many pages, **linked**: edit it in one place and every page changes.

- **Save:** select a block → Inspector → **Save as reusable block**, name it. The block moves to the library and the page now
  holds a reference. The library appears under the block palette ("reusable / library"); click an entry to add it to a page.
- **Edit everywhere:** select a reusable on any page → change the fields → **Update everywhere**. Published pages that use it
  are purged from caches immediately.
- **Detach:** **Detach** copies the content back into the page as an ordinary block that no longer follows the library.
- Rules: a reusable holds one content block (hero, heading, text, image, CTA, services/portfolio grid, spacer, divider,
  testimonial, contact) and cannot contain another reusable. A library entry can only be deleted once no page uses it.
  Creating or changing library entries needs `edit_others_pages` (editors and administrators).
- Public output is exactly the referenced block's own markup (no wrapper); the PHP renderer, the React views and the Node SSR agree
  (covered by the parity test). Site export/import carries the library: reusables are matched by slug, page references are re-pointed.
- REST: `GET /builder/reusables`, `POST /builder/reusables`, `POST /builder/reusables/{id}`, `POST /builder/reusables/{id}/delete`.

## Converting an existing site

[`scripts/convert-peoria.mjs`](scripts/convert-peoria.mjs) is a worked example: it turns the _Peoria Hardwood Floors_ Next.js
site into four RK Builder site-export bundles (pages, linked reusable blocks, theme, services) plus a report of what could not be
converted. It reads the repo's data files **without running any of its code**, keeps images at their source URLs and lets
**Import site** copy them into the media library. Rehearse an import on a throwaway WordPress with
`node scripts/wp-import-bundle.mjs dist/peoria/1-core.json … --theme --content`.

## Site export / import

Administrators get **Export site** and **Import site** on the page list (WordPress-hosted editor: all-in-one plugin or RK Suite).

- **Export** downloads `rk-builder-site-YYYY-MM-DD.json` with: every page that has a builder layout (the working **draft**), the
  theme, the media those pages use (URL, alt, title, size), and Services / Portfolio posts.
- **Import** first runs a **check** (dry run) that reports what would change; nothing is written until you click _Import now_.
  - Pages arrive as **drafts**. A page whose slug already exists gets a new draft revision; if it is live it **stays live
    until you publish**. Import never publishes. Services / projects arrive as drafts too (unless you choose otherwise through the API).
  - Images are **copied into this site's media library** (downloaded over http/https with WordPress' safe HTTP client; JPEG, PNG, GIF,
    WebP, AVIF only), and layouts are rewritten to the local copies. Attachment IDs from another site are never trusted.
  - Every layout is checked by the same strict validator as the editor. An invalid page is **skipped and listed**, never half-imported.
  - Importing the same file again **updates by slug** and re-uses images (matched by source URL): no duplicates.
  - The theme is only replaced if you tick _The theme_. It changes every page, so it is off by default.
- Limits: 8 MB file, 500 pages, 150 images, 300 content posts per import. The source site must be reachable for images to copy;
  otherwise those pages are skipped with a hint to fix the image or allow its host (Settings → RK Builder → Allowed image hosts).

REST (administrators): `GET /rk/v1/builder/site-export`, `POST /rk/v1/builder/site-import` with `{ "bundle": {...}, "options": { "dryRun": true, "theme": false, "content": false, "contentStatus": "draft" } }`
(`dryRun` defaults to **true**).

Bundle: `{ format: "rk-builder-site", version: 1, exportedAt, source: {url, plugin}, theme, media: [{id,url,alt,title,width?,height?}], pages: [{slug,title,wasPublished,layout}], content: [{type,slug,title,status,excerpt,content,order,terms,featured}] }`.

## Media upload

In the media picker choose **Upload image** (WordPress-hosted editor; needs the `upload_files` capability). Type the alt text first
and it is saved with the image. The server checks the file's real type (not its name): JPEG, PNG, GIF, WebP or AVIF, up to the
smaller of WordPress' upload limit and 10 MB. **SVG is refused** because it can carry script. REST: `POST /rk/v1/builder/media`
(multipart field `file`, optional `alt`, `title`) → `201 { item }`.

> The headless Node proxy deliberately exposes only the editing routes, so upload and site export/import are not available there.

## Using the builder

- **Pages** (`/builder`) — search/filter by title, slug, status; shows modified time and revision (and live revision).
- **Edit** — add from the palette (click, or drag), select, edit in the inspector, duplicate, delete (Undo toast),
  reorder by drag **or** the ↑/↓ buttons (fully keyboard-operable). `Ctrl/⌘+Z` undo, `Ctrl/⌘+Shift+Z` redo, `Ctrl/⌘+S` save.
- **Save draft** never publishes. Status text is explicit: _Draft saved to WordPress_ vs _Saved on this device only_.
- **Preview** (toggle) shows unsaved edits; **Preview link** opens the real frontend with a 15-minute draft token.
- **Publish / Update live page / Unpublish** — one dialog; saves first if needed.
- **History** — preview any revision; restore creates a new revision (nothing is deleted; last 20 kept).
- **Theme tab** — colors, font (3 approved), logo (media picker), social links, sticky header, footer columns.
  Administrators only.
- **Image block** — choose from the WordPress media library; alt text is required unless marked decorative.

## Commands

|                                                  |                                                                                                                                     |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| `pnpm verify`                                    | typecheck + lint + format check + unit/contract tests + PHP tests + build                                                           |
| `pnpm check` · `pnpm lint` · `pnpm format:check` | static checks                                                                                                                       |
| `pnpm test` · `pnpm test:php`                    | Vitest (schemas, reducer, API client, SSR, proxy) · plugin tests (plain PHP)                                                        |
| `pnpm build && pnpm test:e2e`                    | Playwright against the mock WordPress (editing, publishing, a11y, recovery)                                                         |
| `pnpm build:plugin`                              | build the all-in-one plugin ZIP (`dist/rk-builder-all-in-one.zip`)                                                                  |
| `pnpm test:all-in-one`                           | **Packaged plugin on real WordPress, no Node server**: wp-admin builder, publish, PHP-rendered page, preview, roles (needs network) |
| `pnpm test:wp`                                   | **Real WordPress**: client + proxy + plugin + SSR through WordPress Playground (needs network)                                      |
| `pnpm test:wp-admin`                             | **Real WordPress wp-admin**: nonce mode in a browser (needs network)                                                                |
| `pnpm smoke:wp`                                  | Plugin REST smoke test against real WordPress                                                                                       |
| `pnpm audit:prod`                                | dependency audit (prod, high severity)                                                                                              |

First run of the Playground-based commands downloads WordPress and `@wp-playground/cli`; Playwright needs
`pnpm exec playwright install chromium` once.

## Troubleshooting

| Symptom                            | Fix                                                                                                |
| ---------------------------------- | -------------------------------------------------------------------------------------------------- |
| "Builder unavailable" on load      | The Node server isn't running or `/api/config` is blocked; check `pnpm dev` output                 |
| Sign-in says _not accepted_        | `BUILDER_EDITOR_PASSWORD` mismatch (restart the server after editing `.env`)                       |
| "Too many attempts"                | Login limiter: 5 per 15 min per IP (`LOGIN_RATE_LIMIT_MAX`)                                        |
| Saves fail with _Not permitted_    | The WordPress user lacks `edit_post`; theme saves need `manage_options`                            |
| Every page 404s publicly           | The page is not published; publishing sets `post_status=publish`                                   |
| Public page stale after publishing | Check `REVALIDATE_SECRET` / `RK_BUILDER_REVALIDATE_*`; otherwise TTL (60 s) applies                |
| Images missing in grids            | Set a featured image on the Service/Portfolio post; absolute image URLs must be on an allowed host |

More in [OPERATIONS.md](OPERATIONS.md).

## License

MIT

### AI flooring visualizer (v1.6)

The **AI flooring visualizer** block is a photo upload, a set of look choices (room, project, style, species, direction,
finish, sheen, city) and a preview, backed by REST endpoints under `/wp-json/rk/v1/visualizer/` (`quota`, `generate`,
`status`, `lead`). Turn it on in **Settings > RK Visualizer** and choose where the images come from:

- **Hugging Face (FLUX Kontext)**: the same model the source site uses, through the Hugging Face router. Needs an access
  token: paste it in the field, set `RK_BUILDER_VIZ_HF_TOKEN` in `wp-config.php`, or set the `HF_TOKEN` environment variable.
  Asynchronous: the browser polls `status`, so no PHP request waits on the model.
- **Google Gemini (image editing)**: sends the photo and the instruction to a Gemini image model (default
  `gemini-2.5-flash-image`, changeable in Settings) and keeps the image it returns. Needs an API key from Google AI Studio:
  paste it in the field, set `RK_BUILDER_VIZ_GEMINI_KEY` in `wp-config.php`, or set `GEMINI_API_KEY` in the environment.
  The key travels in the `x-goog-api-key` header, never in a URL. One synchronous call, bounded by the "give up after" setting.
- **My own backend API**: the plugin POSTs JSON `{prompt, image (data URI), mimeType, options}` to your URL, with your key in
  the header you choose, and expects `{imageUrl}`, `{image: base64 or data URI}` or `{statusUrl}` (polled until it returns one
  of those). Use this to put your own model, queue or serverless function behind the page.
- **Test mode**: returns the uploaded photo, so you can try the whole page without a provider or any cost.

Visitors are limited per browser (a cookie) and per IP address: a few free visualizations (2 by default), then a short
contact form that unlocks one more and stores the lead (listed on the settings page, optionally emailed to you), then one
more each waiting period. A generation is counted when it starts and given back if the provider fails. Photos are checked
(JPEG, PNG or WebP, up to 10 MB, at least 640x480) and sent only to the backend you chose; results from your own backend
that arrive as image data are kept in `uploads/rk-visualizer/` for a week.

### Marketing blocks (v1.5)

For brochure-style sites: `navbar` (fixed header that turns solid on scroll), `coverhero` (full-bleed photo hero with
breadcrumb), `sitefooter`, `contactband`, `section`, `split`, `panel` (copy beside divider rows, or heading beside checks),
`values`, `catalog` (photo/swatch cards with specs and bullets), `detail` (steps, factors, FAQ and a sticky sidebar) and
`gallery`. List-like props are plain text, one item per line with `|` between fields (for example `Label|/path`), so they stay
flat, validated and identical in the PHP and React renderers. Header, footer and contact band are normally reusable blocks.
The full-width look needs the **standalone** rendering mode (Settings → RK Builder).

Small behaviours ship as one inline script (no extra file): `calculator` (project type × quantity → a planning range; one
`Label|rate|unit` line per type), filter buttons on `catalog`/`gallery` (`filters`; a catalog card's tag is the text after the
last `·` in its blurb, a gallery photo's tag is its category), product pop-ups on `catalog` (`modals`, one line per card:
`image|Title|Intro|item; item`), the navbar's mobile menu, and the active-page underline. Without JavaScript the pages still
render; only the interaction is missing.

## SEO per page

A site bundle page can carry `seo: { title, description, image, noindex, service, parent }`, and the bundle `seo.organization` (`name`, `telephone`, `email`, `description`, `logo`). The importer stores them as the `_rk_seo_*` post meta keys (so RK SEO reads the same values) and RK Builder prints the title, description, canonical, Open Graph, Twitter tags, `noindex, follow` and a JSON-LD graph (Organization, WebSite, WebPage, Service, BreadcrumbList) on the public page. When another SEO plugin is active, or the RK SEO module outputs its own graph, RK Builder prints nothing extra.

## Theme engine

New to themes? Read **[docs/CREATE-A-THEME.md](docs/CREATE-A-THEME.md)**: building a theme step by step, the SEO standards, the file format and a launch checklist.

**Pages → Themes** turns the current site into a reusable theme package and keeps a library of them on the site (up to 12).

- **Save this site as a theme**: packages every builder page, reusable block, image reference, the theme settings (colors, fonts, logo, header, footer), services and projects, and the per-page SEO. Saving again with the same name replaces that package.
- **Install**: applies a package in one click. Choose what to include, whether to publish the pages right away, and whether its Home page becomes the front page. **Check first** shows what would change. Pages with the same address are replaced; other pages are left alone.
- **Export / Import theme file**: a package is the normal site export plus a `themeMeta` block, so the file also works with **Import site**. Importing a file adds it to the library without changing the site.
- **Site kits (zip)**: **Download kit (.zip)** packs the site as `manifest.json` + `site.json` + `images/` so the pictures travel with it; **Import kit or theme file** accepts that zip and **Install** reads the pictures from it. Beyond the theme package a kit also carries blog posts, per-entry SEO, caption and description of every picture, the logo, favicon and social images, layout settings, site name and tagline, and redirects (the last three are opt-in at install). Tracking codes, API keys and users are never packed. Needs the PHP zip extension. REST: `POST /builder/kits/export` (the zip), `POST /builder/kits/upload` (multipart `file`).
- **AI copy rewriting**: after installing a kit, **AI & MCP → Rewrite your site's wording with AI** takes a short brief about your business and gives your assistant a ready prompt. Through the MCP tools `rkb_ai_brief`, `rkb_copy_extract` and `rkb_copy_apply` (and the MCP prompt `rewrite-site-copy`) it reads every page, header, footer and reusable block, rewrites the wording and saves it as **drafts**: the live site is untouched until you publish. Links, pictures, colours and settings cannot be changed; list-style texts keep their lines and addresses; limits are enforced; a dry run checks first. See [docs/MCP.md](docs/MCP.md).
- **Kit Library**: **Themes → Kit Library** connects to a catalogue of kits hosted on any static host (one JSON file, built with `node scripts/build-kit-catalogue.mjs`), lists them with previews, and adds one to your themes in a click. Downloads are https only, checked against a SHA-256, then run through the same checks as an uploaded kit; newer versions show **Update**; an optional licence key is sent only to the catalogue's own host. See **[docs/KIT-LIBRARY.md](docs/KIT-LIBRARY.md)**. REST: `GET /builder/library`, `POST /builder/library/settings`, `POST /builder/library/add`.
- **Long catalogs and galleries**: the `catalog` block can show a search box, take its filter buttons from the card label and show the first cards with a **Load more** button (`pageSize`, `search`, `searchLabel`, `tagField`, up to 150 cards); the `gallery` block takes `pageSize` too (up to 300 photos). Everything stays in the page; nothing is removed.
- **Design system**: the editor's **Theme** tab has four style presets (Modern, Classic, Bold, Minimal) and fine controls: accent, dark and soft-background colours, body and heading fonts (seven choices, all system or already-loaded fonts), heading weight, corner style (square, soft, round) and button style (solid, outline). Every setting is optional, validated like the rest of the theme (colours are `#RRGGBB`, the rest are fixed lists) and part of the theme in every export and kit, so a kit carries its own look. A theme that sets none of them renders exactly as before.
- **Install wizard**: **Install** opens three steps: (1) _Everything_ or _Design only_ (colors, header, footer, templates, blocks, no pages or demo content) plus what to include; (2) _Your business_: the demo's business name, phone, email and address are listed, you type yours and they replace the demo's in text, links and SEO (identifiers, slugs and web addresses are never touched); (3) a check of exactly what will happen. Before anything changes, the site is saved.
- **Undo this install**: puts the site back: pages the install added go to the Trash, pages, templates, settings, front page and content types it changed are restored exactly, and anything it hid is shown again. One level (the latest install); pictures it copied stay in Media. REST: `POST /builder/themes/undo`.
- REST: `GET/POST /builder/themes`, `POST /builder/themes/import`, `POST /builder/themes/install`, `GET /builder/themes/export?slug=`, `POST /builder/themes/delete` (administrators only).
- `node scripts/convert-peoria.mjs` also writes `dist/peoria/peoria-theme.json`, the Peoria site as one installable theme.

## Dashboard

Inside WordPress the builder opens on its own dashboard, so day-to-day work does not need wp-admin:

- **Overview**: pages live/draft, images, services and projects, themes, visualizer status, recently edited pages and what needs attention (live pages with unpublished changes or no search description).
- **AI & MCP**: let an AI assistant (Claude, Cursor, …) do dashboard work through the Model Context Protocol. Off by default; an access level (read only, read and write, full), one-click connection keys (or an Application Password), 48 tools over the existing routes with the signed-in user's rights, and an activity log. See [docs/MCP.md](docs/MCP.md).
- **Pages**: search and filter, **New page** (optionally starting with the site header and footer), rename or change the address, **Search & sharing** (title, description, social image, noindex with a live preview, and **Schema markup (JSON-LD)**: page type, breadcrumbs, Article, Service, Product, FAQ and rating, each a switch, with links to the Schema validator and Google's Rich Results Test), duplicate, publish or unpublish, make front page, move to the trash.
- **Media**: upload and browse images, copy an image address.
- **Themes**: the theme engine, plus site export and import.
- **Site & SEO**: site title, tagline, front page, search-engine visibility and the business details used in the structured data.
- **Visualizer**: backend, keys (write-only), limits and the leads list with delete.

On phones the left menu becomes a bottom tab bar. REST: `GET /builder/overview`, `POST /builder/pages/new`, `POST /builder/pages/{id}/update|duplicate|trash|front`, `GET|POST /builder/pages/{id}/seo`, `GET|POST /builder/site`, `GET|POST /builder/visualizer-admin`, `POST /builder/visualizer-admin/leads/delete`. The headless proxy keeps the plain page list.

## Search, local and reviews tools

All in the dashboard (WordPress-hosted editor):

- **Social sharing image**: pick or upload the image for any page under Pages > Search & sharing (with a live share preview), and a site-wide default under Site & SEO. Order used: page image, featured image, site default, first image on the page.
- **Business profile**: Google Business Profile and social links become `sameAs`; address, hours (`Mon-Fri 08:00-17:00`), service areas and a business type publish a `LocalBusiness` node in the page schema.
- **Code & tracking**: Search Console and Bing verification (paste the code or the whole meta tag), GA4 and Tag Manager IDs (not loaded for signed-in editors by default) and free-form head, after-`<body>` and footer snippets (administrators with `unfiltered_html` only).
- **Reviews**: add the Place ID and a Google API key (Places API (New)) to pull the rating, the review count and up to five reviews, refreshed daily if you wish, or add reviews by hand; hide any of them. The **Google reviews** block shows the rating, the cards and "See all reviews" / "Leave a review" buttons. Review ratings are deliberately not added to the structured data (Google does not allow self-serving review markup). The key can also be set with `RK_BUILDER_PLACES_KEY` in `wp-config.php`.
- **Redirects**: 301/302 rules from an old path to a new path or address.

## Editor controls for cards and grids

- **Card catalog**: the inspector edits one card at a time: photo or color swatch (media picker), title, small label, description, filter tag, checklist, name/value details, link, reordering, duplicating, and an optional pop-up per card with its own image, text and list. Block-wide switches cover filters, joined grid, columns, numbering and background.
- **Services grid / Portfolio grid**: equal-height cards, image shape (landscape, wide, square, portrait, natural), show or hide image, description and categories, description length, link the title or add a button, card style, spacing, phone columns, background, eyebrow, intro and a "view all" button. All options are optional, so existing pages keep their look.

## Content types, fields and the theme builder

Everything is in the dashboard (no wp-admin needed); the pieces are admin-only except **Content**.

- **Types & fields** — define your own content types (custom post types) with a singular/plural name, web address, archive on/off, category groups (taxonomies) and fields: short/long text, number, email, link, date, colour, choice list, yes/no, image, gallery, and **repeater** (rows of sub-fields). The built-in Post, Services and Portfolio types can get fields too. Values are stored as post meta `rk_f_<key>`.
- **Content** — list, create, edit, duplicate and trash the entries of any type: title, summary, description, featured image, categories and every field (galleries and repeaters reorderable).
- **Templates** (the theme builder) — designed in the normal block editor:
  - _Single page_: how one entry looks (replaces its page);
  - _Archive / listing_: the type's list page, or one category group's pages;
  - _Card_: the card each entry gets inside a Loop grid.
    A template starts from a layout built from the type's own fields. Only a **published and "in use"** template is live (one per target); switch it off to hand the page back to WordPress.
- **Custom sign-in page**: add the **Sign-in form** block to any page (heading, intro, button label, "Remember me" and "Forgot your password?" on/off) and choose it under **Site & SEO > Sign-in page** (or press **Create a sign-in page**). `wp-login.php` then sends visitors to that page, a wrong password brings them back to it with one fixed message, and a right one signs in through WordPress itself (so two-step and security plugins keep working). After signing in, people who can edit pages land on the builder; a `redirect_to` from wp-admin is honoured when it points at your own site. The page is kept out of caches and search results, and shows who is signed in instead of the form when someone already is. WordPress's own screens (lost password, reset) take the site's colours, logo and name. Safety: if the chosen page is unpublished, deleted or loses the block, the normal WordPress login is used automatically; `/wp-login.php?rk_login=0` always shows it.
- **Image beside copy block**: an optional **Checklist** (one point per line, up to 12, shown with check marks under the copy).
- **Google Business Profile import** (Site & SEO > Local business): type the business name into **Search & Import Google Business Profile**, pick the match and the name, phone, address, opening hours, map link, rating and review count are filled in (review and press Save). Uses the Places API (New) with the key from the Reviews screen (or paste it in the card); the key is only ever sent in a header and never shown again. The rating and review count are stored and published as `aggregateRating` (plus `hasMap`) on the LocalBusiness JSON-LD. A missing key or Google's request limit is explained in plain words. **No key?** Paste a Google Maps share link (share.google, maps.app.goo.gl, g.page or google.com/maps) or a Place ID into the second box: the profile link, the name (if empty) and, when the link carries one, the Place ID are saved (short links are followed only between Google hosts). REST: `POST /builder/places/search`, `/import`, `/link`.
- **Photo gallery block**: the inspector lists every photo with its thumbnail, category and caption; **Add photos** opens the media library where you tick several images at once (or upload new ones) and they arrive in the order picked with their alt text as caption. Reorder with the arrows, remove one or all, or edit the raw text (`image URL|Category|Caption`, up to 300). Options: 2 to 4 columns, photo shape (equal-height rows, square, landscape, portrait, wide), gap, first photo large on/off, captions (over, below, on hover, hidden), category filter buttons, and a **lightbox** (click a photo to see it large, previous/next, arrow keys, Escape; reachable with the keyboard). All options are optional: an older gallery looks as before. **Automatic galleries**: set **Photos from** to the newest images in the media library, project pictures or service pictures and the gallery fills itself and stays current (a new upload or a published project appears on the live page without editing it). Projects and services use each entry's featured image, its title as caption and its first category as the filter button; entries without a picture are skipped. **How many** (up to 24 for projects and services, 40 for the media library) and **Only show** (a category slug such as `hardwood-floors`, or words to find in the media library) narrow it down. The editor preview shows at most 24. The AI copy tools never rewrite the filter.
- **Dynamic blocks** (offered inside templates): _Dynamic text_ (title, summary, description, dates, author, categories or any field, with label, prefix/suffix, link and fallback), _Dynamic image_, _Details list_, _Dynamic gallery_, _Repeater rows_ (list / table / cards) and the **Loop grid** (any type; filters, search, page numbers, related entries, equal-height cards, card design; also usable on ordinary pages).
- The editor shows these blocks with the **PHP renderer's own markup** (`POST /builder/dyn/render`), so what you edit is what is published. They have no React twin; `contracts/valid/layout-dynamic.json` pins their props for both sides.
- Visitors' filter / search / paging use `?rk_term=`, `?rk_q=` and `?rk_page=`.
- **Header** and **Footer** templates (Templates → New template) are site-wide: one live header and one live footer replace the navbar and footer blocks of every page and template.
- **Header options** (navbar block): drop-down menus (a line starting with `- ` sits under the line above, one level, up to 8 each; opens on hover, focus or tap, and is listed under its parent in the phone menu), bar colour (automatic, light, dark, primary), bar height, menu position, logo size, shadow, button style, an **announcement bar** above the header (message, optional link, primary / dark / light, and an optional close button that stays closed in that visitor's browser until the message changes; a dismissed bar is hidden before the page paints, so it never flashes), and an extra button next to the phone button; sub-items can carry a short description (`- Label|/path|Description`), the bar can slim down with a shadow when scrolling, the drop-down animates and works with the arrow keys, and on a phone the sub-items fold under their parent, the page behind the menu stops scrolling and the menu closes after a tap. **Footer options**: colour (dark, light, primary), social links and bottom links (privacy, terms). All options are optional, so existing pages keep their look.
- **Global settings** (dashboard, administrators): **Layout** sets the content width (640–1920 px, default 1144) and the side space on computers and phones for every RK Builder page, header and footer, like Elementor's global layout settings. **Toolbar on the website** chooses what the WordPress toolbar shows to signed-in people: the WordPress default, _RK Builder only_ (site name, account menu and the shortcuts) or hidden. The shortcuts _Edit with RK Builder_ (pages), _Edit template_ / _Edit entry in RK Builder_, _Edit header_ / _Edit footer_ and _RK Builder dashboard_ appear in both styles. **WordPress theme** can keep the active theme's stylesheets off pages RK Builder draws itself (standalone mode). **Speed and clean-up** switches turn off the emoji and embed scripts, the block-editor styles and the generator / RSD / short-link tags. REST: `GET|POST /builder/global`.
- **Favicon**: Site & SEO → Favicon replaces the WordPress site icon on every page.
- **Blog posts** use the Article schema by default: the article starter (category, H1, byline with reading time, lead image, standfirst, readable text column, related posts), a word counter with formatting buttons, a checklist (30–60 character title, 120–160 character description, 300+ words, an H2, short address) and `wordCount` in the Article structured data. The new `readtime` source ("4 min read") is available to Dynamic text and Details list.
- In the dashboard, forms (new page, new template, rename, search & sharing, import, theme engine, image details) open as full pages with a Back link instead of pop-ups; every checkbox is a toggle switch; links carry no underline.
- A **404 page** template (Templates → New template → 404 page) replaces the theme's "page not found" screen site-wide; the response status stays 404.
- Themes (save / install / export / import) now carry the types, the templates (card template ids are re-linked) and the entries of custom types, with their images.
- **Search & schema for entries:** each entry has a search title, description, social image and noindex (Content > entry > Search & sharing, with a Google preview and checklist), stored in the same `_rk_seo_*` meta as pages. Without custom text the title is the entry's and the description is clipped from its summary; the image falls back to the featured image, then the site default.
- Each content type picks a schema type (Web page, Article or Service) and can set a listing-page title and description. Entries print WebPage plus that node, Organization / WebSite / LocalBusiness and breadcrumbs (Home > listing > entry); listings print CollectionPage and breadcrumbs. noindex entries carry no schema. Nothing is printed when an SEO plugin owns the output.
- **Sitemap:** WordPress's own `wp-sitemap.xml` lists every public content type; RK Builder leaves out entries and pages marked noindex, adds `lastmod`, and adds a type's listing page when a live listing template draws it. (WordPress turns its sitemap off while "Discourage search engines" is on.)
