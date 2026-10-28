import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

from PIL import Image

spec = importlib.util.spec_from_file_location("template_import", Path(__file__).with_name("import_ecommerce_templates.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TemplateImportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.images = self.root / "incoming"
        self.images.mkdir()
        for path in ["docs/ecommerce-template-pack", "app/resources", "public/admin"]:
            (self.root / path).mkdir(parents=True)
        for relative in ["docs/ecommerce-template-pack/manifest.json", "app/resources/ecommerce_image_designs.json"]:
            (self.root / relative).write_bytes((module.ROOT / relative).read_bytes())
        # Import tests start from an unactivated catalog, regardless of deployed state.
        catalog_path = self.root / "app/resources/ecommerce_image_designs.json"
        catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
        for style in catalog["styles"].values():
            if style.get("templatePack") == "commerce-v1":
                style["enabled"] = False
        catalog_path.write_text(json.dumps(catalog, ensure_ascii=False), encoding="utf-8")
        (self.root / "public/admin/index.html").write_text("fixture")

    def image(self, ident="main-beauty-podium", size=(1024, 1024), extension="png"):
        path = self.images / f"{ident}.{extension}"
        Image.new("RGB", size, "#f3e6d1").save(path)
        return path

    def test_validates_without_writing_then_enables_only_imported_styles(self):
        self.image()
        self.image("detail-tech-grid", (1024, 1536))
        catalog, prepared = module.prepare_import(self.images, self.root)
        self.assertFalse(catalog["styles"]["main-beauty-podium"]["enabled"])
        module.apply_import(catalog, prepared, self.root)
        saved = json.loads((self.root / "app/resources/ecommerce_image_designs.json").read_text(encoding="utf-8"))
        self.assertTrue(saved["styles"]["main-beauty-podium"]["enabled"])
        self.assertTrue(saved["styles"]["detail-tech-grid"]["enabled"])
        self.assertFalse(saved["styles"]["main-food-table"]["enabled"])
        for prefix in ["frontend/public", "public/admin"]:
            with Image.open(self.root / prefix / "ecommerce-box/styles/detail-tech-grid.jpg") as image:
                self.assertEqual(image.format, "JPEG")
                self.assertEqual(image.size, (1024, 1536))
        with self.assertRaisesRegex(ValueError, "already exists"):
            module.apply_import(catalog, prepared, self.root)

    def test_wrong_ratio_and_small_or_unknown_images_are_rejected(self):
        for ident, size, message in [("main-beauty-podium", (1024, 1536), "aspect"),
                                      ("main-food-table", (512, 512), "too small"),
                                      ("unknown", (1024, 1024), "Unknown")]:
            with self.subTest(ident=ident):
                path = self.image(ident, size)
                with self.assertRaisesRegex(ValueError, message):
                    module.prepare_import(self.images, self.root)
                path.unlink()

    def test_duplicates_and_corrupt_files_are_rejected_before_activation(self):
        self.image()
        duplicate = self.image(extension="webp")
        with self.assertRaisesRegex(ValueError, "Duplicate"):
            module.prepare_import(self.images, self.root)
        duplicate.unlink()
        (self.images / "main-food-table.png").write_bytes(b"not an image")
        with self.assertRaises(OSError):
            module.prepare_import(self.images, self.root)
        self.assertFalse((self.root / "frontend/public/ecommerce-box/styles").exists())


if __name__ == "__main__":
    unittest.main()
