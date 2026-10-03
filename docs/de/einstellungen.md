---
title: Tracker - Einstellungen
description: Die Einstellungen im Überblick
btn: Einstellungen
group: addons
priority: 40
---

# Einstellungen

## Rohdaten aufbewahren (Tage)

Jeder Seitenaufruf wird zunächst einzeln gespeichert und später zu Tagessummen
zusammengefasst. Diese Einstellung legt fest, wie lange die einzelnen Seitenaufrufe nach dem
Zusammenfassen erhalten bleiben (Voreinstellung 90 Tage). Danach werden sie automatisch
gelöscht.

- **0** bedeutet: unbegrenzt aufbewahren.
- Die Tagessummen und damit alle Statistiken bleiben davon unberührt und bleiben immer erhalten.
- Ein kürzerer Zeitraum hält die Datenbank klein und ist datensparsamer.

## Bots und Crawler herausfiltern

Aufrufe von Suchmaschinen-Crawlern, Monitoring-Diensten und Skripten werden nicht gezählt.
Erkannt werden sie am User-Agent, anhand einer Liste von Mustern (reguläre Ausdrücke, durch `|`
getrennt). Die Liste ist vorbelegt und kann ergänzt werden.

- Ein Aufruf ohne User-Agent zählt immer als Bot.
- Groß-/Kleinschreibung spielt keine Rolle.
- Ein ungültiges Muster wird beim Speichern abgelehnt, damit die Erfassung nicht versehentlich
  ausfällt.
- Wird der Filter ausgeschaltet, werden Bots mitgezählt.

## Länder per IP-Adresse ermitteln (GeoIP)

Schaltet die Länder-Erkennung ein. Sie braucht importierte GeoIP-Daten (siehe Seite *GeoIP*).
Ohne Daten hat der Schalter keine Wirkung. Die Länder-Erkennung gilt nur für Aufrufe ab dem
Einschalten.

## Eigene Domain

Wird verwendet, um interne Klicks (von einer Seite deiner Website zur nächsten) aus der
Referrer-Auswertung herauszuhalten. Beim ersten Anlegen wird die aktuelle Domain eingetragen.
Bei einem Domain-Wechsel hier anpassen.

## Was nicht gezählt wird

Unabhängig von den Einstellungen werden angemeldete Administratoren nie erfasst, damit eigene
Besuche beim Bearbeiten die Zahlen nicht verfälschen.
