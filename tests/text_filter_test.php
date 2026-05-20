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

namespace filter_presignedurl;

use filter_presignedurl\text_filter;
use moodle_url;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for filter_presignedurl\text_filter.
 *
 * @package    filter_presignedurl
 * @category   test
 * @author     Niko Hoogeveen <niko.hoogeveen@catalyst-ca.net>
 * @copyright  2026 Catalyst IT Canada LTD
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass('\filter_presignedurl\text_filter')]
final class text_filter_test extends \advanced_testcase {
    /** @var string A fake CloudFront domain used when building test presigned URLs. */
    private const CF_DOMAIN = 'https://abc123.cloudfront.net';

    /** @var string A fake CloudFront key pair ID. */
    private const CF_KEY_PAIR_ID = 'ABCDEFGHIJ12345';

    /** @var string A fake CloudFront signature. */
    private const CF_SIGNATURE = 'fakesignature~value-here';

    /**
     * Reset the per-request static URL cache between tests so earlier lookups
     * do not bleed into later ones.  The cache is private, so we use
     * reflection to clear it.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Configure the CloudFront resource domain so the filter is active.
        set_config('cloudfrontresourcedomain', 'abc123.cloudfront.net', 'tool_objectfs');

        $rc = new \ReflectionClass(text_filter::class);
        $prop = $rc->getProperty('urlcache');
        $prop->setValue(null, []);
    }

    /**
     * Return an instance of the filter bound to the system context.
     *
     * @return text_filter
     */
    private function make_filter(): text_filter {
        return new text_filter(\core\context\system::instance(), []);
    }

    /**
     * Build a fake CloudFront presigned URL for testing.
     *
     * The contenthash is encoded as the final path segment following ObjectFS's
     * `aa/bb/<40-char-hash>` key convention.  The `$expires` parameter controls
     * the `Expires` query value but does not affect whether the filter replaces
     * the URL — all presigned URLs are replaced unconditionally.
     *
     * @param  string $contenthash  40-char SHA-1 hex string.
     * @param  int    $expires      Unix timestamp written into the `Expires` param.
     * @param  string $domain       CloudFront resource domain (default: CF_DOMAIN).
     * @return string               Full URL string.
     */
    private function make_presigned_url(
        string $contenthash,
        int $expires,
        string $domain = self::CF_DOMAIN
    ): string {
        $l1  = substr($contenthash, 0, 2);
        $l2  = substr($contenthash, 2, 2);
        $key = "{$l1}/{$l2}/{$contenthash}";

        $params = http_build_query([
            'Expires'      => $expires,
            'Signature'    => self::CF_SIGNATURE,
            'Key-Pair-Id'  => self::CF_KEY_PAIR_ID,
        ]);

        return "{$domain}/{$key}?{$params}";
    }

    /**
     * Create a real stored file in the {files} table and return its contenthash
     * together with the expected pluginfile.php URL.
     *
     * @param  string $content Arbitrary content to store (determines hash).
     * @return array{0: string, 1: string}  [$contenthash, $pluginfileurl]
     */
    private function create_stored_file(string $content = 'objectfs filter test content'): array {
        $fs         = get_file_storage();
        $syscontext = \core\context\system::instance();

        $filerecord = [
            'contextid' => $syscontext->id,
            'component' => 'core',
            'filearea'  => 'unittest',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'testfile_' . sha1($content) . '.txt',
        ];

        $file = $fs->create_file_from_string($filerecord, $content);

        $url = moodle_url::make_pluginfile_url(
            $syscontext->id,
            'core',
            'unittest',
            0,
            '/',
            $file->get_filename()
        );

        return [$file->get_contenthash(), $url->out(false)];
    }

    /**
     * Text with no presigned-URL indicators is returned unchanged without any
     * DB query.  This covers the fast-path str_contains bail.
     *
     * @dataProvider provider_no_presigned_url_indicators
     */
    public function test_no_presigned_url_indicators_returned_unchanged(string $text): void {
        $filter = $this->make_filter();
        $this->assertSame($text, $filter->filter($text));
    }

    /**
     * Data provider for {@see test_no_presigned_url_indicators_returned_unchanged}.
     *
     * @return array<string, array{0: string}>
     */
    public static function provider_no_presigned_url_indicators(): array {
        return [
            'plain text'                     => ['Hello, world!'],
            'html no urls'                   => ['<p>Some <strong>bold</strong> text.</p>'],
            'regular https link'             => ['<a href="https://moodle.org">Moodle</a>'],
            'has Expires but no Key-Pair-Id' => ['<a href="https://example.com?Expires=99999">link</a>'],
            'has Key-Pair-Id but no Expires' => ['<a href="https://example.com?Key-Pair-Id=ABC123">link</a>'],
        ];
    }

