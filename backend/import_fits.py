import os
import pymysql
import json
import re
import struct
import urllib.error
import urllib.parse
import urllib.request
from PIL import Image
from config import database_config, inbox_path

from astropy.io import fits
from datetime import datetime

DB = database_config().as_dict()

INPUT_DIR = inbox_path()
STATUS_DIRECTORY = os.path.join(INPUT_DIR, ".status")
CATEGORY_MANIFEST = os.path.join(STATUS_DIRECTORY, "phoca_categories.json")
DRY_RUN = "--dry-run" in os.sys.argv

CALDWELL_ALIASES = {
    "C30": ("NGC7331", "Spiral Galaxy"),
    "C33": ("NGC6992", "Supernova Remnant"),
    "C34": ("NGC6960", "Supernova Remnant"),
    "C49": ("NGC2237", "Emission Nebula"),
}

OBJECT_ALIASES = {
    "IC405": "Emission Nebula",
    "IC360": "Emission Nebula",
    "IC1995": "Reflection Nebula",
    "IC1396B": "Emission Nebula",
    "IC353": "Emission Nebula",
    "IC5070": "Emission Nebula",
    "NGC7000": "Nebula",
    "NGC1499": "Emission Nebula",
    "NGC6992": "Supernova Remnant",
    "NGC6995": "Supernova Remnant",
    "NGC6960": "Supernova Remnant",
}

MESSIER_TYPES = {}
for numbers, object_type in [
    ({"M1"}, "Supernova Remnant"),
    (
        {"M2", "M3", "M4", "M5", "M9", "M10", "M12", "M13", "M14",
         "M15", "M19", "M22", "M28", "M30", "M53", "M54", "M55",
         "M56", "M62", "M68", "M69", "M70", "M71", "M72", "M75",
         "M79", "M80", "M92", "M107"},
        "Globular Cluster",
    ),
    (
        {"M6", "M7", "M11", "M18", "M21", "M23", "M25", "M26",
         "M29", "M34", "M35", "M36", "M37", "M38", "M39", "M41",
         "M44", "M45", "M46", "M47", "M48", "M50", "M52", "M67",
         "M93", "M103"},
        "Open Cluster",
    ),
    (
        {"M8", "M16", "M17", "M20", "M42", "M43"},
        "Emission Nebula",
    ),
    ({"M27", "M57", "M76", "M97"}, "Planetary Nebula"),
    ({"M78"}, "Reflection Nebula"),
    (
        {"M24", "M40", "M73"},
        "Starfield",
    ),
    (
        {"M31", "M33", "M51", "M58", "M61", "M63", "M64", "M65",
         "M66", "M74", "M77", "M81", "M83", "M88", "M90", "M91",
         "M94", "M95", "M96", "M98", "M99", "M100", "M101",
         "M104", "M106", "M108", "M109"},
        "Spiral Galaxy",
    ),
    (
        {"M32", "M49", "M59", "M60", "M84", "M85", "M86", "M87",
         "M89", "M102", "M105", "M110"},
        "Elliptical Galaxy",
    ),
    ({"M82"}, "Irregular Galaxy"),
]:
    MESSIER_TYPES.update({name: object_type for name in numbers})

SIMBAD_TYPE_GROUPS = {
    "Galaxy": {
        "G", "GiC", "GiP", "GPair", "GTrpl", "GrG", "GClstr", "CGG",
        "IG", "PaG", "LSB", "H2G", "EmG", "SBG", "rG", "bCG",
        "LINER", "LIN", "Seyfert", "Sy1", "Sy2", "SyG", "AGN",
        "QSO", "Bla", "BLL", "BL?", "OVV",
    },
    "Nebula": {
        "Neb", "GNe", "PN", "PN?", "HII", "SNR", "RNe", "DNe", "BNe",
        "EmO", "Cld", "MoC", "ISM", "SFR", "HVC", "denseCore",
        "bubble", "HI", "PartofCloud",
    },
    "Cluster": {
        "OpC", "GlC", "Cl*", "As*", "Assoc*", "StC", "Cluster*",
    },
    "Starfield": {
        "*", "**", "*iC", "*iN", "*iA", "*i*", "V*", "V*?", "PM*",
        "Pe*", "Em*", "C*", "YSO", "PMS", "RNe?*",
    },
}

SIMBAD_TYPE_OVERRIDES = {
    "sh": "Supernova Remnant",
}
OBJECT_TYPE_REFINEMENTS = {
    "SH2-103": "Supernova Remnant",
    "SH2103": "Supernova Remnant",
    "NGC6960": "Supernova Remnant",
}


