---
title: Tracker - Raw data
description: Inspect individual hits and spot bots
btn: Raw data
group: addons
priority: 35
---

# Raw data

The **Raw data** tab shows the individual pageviews the statistics are computed from, including
the ones detected as bots. It's the tool to use when a number in the overview looks odd.

## Filters

Time range, page (contains ...), user agent (contains ...) and status (counted, all bots, or a
specific bot reason). The tiles at the top show the totals for the filter. Clicking a tile filters
by that status.

## Three views

- **By user agent**: hits per user agent with number of visitors, pages, hits without language or
  without Sec-Fetch headers, and status. Clicking the user agent shows its individual hits.
- **By page**: hits per page. Clicking a page shows its user agents.
- **Individual hits**: the 200 most recent hits with everything stored about them.

## Finding a bot

Typical bot signature: a lot of hits, almost as many "visitors" (rotating IP addresses), often
only one or a few pages, no language, no Sec-Fetch headers.

1. Open **By page** and click the suspicious page.
2. Check the user-agent list to see where the hits come from.
3. If the user agent contains a distinctive name (e.g. `FooScraper/2.1`), create a pattern via
   **Mark as bot**. A suggestion is prefilled and can be shortened. Existing raw data and the
   affected days are corrected right away.
4. If the user agent looks like a regular, current browser, a pattern won't help. Hits like that
   are caught by the header filter (see *Settings*). Outdated Chrome versions are caught
   automatically.

## Notes

- Times are shown in UTC.
- Raw data only exists for the configured retention period, detected bots only for the bot
  retention period.
- The IP address isn't shown here either, since it is never stored.
