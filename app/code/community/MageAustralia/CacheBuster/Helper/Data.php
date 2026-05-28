<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    MageAustralia_CacheBuster
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Cache-buster URL-stamping helper.
 *
 * The post-processor scans the rendered HTML response, finds same-origin
 * asset URLs (href / src / srcset on link/script/img/source/video/audio/
 * iframe/input/track tags), and appends a `?v=<filemtime>` query string
 * derived from the file's on-disk mtime. Browsers and CDNs treat the
 * versioned URL as a new resource and refetch when the file changes;
 * unchanged files keep their long-cached copy.
 *
 * Why query-string instead of path rewriting: this works with stock
 * Maho out of the box. No nginx/apache rewrite rules required. Servers
 * resolve `/skin/foo.css?v=123` to the same file as `/skin/foo.css`;
 * the version only matters as a cache key.
 *
 * Tag-scoped matching: we ONLY rewrite attributes inside an explicit
 * allow-list of HTML tags. That deliberately excludes `<a href>` (those
 * are navigation links, not assets) and prevents accidental rewriting
 * of URL-like substrings embedded inside `<script>` JS literals.
 */
class MageAustralia_CacheBuster_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'MageAustralia_CacheBuster';

    public const XML_PATH_ENABLED    = 'mageaustralia_cachebuster/general/enabled';
    public const XML_PATH_AREAS      = 'mageaustralia_cachebuster/general/areas';
    public const XML_PATH_BUST_SKIN  = 'mageaustralia_cachebuster/paths/bust_skin';
    public const XML_PATH_BUST_JS    = 'mageaustralia_cachebuster/paths/bust_js';
    public const XML_PATH_BUST_MEDIA = 'mageaustralia_cachebuster/paths/bust_media';

    /**
     * HTML tags whose href/src/srcset attributes can legitimately point at
     * a static asset. Deliberately excludes <a> and <form> (navigation /
     * form submission, not asset loads).
     *
     * @var list<string>
     */
    private const ASSET_TAGS = ['link', 'script', 'img', 'source', 'video', 'audio', 'iframe', 'input', 'track'];

    /**
     * Per-request memo of filesystem version lookups so the same file is
     * stat()'d at most once per page.
     *
     * @var array<string, int|null>
     */
    private array $_versionCache = [];

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $storeId);
    }

    /**
     * Frontend / adminhtml gate. Config value is one of:
     *   frontend, adminhtml, frontend_adminhtml.
     */
    public function isEnabledForArea(string $area, ?int $storeId = null): bool
    {
        if (!$this->isEnabled($storeId)) {
            return false;
        }
        $configured = (string) Mage::getStoreConfig(self::XML_PATH_AREAS, $storeId);
        return $configured !== '' && str_contains($configured, $area);
    }

    /**
     * Path prefixes (without leading slash) currently flagged for busting.
     *
     * @return list<string>
     */
    public function getBustablePaths(?int $storeId = null): array
    {
        $paths = [];
        if (Mage::getStoreConfigFlag(self::XML_PATH_BUST_SKIN, $storeId)) {
            $paths[] = 'skin';
        }
        if (Mage::getStoreConfigFlag(self::XML_PATH_BUST_JS, $storeId)) {
            $paths[] = 'js';
        }
        if (Mage::getStoreConfigFlag(self::XML_PATH_BUST_MEDIA, $storeId)) {
            $paths[] = 'media';
        }
        return $paths;
    }

    /**
     * Rewrite all bustable asset URLs in an HTML document. Returns the
     * modified HTML; returns the original string unchanged when nothing
     * matches or when busting is configured off.
     */
    public function bustHtml(string $html, ?int $storeId = null): string
    {
        $bustablePaths = $this->getBustablePaths($storeId);
        if ($bustablePaths === [] || $html === '') {
            return $html;
        }

        $tagAlternation = implode('|', self::ASSET_TAGS);
        // Match an opening tag whose name is in the allow-list, capturing
        // the attribute payload (everything up to the closing `>`). The
        // optional trailing `/` in self-closing tags is part of $attrs.
        $tagPattern = '#<(' . $tagAlternation . ')\b([^>]*)>#i';

        $result = preg_replace_callback(
            $tagPattern,
            function (array $m) use ($bustablePaths): string {
                $tagName = $m[1];
                $attrs   = $this->_rewriteAttrs($m[2], $bustablePaths);
                return '<' . $tagName . $attrs . '>';
            },
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Walk the href / src / srcset attributes inside a single tag's
     * attribute string and rewrite each value.
     */
    private function _rewriteAttrs(string $attrs, array $bustablePaths): string
    {
        $attrPattern = '#\b(href|src|srcset)\s*=\s*("|\')([^"\']*)\2#i';

        $rewritten = preg_replace_callback(
            $attrPattern,
            function (array $m) use ($bustablePaths): string {
                $name  = strtolower($m[1]);
                $quote = $m[2];
                $value = $m[3];

                $newValue = ($name === 'srcset')
                    ? $this->_bustSrcset($value, $bustablePaths)
                    : $this->bustUrl($value, $bustablePaths);

                return $name . '=' . $quote . $newValue . $quote;
            },
            $attrs,
        );

        return $rewritten ?? $attrs;
    }

    /**
     * srcset value is "url1 descriptor1, url2 descriptor2, ...". Bust each
     * URL individually; leave the descriptors alone.
     */
    private function _bustSrcset(string $srcset, array $bustablePaths): string
    {
        $trimmed = trim($srcset);
        if ($trimmed === '') {
            return $srcset;
        }
        $parts = preg_split('/\s*,\s*/', $trimmed);
        if ($parts === false || $parts === []) {
            return $srcset;
        }
        $rebuilt = array_map(function (string $part) use ($bustablePaths): string {
            $tokens = preg_split('/\s+/', trim($part), 2);
            if ($tokens === false || $tokens === []) {
                return $part;
            }
            $url  = $tokens[0];
            $desc = $tokens[1] ?? null;
            $newUrl = $this->bustUrl($url, $bustablePaths);
            return $desc === null ? $newUrl : $newUrl . ' ' . $desc;
        }, $parts);
        return implode(', ', $rebuilt);
    }

    /**
     * Bust a single URL. Returns the original URL unchanged when:
     *   - it's empty, a data:/mailto:/tel: URI, or otherwise unparseable
     *   - its path doesn't contain a bustable prefix
     *   - it's a Maho merged-CSS/JS bundle (already content-hashed)
     *   - the referenced file doesn't exist on disk
     */
    public function bustUrl(string $url, ?array $bustablePaths = null): string
    {
        if ($url === '' || preg_match('#^(data:|mailto:|tel:|javascript:|#)#i', $url)) {
            return $url;
        }

        $bustablePaths ??= $this->getBustablePaths();
        if ($bustablePaths === []) {
            return $url;
        }

        // Extract just the path portion for prefix matching. Use parse_url
        // for absolute URLs; for relative URLs, the path is the URL itself
        // up to the first ? or #.
        $parsed = @parse_url($url);
        if ($parsed === false) {
            return $url;
        }
        $path = $parsed['path'] ?? '';
        if ($path === '') {
            return $url;
        }

        // Which bustable prefix (if any) does this URL's path land inside?
        $matchedPrefix = null;
        $relativePath  = null;
        foreach ($bustablePaths as $prefix) {
            $marker = '/' . $prefix . '/';
            $pos = strpos($path, $marker);
            if ($pos !== false) {
                $matchedPrefix = $prefix;
                $relativePath  = substr($path, $pos + strlen($marker));
                break;
            }
        }
        if ($matchedPrefix === null || $relativePath === null || $relativePath === '') {
            return $url;
        }

        // Maho's built-in merged-asset bundles live at /skin/m/<hash>/...
        // and are already content-versioned via the hash segment. Skip.
        if ($matchedPrefix === 'skin' && str_starts_with($relativePath, 'm/')) {
            return $url;
        }

        $version = $this->_getVersionForPath($matchedPrefix, $relativePath);
        if ($version === null) {
            return $url;
        }

        return $this->_appendOrReplaceVersionQuery($url, $version);
    }

    /**
     * Resolve a (prefix, relative-path) pair to the file's mtime. Returns
     * null when the file doesn't exist on disk. Results are memoised in a
     * per-request map.
     */
    private function _getVersionForPath(string $prefix, string $relativePath): ?int
    {
        $cacheKey = $prefix . ':' . $relativePath;
        if (array_key_exists($cacheKey, $this->_versionCache)) {
            return $this->_versionCache[$cacheKey];
        }

        $baseDir = (string) Mage::getBaseDir($prefix);
        if ($baseDir === '') {
            return $this->_versionCache[$cacheKey] = null;
        }

        // Block path-traversal attempts so a crafted URL can't `stat` files
        // outside the asset directory.
        $candidate = $baseDir . DIRECTORY_SEPARATOR . $relativePath;
        $real      = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return $this->_versionCache[$cacheKey] = null;
        }
        $realBase = realpath($baseDir);
        if ($realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return $this->_versionCache[$cacheKey] = null;
        }

        $mtime = @filemtime($real);
        return $this->_versionCache[$cacheKey] = ($mtime === false ? null : $mtime);
    }

    /**
     * Append `?v=<n>` to a URL that has no query string, or replace an
     * existing `v=<…>` value, or append `&v=<n>` to a URL that already
     * has a different query string. Preserves any URL fragment.
     */
    private function _appendOrReplaceVersionQuery(string $url, int $version): string
    {
        $fragment = '';
        $hashPos  = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url      = substr($url, 0, $hashPos);
        }

        $queryPos = strpos($url, '?');
        if ($queryPos === false) {
            return $url . '?v=' . $version . $fragment;
        }

        $base  = substr($url, 0, $queryPos);
        $query = substr($url, $queryPos + 1);

        if ($query === '') {
            return $base . '?v=' . $version . $fragment;
        }
        if (preg_match('/(^|&)v=[^&]*/', $query)) {
            $query = (string) preg_replace('/(^|&)v=[^&]*/', '$1v=' . $version, $query);
        } else {
            $query .= '&v=' . $version;
        }
        return $base . '?' . $query . $fragment;
    }
}