def catalog_identifier(target_name):
    raw = str(target_name or "").strip()
    if raw.lower().startswith("lunar"):
        return "Moon"
    normalized = re.sub(r"\s+", "", raw).upper()
    alias = CALDWELL_ALIASES.get(normalized)
    return alias[0] if alias else normalized


def import_files():

    root = os.path.abspath(INPUT_DIR)
    files = []
    for directory, subdirectories, filenames in os.walk(root):
        if os.path.abspath(directory) == os.path.abspath(STATUS_DIRECTORY):
            subdirectories[:] = []
            continue
        subdirectories[:] = [
            name for name in subdirectories
            if name != os.path.basename(STATUS_DIRECTORY)
        ]
        for filename in filenames:
            path = os.path.join(directory, filename)
            if os.path.isfile(path):
                files.append(path)

    return files


def valid_category_paths():
    try:
        with open(CATEGORY_MANIFEST, encoding="utf-8") as manifest:
            values = json.load(manifest)
            return {str(value).rstrip("/") for value in values}
    except (FileNotFoundError, json.JSONDecodeError, OSError) as error:
        print("Kategorie-Manifest konnte nicht gelesen werden:", error)
        return set()


def directory_classification(path, category_paths):

    directory = os.path.dirname(os.path.abspath(path))
    root = os.path.abspath(INPUT_DIR)

    if directory == root:
        return None, None

    try:
        relative = os.path.relpath(directory, root)
    except ValueError:
        return None, None

    if relative.startswith(".."):
        return None, None

    parts = [part for part in relative.split(os.sep) if part]
    category_parts = []
    for index in range(len(parts), 0, -1):
        candidate = "/".join(parts[:index])
        if candidate in category_paths:
            category_parts = parts[:index]
            break

    if category_parts:
        target_parts = parts[len(category_parts):]
        return (
            " / ".join(category_parts),
            target_parts[0] if target_parts else None,
        )

    return None, parts[0] if parts else None


def resolve_catalog_type(target_name):
    original_target = str(target_name or "").strip()
    normalized = catalog_identifier(original_target)
    if not normalized:
        return None

    original_key = re.sub(r"\s+", "", original_target).upper()
    aliases = CALDWELL_ALIASES.get(original_key)
    query_name = normalized
    if aliases:
        return aliases[1]

    object_alias = MESSIER_TYPES.get(original_key) or OBJECT_ALIASES.get(original_key)
    if object_alias:
        return object_alias

    refined = OBJECT_TYPE_REFINEMENTS.get(original_key)
    if refined:
        return refined

    match = None
    query_names = list(dict.fromkeys([original_target, query_name]))
    for host in ("simbad.cfa.harvard.edu", "simbad.idia.ac.za"):
        for candidate in query_names:
            if not candidate:
                continue
            url = (
                f"https://{host}/simbad/sim-id"
                "?Ident=" + urllib.parse.quote(candidate)
                + "&output.format=ASCII"
            )
            try:
                request = urllib.request.Request(
                    url,
                    headers={"User-Agent": "AstroAPI/1.0 classification resolver"},
                )
                with urllib.request.urlopen(request, timeout=8) as response:
                    text = response.read(40000).decode("utf-8", errors="replace")
            except (urllib.error.URLError, TimeoutError):
                continue

            match = re.search(r"^Object .+?\s+---\s+(\S+)\s+---", text, re.MULTILINE)
            if match:
                break
        if match:
            break

    if not match:
        return None

    object_type = match.group(1)
    if object_type in SIMBAD_TYPE_OVERRIDES:
        return SIMBAD_TYPE_OVERRIDES[object_type]
    for classification, codes in SIMBAD_TYPE_GROUPS.items():
        if object_type in codes:
            if object_type in {"HII", "SFR"}:
                return "Emission Nebula"
            return classification
    if object_type.endswith("*"):
        return "Starfield"
    if object_type.startswith(("GClstr", "GTrpl", "GrG", "Gi", "PaG", "IG")):
        return "Galaxy"

    print(
        f"SIMBAD object type '{object_type}' for {target_name} is not mapped"
    )

    return None


def is_coarse_classification(value):
    return str(value or "").strip().lower() in {"galaxy", "nebula", "cluster"}


def resolve_catalog_classification(target_name):
    return resolve_catalog_type(target_name)


