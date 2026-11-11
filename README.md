# DigitB News Cloudflare Smart Purge

[![Version](https://img.shields.io/badge/version-1.3.1-blue.svg)](https://github.com/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)



**Version:** 1.3.1  
**License:** GPL-2.0-or-later

Targeted Cloudflare cache purging for WordPress news sites, with an optional purge URL/result logger, configurable log retention, automatic cleanup, and manual homepage purging.

## Overview

DigitB News Cloudflare Smart Purge is designed for WordPress news websites that publish frequently and need newly published content to become visible quickly without using Cloudflare's **Purge Everything** operation.

When a post is published or its status changes to published, the plugin queues targeted URLs for Cloudflare cache purge. Depending on the post's taxonomy, the purge set may include:

- The published post URL
- The site homepage
- Relevant category URLs
- Relevant tag URLs
- Previously assigned taxonomy URLs when applicable

The plugin uses Cloudflare's targeted `purge_cache` API endpoint and does not perform a global cache purge.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A Cloudflare-proxied website/zone
- A Cloudflare API token with permission to purge cache for the target zone
- HTTPS is strongly recommended

## Installation

1. Upload `digitb-news-cloudflare-smart-purge.zip` from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Open **Tools → Cloudflare Smart Purge**.
4. Add the Cloudflare Zone ID and API token as constants in `wp-config.php`.
5. Verify the plugin's API connection/status from the plugin settings page.

## Cloudflare API Configuration

Add the following to `wp-config.php`, preferably before the line:

```php
/* That's all, stop editing! Happy publishing. */
```

Use your actual values:

```php
define( 'CLOUDFLARE_SMART_PURGE_ZONE_ID', 'YOUR_ZONE_ID' );
define( 'CLOUDFLARE_SMART_PURGE_API_TOKEN', 'YOUR_API_TOKEN' );
```

### API token scope

The token should be restricted to the target Cloudflare zone and should have only the cache purge permission required by this plugin:

```text
Zone → Cache Purge → Purge
```

Do not publish or share the API token.

## Logger

The URL/result logger is integrated into the Smart Purge plugin. No separate purge logger plugin is required.

The logger can be enabled or disabled independently of the purge functionality.

When the logger is disabled, Cloudflare purging continues to work normally.

Logged information may include the purge event, status, URL count, and relevant result information needed for troubleshooting.

## Log Retention

The plugin provides configurable automatic log retention:

- 24 hours
- 48 hours
- 1 week

Logs older than the selected retention period are automatically removed during the plugin's cleanup process.

A maximum log-row limit is also available as an additional safety mechanism. This prevents an unusually large number of log records from accumulating.

## Clearing Recent Logs

Use the **Clear Recent Logs** action from:

**Tools → Cloudflare Smart Purge**

This removes the stored recent log records without disabling the Smart Purge system.

## Manual Homepage Purge

The plugin provides a manual **Purge Homepage Cache** action from its Tools page.

Use this when you need to refresh the homepage cache without publishing a new post.

## Automatic Purging

The plugin listens for WordPress post status changes and queues targeted purge requests.

A typical news publishing flow is:

```text
New post published
        ↓
WordPress status change
        ↓
Smart Purge queue
        ↓
Targeted Cloudflare purge
        ↓
Post / homepage / relevant taxonomy URLs
```

The plugin intentionally avoids `Purge Everything` so that unrelated cached assets and pages are not unnecessarily invalidated.

## Recommended Cache Architecture

For a frequently updated WordPress news site, the plugin is intended to work alongside:

```text
WordPress
   ↓
Breeze / Cloudways HTML Cache
   ↓
Redis / Object Cache Pro
   ↓
Cloudflare
   ├── Static asset edge cache
   └── Targeted purge for updated URLs
```

Cloudflare HTML caching is optional and should be configured separately. The plugin itself does not require Cloudflare public HTML caching to be enabled.

## Static Assets

The plugin does not manage CSS, JavaScript, images, fonts, or other static asset caching rules. Those should be handled by Cloudflare Cache Rules or your existing caching stack.

## Troubleshooting

### Purge is not happening

Check:

1. The Cloudflare Zone ID is correct.
2. The API token is valid and has the required purge permission.
3. The domain is in the correct Cloudflare account/zone.
4. The website is proxied through Cloudflare.
5. The WordPress post is actually transitioning to the published state.
6. Review the Smart Purge logs for the returned API status/result.

### The logger is disabled

This does not disable cache purging. Logger state and purge functionality are independent.

### Logs are growing too large

Reduce the retention period and/or lower the maximum log-row limit from the plugin settings page. Old records are automatically cleaned up.

## Security Notes

- Keep the Cloudflare API token secret.
- Restrict the token to the required zone and purge permission.
- Do not place the API token in publicly accessible files.
- Do not commit `wp-config.php` to a public repository.
- Use HTTPS for the WordPress site and Cloudflare API communication.

## Changelog

### 1.3.1

- Integrated the purge URL/result logger into the Smart Purge plugin.
- Added logger enable/disable control.
- Added configurable log retention: 24 hours, 48 hours, and 1 week.
- Added automatic cleanup of expired log records.
- Added maximum log-row safety limit.
- Added Clear Recent Logs control.
- Added dedicated database storage for purge logs.
- Retained targeted Cloudflare purge behavior.
- Retained manual homepage purge.

### 1.2.0

- Added targeted Cloudflare purge workflow for WordPress news publishing.
- Added delayed/deduplicated purge queue and recent purge logging.
- Added manual homepage purge.


## Backward compatibility

Version 1.3.1 supports both the current constants and the legacy constants used by earlier releases. Existing sites can keep:

```php
define( 'NEWS_CF_ZONE_ID', 'YOUR_ZONE_ID' );
define( 'NEWS_CF_API_TOKEN', 'YOUR_API_TOKEN' );
```

New installations may use:

```php
define( 'CLOUDFLARE_SMART_PURGE_ZONE_ID', 'YOUR_ZONE_ID' );
define( 'CLOUDFLARE_SMART_PURGE_API_TOKEN', 'YOUR_API_TOKEN' );
```

If both are present, the `CLOUDFLARE_SMART_PURGE_*` constants take precedence.

## Repository

This repository contains the source code for the DigitB News Cloudflare Smart Purge WordPress plugin.

The repository is intended to be public. **Never commit live Cloudflare credentials, `wp-config.php`, database dumps, server credentials, or other secrets.**

## Development

The plugin is intentionally distributed as a single PHP plugin file with accompanying documentation. No Composer or Node.js build step is required.

Before publishing a release:

1. Confirm the plugin version in the PHP plugin header and `VERSION` constant.
2. Run PHP syntax validation:
   `php -l digitb-news-cloudflare-smart-purge.php`
3. Review the changed files for credentials or private server information.
4. Test activation, manual homepage purge, logger settings, and automatic post-publish purge on a staging site.
5. Create a Git tag matching the release version, for example `v1.3.1`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
