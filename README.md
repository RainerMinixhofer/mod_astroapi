# mod_astroapi

Joomla 6 module and synchronization tooling for publishing AstroAPI
observations through Phoca Gallery.

## Components

- `backend/api.py`: FastAPI service on port 8010
- `backend/import_fits.py`: imports and groups files from AstroInbox
- `backend/inbox_watchdog.py`: watches AstroInbox and triggers import/sync
- `extensions/plg_system_astroapi_phoca_sync/`: Phoca synchronization plugin
- `categories.md`: category tree and category-cover image sources
- `tools/`: category, thumbnail, and Inbox maintenance commands

## Current workflow

1. Copy an observation into `/srv/astrodata/inbox/`.
2. Optionally place it below a mirrored Phoca category path.
3. The watchdog waits until uploads are stable.
4. AstroAPI groups files by timestamp and classifies the observation.
5. The Phoca plugin stores the image, metadata, tags, and all original files.
6. Phoca renders the observation, downloads, SIMBAD link, status filter, and
   browser-playable MP4/WebM video.
7. Source files are removed from Inbox only after durable Joomla archival.

Flat Seestar `MyWorks` folders are supported. Their target name is read from
the folder and normalized through SIMBAD/Caldwell mappings.

## Classification

Priority order:

1. Explicit `PHOCACAT` / `phoca_category`
2. `OBJCLASS` / `object_class`
3. `TARGETTYPE` / `OBJTYPE` / `target_type`
4. FITS target/name matching and catalog lookup
5. Safe `Mixed Deep Sky Fields` fallback

Tags include year, equipment, project, and classification state. Phoca's
classification dropdown can show:

- Unclassified
- Needs Manual Classification
- Classification Structured
- Classification Manual

## Operations

```bash
# Rebuild the Inbox category tree
php tools/create_astroapi_inbox_tree.php

# Refresh covers from categories.md
tools/update_category_thumbnails.sh

# Dry-run a flat or categorized restore
venv/bin/python backend/import_fits.py --dry-run
```

Services:

```bash
systemctl status astroapi.service
systemctl status astroimport-watchdog.service
journalctl -u astroapi.service -f
journalctl -u astroimport-watchdog.service -f
```

The legacy 15-minute `astroimport.timer` is disabled.

## Storage

All observation files are archived before Inbox cleanup:

```text
/var/www/joomla/images/phocagallery/astroapi-media/<observation-id>/
```

Phoca file-list entries are download links. MP4/WebM is also playable directly
in the observation lightbox; raw AVI/MOV remains downloadable.

AstroInbox status files live in:

```text
/srv/astrodata/inbox/.status/
```

They are internal and excluded from observation/category detection.

## Joomla configuration

- AstroAPI URL: `http://localhost:8010`
- Phoca sync plugin: `plg_system_astroapi_phoca_sync`
- Sync requests require the configured `sync_secret`
- Phoca tag links: category-by-tag/search outcome

For a fresh Ubuntu/Joomla/Phoca installation, use
[install/README.md](install/README.md). The installer contains self-contained
Joomla module/plugin payloads, systemd units, database schema, and payload
validation.

See [BACKEND_SETUP.md](BACKEND_SETUP.md) for service and maintenance details.
