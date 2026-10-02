"""Local-only benchmark. Original private images remain at their source paths."""
from pathlib import Path
import argparse
import hashlib
import html
import json
import os
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument("--source-dir", type=Path, required=True)
parser.add_argument("--output-dir", type=Path, required=True)
parser.add_argument("--php", type=Path, required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
files = sorted(args.source_dir.glob("*.jpg"))
if len(files) < 12:
    raise ValueError("At least twelve existing JPEGs required")
selected = [files[round(i * (len(files) - 1) / 11)] for i in range(12)]
index = [{"id": f"B{i+1:02d}", "source": str(file), "sha256": hashlib.sha256(file.read_bytes()).hexdigest()} for i, file in enumerate(selected)]
index_path = root / ".runtime/benchmark-input.json"
index_path.write_text(json.dumps(index), encoding="utf-8")
args.output_dir.mkdir(parents=True, exist_ok=True)
command = [str(args.php), "-d", f"extension_dir={args.php.parent / 'ext'}", "-d", "extension=gd", "-d", "extension=exif", "-d", "extension=mbstring", "-d", "extension=pdo_sqlite", "-d", "extension=mysqli", "-d", "memory_limit=512M", str(root / "tests/benchmark.php"), str(index_path), str(args.output_dir)]
subprocess.run(command, check=True)
results = json.loads((root / "reports/final/benchmark-results.json").read_text())
sections = []
for source, row in zip(index, results):
    if hashlib.sha256(Path(source["source"]).read_bytes()).hexdigest() != source["sha256"]:
        raise ValueError("Source master changed")
    images = [("MASTER (source locale, non copiee)", Path(source["source"]))]
    if row["status"] == "success":
        images += [("THUMB 640 / Q80", args.output_dir / f'{row["id"]}-thumb.jpg'), ("VIEW 2048 / Q90", args.output_dir / f'{row["id"]}-view.jpg'), ("WordPress natif / VIEW", args.output_dir / f'{row["id"]}-native-view.jpg')]
    media = "".join(f'<figure><a href="{html.escape(file.as_uri())}"><img src="{html.escape(file.as_uri())}" loading="lazy" alt="{html.escape(row["id"] + " " + label)}"></a><figcaption>{html.escape(label)}</figcaption></figure>' for label, file in images)
    sections.append(f'<section><h2>{row["id"]} <small>{html.escape(row["status"])}</small></h2><div class="media">{media}</div><pre>{html.escape(json.dumps(row, indent=2))}</pre></section>')
page = '''<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP Seed Pixel - benchmark local</title><style>body{font:16px system-ui;margin:0;color:#24262a;background:#fafafa}header,main{max-width:1240px;margin:auto;padding:24px}header{border-bottom:4px solid #34745c}h1{font-size:26px}h2{font-size:20px}small{color:#34745c}section{padding:24px 0;border-bottom:1px solid #bbc1c5}.media{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}figure{margin:0}img{width:100%;height:340px;object-fit:contain;background:#e3e7e9}figcaption{padding:8px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px}a:focus-visible{outline:3px solid #b7294b}@media(max-width:600px){.media{grid-template-columns:1fr}img{height:260px}header,main{padding:16px}}</style><header><h1>WP Seed Pixel 0.1.0</h1><p>Benchmark prive local. Douze sources, masters conserves. Comparaison WordPress aux memes dimensions, avec sa qualite effective. HUMAN PASS requis ; les taux de compression ne certifient pas la fidelite visuelle.</p></header><main>''' + "".join(sections) + "</main></html>"
(root / "reports/final/WP-SEED-PIXEL-VISUAL-BENCHMARK.html").write_text(page, encoding="utf-8")
index_path.unlink()
print("Visual benchmark prepared locally; all twelve source SHA-256 values unchanged.")
