---
title: Tracker - Quellen & Kampagnen
description: Woher Besucher kommen und wie Kampagnen erfasst werden
btn: Quellen & Kampagnen
group: addons
priority: 30
---

# Quellen & Kampagnen

Ein Referrer allein verrät nicht, ob ein Besuch aus einer bezahlten Google-Anzeige oder einem
organischen Suchergebnis stammt - beide zeigen oft denselben Referrer (`google.com`). Tracker
wertet deshalb zusätzlich die Ziel-URL des ersten Seitenaufrufs aus.

## Reihenfolge der Erkennung

1. `gclid` / `gbraid` / `wbraid` in der URL: **Google Ads**
2. `msclkid`: **Microsoft Ads**
3. `fbclid`: **Meta Ads**
4. `utm_source` vorhanden: **Kampagne** (siehe unten)
5. sonst nach Referrer: bekannte Suchmaschinen (**Suche: Google**, Bing, DuckDuckGo, ...), bekannte
   Social-Netzwerke (**Social: LinkedIn**, ...), sonstige Verweise (**Referral: domain.de**)
6. kein Referrer: **Direkt**

Die Klick-IDs `gclid`, `fbclid` und `msclkid` hängen die Werbeplattformen bei aktiviertem
Auto-Tagging selbst an die URL. Dafür musst du nichts tun.

## Eigene Kampagnen mit UTM-Parametern

Für alles andere (Newsletter, Social-Posts, Partner-Links, QR-Codes) müssen die Links die
UTM-Parameter selbst tragen:

```
https://deine-seite.de/blog/artikel/?utm_source=newsletter&utm_medium=email&utm_campaign=herbst-2026
```

| Parameter | Bedeutung | Beispiel |
|---|---|---|
| `utm_source` | Woher der Link kommt (Pflicht, sonst greift die Erkennung nicht) | `newsletter` |
| `utm_medium` | Über welchen Kanal | `email` |
| `utm_campaign` | Welche Aktion (optional) | `herbst-2026` |

Das Ergebnis erscheint in der Aufschlüsselung **Quellen (Kampagnen)**:

- Beispiel oben: **Kampagne: newsletter (herbst-2026)**
- Ohne `utm_campaign`: **Kampagne: newsletter**
- Mit `utm_medium` = `cpc`, `ppc`, `paid`, `paidsearch` oder `display`: **Bezahlt: quelle**

Wichtig: Schreibweise und Groß-/Kleinschreibung der Werte werden so übernommen, wie sie in der
URL stehen. `Newsletter` und `newsletter` erscheinen als zwei getrennte Einträge.

## Nur der erste Aufruf zählt

Damit ein mehrseitiger Besuch die Quellen nicht mehrfach zählt, wird pro Besucher und Tag nur der
zeitlich erste Seitenaufruf für die Quelle ausgewertet. Die UTM-Parameter stehen ohnehin nur
auf der Seite, auf der der Besucher ankommt.
