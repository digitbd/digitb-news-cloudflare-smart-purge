=== DigitB News Cloudflare Smart Purge ===
Contributors: digitb
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later

Targeted Cloudflare cache purge for WordPress news publishing, with optional purge URL logging and automatic log retention.

== Configuration ==
Add these constants to wp-config.php before the line that says 'That's all, stop editing!':

 define( 'CLOUDFLARE_SMART_PURGE_ZONE_ID', 'YOUR_ZONE_ID' );
 define( 'CLOUDFLARE_SMART_PURGE_API_TOKEN', 'YOUR_API_TOKEN' );

The API token should have only the required Cache Purge permission for the target zone.

== Features ==
* Purges the post URL, homepage and relevant taxonomy URLs after publishing.
* Targeted Cloudflare URL purge; never uses Purge Everything.
* Optional URL/result logger that can be enabled or disabled independently of purging.
* Retention options: 24 hours, 48 hours or 1 week.
* Automatic cleanup of old logs.
* Maximum log row safety limit, configurable from 50 to 5000.
* Manual homepage purge.
* Clear Recent Logs button.
* Logs are stored in a dedicated WordPress database table rather than the options table.


== Backward Compatibility ==
Version 1.3.1 supports the legacy NEWS_CF_ZONE_ID and NEWS_CF_API_TOKEN constants as well as the current CLOUDFLARE_SMART_PURGE_ZONE_ID and CLOUDFLARE_SMART_PURGE_API_TOKEN constants. Current constants take precedence when both are defined.
