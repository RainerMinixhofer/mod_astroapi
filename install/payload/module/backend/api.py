from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse
from pathlib import Path
import json
from datetime import datetime, timezone
import pymysql
from config import database_config, inbox_path

app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

DB = database_config().as_dict()
INBOX_PATH = Path(inbox_path())


def get_conn():
    return pymysql.connect(
        **DB,
        cursorclass=pymysql.cursors.DictCursor
    )


def ensure_upload_column(conn):
    with conn.cursor() as cur:
        cur.execute("""
            ALTER TABLE observations
            ADD COLUMN IF NOT EXISTS phoca_uploaded_at DATETIME NULL
        """)
    conn.commit()


def protected_inbox_directories(inbox: Path) -> set[Path]:
    status_directory = inbox / ".status"
    manifest = status_directory / "phoca_categories.json"
    if not manifest.is_file():
        raise RuntimeError("Phoca category manifest is missing")

    with manifest.open(encoding="utf-8") as source:
        relative_paths = json.load(source)

    protected = {inbox, status_directory}
    for relative in relative_paths:
        path = (inbox / relative).resolve()
        try:
            path.relative_to(inbox)
        except ValueError as error:
            raise RuntimeError("Category manifest contains an invalid path") from error
        protected.add(path)
        protected.update(
            parent
            for parent in path.parents
            if parent == inbox or inbox in parent.parents
        )

    return protected


def remove_empty_source_directories(inbox: Path, paths: list[Path]) -> int:
    protected = protected_inbox_directories(inbox)
    candidates = set()

    for path in paths:
        parent = path.parent
        while parent != inbox and inbox in parent.parents:
            candidates.add(parent)
            parent = parent.parent

    removed = 0
    for directory in sorted(candidates, key=lambda value: len(value.parts), reverse=True):
        if directory in protected or not directory.is_dir() or any(directory.iterdir()):
            continue
        directory.rmdir()
        removed += 1

    return removed


def remove_all_empty_source_directories(inbox: Path) -> int:
    protected = protected_inbox_directories(inbox)
    candidates = []
    for directory in inbox.rglob("*"):
        if (
            directory.is_dir()
            and directory not in protected
            and directory != inbox
            and (inbox / ".status") not in directory.parents
        ):
            candidates.append(directory)

    removed = 0
    for directory in sorted(candidates, key=lambda value: len(value.parts), reverse=True):
        if not directory.is_dir() or any(directory.iterdir()):
            continue
        directory.rmdir()
        removed += 1
    return removed


@app.on_event("startup")
def startup():
    conn = get_conn()
    try:
        ensure_upload_column(conn)
    finally:
        conn.close()


@app.get("/")
def root():
    return {"status": "ok"}


@app.get("/import-status")
def import_status():
    inbox = Path(inbox_path())
    status_file = inbox / ".status" / "astroapi_import_status.json"
    watcher = {
        "phase": "unknown",
        "files": 0,
        "updated_at": None,
    }
    if status_file.is_file():
        try:
            with status_file.open(encoding="utf-8") as source:
                watcher = json.load(source)
        except (OSError, json.JSONDecodeError):
            watcher["phase"] = "unavailable"

    conn = get_conn()
    try:
        cur = conn.cursor()
        cur.execute(
            "SELECT COUNT(*) AS total FROM observations "
            "WHERE phoca_uploaded_at IS NULL"
        )
        pending = int(cur.fetchone()["total"])
        cur.execute(
            "SELECT MAX(phoca_uploaded_at) AS last_uploaded_at FROM observations"
        )
        uploaded = cur.fetchone()["last_uploaded_at"]
    finally:
        conn.close()

    return {
        "available": watcher.get("phase") not in {"unknown", "unavailable"},
        "watcher": watcher,
        "pending_observations": pending,
        "last_uploaded_at": uploaded,
        "server_time": datetime.now(timezone.utc).isoformat(),
    }


@app.get("/objects")
def objects(include_uploaded: bool = False):

    conn = get_conn()
    cur = conn.cursor()

    where = "" if include_uploaded else "WHERE o.phoca_uploaded_at IS NULL"
    cur.execute(f"""
        SELECT
            o.id,
            o.observation_key,
            o.target_name,
            o.observation_time,

            (
                SELECT file_path
                FROM observation_files f
                WHERE f.observation_id=o.id
                AND f.file_type='THUMBNAIL'
                LIMIT 1
            ) AS thumbnail

        FROM observations o
        {where}

        ORDER BY o.observation_time DESC
    """)

    rows = cur.fetchall()

    conn.close()

    return rows


