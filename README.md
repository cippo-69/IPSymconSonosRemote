# IP-Symcon – Sonos Kachel

Bedienoberfläche für die Kachel-Visualisierung (HTML-SDK) auf Basis des Sonos-Moduls von
[tkugelberg/Sonos](https://github.com/tkugelberg/Sonos). Aufbau und Bedienung wie die Kachel aus
[IPSymconMusicAssistant](https://github.com/cippo-69/IPSymconMusicAssistant).

> Status: 0.1 – noch nicht auf einem Symcon-System getestet.

## Funktionen
- **Geräteauswahl per Pulldown** (Jetzt läuft, Favoriten, Bibliothek – die Auswahl gilt überall).
- **Ein/Aus** je Gerät über einen Schaltaktor (z. B. Zigbee2MQTT-Steckdose). Geräte ohne Schaltaktor gelten als immer an.
  Vor dem Ausschalten eines Sonos-Geräts wird die Wiedergabe angehalten und die Gruppe verlassen bzw. aufgelöst.
- **Eine Gruppe über den Master-Schalter, Mitglieder frei wählbar:** Bei jedem Gerät mit dem Schalter „Gruppe“ auswählen,
  ob es mitspielen soll (auch im ausgeschalteten Zustand). Master an → nur die ausgewählten Geräte kommen dazu; Master aus →
  Gruppe aufgelöst, die Auswahl bleibt für das nächste Mal. Bei aktiver Gruppe wirkt der Schalter „Gruppe“ sofort.
  Ein ausgewähltes Sonos-Gerät, das erst eingeschaltet wird, tritt automatisch bei, sobald es hochgefahren ist.
- **Nicht-Sonos-Geräte in der Gruppe (gekoppelt):** Synchrone Wiedergabe ist ein Sonos-eigenes Protokoll – nur Sonos-Geräte
  können einer Sonos-Gruppe beitreten. Andere Geräte werden stattdessen gekoppelt: Sie werden eingeschaltet, auf den
  konfigurierten Eingang gestellt (z. B. AV-Receiver auf den Eingang mit Sonos Port) und folgen Play/Pause/Stopp des Masters –
  auch wenn der Master über die Sonos-App bedient wird. Eigene Musik bekommen sie nur über diesen Eingang.
- **Favoriten:** Playlists, Radio, Titel, Interpreten, Alben – je 20 Plätze, umbenennen, verschieben, löschen.
  Speichern per Stern in der Bibliothek oder bei „Jetzt läuft“ (aktueller Sender bzw. Titel).
- **Bibliothek:** Sonos-Favoriten, Sonos-Playlists, Radio (Meine Sender), lokale Musikbibliothek (Interpreten, Alben,
  Titel mit Suche). „Speichern als“ legt die Favoritenart fest (Automatisch = aus der Sonos-Klasse).
- **Andere Geräte (nicht Sonos):** jede Instanz oder reine Variablen – An/Aus, Lautstärke, Stumm, Wiedergabe, Titel.
  Sie werden nicht gruppiert und spielen keine Favoriten ab (Kennzeichnung „extern“).

## Voraussetzungen
- IP-Symcon ab 8.0 (Kachel-Visualisierung, IPSModuleStrict)
- Bibliothek **Sonos Module** (tkugelberg/Sonos) mit eingerichtetem Splitter und Player-Instanzen
- PHP-SOAP (im Symcon-Lieferumfang, wird auch vom Sonos-Modul genutzt)

## Einrichtung
1. Modulverwaltung → Repository dieser Bibliothek hinzufügen.
2. Instanz **Sonos Kachel** anlegen.
3. „Alle Sonos Player übernehmen“ klicken – legt je Sonos-Player-Instanz eine Zeile an.
4. Je Zeile den **Schaltaktor** wählen (Zustandsvariable der Z2M-Steckdose). Beim Gerät ohne Steckdose leer lassen.
5. Weitere Geräte: Zeile hinzufügen, Instanz wählen. Lautstärke/Stumm/Wiedergabe/Titel werden unter der Instanz
   automatisch gesucht (Ident bzw. Profil `~Volume`, `Mute`, `~Playback…`, `~Song`, `~Artist`, `Power`/`State`);
   die optionalen Spalten überschreiben die Suche.
6. Kachel in der Visualisierung platzieren.

Empfehlung für das Sonos-Modul: in den Player-Instanzen „Detaillierte Informationen“ einschalten (Titel, Interpret, Cover)
und „Mute-Steuerung“, wenn der Stumm-Knopf erscheinen soll.

## Befehle
```php
SNR_PlayFavorite($id, 'radio', 3, '12345');  // Art, Platz 1–20, Instanz-ID des Geräts ('' = erstes eingeschaltetes Sonos-Gerät)
SNR_SetPower($id, '12345', true);           // Gerät ein/aus
SNR_SelectForGroup($id, '23456', true);     // Gerät für die Gruppe auswählen (bei aktiver Gruppe sofort)
SNR_SetMaster($id, '12345', true);          // Gruppe mit 12345 als Master und den ausgewählten Geräten bilden (false = auflösen)
SNR_GetFavorites($id);                      // JSON aller Favoriten
```

## Erweitern um weitere Gerätetypen
Gerätezugriffe liegen in `libs/DeviceDrivers.php`:
- `SNRDevice` – Schnittstelle (Zustand, Befehle, Lautstärke, Stumm, Ein/Aus, Abspielen)
- `SNRBaseDevice` – gemeinsame Logik (Name, Schaltaktor, Variablen schreiben inkl. `ON`/`OFF`-Strings)
- `GenericDevice` – Variablen-basiert, `SonosDevice` – Sonos über `SNS_*` und UPnP

Für einen neuen Typ eine Klasse von `SNRBaseDevice` ableiten und in `SNRDeviceFactory::DRIVERS` die Modul-GUID der
Instanz eintragen. Über `Capabilities()` (`power`, `volume`, `mute`, `transport`, `group`, `media`) steuert der Treiber,
welche Bedienelemente die Kachel zeigt; mit `media = true` erscheint das Gerät auch unter „Abspielen auf“.

## Hinweise
- Die Auswahl ist maßgeblich: Wird ein ausgewähltes Sonos-Gerät in der Sonos-App aus der Gruppe genommen, holt die Kachel
  es wieder hinein. Zum dauerhaften Herausnehmen den Schalter „Gruppe“ in der Kachel ausschalten.
- Wirklich synchronen Ton auf Sonos und anderen Geräten (AirPlay, Chromecast …) gemeinsam bietet Music Assistant über
  Gruppen-Player – das ist eine Alternative zur Kopplung hier.
- Nach dem Einschalten braucht ein Sonos-Gerät ca. eine Minute, bis es im Netz und im Sonos-Modul wieder aktiv ist;
  bis dahin steht es auf „startet oder nicht erreichbar“.
- Gespeicherte Titel von Streamingdiensten („Jetzt läuft“ → Stern) lassen sich nicht bei jedem Dienst wieder abspielen.
  Zuverlässiger: im Sonos-App zu „Meine Sonos“ hinzufügen und über Bibliothek → Sonos-Favoriten speichern.
- Cover-Bilder lokaler Musik kommen direkt vom Sonos-Gerät (`http://<ip>:1400/…`) und sind nur im lokalen Netz sichtbar.

## Lizenz
MIT
