---
title: Tracker - Settings
description: The settings at a glance
btn: Settings
group: addons
priority: 40
---

# Settings

## Keep raw hits for (days)

Every pageview is first stored individually and later folded into daily totals. This setting
controls how long the individual pageviews are kept after that (default 90 days). They are then
deleted automatically.

- **0** means: keep forever.
- The daily totals, and with them all statistics, are unaffected and always kept.
- A shorter period keeps the database small and is more data-minimal.

## Filter out bots and crawlers

Visits from search-engine crawlers, monitoring services and scripts are not counted. They are
recognised by their user agent, using a list of patterns (regular expressions separated by `|`).
The list is prefilled and can be extended.

- A request without a user agent always counts as a bot.
- Matching is case-insensitive.
- An invalid pattern is rejected on save, so capture can't silently break.
- If the filter is switched off, bots are counted.

## Resolve country from IP address (GeoIP)

Turns on country detection. It needs imported GeoIP data (see the *GeoIP* page). Without data the
switch has no effect. Country detection only applies to visits from the moment it is switched on.

## Own domain

Used to keep internal clicks (from one page of your site to another) out of the referrer
breakdown. The current domain is entered when first set up. Adjust it here after a domain change.

## What is never counted

Regardless of the settings, logged-in administrators are never recorded, so your own visits while
editing don't distort the numbers.