@app.post("/observation/{obs_id}/phoca-uploaded")
async def confirm_phoca_upload(obs_id: int, request: Request):
    if request.client is None or request.client.host not in {"127.0.0.1", "::1"}:
        raise HTTPException(status_code=403, detail="Local confirmation required")

    inbox = INBOX_PATH.resolve()
    archive_root = Path("/var/www/joomla/images/phocagallery").resolve()
    raw_payload = await request.body()
    try:
        payload = json.loads(raw_payload or b"{}")
    except json.JSONDecodeError:
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    media_links = payload.get("media_links", {}) if isinstance(payload, dict) else {}
    if isinstance(media_links, list):
        media_links = {}
    if not isinstance(media_links, dict):
        raise HTTPException(status_code=400, detail="media_links must be an object")
    conn = get_conn()
    try:
        cur = conn.cursor()
        cur.execute(
            "SELECT id, file_path FROM observation_files WHERE observation_id=%s",
            (obs_id,),
        )
        rows = cur.fetchall()
        if not rows:
            raise HTTPException(status_code=404, detail="Observation has no files")

        paths = []
        for row in rows:
            path = Path(row["file_path"]).resolve()
            try:
                path.relative_to(inbox)
                paths.append(path)
            except ValueError:
                try:
                    path.relative_to(archive_root)
                except ValueError as error:
                    raise HTTPException(
                        status_code=400,
                        detail="Observation file is outside Inbox and Joomla archive",
                    ) from error

        archive_paths = {}
        for file_id, relative in media_links.items():
            archive = (archive_root / str(relative).lstrip("/")).resolve()
            try:
                archive.relative_to(archive_root)
            except ValueError as error:
                raise HTTPException(
                    status_code=400,
                    detail="Invalid Joomla archive path",
                ) from error
            if not archive.is_file():
                raise HTTPException(
                    status_code=409,
                    detail="Joomla archive copy is missing",
                )
            archive_paths[int(file_id)] = archive

        if len(archive_paths) != len(rows):
            raise HTTPException(
                status_code=409,
                detail="Not all observation files were archived",
            )

        for file_id, archive in archive_paths.items():
            cur.execute(
                "UPDATE observation_files SET file_path=%s WHERE id=%s AND observation_id=%s",
                (str(archive), file_id, obs_id),
            )

        for path in paths:
            if path.is_file() or path.is_symlink():
                path.unlink()

        removed_directories = remove_empty_source_directories(inbox, paths)
        removed_directories += remove_all_empty_source_directories(inbox)

        cur.execute(
            "UPDATE observations SET phoca_uploaded_at=NOW() WHERE id=%s",
            (obs_id,),
        )
        conn.commit()
        return {
            "id": obs_id,
            "removed_files": len(paths),
            "removed_directories": removed_directories,
        }
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

@app.get("/objects/{target_name}")
def objects_by_target(target_name: str):

    conn = get_conn()
    cur = conn.cursor()

    cur.execute("""
        SELECT
            o.id,
            o.observation_key,
            o.target_name,
            o.observation_time,

            (
                SELECT file_path
                FROM observation_files f
                WHERE f.observation_id = o.id
                AND f.file_type = 'THUMBNAIL'
                LIMIT 1
            ) AS thumbnail

        FROM observations o

        WHERE o.target_name = %s

        ORDER BY o.observation_time DESC
    """, (target_name,))

    rows = cur.fetchall()

    conn.close()

    return rows

@app.get("/observation/{obs_id}")
def observation(obs_id: int):

    conn = get_conn()
    cur = conn.cursor()

    cur.execute("""
        SELECT *
        FROM observations
        WHERE id=%s
    """, (obs_id,))

    obs = cur.fetchone()

    if not obs:
        conn.close()
        return {"error": "not found"}

    cur.execute("""
        SELECT
            id,
            file_type,
            file_name,
            file_path
        FROM observation_files
        WHERE observation_id=%s
        ORDER BY file_type
    """, (obs_id,))

    obs["files"] = cur.fetchall()

    conn.close()

    return obs


@app.get("/observation/{obs_id}/file/{file_id}")
def observation_file(obs_id: int, file_id: int):
    conn = get_conn()
    try:
        cur = conn.cursor()
        cur.execute("""
            SELECT file_path, file_name
            FROM observation_files
            WHERE id=%s AND observation_id=%s
        """, (file_id, obs_id))
        row = cur.fetchone()
    finally:
        conn.close()

    if not row or not Path(row["file_path"]).is_file():
        raise HTTPException(status_code=404, detail="Observation file not found")

    return FileResponse(
        row["file_path"],
        filename=row["file_name"],
    )


@app.get("/thumb/{obs_id}")
def thumb(obs_id: int):

    conn = get_conn()
    cur = conn.cursor()

    cur.execute("""
        SELECT file_path
        FROM observation_files
        WHERE observation_id=%s
        AND file_type='THUMBNAIL'
        LIMIT 1
    """, (obs_id,))

    row = cur.fetchone()

    conn.close()

    if not row:
        return {}

    return FileResponse(row["file_path"])


@app.get("/stack/{obs_id}")
def stack(obs_id: int):

    conn = get_conn()
    cur = conn.cursor()

    cur.execute("""
        SELECT file_path, file_name
        FROM observation_files
        WHERE observation_id=%s
        AND file_type != 'FITS'
    """, (obs_id,))

    rows = cur.fetchall()

    conn.close()

    displayable_extensions = {".jpg", ".jpeg", ".png", ".webp", ".avif", ".gif"}
    candidates = [
        Path(row["file_path"])
        for row in rows
        if Path(row["file_path"]).suffix.lower() in displayable_extensions
        and Path(row["file_path"]).is_file()
    ]

    if not candidates:
        return {}

    largest = max(candidates, key=lambda path: path.stat().st_size)

    return FileResponse(largest)


@app.get("/targets")
def targets():

    conn = get_conn()
    cur = conn.cursor()

    cur.execute("""
        SELECT
            target_name,
            COUNT(*) AS observations,
            MIN(observation_time) AS first_obs,
            MAX(observation_time) AS last_obs
        FROM observations
        WHERE target_name IS NOT NULL
        GROUP BY target_name
        ORDER BY target_name
    """)

    rows = cur.fetchall()

    conn.close()

    return rows

