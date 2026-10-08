---
title: Tracker - Datenschutz & Technik
description: Was gespeichert wird und wie die Erfassung funktioniert
btn: Datenschutz & Technik
group: addons
priority: 60
---

# Datenschutz & Technik

## Was gespeichert wird

Pro Seitenaufruf: Zeitpunkt, aufgerufene Adresse inkl. Parametern, Referrer, User-Agent,
Browser-Sprache, ob Sec-Fetch-Header gesendet wurden (ja/nein), ein Länderkürzel (nur bei aktiviertem
GeoIP), ein Besucher-Hash und gegebenenfalls der Grund, warum der Aufruf als Bot gilt.

**Nicht gespeichert wird die IP-Adresse**, auch nicht kurzzeitig in der Datenbank. Sie wird nur
im Moment des Aufrufs für den Hash und die Länder-Erkennung verwendet.

## Besucher ohne Cookie

Der Besucher-Hash entsteht aus IP-Adresse, User-Agent, dem aktuellen Datum und einem
installationseigenen Zufallswert. Am nächsten Tag ergibt sich für denselben Besucher ein
anderer Wert, eine tagesübergreifende Wiedererkennung ist nicht möglich. Es werden weder Cookies
noch andere Merkmale im Browser gespeichert.

Ob für deine Website daraus rechtlich ein Hinweis in der Datenschutzerklärung nötig ist, hängt vom
Einzelfall ab. Das ersetzt keine Rechtsberatung.

## So läuft die Erfassung ab

1. Beim Ausliefern einer Seite feuert ein interner Hook (`page.display.after`) **nachdem** die
   Seite bereits an den Besucher gesendet wurde.
2. Dort wird (bei PHP-FPM mit `fastcgi_finish_request()`) die Verbindung zum Besucher beendet. Der
   Server schreibt den Aufruf erst danach in die Datenbank. Ohne FPM geschieht das Schreiben
   unmittelbar, ist aber nur ein einzelner Datenbankeintrag.
3. Aufrufe von Administratoren werden verworfen. Als Bot erkannte Aufrufe werden markiert gespeichert
   (nur zur Kontrolle unter *Rohdaten*, nach der Bot-Aufbewahrungsdauer gelöscht) und nie mitgezählt.
4. Beim Öffnen der Übersicht (höchstens alle fünf Minuten) werden neue Aufrufe zu Tagessummen
   verarbeitet: Browser, Betriebssystem, Gerätetyp, Quelle und Referrer werden bestimmt. Betroffene
   Tage werden dabei komplett neu berechnet, so bleiben die Besucherzahlen korrekt.
5. Zuletzt werden Rohdaten gelöscht, die älter sind als die eingestellte Aufbewahrungsdauer.

Gespeichert wird in `plugins/tracker/data/tracker.sqlite3`. Das Diagramm nutzt die mitgelieferte
Bibliothek Chart.js (MIT-Lizenz), es werden keine externen Dienste geladen.

## Einschränkungen (Stand v1)

- Browser-, Betriebssystem- und Gerätetyp-Erkennung ist bewusst einfach gehalten (Mustererkennung,
  keine vollständige Geräte-Datenbank). Das genügt für einen Überblick, erreicht aber nicht die
  Genauigkeit eines spezialisierten Analyse-Tools.
- Erfasst werden normale Seitenaufrufe der Website, keine Klicks, Scrolltiefe oder Verweildauer.
- Wegen der tagesweise wechselnden Besucher-Kennung gibt es keine echte Sitzungs- oder
  Wiederkehrer-Auswertung.
- GeoIP unterstützt nur IPv4.
