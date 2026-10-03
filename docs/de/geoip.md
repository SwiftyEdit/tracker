---
title: Tracker - GeoIP
description: Länder-Erkennung einrichten
btn: GeoIP
group: addons
priority: 50
---

# GeoIP (Länder-Erkennung)

Die Länder-Auswertung braucht eine Tabelle, die IP-Bereichen ein Land zuordnet. Diese wird
bewusst **nicht** mit dem Plugin ausgeliefert (das Release-Zip enthält keine `data/`-Inhalte),
sondern einmalig pro Installation importiert.

## Einrichten

1. Eine passende CSV-Datei besorgen (siehe unten).
2. Unter **Einstellungen** rechts im Bereich **GeoIP-Daten importieren** entweder eine bereits im
   Ordner `plugins/tracker/data/geoip/` liegende Datei mit **Importieren** einlesen oder eine neue
   Datei hochladen (**Hochladen & importieren**). Der Import dauert einige Sekunden.
3. Den Schalter **Länder anhand der IP-Adresse ermitteln** aktivieren und speichern.

Ein Import ersetzt die vorhandenen GeoIP-Daten vollständig. Die Anzeige „… IP-Bereiche geladen"
im Einstellungsformular aktualisiert sich erst beim erneuten Öffnen des Tabs.

## Dateiformat

Eine Zeile je IP-Bereich, ohne Kopfzeile: `Start-IP,End-IP,Ländercode`, z. B.
`1.0.0.0,1.0.0.255,AU`. Auch CIDR-Schreibweise (`1.0.0.0/24,AU`) wird akzeptiert. Ungültige
Zeilen werden übersprungen, die Anzahl steht in der Erfolgsmeldung.

## Kostenlose Quelle

[sapics/ip-location-db](https://github.com/sapics/ip-location-db) stellt mehrere
IPv4-Länder-Datensätze genau in diesem Format bereit, ohne Account. Die Dateien liegen unter
`https://github.com/sapics/ip-location-db/releases/download/latest/` :

| Datei | Lizenz | Quellenangabe |
|---|---|---|
| `user-country-ipv4.csv`, `iptoasn-country-ipv4.csv` | PDDL 1.0 (gemeinfrei) | nicht nötig |
| `dbip-country-ipv4.csv` | CC BY 4.0 | nötig, Link auf db-ip.com |
| `geolite2-country-ipv4.csv` | CC BY-SA 4.0 | nötig |

Bei `dbip` und `geolite2` ist laut Lizenz eine Quellenangabe auf der eigenen Website
erforderlich, z. B. im Impressum.

## Hinweise

- Unterstützt wird nur IPv4. Aufrufe über IPv6 bekommen kein Land.
- Die Genauigkeit ist länderbezogen und für Überblickszwecke gedacht.
- Die Daten werden nicht automatisch aktualisiert. Für neuere Daten die Datei erneut laden und
  importieren.
