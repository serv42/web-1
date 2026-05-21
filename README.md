# Solide IT Website

Die offizielle Website für **Solide IT Support Portal**. Dieses Projekt ist als containerisierte PHP-FPM- und Nginx-Umgebung konzipiert und wird per Docker Compose betrieben.

## Features

- **Fernwartungs-Hub & Buchungssystem**: Integriertes Portal zur einfachen Buchung von Support-Terminen (`api/booking.php`).
- **Mehrsprachigkeit (i18n)**: Volle Lokalisierung (Deutsch/Englisch) über eine zentrale `translations.json`.
- **E-Mail-Integration**: Zuverlässiger SMTP-Versand via `PHPMailer` für Kontakt- und Buchungsformulare.
- **Dockerized Architecture**: Läuft mit einer minimalen Alpine-Basis (PHP 8.3-FPM + Nginx) unter der Kontrolle von Supervisor.
- **Rate-Limiting**: Integrierter Schutz vor Missbrauch bei API-Endpunkten mit persistentem Speicher über Docker Volumes.

---

## Voraussetzungen

- **Docker** und **Docker Compose** müssen auf dem Zielsystem installiert sein.

## Lokale Einrichtung & Entwicklung

1. **Repository klonen** (falls noch nicht geschehen):
   ```bash
   git clone https://github.com/serv42/web-1.git
   cd web-1
   ```

2. **Umgebungsvariablen anlegen**:
   Kopiere die `.env.example` (bzw. erstelle eine `.env`) im Hauptverzeichnis und passe die SMTP-Zugangsdaten an:
   ```env
   # --- SMTP Configuration ---
   SMTP_HOST=w01494a6.kasserver.com
   SMTP_PORT=587
   SMTP_USER=noreply@solide-it.com
   SMTP_PASS=dein-geheimes-passwort
   SMTP_SECURE=tls

   # --- Mail Settings ---
   MAIL_FROM=noreply@solide-it.com
   MAIL_FROM_NAME=Solide.IT Webseite
   MAIL_TO=m@manage-that.com
   MAIL_TO_NAME=Solide.IT Team
   ```

3. **Container bauen und starten**:
   ```bash
   docker compose up -d --build
   ```

   Die Webseite ist anschließend unter `http://127.0.0.1:8385` erreichbar.

---

## Projektstruktur

- **`index.html`** – Hauptseite der Webpräsenz mit Fernwartungs-Modal.
- **`translations.json`** – Übersetzungen für die Mehrsprachigkeit.
- **`api/booking.php`** – Backend-Logik für Terminbuchungen & E-Mail-Versand.
- **`nginx.conf`** – Nginx-Webserverkonfiguration mit Weiterleitungen und Security-Headers.
- **`supervisord.conf`** – Prozesssteuerung für Nginx und PHP-FPM im selben Container.
- **`Dockerfile`** – Multi-Prozess Alpine-Image (PHP 8.3-FPM, Nginx, Supervisor, Composer & PHPMailer).
- **`docker-compose.yml`** – Orchestriert den Webseiten-Container und das Rate-Limit Volume.
