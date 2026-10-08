---
title: Tracker - Rohdaten
description: Einzelne Aufrufe prüfen und Bots erkennen
btn: Rohdaten
group: addons
priority: 35
---

# Rohdaten

Der Tab **Rohdaten** zeigt die einzelnen Seitenaufrufe, aus denen die Statistik berechnet wird,
inklusive der als Bot erkannten. Er ist das Werkzeug, wenn eine Zahl in der Übersicht seltsam
aussieht.

## Filter

Zeitraum, Seite (enthält ...), User-Agent (enthält ...) und Status (gezählt, Bots insgesamt oder
ein bestimmter Bot-Grund). Die Kacheln oben zeigen die Summen für den Filter. Ein Klick auf eine
Kachel filtert nach diesem Status.

## Drei Ansichten

- **Nach User-Agent**: Aufrufe pro User-Agent mit Anzahl Besucher, Seiten, Aufrufe ohne Sprache
  bzw. ohne Sec-Fetch-Header und Status. Ein Klick auf den User-Agent zeigt dessen einzelne Aufrufe.
- **Nach Seite**: Aufrufe pro Seite. Ein Klick zeigt die User-Agents dieser Seite.
- **Einzelne Aufrufe**: die 200 neuesten Aufrufe mit allen gespeicherten Merkmalen.

## Einen Bot finden

Typisches Muster eines Bots: sehr viele Aufrufe, fast genauso viele „Besucher“ (wechselnde
IP-Adressen), oft nur eine oder wenige Seiten, keine Sprache, keine Sec-Fetch-Header.

1. **Nach Seite** öffnen und die auffällige Seite anklicken.
2. In der User-Agent-Liste nachsehen, woher die Aufrufe kommen.
3. Enthält der User-Agent einen eindeutigen Namen (z. B. `FooScraper/2.1`), über **Als Bot
   markieren** ein Muster anlegen. Ein Vorschlag ist bereits eingetragen und kann gekürzt werden.
   Vorhandene Rohdaten und die betroffenen Tage werden sofort korrigiert.
4. Sieht der User-Agent aus wie ein normaler Browser, hilft kein Muster. Solche Aufrufe erkennt
   der Header-Filter (siehe *Einstellungen*).

## Hinweise

- Zeiten sind in UTC angegeben.
- Rohdaten gibt es nur für den eingestellten Aufbewahrungszeitraum, erkannte Bots nur für die
  Bot-Aufbewahrungsdauer.
- Die IP-Adresse wird auch hier nicht angezeigt, weil sie nie gespeichert wird.