def observation_key(filename):

    name = filename.rsplit(".", 1)[0]

    replacements = [
        "_thn",
        "_AstroEditor_AI",
        "_annotated",
        "_starless",
        "-RAW",
    ]

    for r in replacements:
        name = name.replace(r, "")

    return name


def timestamp_key(path):
    filename = os.path.basename(path)
    matches = re.findall(
        r"(?<!\d)(20\d{2}[-_]?\d{2}[-_]?\d{2}[-_](?:\d{2}[-_:]?\d{2}[-_:]?\d{2}))(?!\d)",
        filename,
    )
    if not matches:
        return None

    digits = re.sub(r"\D", "", matches[-1])
    try:
        parsed = datetime.strptime(digits, "%Y%m%d%H%M%S")
    except ValueError:
        return None
    return parsed.strftime("%Y%m%d-%H%M%S")


def timestamp_from_filename(path):
    key = timestamp_key(path)
    if key is None:
        return None
    return datetime.strptime(key, "%Y%m%d-%H%M%S")


def parse_timestamp(value):
    if value is None:
        return None

    if isinstance(value, (datetime,)):
        return value

    text = str(value).strip()
    candidates = [
        ("%Y:%m:%d %H:%M:%S", text[:19]),
        ("%Y-%m-%dT%H:%M:%S", text[:19]),
        ("%Y-%m-%d %H:%M:%S", text[:19]),
    ]
    for date_format, candidate in candidates:
        try:
            return datetime.strptime(candidate, date_format)
        except ValueError:
            continue

    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00")).replace(tzinfo=None)
    except ValueError:
        return None


def image_metadata_time(path):
    try:
        with Image.open(path) as image:
            exif = image.getexif()
            for tag_id in (36867, 36868, 306):
                timestamp = parse_timestamp(exif.get(tag_id))
                if timestamp is not None:
                    return timestamp

            for key in ("DateTimeOriginal", "DateTime", "Creation Time", "creation_time"):
                timestamp = parse_timestamp(image.info.get(key))
                if timestamp is not None:
                    return timestamp
    except (OSError, ValueError):
        pass
    return None


def mp4_metadata_time(path):
    try:
        with open(path, "rb") as media:
            data = media.read()
    except OSError:
        return None

    position = data.find(b"mvhd")
    if position < 4:
        return None

    version = data[position + 4]
    time_offset = position + 8 if version == 0 else position + 12
    length = 4 if version == 0 else 8
    if time_offset + length > len(data):
        return None

    seconds = struct.unpack(
        ">I" if length == 4 else ">Q",
        data[time_offset:time_offset + length],
    )[0]
    try:
        from datetime import timedelta
        return datetime(1904, 1, 1) + timedelta(seconds=seconds)
    except (OverflowError, ValueError):
        return None


def avi_metadata_time(path):
    try:
        with open(path, "rb") as media:
            data = media.read()
    except OSError:
        return None

    position = data.find(b"IDIT")
    if position < 0 or position + 8 > len(data):
        return None

    size = struct.unpack("<I", data[position + 4:position + 8])[0]
    text = data[position + 8:position + 8 + size].split(b"\x00", 1)[0]
    value = text.decode("ascii", errors="ignore").strip()
    timestamp = parse_timestamp(value)
    if timestamp is not None:
        return timestamp

    for date_format in ("%a %b %d %H:%M:%S %Y", "%Y-%m-%d %H:%M:%S"):
        try:
            return datetime.strptime(value, date_format)
        except ValueError:
            continue
    return None


def media_metadata_time(path):
    extension = os.path.splitext(path)[1].lower()
    if extension in {".jpg", ".jpeg", ".png", ".webp", ".avif"}:
        return image_metadata_time(path)
    if extension in {".mp4", ".mov"}:
        return mp4_metadata_time(path)
    if extension == ".avi":
        return avi_metadata_time(path)
    return None


def observation_timestamp(path, fits_time=None):
    return (
        fits_time
        or timestamp_from_filename(path)
        or media_metadata_time(path)
        or datetime.fromtimestamp(os.path.getmtime(path))
    )


def timestamp_group_key(path):
    timestamp = observation_timestamp(path)
    return timestamp.strftime("%Y%m%d-%H%M%S")


