"""Offline checks for the ephemeral harness guards; no site credentials loaded."""
import base64
import json
from pathlib import Path
import re
import runpy
import subprocess

root = Path(__file__).resolve().parents[1]
gate = runpy.run_path(str(root / "tests/m51-host-gate.py"), run_name="harness")
rendered = gate["render"](root, "0" * 64, "a" * 32, 2000000000, "/synthetic/www", "/synthetic", "synthetic.invalid")
checks = {"magic_dir_preserved": b"__DIR__" in rendered, "placeholders_resolved": set(re.findall(rb"__[A-Z_]+__", rendered)) <= {b"__DIR__"},
          "synthetic_table_namespace": b"m51_" + b"a" * 32 + b"_" in rendered}
lint = subprocess.run(["wsl.exe", "--", "bash", "/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh", "-l"], input=rendered, capture_output=True, timeout=30)
checks["rendered_php_lint"] = lint.returncode == 0 and b"No syntax errors" in lint.stdout
template = (root / "tests/m51-host-endpoint.php.in").read_text()
expression = re.search(r"m51_assert\(preg_match\((.+), \$sql\), 'write_scope'\);", template).group(1)
table = "m51_" + "a" * 32 + "_seed_pixel_items"
cases = {"drop_exact": [f"DROP TABLE {table}", True], "drop_if_exists": [f"DROP TABLE IF EXISTS {table}", True],
         "update_exact": [f"UPDATE {table} SET stage='queued'", True], "insert_exact": [f"INSERT INTO {table} (id) VALUES (1)", True],
         "create_exact": [f"CREATE TABLE {table} (id int)", True], "wordpress_update_denied": ["UPDATE wp_options SET option_value='x'", False],
         "suffix_table_denied": [f"DROP TABLE {table}_other", False], "prefix_table_denied": ["DROP TABLE wp_seed_pixel_items", False]}
php = "<?php\n$table=preg_quote('" + table + "','/'); $cases=json_decode(base64_decode('" + base64.b64encode(json.dumps(cases).encode()).decode() + "'),true); $out=array(); foreach($cases as $name=>$case){$sql=$case[0];$out[$name]=((bool)preg_match(" + expression + ",$sql))===$case[1];} echo json_encode($out);"
run = subprocess.run(["wsl.exe", "--", "bash", "/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh"], input=php.encode(), capture_output=True, timeout=30)
assert run.returncode == 0, "PHP scope test failed"
checks.update(json.loads(run.stdout))
assert all(checks.values()), "Harness guard regression"
(root / "reports/storage-m5.1/authority-gate/harness.json").write_text(json.dumps({"checks": checks}, indent=2) + "\n", encoding="utf-8")
print(str(len(checks)) + " offline harness checks PASS")
