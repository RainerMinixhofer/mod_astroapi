# AstroAPI backend operations

## Layout

```text
backend/
  api.py
  import_fits.py
  inbox_watchdog.py
  requirements.txt
```

Python environment:

```text
/var/www/joomla/modules/mod_astroapi/venv
```

## Services

- `astroapi.service`: FastAPI on `http://localhost:8010`
- `astroimport-watchdog.service`: monitors `/srv/astrodata/inbox`
- `astroimport.timer`: obsolete and disabled

```bash
systemctl status astroapi.service
systemctl status astroimport-watchdog.service
journalctl -u astroapi.service -f
journalctl -u astroimport-watchdog.service -f
```

The watchdog polls every 2 seconds, requires 60 seconds without a file
change, and verifies the snapshot for another 5 seconds before importing.
Adjust with `ASTROAPI_POLL_SECONDS`, `ASTROAPI_QUIET_SECONDS`, and
`ASTROAPI_VERIFY_SECONDS`.

The Phoca callback credential is stored in `/etc/astroapi-phoca-sync.env`
and must match the Joomla plugin's `sync_secret`.

## AstroInbox

The Inbox mirrors the active Phoca category tree. Rebuild it after category
changes:

```bash
php tools/create_astroapi_inbox_tree.php
```

Internal state is stored in `.status/`:

- `astroapi_import_status.json`
- `astroapi_imported_snapshot.json`
- `phoca_categories.json`

`.status/` is protected and ignored during observation detection and cleanup.

Files can be placed:

- in an exact Phoca category path
- in a Seestar object folder such as `/srv/astrodata/inbox/C30/`
- in the Inbox root when automatic classification is acceptable

All files with the same timestamp are grouped into one observation. The import
uses this precedence: filename timestamp, FITS `DATE-OBS`, image EXIF, video
creation metadata, filesystem timestamp.

## Manual import

```bash
venv/bin/python backend/import_fits.py --dry-run
venv/bin/python backend/import_fits.py
```

The dry run reports whether each group restores an existing observation or
creates a new one.

Existing observations are matched first by exact archived filename, then by
observation key and timestamp. Files can therefore be restored from a single
flat directory.

## Classification metadata

Supported FITS headers:

```text
OBJECT
TARGETTYPE / OBJTYPE
OBJCLASS
PHOCACAT
PROJECT
TELESCOP
INSTRUME
DATE-OBS
```

`PHOCACAT` may contain a slash-separated Phoca path:

```text
Deep Sky Objects/Nebulae/Planetary Nebulae
```

Recognized project/processing formats include PixInsight XISF/project files,
AutoStakkert `.as3`, PIPP `.psf`, DeepSkyStacker `.dss`, RegiStax `.rgpj/.r6`,
WinJUPOS `.wjp/.wjp2`, Siril `.sir/.siril`, metadata, FITS, raw camera, SER,
AVI/MOV, and MP4/WebM.

## Permanent media archive

Before Inbox cleanup, every observation file is streamed into:

```text
/var/www/joomla/images/phocagallery/astroapi-media/<observation-id>/
```

Filenames include a content hash. AstroAPI database rows point to these
archive files, and Phoca file-list entries become download links. MP4/WebM is
also embedded in the Phoca lightbox. Raw AVI/MOV is downloadable.

Inbox cleanup is refused unless every required file has a verified Joomla
archive copy.

## Phoca status and filters

The frontend status shows the current watchdog/import phase, files, pending
observations, and `new/existing` counts during active import.

The classification dropdown filters globally by Phoca tags:

- `Unclassified`
- `Needs_Manual_Classification`
- `Classification_Structured`
- `Classification_Manual`

## Maintenance

```bash
# Verify services
curl -sS http://localhost:8010/
curl -sS http://localhost:8010/import-status

# Refresh categories and covers
php tools/install_phoca_categories.php --apply
tools/update_category_thumbnails.sh

# Check Python syntax
venv/bin/python -m py_compile backend/api.py backend/import_fits.py backend/inbox_watchdog.py
```

Database credentials are held in `backend/api.py` and
`backend/import_fits.py`; keep file permissions restricted.
