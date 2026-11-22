import os
from dataclasses import dataclass
from pathlib import Path

try:
    from dotenv import load_dotenv
except ImportError:
    load_dotenv = None


MODULE_ROOT = Path(__file__).resolve().parent.parent


def load_environment_file(path: Path) -> None:
    if not path.is_file():
        return
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]
        os.environ.setdefault(key, value)


load_environment_file(MODULE_ROOT / ".env")
load_environment_file(Path("/etc/astroapi/astroapi.env"))


@dataclass(frozen=True)
class DatabaseConfig:
    host: str
    user: str
    password: str
    database: str
    port: int

    def as_dict(self) -> dict:
        return {
            "host": self.host,
            "user": self.user,
            "password": self.password,
            "database": self.database,
            "port": self.port,
        }


def database_config() -> DatabaseConfig:
    return DatabaseConfig(
        host=os.environ.get("ASTROAPI_DB_HOST", "localhost"),
        user=os.environ.get("ASTROAPI_DB_USER", "astroapi"),
        password=os.environ.get("ASTROAPI_DB_PASSWORD", ""),
        database=os.environ.get("ASTROAPI_DB_NAME", "astrophotos"),
        port=int(os.environ.get("ASTROAPI_DB_PORT", "3306")),
    )


def inbox_path() -> str:
    return os.environ.get("ASTROAPI_INBOX", "/srv/astrodata/inbox")
