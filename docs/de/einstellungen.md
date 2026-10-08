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

## Bot-Erkennung

Die Karte **Bot-Erkennung** legt fest, welche Aufrufe nicht als Besucher zählen. Erkannte Bots
werden nicht verworfen, sondern markiert gespeichert: Sie tauchen in keiner Statistik auf, lassen
sich aber im Tab **Rohdaten** nachprüfen.

- **Bots und Crawler herausfiltern**: Hauptschalter. Ausgeschaltet zählt jeder Aufruf.
- **Aufrufe ohne typische Browser-Header als Bot werten** (Voreinstellung an): Echte Browser
  senden immer eine Sprache (`Accept-Language`) und über HTTPS die `Sec-Fetch-*`-Header. Viele
  Scraper geben sich mit einem normalen Browser-User-Agent aus, lassen diese Header aber weg. Sie
  wechseln oft auch ständig die IP-Adresse, deshalb sieht jeder Aufruf aus wie ein neuer Besucher
  (fast genauso viele Besucher wie Seitenaufrufe). Ein User-Agent-Muster kann solche Bots nicht
  erkennen, dieser Filter schon. Die Sec-Fetch-Prüfung greift nur bei HTTPS-Aufrufen.
- **Eigene Muster**: Texte, die im User-Agent vorkommen (kein Regex, Groß-/Kleinschreibung egal).
  Jedes Muster zeigt, wie viele Aufrufe in den aktuellen Rohdaten es erkannt hat. Am einfachsten
  fügt man sie im Tab **Rohdaten** über „Als Bot markieren“ hinzu.
- **Eingebaute Muster**: eine mitgelieferte Liste (Suchmaschinen, SEO-Tools, KI-Crawler,
  Link-Vorschauen, HTTP-Bibliotheken). Sie wird mit Plugin-Updates erweitert.
- Ein Aufruf ohne User-Agent zählt immer als Bot.
- Muster, die auch in normalen Browser-User-Agents vorkommen (z. B. `chrome`), werden abgelehnt,
  damit nicht versehentlich echte Besucher herausfallen.
- **Bot-Aufrufe aufbewahren (Tage)**: Wie lange erkannte Bots in den Rohdaten bleiben
  (Voreinstellung 14). 0 = so lange wie die übrigen Rohdaten.

### Rückwirkend korrigieren

Jede Änderung an Mustern oder Schaltern wird sofort auf die vorhandenen Rohdaten angewendet. Die
betroffenen Tage werden neu berechnet. Das gilt so weit zurück, wie Rohdaten vorhanden sind (siehe
Aufbewahrung). Ältere Tage behalten ihre Zahlen. Mit **Rohdaten neu bewerten** lässt sich das
jederzeit manuell für alle Rohdaten auslösen.

Die Sec-Fetch-Header werden erst ab Version 1.1.0 erfasst. Ältere Aufrufe lassen sich
rückwirkend nur über Sprache und User-Agent bewerten.

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
