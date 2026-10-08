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

## Bot detection

The **Bot detection** card decides which hits don't count as visitors. Detected bots aren't
discarded but stored with a flag: they never show up in any statistic, but can be inspected in
the **Raw data** tab.

- **Filter out bots and crawlers**: main switch. When off, every hit counts.
- **Treat requests without typical browser headers as bots** (on by default): real browsers
  always send a language (`Accept-Language`) and, over HTTPS, the `Sec-Fetch-*` headers. Many
  scrapers pose as a regular browser user agent but omit these headers. They often rotate IP
  addresses too, so every hit looks like a new visitor (almost as many visitors as pageviews). A
  user-agent pattern can't catch such bots, this filter can. The Sec-Fetch check only applies to
  HTTPS requests.
- **Treat outdated Chrome versions as bots** (on by default): Chrome, Edge and Opera update
  themselves automatically. A user agent with a Chrome version about three years old (e.g.
  `Chrome/101` in 2026) practically always comes from a scraper with a hardcoded user agent. The
  threshold is derived from Chrome's release cadence and moves along automatically; the card shows
  the current one. Computers without updates (Windows 7/8, very old macOS versions) are affected
  too - a negligible share today.
- **Custom patterns**: texts contained in the user agent (no regex, case-insensitive). Each
  pattern shows how many hits in the current raw data it caught. The easiest way to add one is
  "Mark as bot" in the **Raw data** tab.
- **Built-in patterns**: a bundled list (search engines, SEO tools, AI crawlers, link previews,
  HTTP libraries). It is extended with plugin updates.
- A request without a user agent always counts as a bot.
- Patterns that also occur in regular browser user agents (e.g. `chrome`) are rejected so real
  visitors can't be filtered out by accident.
- **Keep bot hits (days)**: how long detected bots stay in the raw data (default 14). 0 = as long
  as the other raw data.

### Correcting the past

Every change to patterns or switches is applied to the existing raw data right away and the
affected days are recalculated - as far back as raw data exists (see retention). Older days keep
their numbers. **Re-evaluate raw data** triggers this manually for all raw data at any time.

Sec-Fetch headers are only recorded from version 1.1.0 on. Older hits can only be re-evaluated by
language and user agent.

## Resolve country from IP address (GeoIP)

Turns on country detection. It needs imported GeoIP data (see the *GeoIP* page). Without data the
switch has no effect. Country detection only applies to visits from the moment it is switched on.

## Own domain

Used to keep internal clicks (from one page of your site to another) out of the referrer
breakdown. The current domain is entered when first set up. Adjust it here after a domain change.

## What is never counted

Regardless of the settings, logged-in administrators are never recorded, so your own visits while
editing don't distort the numbers.
