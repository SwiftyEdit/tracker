---
title: Tracker - Privacy & technology
description: What is stored and how capture works
btn: Privacy & technology
group: addons
priority: 60
---

# Privacy & technology

## What is stored

Per pageview: timestamp, requested address including parameters, referrer, user agent, browser
language, whether Sec-Fetch headers were sent (yes/no), a country code (only with GeoIP on), a
visitor hash and, if applicable, the reason the hit counts as a bot.

**The IP address is not stored**, not even briefly in the database. It is only used at the
moment of the request for the hash and the country lookup.

## Visitors without a cookie

The visitor hash is built from IP address, user agent, the current date and a per-installation
random value. The same visitor gets a different value the next day, so recognising someone
across days is not possible. No cookies or other identifiers are placed in the browser.

Whether this requires a note in your privacy policy depends on your case. This is not legal
advice.

## How capture works

1. When a page is delivered, an internal hook (`page.display.after`) fires **after** the page has
   already been sent to the visitor.
2. There (on PHP-FPM via `fastcgi_finish_request()`) the connection to the visitor is closed. The
   server only then writes the hit to the database. Without FPM the write happens immediately, but
   it is a single database insert.
3. Hits from administrators are discarded. Hits recognised as bots are stored with a flag (only for
   inspection under *Raw data*, deleted after the bot retention period) and never counted.
4. When the overview is opened (at most every five minutes), new hits are folded into daily totals:
   browser, operating system, device type, source and referrer are determined. Affected days are
   recomputed completely, which keeps visitor counts correct.
5. Finally, raw data older than the configured retention is deleted.

Data is stored in `plugins/tracker/data/tracker.sqlite3`. The chart uses the bundled Chart.js
library (MIT license), no external services are loaded.

## Known limitations (v1)

- Browser, OS and device detection is deliberately simple (pattern-based, not a full device
  database). It is enough for an overview but doesn't reach the precision of a dedicated
  analytics tool.
- Normal page views are recorded, not clicks, scroll depth or time on page.
- Because the visitor identifier changes daily, there is no real session or returning-visitor
  analysis.
- GeoIP supports IPv4 only.