def determine_type(filename):
    lowered = filename.lower()
    basename = os.path.basename(lowered)
    extension = os.path.splitext(basename)[1]

    if extension in {".fit", ".fits"}:
        return "FITS"
    if extension == ".xisf":
        return "PIXINSIGHT_XISF"
    if extension in {".pxiproject", ".pjsr"}:
        return "PIXINSIGHT_PROJECT"
    if extension == ".as3":
        return "AUTOSTAKKERT_PROJECT"
    if extension == ".psf":
        return "PIPP_PROJECT"
    if extension == ".dss":
        return "DEEPSKYSTACKER_PROJECT"
    if extension in {".rgpj", ".r6"}:
        return "REGISTAX_PROJECT"
    if extension in {".wjp", ".wjp2"}:
        return "WINJUPOS_PROJECT"
    if extension in {".sir", ".siril"}:
        return "SIRIL_PROJECT"
    if extension in {".json", ".yaml", ".yml"} and "astro" in basename:
        return "ASTRO_METADATA"
    if extension in {".txt", ".csv", ".log"}:
        return "METADATA"
    if "_thn." in lowered:
        return "THUMBNAIL"
    if "_astroeditor_ai." in lowered:
        return "AI"
    if extension in {".jpg", ".jpeg", ".png", ".webp", ".avif"}:
        return "STACK"
    if extension in {".avi", ".mov", ".ser"}:
        return "VIDEO_RAW"
    if extension in {".mp4", ".webm"}:
        return "VIDEO"
    if extension in {".tif", ".tiff"}:
        return "PROCESSED_IMAGE"
    if extension in {".raw", ".nef", ".cr2", ".cr3", ".arw", ".orf", ".rw2"}:
        return "CAMERA_RAW"

    return "OTHER"


def parse_fits_metadata(path):

    result = {}

    try:

        hdul = fits.open(path)

        hdr = hdul[0].header

        result["target_name"] = hdr.get("OBJECT")
        result["target_type"] = (
            hdr.get("TARGETTYPE")
            or hdr.get("OBJTYPE")
        )
        result["object_class"] = hdr.get("OBJCLASS")
        result["phoca_category"] = hdr.get("PHOCACAT")
        result["project_name"] = hdr.get("PROJECT")
        result["telescope"] = hdr.get("TELESCOP")
        result["camera"] = hdr.get("INSTRUME")
        result["filter_used"] = hdr.get("FILTER")
        result["exposure_time"] = hdr.get("EXPTIME")
        result["gain_value"] = hdr.get("GAIN")

        dateobs = hdr.get("DATE-OBS")

        if dateobs:

            try:
                result["observation_time"] = (
                    datetime.fromisoformat(
                        dateobs.replace("Z", "")
                    )
                )
            except:
                pass

        hdul.close()

    except Exception as e:

        print(path, e)

    return result


conn = pymysql.connect(**DB)
cur = conn.cursor()

cur.execute("""
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'observations'
""")
existing_columns = {row[0] for row in cur.fetchall()}

for column, definition in {
    "object_class": "VARCHAR(100) NULL",
    "phoca_category": "VARCHAR(500) NULL",
    "project_name": "VARCHAR(255) NULL",
    "phoca_uploaded_at": "DATETIME NULL",
}.items():
    if column not in existing_columns:
        cur.execute(
            f"ALTER TABLE observations ADD COLUMN {column} {definition}"
        )
conn.commit()

groups = {}
category_paths = valid_category_paths()

for path in import_files():
    filename = os.path.basename(path)
    key = timestamp_key(path) or timestamp_group_key(path)

    if key not in groups:
        groups[key] = []

    groups[key].append(path)

