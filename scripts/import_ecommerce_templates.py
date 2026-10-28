"""Validate reference images, normalize to JPEG, then enable their known templates."""

import argparse
from io import BytesIO
import json
import os
from pathlib import Path
import tempfile
import warnings

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parents[1]
EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}
MAX_BYTES = 30 * 1024 * 1024


def prepare_import(source: Path, root: Path = ROOT):
    source = source.resolve(strict=True)
    if not source.is_dir():
        raise ValueError("Source must be a directory")
    manifest = json.loads((root / "docs/ecommerce-template-pack/manifest.json").read_text(encoding="utf-8"))
    known = {item["id"]: item for item in manifest}
    catalog = json.loads((root / "app/resources/ecommerce_image_designs.json").read_text(encoding="utf-8"))
    prepared = {}
    for file in sorted(source.iterdir()):
        if file.suffix.lower() not in EXTENSIONS or not file.is_file():
            continue
        if not file.resolve().is_relative_to(source):
            raise ValueError(f"Image resolves outside source directory: {file.name}")
        ident = file.stem
        if ident not in known:
            raise ValueError(f"Unknown image name: {file.name}")
        if ident in prepared:
            raise ValueError(f"Duplicate template image: {ident}")
        if file.stat().st_size > MAX_BYTES:
            raise ValueError(f"Image exceeds 30 MB: {file.name}")
        spec = known[ident]
        style = catalog["styles"].get(ident)
        if not style or style.get("templatePack") != "commerce-v1" or style["purposes"] != [spec["purpose"]]:
            raise ValueError(f"Template catalog does not match manifest: {ident}")
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(file) as original:
                original.verify()
            with Image.open(file) as original:
                if original.format not in {"JPEG", "PNG", "WEBP"} or getattr(original, "n_frames", 1) != 1:
                    raise ValueError(f"Expected a static JPEG/PNG/WebP: {file.name}")
                image = ImageOps.exif_transpose(original)
                width, height = image.size
                expected = 1 if spec["aspect"] == "1:1" else 2 / 3
                if width < spec["minWidth"] or height < spec["minHeight"]:
                    raise ValueError(f"Image too small ({width}x{height}): {file.name}")
                if abs(width / height / expected - 1) > 0.03:
                    raise ValueError(f"Expected aspect {spec['aspect']}, got {width}x{height}: {file.name}")
                rgba = image.convert("RGBA")
                flattened = Image.new("RGB", rgba.size, "white")
                flattened.paste(rgba, mask=rgba.getchannel("A"))
                flattened.thumbnail((2048, 3072), Image.Resampling.LANCZOS)
                output = BytesIO()
                flattened.save(output, format="JPEG", quality=92, optimize=True)
        prepared[ident] = {"bytes": output.getvalue(), "width": width, "height": height}
    if not prepared:
        raise ValueError("No recognized template images found")
    return catalog, prepared


def atomic_write(path: Path, content: bytes):
    path.parent.mkdir(parents=True, exist_ok=True)
    name = None
    try:
        with tempfile.NamedTemporaryFile(dir=path.parent, prefix=".template-", delete=False) as staged:
            name = staged.name
            staged.write(content)
        os.replace(name, path)
    finally:
        if name and Path(name).exists():
            Path(name).unlink()


def apply_import(catalog, prepared, root: Path = ROOT, replace=False):
    source = root / "frontend/public/ecommerce-box/styles"
    deployed = root / "public/admin/ecommerce-box/styles"
    # Refuse accidental replacement and finish all validation before changing any file.
    for ident in prepared:
        for directory in [source, deployed]:
            if (directory / f"{ident}.jpg").exists() and not replace:
                raise ValueError(f"Reference already exists: {ident}; use --replace to overwrite")
    for ident, image in prepared.items():
        atomic_write(source / f"{ident}.jpg", image["bytes"])
        if (root / "public/admin/index.html").is_file():
            atomic_write(deployed / f"{ident}.jpg", image["bytes"])
        catalog["styles"][ident]["enabled"] = True
    # Activate last: a failed image copy never exposes an incomplete template.
    atomic_write(root / "app/resources/ecommerce_image_designs.json",
                 (json.dumps(catalog, ensure_ascii=False, indent=2) + "\n").encode("utf-8"))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path, help="Folder containing named template images")
    parser.add_argument("--apply", action="store_true", help="Copy images and enable validated templates")
    parser.add_argument("--replace", action="store_true", help="Allow replacing previously imported references")
    args = parser.parse_args()
    try:
        catalog, prepared = prepare_import(args.source)
        for ident, image in prepared.items():
            print(f"OK {ident}.jpg ({image['width']}x{image['height']})")
        if args.apply:
            apply_import(catalog, prepared, replace=args.replace)
            print("Imported. Rebuild frontend (npm run build) and restart PHP/ecommerce workers before verification.")
        else:
            print("Validation only. Add --apply to import; no files changed.")
    except (ValueError, OSError, Image.DecompressionBombWarning, Image.DecompressionBombError) as error:
        parser.exit(1, f"Import failed: {error}\n")


if __name__ == "__main__":
    main()
