---
title: Tracker - Overview
description: Overview of the Tracker plugin
btn: Overview
group: addons
priority: 10
---

# Tracker

Tracker is a self-hosted, privacy-friendly statistics plugin for your own site: pageviews,
referrers, browsers, operating system, device type, country and campaign source (Google Ads,
Meta Ads, UTM parameters, ...).

## How it works

- Capture runs **entirely server-side**, with no JavaScript on the frontend. There is no tracking
  script, no extra request and no perceptible load time.
- There are **no cookies**, so this statistics plugin alone does not require a cookie banner.
- The actual interpretation work (browser/OS detection, campaign classification, purging old raw
  data) runs **lazily in the background**, never during a real visitor's pageview.
- The statistics page only reads precomputed daily totals, so it stays fast no matter how many
  pageviews have been recorded.

## Getting started

1. Activate the plugin under **Addons** and open the **Overview** tab once. This creates the
   database. Pageviews that happened before that are not recorded.
2. Check the defaults under **Settings** (retention, bot filter, own domain).
3. Optional: import GeoIP data to enable the country breakdown (see the **GeoIP** page).
4. Look at the **Overview** tab after the first visits. The statistics refresh when you open it,
   at most every five minutes.

## Help pages

- **Reports**: how to read the overview page (time range, chart, cards)
- **Sources & campaigns**: where visitors come from and how to make newsletters or social posts traceable
- **Raw data**: inspect individual hits and spot bots
- **Settings**: retention, bot detection, own domain
- **GeoIP**: setting up country detection
- **Privacy & technology**: what is stored, how capture works, known limitations
