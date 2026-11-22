import os
import json
import hashlib
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
import urllib.parse
import urllib.request


INBOX = Path(os.environ.get("ASTROAPI_INBOX", "/srv/astrodata/inbox"))
POLL_SECONDS = float(os.environ.get("ASTROAPI_POLL_SECONDS", "2"))
QUIET_SECONDS = float(os.environ.get("ASTROAPI_QUIET_SECONDS", "60"))
VERIFY_SECONDS = float(os.environ.get("ASTROAPI_VERIFY_SECONDS", "5"))
IMPORTER = Path(__file__).with_name("import_fits.py")
PHOCA_SYNC_URL = os.environ.get(
    "ASTROAPI_PHOCA_SYNC_URL",
    "http://127.0.0.1/index.php",
)
PHOCA_SYNC_SECRET = os.environ.get("ASTROAPI_PHOCA_SYNC_SECRET", "")
STATUS_DIRECTORY = INBOX / ".status"
STATUS_DIRECTORY.mkdir(parents=True, exist_ok=True)
STATE_FILE = STATUS_DIRECTORY / "astroapi_import_status.json"
IMPORTED_STATE = STATUS_DIRECTORY / "astroapi_imported_snapshot.json"


def snapshot() -> dict[Path, tuple[int, int]]:
    files: dict[Path, tuple[int, int]] = {}
    if not INBOX.exists():
        return files

    for directory, subdirectories, filenames in os.walk(INBOX):
        if str(Path(directory).resolve()) == str(STATUS_DIRECTORY.resolve()):
            subdirectories[:] = []
            continue
        subdirectories[:] = [
            name for name in subdirectories
            if name != STATUS_DIRECTORY.name
        ]
        for filename in filenames:
            path = Path(directory, filename)
            try:
                if (
                    path.is_file()
                    and not path.is_symlink()
                ):
                    stat = path.stat()
                    files[path] = (stat.st_size, stat.st_mtime_ns)
            except FileNotFoundError:
                continue

    return files


def write_status(phase: str, files: int, **details) -> None:
    state = {
        "phase": phase,
        "files": files,
        "updated_at": datetime.now(timezone.utc).isoformat(),
        **details,
    }
    temporary = STATE_FILE.with_suffix(".tmp")
    temporary.write_text(
        json.dumps(state, ensure_ascii=False),
        encoding="utf-8",
    )
    temporary.replace(STATE_FILE)


def snapshot_hash(files: dict[Path, tuple[int, int]]) -> str:
    digest = hashlib.sha256()
    for path, (size, modified) in sorted(files.items()):
        digest.update(str(path.relative_to(INBOX)).encode("utf-8"))
        digest.update(f":{size}:{modified}\n".encode("ascii"))
    return digest.hexdigest()


def already_imported(files: dict[Path, tuple[int, int]]) -> bool:
    try:
        with IMPORTED_STATE.open(encoding="utf-8") as source:
            state = json.load(source)
        return state.get("snapshot") == snapshot_hash(files)
    except (OSError, json.JSONDecodeError):
        return False


def record_imported(files: dict[Path, tuple[int, int]]) -> None:
    temporary = IMPORTED_STATE.with_suffix(".tmp")
    temporary.write_text(
        json.dumps({"snapshot": snapshot_hash(files)}, ensure_ascii=False),
        encoding="utf-8",
    )
    temporary.replace(IMPORTED_STATE)


def sync_phoca() -> None:
    if not PHOCA_SYNC_SECRET:
        raise RuntimeError("ASTROAPI_PHOCA_SYNC_SECRET is not configured")

    query = urllib.parse.urlencode(
        {
            "option": "com_phocagallery",
            "sync_astro": "1",
            "sync_secret": PHOCA_SYNC_SECRET,
            "tmpl": "component",
        }
    )
    request = urllib.request.Request(
        PHOCA_SYNC_URL + "?" + query,
        data=b"",
        method="POST",
        headers={
            "Host": "server.ww.minixhofer.com",
            "X-Requested-With": "AstroInbox",
        },
    )
    with urllib.request.urlopen(request, timeout=300) as response:
        body = response.read()
        if response.status != 200:
            raise RuntimeError(f"Phoca sync returned HTTP {response.status}")
        if b"AstroAPI Phoca sync failed" in body:
            raise RuntimeError("Phoca reported a synchronization failure")


def main() -> int:
    previous = snapshot()
    last_change = time.monotonic()
    last_failure = 0.0
    last_imported_at = None
    needs_import = bool(previous)
    write_status("idle", len(previous), last_imported_at=last_imported_at)
    print(
        f"Watching {INBOX}: quiet={QUIET_SECONDS:g}s, "
        f"verify={VERIFY_SECONDS:g}s, poll={POLL_SECONDS:g}s",
        flush=True,
    )

    while True:
        time.sleep(POLL_SECONDS)
        current = snapshot()
        now = time.monotonic()

        if current != previous:
            previous = current
            last_change = now
            needs_import = bool(current)
            if current:
                write_status(
                    "uploading",
                    len(current),
                    quiet_seconds=QUIET_SECONDS,
                    last_imported_at=last_imported_at,
                )
            else:
                write_status("idle", 0, last_imported_at=last_imported_at)
            continue

        if not current or not needs_import:
            continue

        elapsed = now - last_change
        if elapsed < QUIET_SECONDS:
            write_status(
                "waiting",
                len(current),
                remaining_seconds=round(max(0, QUIET_SECONDS - elapsed), 1),
                last_imported_at=last_imported_at,
            )
            continue

        write_status(
            "verifying",
            len(current),
            last_imported_at=last_imported_at,
        )
        time.sleep(VERIFY_SECONDS)
        verified = snapshot()
        if verified != current:
            previous = verified
            last_change = time.monotonic()
            continue

        if now - last_failure < QUIET_SECONDS:
            continue

        write_status(
            "importing",
            len(current),
            last_imported_at=last_imported_at,
        )
        print(f"Inbox stable; importing {len(current)} file(s).", flush=True)
        result = subprocess.run(
            [sys.executable, str(IMPORTER)],
            cwd=str(IMPORTER.parent),
            check=False,
        )

        if result.returncode != 0:
            last_failure = time.monotonic()
            write_status(
                "error",
                len(current),
                last_error=f"Importer exited with code {result.returncode}",
                last_imported_at=last_imported_at,
            )
            print(
                f"Import failed with exit code {result.returncode}; retry deferred.",
                file=sys.stderr,
                flush=True,
            )

        else:
            last_imported_at = datetime.now(timezone.utc).isoformat()
            record_imported(current)
            write_status(
                "syncing_phoca",
                len(current),
                last_imported_at=last_imported_at,
            )
            try:
                sync_phoca()
                needs_import = False
            except Exception as error:
                last_failure = time.monotonic()
                write_status(
                    "sync_error",
                    len(current),
                    last_error=str(error),
                    last_imported_at=last_imported_at,
                )
                print(f"Phoca sync failed: {error}", file=sys.stderr, flush=True)
                needs_import = True
                continue

        previous = snapshot()
        last_change = time.monotonic()
        write_status(
            "idle",
            len(previous),
            last_imported_at=last_imported_at,
        )


if __name__ == "__main__":
    raise SystemExit(main())