for group_key, files in groups.items():

    print("Importiere:", group_key)

    metadata = {}
    fits_file = None

    for f in files:
        if f.lower().endswith((".fit", ".fits")):
            fits_file = f
            break

    if fits_file:
        metadata = parse_fits_metadata(fits_file)

    filename_time = observation_timestamp(fits_file or files[0])
    if metadata.get("observation_time") is None and filename_time is not None:
        metadata["observation_time"] = filename_time

    manual_category, folder_target = directory_classification(
        fits_file or files[0],
        category_paths,
    )
    if not metadata.get("target_name") and folder_target:
        metadata["target_name"] = folder_target
    if (
        metadata.get("target_name")
        and (
            not metadata.get("target_type")
            or is_coarse_classification(metadata["target_type"])
        )
    ):
        resolved_type = resolve_catalog_classification(metadata["target_name"])
        if resolved_type:
            metadata["target_type"] = resolved_type

        if is_coarse_classification(metadata.get("target_type")):
            print(
                "Classification needs review:",
                metadata.get("target_name"),
                metadata.get("target_type"),
            )
    if metadata.get("target_name") and not metadata.get("simbad_oid"):
        metadata["simbad_oid"] = catalog_identifier(metadata["target_name"])
    if manual_category:
        metadata["phoca_category"] = manual_category

    representative = fits_file or next(
        (
            path for path in files
            if determine_type(os.path.basename(path)) in {"STACK", "VIDEO"}
        ),
        files[0],
    )
    key = observation_key(os.path.basename(representative))
    existing_time_key = timestamp_key(representative)
    if existing_time_key:
        key = existing_time_key
    canonical_time = metadata.get("observation_time")
    placeholders = ",".join(["%s"] * len(files))
    file_names = [os.path.basename(path) for path in files]
    cur.execute("""
        SELECT id
        FROM observations
        WHERE observation_key=%s
           OR (%s IS NOT NULL AND observation_time=%s)
           OR id IN (
               SELECT observation_id
               FROM observation_files
               WHERE file_name IN ({file_placeholders})
           )
        ORDER BY
            CASE
                WHEN id IN (
                    SELECT observation_id
                    FROM observation_files
                    WHERE file_name IN ({file_placeholders})
                ) THEN 0
                WHEN observation_key=%s THEN 1
                ELSE 2
            END,
            id
        LIMIT 1
    """.format(file_placeholders=placeholders), (
        key,
        canonical_time,
        canonical_time,
        *file_names,
        *file_names,
        key,
    ))

    row = cur.fetchone()

    if DRY_RUN:
        action = (
            f"RESTORE observation={row[0]}"
            if row
            else "NEW OBSERVATION"
        )
        print(
            "  DRY-RUN",
            action,
            "files=" + ", ".join(file_names),
            sep=" ",
        )
        continue

    if row:

        observation_id = row[0]

    else:
        cur.execute("""
            INSERT INTO observations (

                observation_key,
                target_name,
                target_type,
                object_class,
                phoca_category,
                project_name,
                simbad_oid,
                observation_time,

                telescope,
                camera,
                filter_used,

                exposure_time,
                gain_value

            )
            VALUES (
                %s,%s,%s,%s,%s,%s,%s,
                %s,%s,%s,%s,%s,%s
            )
        """, (

            key,
            metadata.get("target_name"),
            metadata.get("target_type"),
            metadata.get("object_class"),
            metadata.get("phoca_category"),
            metadata.get("project_name"),
            metadata.get("simbad_oid"),
            metadata.get("observation_time"),

            metadata.get("telescope"),
            metadata.get("camera"),
            metadata.get("filter_used"),

            metadata.get("exposure_time"),
            metadata.get("gain_value")

        ))

        conn.commit()

        observation_id = cur.lastrowid

    if row:
        cur.execute("""
            UPDATE observations
            SET target_name = COALESCE(target_name, %s),
                target_type = COALESCE(%s, target_type),
                object_class = COALESCE(%s, object_class),
                phoca_category = COALESCE(%s, phoca_category),
                project_name = COALESCE(%s, project_name),
                simbad_oid = COALESCE(%s, simbad_oid),
                observation_time = COALESCE(%s, observation_time),
                phoca_uploaded_at = NULL
            WHERE id = %s
        """, (
            metadata.get("target_name"),
            metadata.get("target_type"),
            metadata.get("object_class"),
            manual_category or metadata.get("phoca_category"),
            metadata.get("project_name"),
            metadata.get("simbad_oid"),
            metadata.get("observation_time"),
            observation_id,
        ))
        conn.commit()

    #
    # Assets importieren
    #

    for path in files:

        filename = os.path.basename(path)

        ftype = determine_type(filename)

        cur.execute("""
            SELECT id
            FROM observation_files
            WHERE observation_id=%s
            AND file_name=%s
        """, (
            observation_id,
            filename
        ))

        existing_file = cur.fetchone()
        if existing_file:
            cur.execute("""
                UPDATE observation_files
                SET file_type=%s, file_name=%s, file_path=%s
                WHERE id=%s
            """, (
                ftype,
                filename,
                path,
                existing_file[0],
            ))
            continue

        cur.execute("""
            INSERT INTO observation_files (

                observation_id,
                file_type,
                file_name,
                file_path

            )
            VALUES (
                %s,%s,%s,%s
            )
        """, (

            observation_id,
            ftype,
            filename,
            path

        ))

    conn.commit()

cur.close()
conn.close()

print("Fertig.")

