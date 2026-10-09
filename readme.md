# Tracker

Self-hosted, privacy-friendly statistics for your SwiftyEdit site: pageviews, referrers,
browsers, operating system, device type, country and campaign source (Google Ads, Meta Ads,
UTM parameters) - captured server-side with zero frontend footprint.

See the plugin's own **Hilfe/Help** tab (Addons > Tracker) for the full walkthrough.

## Features

- **Zero frontend JavaScript.** Capture runs through a server-side hook that fires only after
  the page has already been sent to the visitor (`fastcgi_finish_request()`), so it never adds
  perceptible load time - no beacon script, no extra HTTP request.
- **Cookie-less.** Visitors are recognised via a daily-rotating, per-site-salted hash of IP +
  user agent - no persistent cookie, no consent banner required. The raw IP address is never
  written to disk, not even transiently in a log.
- **Backend does the heavy lifting.** Raw hits are stored as-is; user-agent parsing, referrer/
  campaign classification and retention cleanup all run lazily, throttled, only when the admin
  opens the statistics page - never during a real visitor's request.
- **Campaign/paid-traffic detection.** Distinguishes Google Ads, Microsoft/Bing Ads, Meta Ads
  and manually UTM-tagged campaigns from organic search/social/referral/direct traffic - a plain
  referrer can't tell these apart on its own, see the Help tab for why.
- **Bot detection you can check**: built-in user-agent list plus custom patterns, and a header
  filter (missing `Accept-Language` / `Sec-Fetch-*`) plus an outdated-Chrome rule (~3 years behind,
  threshold moves with Chrome's release cadence) that catch scrapers posing as browsers.
  Bots are stored flagged, never counted, and visible in the "Raw data" tab - where a user agent
  can be marked as a bot directly. Rule changes re-evaluate existing raw data.
- **Configurable**: raw-hit retention window, bot retention, optional GeoIP country lookup via an
  importable IP-range CSV.
- Built-in "Help" tab in the plugin's own backend UI, same pattern as `plugins/paperboy` /
  `plugins/former`.

## GeoIP data

The country breakdown needs an IP-range → country CSV imported under Addons > Tracker >
Einstellungen (upload, or pick a file already sitting in `data/geoip/`). This isn't bundled in
the plugin's release zip (the build script strips `data/`, see
`scripts/build_plugin_release.sh`) - it has to be downloaded and imported once per installation.

Confirmed free, no-account source (checked 2026-09-04):
[sapics/ip-location-db](https://github.com/sapics/ip-location-db) mirrors several IPv4
country-level datasets as plain `start_ip,end_ip,country_code` CSVs (no header), matching what
`tr_import_geoip_csv()` expects directly:

- `user-country-ipv4.csv` / `iptoasn-country-ipv4.csv` — PDDL 1.0, no attribution required
- `dbip-country-ipv4.csv` — DB-IP Lite, CC BY 4.0 (**requires attribution**: link back to
  [db-ip.com](https://db-ip.com))
- `geolite2-country-ipv4.csv` — CC BY-SA 4.0 (**requires attribution**)

All downloadable directly from `https://github.com/sapics/ip-location-db/releases/download/latest/<file>`,
no signup. If you import a DB-IP or GeoLite2 file, keep the required attribution somewhere
visible on your site (e.g. the imprint/privacy page) per that dataset's license.

## Database file

Data is split over two SQLite files in `data/`, both in WAL mode (set automatically on every
connection):

- `tracker.sqlite3`: settings, daily aggregates, GeoIP ranges. Small, written only during
  aggregation. These aggregates can't be rebuilt once the raw hits behind them are purged, so a
  snapshot is written once a day to `tracker-backup.sqlite3`.
- `tracker-raw.sqlite3`: raw hits only, written on every pageview. Losing it costs at most the hits
  since the last aggregation. If it goes missing, a new one is created automatically.

Installs from 1.1.1 or older keep everything in `tracker.sqlite3`. The first backend visit after
the update moves the raw hits into `tracker-raw.sqlite3`. This happens once and can take a few
seconds on a large table.

`data/` must be writable, because SQLite keeps `-wal` and `-shm` files next to each database. The
databases must not be on a network filesystem (NFS).

**Never copy or overwrite the database files on their own while the site is live.** Recent writes may
still be in the `-wal` file only, so a plain file copy (FTP, `cp`) can be incomplete or
corrupt. Overwriting the live file while a `-wal` file sits next to it corrupts the database.
Make copies with SQLite itself instead:

```
sqlite3 tracker.sqlite3 ".backup copy.sqlite3"
```

The Parachute backup plugin already does this (`VACUUM INTO`).

## Not included yet (v1)

- No custom date-range picker on the overview page yet (fixed last-30-days window).
- Browser/OS/device detection is pattern-based, not a full device-detection library.

## License

GPL-3.0 — see [license.txt](license.txt). GeoIP data imported via the CSV import feature is
licensed separately by its own source (see above) - not covered by Tracker's own GPL-3.0.
