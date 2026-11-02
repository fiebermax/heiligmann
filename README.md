# Lohnunternehmen Heiligmann – Website

Statische Website für IONOS Webhosting (mit PHP für die Galerie-Verwaltung).
Der Ordner `ionos/` wird per FTP/SFTP ins Webroot hochgeladen.

## Lokale Vorschau

    cd ionos
    php -S localhost:8000        # mit Galerie-Verwaltung
    # oder: python -m http.server 8000   (nur Ansicht, ohne Verwaltung)

Dann http://localhost:8000 öffnen.

## Galerie-Verwaltung

- Adresse: `https://lohnunternehmen-heiligmann.de/verwaltung/`
- Beim **ersten Aufruf** wird ein Passwort festgelegt – direkt nach dem Hochladen erledigen.
- Passwort vergessen: `verwaltung/passwort.php` per FTP löschen, dann neu festlegen.
- Fotos werden im Browser verkleinert und auf dem Server als JPG gespeichert.

Diese Dateien entstehen nur auf dem Server und sind **nicht** im Repo
(beim erneuten Hochladen der Website also nicht überschrieben):

- `data/galerie.json` – Liste und Reihenfolge der Galerie-Fotos
  (solange sie fehlt, gilt `data/galerie.start.json`)
- `uploads/galerie/` – hochgeladene Fotos
- `verwaltung/passwort.php` – Passwort-Hash