    /**
     * A presigned URL in an <a href> is replaced with the canonical
     * pluginfile.php URL when a matching file record exists.
     */
    public function test_presigned_href_replaced_with_pluginfile_url(): void {
        [$contenthash, $pluginfileurl] = $this->create_stored_file();
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);

        $input    = '<p>Download: <a href="' . $presigned . '">my file</a></p>';
        $expected = '<p>Download: <a href="' . $pluginfileurl . '">my file</a></p>';

        $this->assertSame($expected, $this->make_filter()->filter($input));
    }

    /**
     * A presigned URL in an <img src> is replaced with the canonical
     * pluginfile.php URL when a matching file record exists.
     */
    public function test_presigned_img_src_replaced_with_pluginfile_url(): void {
        [$contenthash, $pluginfileurl] = $this->create_stored_file('image content');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);

        $input    = '<img src="' . $presigned . '" alt="photo">';
        $expected = '<img src="' . $pluginfileurl . '" alt="photo">';

        $this->assertSame($expected, $this->make_filter()->filter($input));
    }

    /**
     * Multiple distinct presigned URLs in one text block are all replaced in a
     * single filter call.
     */
    public function test_multiple_presigned_urls_all_replaced(): void {
        [$hash1, $url1] = $this->create_stored_file('file one');
        [$hash2, $url2] = $this->create_stored_file('file two');

        $presigned1 = $this->make_presigned_url($hash1, time() + 7200);
        $presigned2 = $this->make_presigned_url($hash2, time() + 3600);

        $input = '<a href="' . $presigned1 . '">one</a> <a href="' . $presigned2 . '">two</a>';

        $result = $this->make_filter()->filter($input);

        $this->assertStringContainsString($url1, $result);
        $this->assertStringContainsString($url2, $result);
        $this->assertStringNotContainsString($presigned1, $result);
        $this->assertStringNotContainsString($presigned2, $result);
    }

    /**
     * When the same presigned URL appears multiple times in the HTML, every
     * occurrence is replaced (not just the first).
     */
    public function test_repeated_presigned_url_all_occurrences_replaced(): void {
        [$contenthash, $pluginfileurl] = $this->create_stored_file('repeated content');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);

        $input = '<a href="' . $presigned . '">A</a> <a href="' . $presigned . '">B</a>';

        $result = $this->make_filter()->filter($input);

        $this->assertStringNotContainsString($presigned, $result);
        $this->assertEquals(2, substr_count($result, $pluginfileurl));
    }

    /**
     * A presigned URL whose Expires timestamp is in the future is still
     * replaced — the filter acts unconditionally on all CloudFront presigned
     * URLs so that content does not break when the URL eventually expires.
     */
    public function test_valid_presigned_url_is_replaced(): void {
        [$contenthash, $pluginfileurl] = $this->create_stored_file('valid file');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);

        $input    = '<a href="' . $presigned . '">file</a>';
        $expected = '<a href="' . $pluginfileurl . '">file</a>';

        $this->assertSame($expected, $this->make_filter()->filter($input));
    }

    /**
     * A presigned URL whose contenthash does not match any record in the
     * {files} table is left unchanged.
     */
    public function test_unknown_contenthash_not_replaced(): void {
        // This contenthash is valid in format but has no file record.
        $unknownhash = str_repeat('0', 40);
        $presigned   = $this->make_presigned_url($unknownhash, time() + 3600);

        $input = '<a href="' . $presigned . '">file</a>';

        $this->assertSame($input, $this->make_filter()->filter($input));
    }

    /**
     * A URL whose final path segment is not a valid 40-char lowercase hex
     * SHA-1 string is ignored even when CloudFront signature params are present.
     */
    public function test_non_hash_path_segment_ignored(): void {
        $expires = time() - 3600;
        $badurl  = self::CF_DOMAIN . '/aa/bb/not-a-valid-sha1-segment'
                 . '?Expires=' . $expires
                 . '&Signature=' . self::CF_SIGNATURE
                 . '&Key-Pair-Id=' . self::CF_KEY_PAIR_ID;

        $input = '<a href="' . $badurl . '">link</a>';

        $this->assertSame($input, $this->make_filter()->filter($input));
    }

    /**
     * Composite attribute names such as `data-href` and `data-src` are NOT
     * rewritten, because they are not actual navigation or resource attributes.
     * The negative lookbehind in the pattern prevents false matches.
     */
    public function test_composite_attribute_names_not_rewritten(): void {
        [$contenthash] = $this->create_stored_file('composite attr');
        $expired = $this->make_presigned_url($contenthash, time() - 3600);

        // Data-href and data-src must not be touched.
        $input = '<div data-href="' . $expired . '" data-src="' . $expired . '">x</div>';

        $this->assertSame($input, $this->make_filter()->filter($input));
    }

    /**
     * A file that only exists as a draft (`filearea = 'draft'`) is excluded
     * from the lookup and the expired URL is left unchanged.
     */
    public function test_draft_file_excluded_from_lookup(): void {
        $fs         = get_file_storage();
        $syscontext = \core\context\user::instance(2); // Typically admin user.
        $content    = 'draft only content ' . uniqid();

        $filerecord = [
            'contextid' => $syscontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => 9999,
            'filepath'  => '/',
            'filename'  => 'draftfile.txt',
        ];
        $file      = $fs->create_file_from_string($filerecord, $content);
        $presigned = $this->make_presigned_url($file->get_contenthash(), time() + 3600);
        $input     = '<a href="' . $presigned . '">draft</a>';

        $this->assertSame($input, $this->make_filter()->filter($input));
    }

    /**
     * Calling the filter twice with the same presigned URL (same contenthash)
     * produces the correct replacement both times, proving the cache returns a
     * valid hit on the second call.
     */
    public function test_cache_returns_correct_url_on_subsequent_calls(): void {
        [$contenthash, $pluginfileurl] = $this->create_stored_file('cache test content');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);
        $input     = '<a href="' . $presigned . '">file</a>';
        $expected  = '<a href="' . $pluginfileurl . '">file</a>';

        $filter = $this->make_filter();

        // First call – populates the cache.
        $this->assertSame($expected, $filter->filter($input));

        // Second call – served from cache; output must be identical.
        $this->assertSame($expected, $filter->filter($input));
    }

    /**
     * A cache miss (unknown contenthash) is stored as `false` so a repeated
     * call with the same contenthash does not hit the DB again.  The URL is
     * left unchanged on both calls.
     */
    public function test_cache_records_misses_and_url_unchanged_on_repeat(): void {
        $unknownhash = str_repeat('a', 40);
        $presigned   = $this->make_presigned_url($unknownhash, time() + 3600);
        $input       = '<a href="' . $presigned . '">file</a>';

        $filter = $this->make_filter();

        $this->assertSame($input, $filter->filter($input));
        $this->assertSame($input, $filter->filter($input));

        // The cache should contain exactly one entry (the miss) for this hash.
        $rc   = new \ReflectionClass(text_filter::class);
        $prop = $rc->getProperty('urlcache');
        $prop->setAccessible(true);
        $cache = $prop->getValue(null);

        $this->assertArrayHasKey($unknownhash, $cache);
        $this->assertFalse($cache[$unknownhash]);
    }

    /**
     * When the HTML contains `&amp;` instead of `&` in the presigned URL query
     * string (as is common in rendered HTML attributes), the filter correctly
     * decodes the URL before extracting the contenthash and replaces it with
     * the canonical pluginfile.php URL.
     */
    public function test_html_encoded_ampersands_in_presigned_url(): void {
        [$contenthash, $pluginfileurl] = $this->create_stored_file('html encoded url');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);

        $input    = '<a href="' . $presigned . '">file</a>';
        $expected = '<a href="' . htmlspecialchars($pluginfileurl, ENT_QUOTES, 'UTF-8') . '">file</a>';

        $this->assertSame($expected, $this->make_filter()->filter($input));
    }

    /**
     * When no CloudFront resource domain is configured in tool_objectfs, the
     * filter returns content unchanged without attempting any rewriting.
     */
    public function test_no_configured_domain_returns_unchanged(): void {
        unset_config('cloudfrontresourcedomain', 'tool_objectfs');

        [$contenthash] = $this->create_stored_file('no domain configured');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600);
        $input     = '<a href="' . $presigned . '">file</a>';

        $this->assertSame($input, $this->make_filter()->filter($input));
    }

    /**
     * A presigned URL from a different CloudFront distribution (domain does not
     * match the configured cloudfrontresourcedomain) is left unchanged.
     */
    public function test_different_cloudfront_domain_not_rewritten(): void {
        [$contenthash] = $this->create_stored_file('wrong domain');
        $presigned = $this->make_presigned_url($contenthash, time() + 3600, 'https://other.cloudfront.net');
        $input     = '<a href="' . $presigned . '">file</a>';

        $this->assertSame($input, $this->make_filter()->filter($input));
    }
}
