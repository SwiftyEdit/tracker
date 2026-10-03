---
title: Tracker - GeoIP
description: Setting up country detection
btn: GeoIP
group: addons
priority: 50
---

# GeoIP (country detection)

The country breakdown needs a table that maps IP ranges to countries. It is deliberately **not**
shipped with the plugin (the release zip contains nothing under `data/`) and is imported once
per installation instead.

## Setting up

1. Get a suitable CSV file (see below).
2. Under **Settings**, in the **Import GeoIP data** card on the right, either import a file that
   already sits in `plugins/tracker/data/geoip/` with **Import**, or upload a new file
   (**Upload & import**). The import takes a few seconds.
3. Switch on **Resolve country from IP address** and save.

An import fully replaces any previous GeoIP data. The "… IP ranges loaded" line in the settings
form only refreshes when you reopen the tab.

## File format

One line per IP range, no header row: `start_ip,end_ip,country_code`, e.g.
`1.0.0.0,1.0.0.255,AU`. CIDR notation (`1.0.0.0/24,AU`) is accepted too. Invalid lines are
skipped, the number is shown in the success message.

## Free source

[sapics/ip-location-db](https://github.com/sapics/ip-location-db) provides several IPv4 country
datasets in exactly this format, no account needed. The files are at
`https://github.com/sapics/ip-location-db/releases/download/latest/` :

| File | License | Attribution |
|---|---|---|
| `user-country-ipv4.csv`, `iptoasn-country-ipv4.csv` | PDDL 1.0 (public domain) | not required |
| `dbip-country-ipv4.csv` | CC BY 4.0 | required, link to db-ip.com |
| `geolite2-country-ipv4.csv` | CC BY-SA 4.0 | required |

For `dbip` and `geolite2` the license requires attribution on your own site, e.g. in the
imprint.

## Notes

- Only IPv4 is supported. IPv6 visits don't get a country.
- Accuracy is country-level and meant for overview purposes.
- The data is not updated automatically. To get newer data, download the file again and import it.
