# filter_presignedurl — ObjectFS CloudFront presigned URL filter

A Moodle filter plugin that replaces ObjectFS CloudFront presigned URLs embedded in course content with permanent `pluginfile.php` URLs, ensuring files remain accessible after the presigned URL expires.

## Background

When [tool_objectfs](https://github.com/catalyst/moodle-tool_objectfs) is configured with CloudFront presigned URL delivery, every file served through Moodle receives a time-limited URL of the form:

```
https://<distribution>.cloudfront.net/aa/bb/<40-char-contenthash>?Expires=<unix>&Signature=<sig>&Key-Pair-Id=<id>
```

If a teacher copies such a URL from their browser and pastes it directly into a course page, activity description, or any other rich-text area, that link will silently break once the `Expires` timestamp passes. This filter intercepts all presigned URLs at render time and rewrites them to their canonical, permanent Moodle `pluginfile.php` equivalents before the page is sent to the browser.

## How it works

The filter runs on every piece of HTML content Moodle renders and applies a three-pass algorithm:

1. **Detect** — a fast string check for `Expires=` and `Key-Pair-Id=` bails out immediately for text blocks that contain no presigned URLs, adding negligible overhead to the common case.
2. **Collect** — all `href` and `src` attribute values are matched. For each one that looks like an ObjectFS presigned URL, the 40-character SHA-1 contenthash is extracted from the final URL path segment (`aa/bb/<hash>`).
3. **Resolve** — the contenthashes are looked up in the `{files}` database table in a single batched query. Each matching record is converted to a `moodle_url::make_pluginfile_url()` URL and the presigned URL is replaced in-place.

A per-request in-memory cache prevents repeated DB queries when the same file appears multiple times on the same page.

**All presigned URLs are replaced unconditionally** — even ones that have not yet expired — because a URL that is valid today will silently break once its expiry time passes. Replacing with a permanent URL at render time is safer than attempting to detect expiry.

Only `href` and `src` attributes are rewritten. Composite attribute names such as `data-href` and `data-src` are left untouched.

## Requirements

| Requirement | Version |
|---|---|
| Moodle | 4.5 or later (requires `2025092600`) |
| PHP | 8.1 or later |
| [tool_objectfs](https://github.com/catalyst/moodle-tool_objectfs) | Any version |

## Supported branches

| Moodle version    | Branch                                                                                             | PHP  | MySQL   | PostgreSQL  |
|-------------------|----------------------------------------------------------------------------------------------------|------|---------|-------------|
| Moodle 4.5+       | [MOODLE_405_STABLE](https://github.com/catalyst/moodle-filter_presignedurl/tree/MOODLE_405_STABLE) | 8.1+ | 8.0+    | 13+         |

## Installation

### Via Git (recommended)

```bash
cd /path/to/moodle
git clone git@github.com:catalyst/moodle-filter_presignedurl.git filter/presignedurl
```

### Manual

1. Download the plugin archive.
2. Extract it so the path is `<moodleroot>/filter/presignedurl/`.
3. Ensure the directory contains `version.php`, `classes/text_filter.php`, `lang/en/filter_presignedurl.php`, and `classes/privacy/provider.php`.

### Database upgrade

After placing the files, trigger the Moodle upgrade:

```bash
php admin/cli/upgrade.php
```

Or navigate to **Site administration → Notifications** in your browser and follow the upgrade wizard.

## Configuration

### Enable the filter

1. Go to **Site administration → Plugins → Filters → Manage filters**.
2. Find **ObjectFS Presigned URL Replacement** in the list.
3. Set its state to **Enabled**.

### Filter order

This filter rewrites raw URLs, so it should run **before** content-rendering filters. In the Manage filters interface, use the up/down arrows to position `filter_presignedurl` above filters such as:

- Multi-language content (`filter_multilang`)
- Activity names auto-linking (`filter_activitynames`)

The filter does not need to run before security-related filters.

### Context visibility

By default the filter is active in all contexts. No per-course or per-activity configuration is needed.

## Running the tests

The plugin includes a PHPUnit test suite covering URL detection, DB resolution, caching behaviour, and edge cases.

```bash
# From the Moodle root
vendor/bin/phpunit filter/presignedurl/tests/text_filter_test.php
```

To run via the Moodle test runner:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite filter_presignedurl_testsuite
```

## Limitations

- Only `href` and `src` attributes in HTML are rewritten. A presigned URL pasted as visible plain text (not as a hyperlink) is not modified.
- Draft files (`filearea = 'draft'`) and directory placeholder records (`filename = '.'`) are intentionally excluded from the lookup.
- When the same contenthash matches multiple file records (e.g. the same file duplicated across courses), the record with the lowest `id` is used to build the replacement URL.
- The filter does not modify the stored content in the database — it only rewrites content at render time.
