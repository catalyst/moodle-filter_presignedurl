<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_objectfs;

use core_filters\text_filter as base_text_filter;
use moodle_url;

/**
 * ObjectFS CloudFront presigned URL filter.
 *
 * Scans rendered HTML for ObjectFS CloudFront presigned URLs and replaces them
 * with their canonical Moodle pluginfile.php URLs.  The contenthash is derived
 * from the final path segment of every presigned URL ObjectFS generates
 * (e.g. `https://abc.cloudfront.net/17/73/<40-char-hash>?Expires=…`).
 *
 * All presigned URLs are replaced — not just expired ones — because any URL
 * that is valid today will silently break once its `Expires` timestamp passes.
 * Replacing them with permanent pluginfile.php URLs at render time ensures
 * content remains accessible regardless of when it was authored.
 *
 * Only `href` and `src` attributes are rewritten; the filter does not touch
 * plain text or other attribute types.
 *
 * @package    filter_objectfs
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends base_text_filter {

    /**
     * Per-request in-memory cache: contenthash => pluginfile URL string, or
     * false when no matching file record exists.
     *
     * Keyed by request so that multiple text blocks on the same page share
     * lookups without extra DB queries.
     *
     * @var array<string, string|false>
     */
    private static array $urlcache = [];

    /**
     * Apply the filter to a block of HTML.
     *
     * Returns the text unchanged when no ObjectFS CloudFront presigned URLs are
     * detected, ensuring zero overhead for the common case.
     *
     * @param  string $text    The HTML content to filter.
     * @param  array  $options Filter options (unused, kept for interface compat).
     * @return string          Filtered HTML content.
     */
    #[\Override]
    public function filter($text, array $options = []): string {
        // Quick bail: the CloudFront signing marker must appear in the raw HTML
        // before we do any expensive work.  This exits immediately for the vast
        // majority of text blocks that contain no presigned URLs at all.
        if (!str_contains($text, 'Expires=') || !str_contains($text, 'Key-Pair-Id=')) {
            return $text;
        }

        // Match href and src attribute values inside single or double quotes.
        // The negative lookbehind `(?<![a-zA-Z0-9_-])` prevents matching
        // composite attribute names such as `data-href` or `data-src`.
        $pattern = '/(?<![a-zA-Z0-9_-])(href|src)=(["\'])([^"\']+)\2/i';

        if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return $text;
        }

        // --- Pass 1: collect presigned URLs and their contenthashes ---
        //
        // HTML attribute values may encode `&` as `&amp;`.  We HTML-decode
        // before handing the string to parse_url / parse_str so that query
        // parameters are parsed correctly.

        /** @var array<string, string> $presignedmap  rawurl-as-in-HTML => contenthash */
        $presignedmap = [];
        /** @var array<string, true> $pendinghashes  contenthash => true (de-duplicated) */
        $pendinghashes = [];

        foreach ($matches as $match) {
            $rawurl  = $match[3];
            $decoded = html_entity_decode($rawurl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $contenthash = $this->extract_contenthash_from_presigned_url($decoded);
            if ($contenthash === null) {
                continue;
            }

            $presignedmap[$rawurl]        = $contenthash;
            $pendinghashes[$contenthash]  = true;
        }

        if (empty($presignedmap)) {
            return $text;
        }

        // --- Pass 2: resolve contenthashes → pluginfile.php URLs (batched) ---

        $pluginfileurls = $this->resolve_contenthashes(array_keys($pendinghashes));

        if (empty($pluginfileurls)) {
            return $text;
        }

        // --- Pass 3: rewrite presigned attribute values ---
        //
        // We use preg_replace_callback so we handle every occurrence and
        // rebuild the attribute cleanly, HTML-encoding the replacement URL.

        return preg_replace_callback(
            $pattern,
            function (array $match) use ($presignedmap, $pluginfileurls): string {
                $attr   = $match[1]; // 'href' or 'src'
                $quote  = $match[2]; // '"' or "'"
                $rawurl = $match[3];

                if (!isset($presignedmap[$rawurl])) {
                    return $match[0]; // Not a CloudFront presigned URL – leave unchanged.
                }

                $contenthash = $presignedmap[$rawurl];

                if (!isset($pluginfileurls[$contenthash])) {
                    return $match[0]; // File not found in DB – leave unchanged.
                }

                // Encode `&` and `"` so the URL is safe inside an HTML attribute.
                $newurl = htmlspecialchars($pluginfileurls[$contenthash], ENT_QUOTES, 'UTF-8');

                return "{$attr}={$quote}{$newurl}{$quote}";
            },
            $text
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the contenthash from a URL path and return it when the URL is an
     * ObjectFS CloudFront presigned URL, or return null otherwise.
     *
     * ObjectFS stores objects under the key `aa/bb/<40-char-sha1>` (where `aa`
     * and `bb` are the first two and second two hex characters of the hash).
     * The contenthash is therefore always the last path segment of the URL and
     * is exactly 40 lowercase hexadecimal characters — no extra query parameter
     * is required.
     *
     * Detection criteria:
     *  - The final path segment is a valid 40-character lowercase hex SHA-1.
     *  - Both `Expires` and `Key-Pair-Id` query parameters are present
     *    (CloudFront signed URL markers; distinguishes these from plain S3 or
     *    other URLs that happen to have a hex path segment).
     *
     * The expiry timestamp is intentionally NOT checked — all presigned URLs
     * are replaced unconditionally because any URL that is valid today will
     * silently break once its `Expires` value passes.
     *
     * @param  string $url  Fully decoded URL string.
     * @return string|null  The 40-char contenthash, or null when not applicable.
     */
    private function extract_contenthash_from_presigned_url(string $url): ?string {
        // Avoid parse_url overhead when the required markers are obviously absent.
        if (!str_contains($url, 'Expires=') || !str_contains($url, 'Key-Pair-Id=')) {
            return null;
        }

        $parsed = parse_url($url);
        if (empty($parsed['path']) || empty($parsed['query'])) {
            return null;
        }

        // Extract the contenthash from the final path segment.
        $segment = basename($parsed['path']);
        if (!preg_match('/^[0-9a-f]{40}$/', $segment)) {
            return null;
        }

        // Confirm CloudFront signature markers are present.
        parse_str($parsed['query'], $params);
        if (empty($params['Expires']) || empty($params['Key-Pair-Id'])) {
            return null;
        }

        return $segment;
    }

    /**
     * Resolve a list of contenthashes to their canonical pluginfile.php URLs.
     *
     * Looks up the first non-draft, non-directory file record that carries
     * each hash and builds a `moodle_url::make_pluginfile_url()` URL from it.
     *
     * Results are stored in {@see self::$urlcache} for the lifetime of the
     * current request, so repeated references to the same file on the same
     * page do not trigger additional DB queries.
     *
     * Hashes with no matching file record are cached as `false` so we also
     * avoid re-querying for genuinely missing files.
     *
     * @param  string[] $hashes  List of 40-char SHA-1 hex strings.
     * @return array<string, string>  contenthash => absolute pluginfile URL.
     */
    private function resolve_contenthashes(array $hashes): array {
        global $DB;

        $result    = [];
        $uncached  = [];

        foreach ($hashes as $hash) {
            if (array_key_exists($hash, self::$urlcache)) {
                if (self::$urlcache[$hash] !== false) {
                    $result[$hash] = self::$urlcache[$hash];
                }
            } else {
                $uncached[] = $hash;
            }
        }

        if (empty($uncached)) {
            return $result;
        }

        // Fetch one non-draft, non-directory file record per contenthash.
        // ORDER BY id ASC gives a stable, deterministic choice when multiple
        // records share the same hash (e.g. a file duplicated across courses).
        [$insql, $inparams] = $DB->get_in_or_equal($uncached, SQL_PARAMS_NAMED);

        $sql = "SELECT f.contenthash, f.contextid, f.component, f.filearea,
                       f.itemid, f.filepath, f.filename
                  FROM {files} f
                 WHERE f.contenthash {$insql}
                   AND f.filename  != '.'
                   AND f.filearea  != 'draft'
              ORDER BY f.id ASC";

        $records = $DB->get_records_sql($sql, $inparams);

        // Build the result map, keeping only the first record seen per hash.
        $seen = [];
        foreach ($records as $record) {
            if (isset($seen[$record->contenthash])) {
                continue;
            }
            $seen[$record->contenthash] = true;

            $url = moodle_url::make_pluginfile_url(
                $record->contextid,
                $record->component,
                $record->filearea,
                $record->itemid,
                $record->filepath,
                $record->filename
            );

            $urlstring = $url->out(false);
            self::$urlcache[$record->contenthash] = $urlstring;
            $result[$record->contenthash]         = $urlstring;
        }

        // Cache misses: mark hashes with no file record so we skip the DB
        // on subsequent calls within the same request.
        foreach ($uncached as $hash) {
            if (!isset(self::$urlcache[$hash])) {
                self::$urlcache[$hash] = false;
            }
        }

        return $result;
    }
}
