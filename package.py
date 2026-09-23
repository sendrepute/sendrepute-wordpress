"""Build the installable plugin, with a deterministic allowlisted ZIP."""
from pathlib import Path
import zipfile

root = Path(__file__).resolve().parent
target = root / "dist" / "sendrepute-0.2.0.zip"
target.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
    for file in sorted((root / "sendrepute").rglob("*")):
        if file.is_file() and file.suffix in {".php", ".txt"}:
            info = zipfile.ZipInfo(file.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
            info.external_attr = 0o644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, file.read_bytes())
print(target.relative_to(Path.cwd()))