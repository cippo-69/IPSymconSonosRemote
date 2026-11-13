# IP-Symcon – Sonos Kachel

Bedienoberfläche für die Kachel-Visualisierung (HTML-SDK) auf Basis des Sonos-Moduls von
[tkugelberg/Sonos](https://github.com/tkugelberg/Sonos). Aufbau und Bedienung wie die Kachel aus
[IPSymconMusicAssistant](https://github.com/cippo-69/IPSymconMusicAssistant).

> Status: 0.2 – noch nicht auf einem Symcon-System getestet.

## Funktionen
- **Geräteauswahl per Pulldown** (Jetzt läuft, Favoriten, Bibliothek – die Auswahl gilt überall).
- **Ein/Aus** je Gerät über einen Schaltaktor (z. B. Zigbee2MQTT-Steckdose). Geräte ohne Schaltaktor gelten als immer an.
  Vor dem Ausschalten eines Sonos-Geräts wird die Wiedergabe angehalten und die Gruppe verlassen bzw. aufgelöst.
- **Eine Gruppe, Mitglieder frei wählbar:** Bei jedem Gerät mit dem Schalter „Gruppe“ auswählen, ob es mitspielen soll
  (auch im ausgeschalteten Zustand). Das zuerst ausgewählte eingeschaltete Sonos-Gerät wird Master und ist in der Kachel so
  markiert; alle weiteren ausgewählten Geräte spielen mit ihm. Wird der Master abgewählt oder ausgeschaltet, übernimmt das
  nächste ausgewählte Sonos-Gerät. Alle abgewählt → Gruppe aufgelöst.
  Ein ausgewähltes Sonos-Gerät, das erst eingeschaltet wird, tritt automatisch bei, sobald es hochgefahren ist.
- **Nicht-Sonos-Geräte in der Gruppe (gekoppelt):** Synchrone Wiedergabe ist ein Sonos-eigenes Protokoll – nur Sonos-Geräte
  können einer Sonos-Gruppe beitreten. Andere Geräte werden stattdessen gekoppelt: Sie werden eingeschaltet, auf den
  konfigurierten Eingang gestellt (z. B. AV-Receiver auf den Eingang mit Sonos Port) und folgen Play/Pause/Stopp des Masters –
  auch wenn der Master über die Sonos-App bedient wird. Eigene Musik bekommen sie nur über diesen Eingang.
- **Favoriten:** Playlists, Radio, Titel, Interpreten, Alben – je 20 Plätze, umbenennen, verschieben, löschen.
  Speichern per Stern in der Bibliothek oder bei „Jetzt läuft“ (aktueller Sender bzw. Titel).
- **Bibliothek:** Sonos-Favoriten, Sonos-Playlists, Radio (Meine Sender), lokale Musikbibliothek (Interpreten, Alben,
  Titel mit Suche). „Speichern als“ legt die Favoritenart fest (Automatisch = aus der Sonos-Klasse).
- **Suche in Musikdiensten (Qobuz, Amazon Music, Spotify, Deezer, Tidal …):** Die Kachel sucht direkt im Dienst –
  über dieselbe Schnittstelle wie die Sonos-App (Sonos Music API). Kategorien (Titel, Alben, Interpreten, Playlists) kommen
  vom Dienst; ohne Suchbegriff zeigt die Kachel die Startebene des Dienstes (z. B. „Meine Musik“). Interpreten und
  Ordner lassen sich öffnen, Alben/Playlists/Titel abspielen und als Favorit speichern.
- **Internetradio (TuneIn):** Sendersuche und Rubriken (Lokale Sender, Musik, …) ohne Konto.
- **Klang je Sonos-Gerät:** Bässe und Höhen als Schieberegler, Loudness als Schalter im Klang-Untermenü der Gerätekarte – sofern in der Sonos-Instanz
  „Tiefen-“, „Höhen-“ bzw. „Loudness-Steuerung“ eingeschaltet ist.
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

### Musikdienste verknüpfen
1. In der Sonos-App den Dienst einrichten (falls noch nicht geschehen) und am besten einen Favoriten daraus anlegen –
   daraus lernt das Modul die Sonos-Kontonummer und das URI-Schema des Dienstes.
2. Instanz → „Musikdienste (Suche in der Kachel)“ → „Dienste von Sonos laden“. ★ markiert Dienste, die in den Sonos-Favoriten
   vorkommen.
3. Dienst wählen → „Dienst verknüpfen“ → „Anmeldeseite öffnen“ und beim Dienst anmelden. Die Verknüpfung wird danach
   automatisch abgeschlossen (bis 10 Minuten). Der Dienst erscheint dann als Quelle in der Bibliothek der Kachel.

Die Anmeldung gilt für die Kachel; abgespielt wird über das Konto, das in der Sonos-Anlage hinterlegt ist.
Unterstützt sind Dienste mit Anmeldeart AppLink/DeviceLink (die meisten aktuellen) und anonyme Dienste.

Empfehlung für das Sonos-Modul: in den Player-Instanzen „Detaillierte Informationen“ einschalten (Titel, Interpret, Cover)
und „Mute-Steuerung“, wenn der Stumm-Knopf erscheinen soll.

## Befehle
```php
SNR_PlayFavorite($id, 'radio', 3, '12345');  // Art, Platz 1–20, Instanz-ID des Geräts ('' = erstes eingeschaltetes Sonos-Gerät)
SNR_SetPower($id, '12345', true);           // Gerät ein/aus
SNR_SelectForGroup($id, '23456', true);     // Gerät für die Gruppe auswählen/abwählen (das erste ausgewählte wird Master)
SNR_SetTone($id, '12345', 'bass', 3);       // Klang: 'bass'/'treble' -10…10, 'loudness' 0/1 (wenn in der Sonos-Instanz freigeschaltet)
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
- Musikdienste: Lässt sich ein Album oder eine Playlist nicht als Ganzes einreihen, holt das Modul die Titel einzeln
  (bis 100). Klappt das Abspielen eines Dienstes gar nicht, fehlt meist ein Sonos-Favorit dieses Dienstes als Vorlage
  (siehe „Musikdienste verknüpfen“, Schritt 1) – danach „Dienste von Sonos laden“ erneut klicken.
- Amazon Music ist ungewöhnlich streng; ob Suche und Wiedergabe mit dem eigenen Konto gehen, muss vor Ort getestet werden.
- Cover-Bilder lokaler Musik kommen direkt vom Sonos-Gerät (`http://<ip>:1400/…`) und sind nur im lokalen Netz sichtbar.

## Lizenz
MIT
