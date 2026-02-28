# 🐶 Hostinger Cache Watchdog

> Automatically monitors your WooCommerce shop page every minute, clears all caches when products go missing, and sends Slack alerts at every step — including a live screenshot when products come back online.

![Version](https://img.shields.io/badge/version-2.0.0-blue?style=flat-square)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b?style=flat-square&logo=wordpress)
![WooCommerce](https://img.shields.io/badge/WooCommerce-3.0%2B-96588a?style=flat-square&logo=woocommerce)
![License](https://img.shields.io/badge/license-GPL--2.0-green?style=flat-square)

---

## The Problem

WooCommerce shops on cached hosting (Hostinger, SiteGround, etc.) can silently serve a broken, product-less shop page to customers — while everything looks fine in your WordPress dashboard. You only find out when a customer complains or you happen to visit the site.

**Hostinger Cache Watchdog fixes this.** It watches your shop page every minute, catches the issue the moment it happens, clears all caches automatically, and keeps you informed via Slack at every step.

---

## How It Works

Every minute, the plugin fetches your live shop page and checks whether your theme's product list actually rendered. If products go missing, it immediately:

1. 🚨 **Alerts you on Slack** — red message with timestamp
2. 🧹 **Clears every cache layer** — LiteSpeed, WP Rocket, W3TC, WP Super Cache, Autoptimize, SG Optimizer, WP Object Cache, and WooCommerce transients
3. 🟠 **Sends a second Slack alert** — confirming which caches were cleared
4. ✅ **Sends a recovery alert** — green message with a live screenshot of your restored shop, once products are detected again

---

## Features

- **Minute-by-minute monitoring** via WordPress cron (or a real server cron job)
- **Smart detection** using your theme's actual HTML attributes — no generic WooCommerce class guessing
- **Automatic cache clearing** across 7+ popular cache plugins simultaneously
- **Three-stage Slack notifications** — alert → cleared → recovered (with screenshot)
- **Live shop screenshot** in the recovery notification via [thum.io](https://www.thum.io/)
- **Admin dashboard** with live status, one-click manual check, manual cache clear, and test Slack message
- **Activity log** with colour-coded entries (500-line rolling log)
- **No false positives** — network errors and non-200 responses are safely ignored

---

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- A Slack workspace with an Incoming Webhook URL

---

## Installation

### Option 1 — Manual Upload

1. Download the latest release as a `.zip`
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Install Now**, then **Activate**

### Option 2 — FTP / File Manager

1. Upload `hostinger-cache-watchdog.php` to `/wp-content/plugins/hostinger-cache-watchdog/`
2. Activate from **Plugins → Installed Plugins**

---

## Configuration

### 1. Add Your Slack Webhook

Go to **Settings → 🐶 Cache Watchdog** and paste your Slack Incoming Webhook URL.

Don't have one yet? [Create a Slack webhook →](https://api.slack.com/messaging/webhooks)

### 2. Set Up a Real Cron Job (Recommended)

WordPress cron only fires when someone visits your site. For reliable every-minute checks, add a real server cron job.

In **Hostinger cPanel → Advanced → Cron Jobs**, add:

```
* * * * * php /home/your-username/public_html/wp-cron.php
```

Set the frequency to **Every Minute** or enter `* * * * *` manually.

### 3. Send a Test Notification

Click **💬 Send Test Slack Message** in the admin panel to confirm your webhook is working. The test message includes a live screenshot of your shop.

---

## Admin Panel

Navigate to **Settings → 🐶 Cache Watchdog** to access:

| Section | Description |
|---|---|
| 📊 Live Status | Current shop health (Products Online / Products Missing) and next scheduled check time |
| ⚡ Actions | Run a manual check, clear all caches now, or send a test Slack message |
| ⚙️ Settings | Slack Webhook URL configuration |
| 📋 Activity Log | Rolling 500-line log with colour-coded entries, newest first |
| 📖 Setup Guide | Cron job instructions and notification flow explanation |

---

## Slack Notifications

The plugin sends up to three Slack messages per incident:

| Colour | Trigger | Content |
|---|---|---|
| 🔴 Red | Products not found | Site URL, timestamp, "clearing cache now" |
| 🟠 Orange | Cache cleared | List of every cache layer that was purged |
| 🟢 Green | Products restored | Down since / recovered timestamps + live screenshot |

---

## Supported Cache Plugins

Hostinger Cache Watchdog will automatically detect and clear whichever of these are active:

- LiteSpeed Cache
- WP Rocket
- W3 Total Cache
- WP Super Cache
- Autoptimize
- SG Optimizer (SiteGround)
- WordPress Object Cache (`wp_cache_flush`)
- WooCommerce Transients (direct DB purge)

---

## Product Detection

The plugin detects products by checking your theme's rendered HTML for:

```
data-lazy-container="products"
data-product-item="true"
```

Both must be present for the shop to be considered healthy. This matches the actual markup output by the theme and avoids false positives from generic page elements like `id="content"` which are present on every page regardless of product status.

> **Note:** If you switch themes, verify these attributes still appear in your shop page's HTML source and update the `scw_fetch_and_detect()` function if needed.

---

## File Structure

```
hostinger-cache-watchdog/
└── hostinger-cache-watchdog.php   # Single-file plugin
```

The plugin also creates one file outside the plugin directory:

```
wp-content/
└── hostinger-cache-watchdog.log   # Rolling activity log (max 500 lines)
```

---

## Frequently Asked Questions

**Why is the check not running every minute?**
WordPress cron is triggered by page visits, not a real clock. If your site has low traffic, checks may be infrequent. Set up a real server cron job as described in the [Configuration](#configuration) section.

**Will this slow down my site?**
No. The cron job runs in the background and is not triggered by customer page loads. Cache clearing only happens when products are actually missing.

**What if my site goes down entirely (500 error, etc.)?**
The plugin treats non-200 HTTP responses as "skip this check" rather than triggering a false alarm. Only a 200 response with missing product HTML triggers alerts.

**Can I customise which channel alerts go to?**
Yes — the channel is determined by your Slack webhook configuration, not the plugin. Create separate webhooks for different channels if needed.

**The recovery alert fired but the screenshot looks blank. Why?**
Screenshots are generated by [thum.io](https://www.thum.io/), a free third-party service. Occasionally it may be slow or rate-limited. The shop data itself is confirmed healthy by the plugin's own HTML check regardless of the screenshot.

---

## Changelog

### 2.0.0
- Rewrote product detection to use theme-specific HTML attributes instead of generic WooCommerce classes
- Removed unreliable `id="content"` check that caused false positives
- Added three-stage Slack notification flow (alert → cleared → recovered)
- Added live screenshot in recovery notification via thum.io
- Added admin dashboard with live status, manual actions, and activity log
- Added support for SG Optimizer cache clearing
- Rolling log trimming (keeps last 500 entries)

---

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you'd like to change.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/your-feature`)
3. Commit your changes (`git commit -m 'Add your feature'`)
4. Push to the branch (`git push origin feature/your-feature`)
5. Open a Pull Request

---

## Author

**Nazrul Islam** — [nazrulislam.dev](https://nazrulislam.dev)

---

## License

This plugin is licensed under the [GPL-2.0 License](https://www.gnu.org/licenses/gpl-2.0.html).
