---
title: Tracker - Übersicht
description: Übersicht über das Tracker Plugin
btn: Übersicht
group: addons
priority: 10
---

# Tracker

Tracker ist eine selbst gehostete, datenschutzfreundliche Statistik für die eigene Website:
Seitenaufrufe, Referrer, Browser, Betriebssystem, Gerätetyp, Land und Kampagnen-Quelle
(Google Ads, Meta Ads, UTM-Parameter, ...).

## Grundprinzip

- Die Erfassung läuft **komplett serverseitig**, ohne jedes JavaScript im Frontend. Es gibt kein
  Tracking-Script, keinen zusätzlichen Request und keine wahrnehmbare Ladezeit.
- Es gibt **keine Cookies** und damit keinen Bedarf für ein Cookie-Banner nur wegen dieser Statistik.
- Die eigentliche Auswertung (Browser-/Betriebssystem-Erkennung, Kampagnen-Zuordnung, Bereinigung
  alter Rohdaten) läuft **zeitversetzt im Hintergrund**, nie während eines echten Seitenaufrufs.
- Die Statistik-Seite liest nur vorberechnete Tagessummen und bleibt dadurch schnell, egal wie
  viele Seitenaufrufe erfasst wurden.

## Erste Schritte

1. Plugin unter **Addons** aktivieren und einmal den Tab **Übersicht** öffnen. Dabei wird die
   Datenbank angelegt. Seitenaufrufe, die vorher stattfanden, werden nicht erfasst.
2. Unter **Einstellungen** prüfen, ob die Voreinstellungen passen (Aufbewahrung, Bot-Filter,
   eigene Domain).
3. Optional: GeoIP-Daten importieren, um die Länder-Auswertung zu aktivieren (Seite **GeoIP**).
4. Nach den ersten Besuchen im Tab **Übersicht** nachsehen. Die Auswertung aktualisiert sich beim
   Öffnen, höchstens alle fünf Minuten.

## Die Hilfe-Seiten

- **Auswertung**: wie die Übersichtsseite zu lesen ist (Zeitraum, Diagramm, Karten)
- **Quellen & Kampagnen**: woher Besucher kommen und wie man Newsletter oder Social-Posts erfassbar macht
- **Einstellungen**: Aufbewahrung, Bot-Filter, eigene Domain
- **GeoIP**: Länder-Erkennung einrichten
- **Datenschutz & Technik**: was gespeichert wird, wie die Erfassung funktioniert, Einschränkungen
