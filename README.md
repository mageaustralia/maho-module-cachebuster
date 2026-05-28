# MageAustralia_CacheBuster

Query-string cache buster for [Maho](https://github.com/MahoCommerce/maho) 26.5+. Post-processes the rendered HTML response and appends `?v=<filemtime>` to same-origin `/skin/`, `/js/`, `/media/` asset URLs so browsers and CDNs refetch each asset when its underlying file changes.

**No nginx/apache rewrite rules required.** Servers resolve `/skin/foo.css?v=12345` to the same file as `/skin/foo.css` out of the box  -  the version only matters as a cache key.

Inspired by [gordonknoppe/magento-cachebuster](https://github.com/gordonknoppe/magento-cachebuster) and [justbetter/magento1-cache-buster](https://github.com/justbetter/magento1-cache-buster), rewritten clean-room for Maho 26.5+ with the path-rewriting variant deliberately avoided in favour of pure query-string busting (no infra config needed).

- PHP 8.3+, strict types throughout, modern Maho conventions
- No DB schema, no cron, no JS, no admin controller  -  just one observer + one helper
- Per-file versioning (filemtime), so unchanged assets keep their long-cached copy across deploys
- Per-request `stat` memoisation so the same file is `stat`'d at most once per page
- Defensive: failures are logged and the original response is shipped untouched
- OSL-3.0

## Install

```bash
composer config repositories.maho-module-cachebuster vcs https://github.com/mageaustralia/maho-module-cachebuster.git
COMPOSER_ROOT_VERSION=26.5.x-dev composer require mageaustralia/maho-module-cachebuster:dev-main
./maho cache:flush
```

The `COMPOSER_ROOT_VERSION` env var lets the `mahocommerce/maho ^26.5` constraint resolve against a `dev-main` checkout of the Maho fork.

If your install drops modules into `app/code/community/` directly rather than via Composer, follow the same symlink + copy pattern the other `mageaustralia/maho-module-*` modules use.

## Configure

**System > Configuration > Advanced > Cache Buster**.

| Group | Field | What it does |
|---|---|---|
| General | Enabled | Master switch. Module ships **disabled**  -  flip to Yes after install. |
| General | Areas | Where the post-processor runs. `Frontend only` is the safe default. `Adminhtml` is rarely worth the per-request overhead. |
| Paths to Bust | Bust /skin/ URLs | Theme assets (CSS, JS, images shipped with the theme). |
| Paths to Bust | Bust /js/ URLs | Global JS files under `js/`. |
| Paths to Bust | Bust /media/ URLs | Uploaded media (product images, CMS uploads). Off-able if your media filenames already change with each upload. |

Then `./maho cache:flush`.

## How it works

The module hooks `http_response_send_before` and, when the response is `Content-Type: text/html`, walks the body for HTML tags whose attributes can legitimately point at a static asset:

```
<link>  <script>  <img>  <source>  <video>  <audio>  <iframe>  <input>  <track>
```

Deliberately **excluded**: `<a href>` and `<form action>`  -  those are navigation / form submission, not assets, and rewriting them would change behaviour.

For each `href` / `src` / `srcset` attribute on a matched tag, the URL's path is inspected:

1. Does it contain `/skin/`, `/js/`, or `/media/` (per config)?
2. Can the part of the path after the prefix be resolved to a real file on disk (with realpath, so symlinks work; path-traversal is blocked)?
3. Is it **not** a Maho merged-asset bundle at `/skin/m/<hash>/...` (those are already content-hashed)?

If all three pass, the URL gets `?v=<filemtime>` appended (or its existing `v=` value replaced). Existing query strings, fragments (`#hash`), data URIs, `mailto:`, `tel:`, and external/cross-origin URLs are all left untouched.

### Caveats

- **`<a>` tags are intentionally ignored**  -  see above. If you want a link's *target page* to break the cache, that's a separate concern (and usually wrong anyway).
- **JS string literals are not rewritten.** A `<script>` containing `const url = "/skin/foo.css"` keeps that string verbatim; the tag-scoped matcher only rewrites attributes, not tag contents. If you build asset URLs in JS and want them versioned, do it in JS (read a build-time constant or fetch via `mahoFetch`).
- **CDN behaviour varies.** Most CDNs treat query-string variants as separate cache keys by default (CloudFront, Cloudflare with the right rules, Fastly). Some require explicit config to include the query string in the cache key. Confirm with your CDN if a deploy doesn't seem to invalidate.
- **`merge_css_files` / `merge_js_files`** built into Maho still work alongside this  -  the merged-asset URLs at `/skin/m/<hash>/...` are recognised and skipped, so they get their content-hash versioning and nothing else.
- **Performance**: ~one regex pass over the HTML body plus one `realpath` + `filemtime` per unique asset URL, memoised per-request. On a typical page (30-50 assets) the cost is well under 5ms.

## Disable / uninstall

Disable without removing:

- **System > Configuration > Advanced > Cache Buster > General > Enabled = No**, then `./maho cache:flush`. The post-processor short-circuits and the response is sent untouched.

Uninstall completely:

```bash
composer remove mageaustralia/maho-module-cachebuster
./maho cache:flush
```

## Compatibility

- Maho 26.5+
- PHP 8.3+

## Development

```bash
composer install
```

CI: see `.github/workflows/ci.yml`  -  runs the shared `mageaustralia/maho-ci` reusable workflow (composer-validate + `php -l` + removed-Zend/Varien/Prototype scan).
