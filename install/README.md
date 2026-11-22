# Fresh Ubuntu + Joomla/Phoca installation

This directory contains the self-contained installation payload for
`mod_astroapi`, the AstroAPI backend, and `plg_system_astroapi_phoca_sync`.

## 1. Ubuntu and Joomla prerequisites

Install a current Joomla 6 instance and MariaDB first. The Joomla root and CLI
must exist:

```text
/var/www/joomla/configuration.php
/var/www/joomla/cli/joomla.php
```

Install Phoca Gallery through its official Joomla package. This repository does
not redistribute Phoca itself. Verify this directory exists:

```text
/var/www/joomla/administrator/components/com_phocagallery
```

The installer adds the required Ubuntu packages:

- Python 3, venv and pip
- PHP CLI/CURL/GD/MySQL extensions
- MariaDB client
- zip/unzip and rsync

## 2. Run the installer

From this module repository:

```bash
sudo env \
  SERVICE_USER="$USER" \
  ASTROAPI_DB_PASSWORD='choose-a-long-password' \
  ./install/install.sh \
    --joomla-root /var/www/joomla
```

If the service user is the account invoking `sudo`, `SERVICE_USER` may be
omitted. The script will generate a Phoca sync secret when none is supplied.

Optional environment variables:

```text
WEB_GROUP
ASTROAPI_INBOX
ASTROAPI_DB_NAME
ASTROAPI_DB_USER
ASTROAPI_DB_PASSWORD
ASTROAPI_DB_HOST
ASTROAPI_DB_PORT
ASTROAPI_PORT
ASTROAPI_PHOCA_SYNC_SECRET
```

Use `--skip-apt` on a pre-provisioned server.

## 3. What the installer does

- checks Joomla and Phoca
- creates the AstroAPI database/user and schema
- installs/updates `mod_astroapi`
- installs/enables `plg_system_astroapi_phoca_sync`
- creates `/var/www/joomla/modules/mod_astroapi/venv`
- writes restricted environment files under `/etc/astroapi/`
- installs `astroapi.service`
- installs and enables `astroimport-watchdog.service`
- disables the obsolete `astroimport.timer`
- applies the Phoca category tree
- creates the mirrored AstroInbox category directories
- starts the backend and watchdog

## 4. Joomla configuration

In Joomla:

1. Open **System → Extensions → Modules**.
2. Publish `mod_astroapi` where required.
3. Set API URL to `http://127.0.0.1:8010`.
4. Confirm the AstroAPI Phoca sync plugin is enabled.
5. Create/publish a **Phoca Gallery Categories** menu item named
   `Astronomy Observations`.
6. Point it to the top-level Phoca categories.
7. Enable Phoca tag display with category-by-tag/search links.

## 5. Category covers

After the menu and categories are available:

```bash
cd /var/www/joomla/modules/mod_astroapi
ASTROAPI_JOOMLA_ROOT=/var/www/joomla \
./tools/update_category_thumbnails.sh
```

`URL: None` entries preserve an existing cover.

## 6. Inbox workflow

Place files under:

```text
/srv/astrodata/inbox/<Phoca category path>/
```

or copy Seestar `MyWorks` object folders directly below Inbox.

The watcher waits for a stable upload, then imports and synchronizes:

- classification
- year/equipment/project tags
- Phoca image and category linkage
- SIMBAD link
- download links for all files
- MP4/WebM player

All files are permanently archived below:

```text
/var/www/joomla/images/phocagallery/astroapi-media/<observation-id>/
```

Inbox source files are removed only after every archive copy is verified.

## 7. Verification

```bash
curl -sS http://127.0.0.1:8010/
curl -sS http://127.0.0.1:8010/import-status
systemctl status astroapi.service
systemctl status astroimport-watchdog.service
journalctl -u astroimport-watchdog.service -n 100
```

Expected empty-state status:

```json
{"phase":"idle","files":0}
```

## 8. Files and secrets

Runtime secrets are not stored in the repository. They are generated or
provided during installation and written to:

```text
/etc/astroapi/astroapi.env
/etc/astroapi/phoca-sync.env
```

Do not commit or expose these files.
