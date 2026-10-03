---
title: Tracker - Sources & campaigns
description: Where visitors come from and how campaigns are recorded
btn: Sources & campaigns
group: addons
priority: 30
---

# Sources & campaigns

A referrer alone doesn't reveal whether a visit came from a paid Google ad or an organic search
result - both often carry the same referrer (`google.com`). Tracker therefore also reads the
query string of the landing page.

## Order of detection

1. `gclid` / `gbraid` / `wbraid` in the URL: **Google Ads**
2. `msclkid`: **Microsoft Ads**
3. `fbclid`: **Meta Ads**
4. `utm_source` present: **Campaign** (see below)
5. otherwise by referrer: known search engines (**Suche: Google**, Bing, DuckDuckGo, ...), known
   social networks (**Social: LinkedIn**, ...), other referrals (**Referral: domain.com**)
6. no referrer: **Direkt** (direct)

The click IDs `gclid`, `fbclid` and `msclkid` are added to the URL by the ad platforms
themselves when auto-tagging is on. You don't have to do anything for those.

## Your own campaigns with UTM parameters

For everything else (newsletters, social posts, partner links, QR codes) the links have to
carry the UTM parameters themselves:

```
https://your-site.com/blog/article/?utm_source=newsletter&utm_medium=email&utm_campaign=autumn-2026
```

| Parameter | Meaning | Example |
|---|---|---|
| `utm_source` | Where the link comes from (required, otherwise detection doesn't apply) | `newsletter` |
| `utm_medium` | Through which channel | `email` |
| `utm_campaign` | Which campaign (optional) | `autumn-2026` |

The result appears in the **Sources (campaigns)** breakdown:

- Example above: **Kampagne: newsletter (autumn-2026)**
- Without `utm_campaign`: **Kampagne: newsletter**
- With `utm_medium` = `cpc`, `ppc`, `paid`, `paidsearch` or `display`: **Bezahlt: source**

Note: values are used exactly as written in the URL, including capitalisation. `Newsletter` and
`newsletter` show up as two separate entries.

## Only the first pageview counts

So that a multi-page visit doesn't count the source several times, only a visitor's
chronologically first pageview of the day is evaluated for the source. The UTM parameters are
only on the page the visitor lands on anyway.
