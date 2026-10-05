"""One authorized photographic upload; frozen Pixel runtime and scoped rollback."""
import argparse
import base64
from datetime import datetime, timezone
import ftplib
import hashlib
import io
import json
from pathlib import Path
import re
import secrets
import socket
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

from PIL import Image


ROOT = Path(r"C:\Dev\git\wp-seed-pixel")
CONFIG = Path(r"C:\Dev\git\therapsycorporel-site\.env")
OUT = ROOT / "reports/storage-m5.1/first-real-pilot"
ZIP = OUT / "wp-seed-pixel-m51-runtime-pilot.zip"
SOURCE = OUT / "IMAGE-A-ORIGINAL.jpg"
SITE = "https://therapsycorporel.fr"
SOURCE_SHA = "f0ee8da961dc4c49446e4f93d8e86a1d61ae0e765c39b41d90ee6de3774e655b"
ZIP_SHA = "e2f3b5b0b41d9ee01960fd36ffda8aa605022a97ebf3cb93c569aa4e6c3dc947"
UA = "WP-Seed-Pixel-M5.1-One-JPEG/1.0"


def require(value, code):
    if not value:
        raise RuntimeError(code)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def configuration():
    cfg = {}
    for line in CONFIG.read_text(encoding="utf-8-sig").splitlines():
        match = re.match(r"^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$", line)
        if match:
            cfg[match[1]] = match[2].strip().strip('"').strip("'")
    for key in ("WP_URL", "WP_USER", "WP_APP_PASSWORD", "SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD"):
        require(cfg.get(key), "CANONICAL_CONFIG_MISSING")
    require(cfg["WP_URL"].rstrip("/") == SITE, "AUTHORIZED_SITE_ONLY")
    return cfg


def frozen_gate():
    require(Path(__file__).resolve().parents[1] == ROOT, "OWNED_PIXEL_CHECKOUT")
    manifest = json.loads((OUT / "runtime-package-manifest.json").read_text())
    require(manifest["runtime_frozen"] and manifest["file_count"] == 49, "RUNTIME_COUNT")
    require(ZIP.stat().st_size == 149020 and digest(ZIP.read_bytes()) == ZIP_SHA, "ZIP_IDENTITY")
    with zipfile.ZipFile(ZIP) as archive:
        require(archive.testzip() is None and len([e for e in archive.infolist() if not e.is_dir()]) == 49, "ZIP_CRC")
        for entry in manifest["files"]:
            require(digest((ROOT / entry["file"]).read_bytes()) == entry["sha256"], "RUNTIME_DIVERGENCE")
            require(digest(archive.read("wp-seed-pixel/" + entry["file"])) == entry["sha256"], "ZIP_MANIFEST")
    require(digest(SOURCE.read_bytes()) == SOURCE_SHA and SOURCE.stat().st_size == 1957818, "EXACT_SOURCE_A")
    checks = 0
    for name in ("schema-cas", "locks", "uploads-budget", "quarantine", "crash", "local-photo"):
        evidence = json.loads((OUT / "local-gate" / (name + ".json")).read_text())
        require(evidence["checks"] and all(evidence["checks"].values()), "LOCAL_GATE_FAILED")
        checks += len(evidence["checks"])
    require(checks == 146, "LOCAL_GATE_COUNT")
    run = subprocess.run(["git", "diff", "--cached", "--name-only"], cwd=ROOT, capture_output=True, check=True)
    require(not run.stdout, "INDEX_NOT_EMPTY")
    run = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, check=True)
    require(run.stdout.decode().strip() == "3da4435591488e6089b827c7b581c706e1e4a034", "HEAD_CHANGED")
    return manifest


def render(manifest, cfg, nonce, token, deadline):
    replacements = {
        "__NONCE__": nonce, "__TOKEN_HASH__": digest(token.encode()), "__DEADLINE__": str(deadline),
        "__ZIP_SHA__": ZIP_SHA, "__IMAGE_SHA__": SOURCE_SHA,
        "__ACTOR__": cfg["WP_USER"].replace("\\", "\\\\").replace("'", "\\'"),
        "__MANIFEST__": base64.b64encode(json.dumps(manifest["files"], separators=(",", ":")).encode()).decode(),
    }
    guard = (ROOT / "tests/m51-first-pilot-guard.php.in").read_text(encoding="utf-8")
    for key, value in replacements.items():
        guard = guard.replace(key, value)
    replacements["__GUARD__"] = base64.b64encode(guard.encode()).decode()
    endpoint = (ROOT / "tests/m51-first-pilot-endpoint.php.in").read_text(encoding="utf-8")
    for key, value in replacements.items():
        endpoint = endpoint.replace(key, value)
    require(not re.search(r"__(?:NONCE|TOKEN_HASH|DEADLINE|ACTOR|MANIFEST|ZIP_SHA|IMAGE_SHA|GUARD)__", endpoint), "UNRENDERED_PLACEHOLDER")
    for php in (guard, endpoint):
        lint = subprocess.run(["wsl.exe", "--", "php", "-l"], input=php.encode(), capture_output=True)
        require(lint.returncode == 0, "HARNESS_PHP_LINT")
    return guard.encode(), endpoint.encode()


class Pilot:
    def __init__(self, cfg, nonce, token, endpoint):
        self.cfg = cfg
        self.nonce = nonce
        self.token = token
        self.endpoint = endpoint
        self.ftp = None
        self.remote_name = "/www/pixel-one-pilot-" + nonce + ".php"
        self.url = SITE + "/pixel-one-pilot-" + nonce + ".php"
        self.headers = {"User-Agent": UA, "Cache-Control": "no-cache"}
        self.context = ssl.create_default_context()
        self.evidence = {"utc": datetime.now(timezone.utc).isoformat(), "site": SITE,
                         "human_baseline_bytes": 624000000, "ceiling_bytes": 700000000,
                         "provider_quota_bytes": 1000000000, "storage_scope": "human OVH baseline plus live pilot-owned additions",
                         "network_requests": 0, "actions": [], "verdict": "BLOCKED"}
        self.auth = "Basic " + base64.b64encode((cfg["WP_USER"] + ":" + cfg["WP_APP_PASSWORD"]).encode()).decode()
        self.uploaded_endpoint = False
        self.baseline = False
        self.install_attempted = False
        self.success = False
        self.attachment = 0

    def http(self, url, method="GET", data=None, headers=None, timeout=35, expected=200):
        require(urllib.parse.urlsplit(url).netloc == "therapsycorporel.fr", "HTTP_SITE_SCOPE")
        self.evidence["network_requests"] += 1
        request = urllib.request.Request(url, data=data, method=method, headers={**self.headers, **(headers or {})})
        try:
            with urllib.request.urlopen(request, timeout=timeout, context=self.context) as response:
                require(response.status == expected, "HTTP_STATUS")
                require(urllib.parse.urlsplit(response.url).netloc == "therapsycorporel.fr", "HTTP_REDIRECT_SCOPE")
                body = response.read(8000001)
                require(len(body) <= 8000000, "HTTP_RESPONSE_BOUND")
                return body, dict(response.headers)
        except urllib.error.HTTPError as error:
            body = error.read(65536)
            if error.code == expected:
                return body, dict(error.headers)
            try:
                code = json.loads(body).get("code", "HTTP_" + str(error.code))
            except (ValueError, AttributeError):
                code = "HTTP_" + str(error.code)
            require(isinstance(code, str) and re.fullmatch(r"[A-Za-z0-9_-]{1,80}", code), "HTTP_UNCLASSIFIED_FAILURE")
            raise RuntimeError(code) from None

    def preflight(self):
        for attempt in range(2):
            try:
                self.http(SITE + "/", timeout=25)
                self.evidence["https_preflight"] = "PASS 200; TLS verified"
                return
            except (TimeoutError, socket.timeout):
                if attempt:
                    raise RuntimeError("HTTPS_TWO_TIMEOUTS") from None
                time.sleep(15)
            except urllib.error.URLError as error:
                if isinstance(error.reason, (TimeoutError, socket.timeout)) and not attempt:
                    time.sleep(15)
                    continue
                raise RuntimeError("HTTPS_TRANSPORT_FAILURE") from None

    def connect(self):
        ftp = ftplib.FTP_TLS(context=self.context, timeout=25)
        self.ftp = ftp
        ftp.connect(self.cfg["SFTP_HOST"], 21)
        try:
            ftp.auth()
            ftplib.FTP.login(ftp, self.cfg["SFTP_USER"], self.cfg["SFTP_PASSWORD"])
            ftp.prot_p()
            self.evidence["transport"] = "FTPS"
        except ftplib.error_perm as error:
            if not str(error).startswith(("500", "502")) or isinstance(ftp.sock, ssl.SSLSocket):
                raise RuntimeError("FTPS_AUTH_FAILURE") from None
            ftplib.FTP.login(ftp, self.cfg["SFTP_USER"], self.cfg["SFTP_PASSWORD"])
            self.evidence["transport"] = "FTP established project mechanism; TLS unsupported"

    def upload_endpoint(self):
        self.ftp.storbinary("STOR " + self.remote_name, io.BytesIO(self.endpoint))
        self.uploaded_endpoint = True
        got = io.BytesIO()
        self.ftp.retrbinary("RETR " + self.remote_name, got.write)
        require(digest(got.getvalue()) == digest(self.endpoint), "ENDPOINT_READBACK")
        _, headers = self.http(self.url, expected=404)
        require("no-store" in headers.get("Cache-Control", ""), "ENDPOINT_ACCESS_CONTROL")

    def action(self, name, **values):
        body, _ = self.http(self.url, method="POST", data=json.dumps({"action": name, **values}).encode(),
                            headers={"Content-Type": "application/json", "X-Pixel-Preflight": self.token}, timeout=150)
        result = json.loads(body)
        require(result.get("ok") is True, "HARNESS_ACTION_FAILED")
        self.evidence["actions"].append(name)
        self.evidence[name] = result["result"]
        return result["result"]

    def upload_image(self):
        filename = "pixel-one-disposable-" + self.nonce + ".jpg"
        boundary = "pixel-" + secrets.token_hex(16)
        parts = []
        for field, value in (("title", "Pixel disposable photographic pilot"), ("alt_text", "Glass, citrus fruit and green linen in daylight")):
            parts.append(("--" + boundary + '\r\nContent-Disposition: form-data; name="' + field + '"\r\n\r\n' + value + "\r\n").encode())
        parts.append(("--" + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="' + filename + '"\r\nContent-Type: image/jpeg\r\n\r\n').encode())
        parts.extend((SOURCE.read_bytes(), ("\r\n--" + boundary + "--\r\n").encode()))
        body, _ = self.http(SITE + "/wp-json/wp/v2/media", method="POST", data=b"".join(parts), expected=201,
                            headers={"Authorization": self.auth, "X-Pixel-Preflight": self.token,
                                     "Content-Type": "multipart/form-data; boundary=" + boundary}, timeout=150)
        data = json.loads(body)
        self.attachment = int(data["id"])
        self.evidence["upload"] = {"id": self.attachment, "source_url": data["source_url"], "media_details": data["media_details"]}
        return self.attachment

    def qa_http(self, qa):
        body, headers = self.http(qa["media_url"])
        require(digest(body) == qa["candidate"]["sha256"] and len(body) == qa["candidate"]["bytes"], "PUBLIC_MASTER_SHA")
        require(headers.get("Content-Type", "").split(";")[0] == "image/jpeg", "PUBLIC_MASTER_MIME")
        with Image.open(io.BytesIO(body)) as image:
            image.load()
            require(image.size == (1536, 1024), "PUBLIC_MASTER_DIMENSIONS")
            info = {"dimensions": list(image.size), "icc_bytes": len(image.info.get("icc_profile", b"")), "orientation": image.getexif().get(274, 1)}
        require(info["orientation"] == 1, "PUBLIC_ORIENTATION")
        target = OUT / "IMAGE-B-PIXEL-OPTIMIZED.jpg"
        target.write_bytes(body)
        self.evidence["image_b"] = {"file": str(target), "sha256": digest(body), "bytes": len(body), **info}
        readback, _ = self.http(SITE + "/wp-json/wp/v2/media/" + str(self.attachment), headers={"Authorization": self.auth})
        rest = json.loads(readback)
        require(rest["id"] == self.attachment and rest["source_url"] == qa["media_url"], "REST_IDENTITY")
        require(rest["media_details"]["width"] == 1536 and rest["media_details"]["height"] == 1024, "REST_DIMENSIONS")
        self.evidence["rest"] = {"status": "PASS", "metadata": rest["media_details"]}
        native = self.evidence["native"]["before"]
        checked = []
        base_url = qa["media_url"].rsplit("/", 1)[0] + "/"
        for size in qa["metadata"].get("sizes", {}).values():
            require(Path(size["file"]).name == size["file"], "SIZE_FILENAME_SCOPE")
            relative = native["relative"].rsplit("/", 1)[0] + "/" + size["file"]
            payload, _ = self.http(base_url + urllib.parse.quote(size["file"]))
            require(digest(payload) == native["files"][relative]["sha256"], "DERIVATIVE_CHANGED")
            with Image.open(io.BytesIO(payload)) as image:
                image.load()
                require(image.size == (size["width"], size["height"]), "DERIVATIVE_DIMENSIONS")
            checked.append({"file": size["file"], "bytes": len(payload), "sha256": digest(payload)})
        require(qa["srcset"] and checked, "SRCSET_MISSING")
        self.evidence["srcset"] = {"status": "PASS", "checked": checked, "value": qa["srcset"]}
        repeat, _ = self.http(qa["media_url"])
        require(digest(repeat) == digest(body), "MASTER_CACHE_STALE")
        self.evidence["cache"] = "Repeated canonical GET matches retained master; no purge"
        self.http(SITE + "/")
        self.evidence["site_health"] = "HTTPS homepage 200; REST media and canonical JPEG 200; no exhaustive audit"
        self.evidence["media_library"] = "Native attachment and Media REST verified; privileged admin UI not tested"

    def run(self):
        self.preflight()
        self.connect()
        self.upload_endpoint()
        self.action("baseline")
        self.baseline = True
        self.ftp.storbinary("STOR /.pixel-one-jpeg-" + self.nonce + "/candidate.zip", io.BytesIO(ZIP.read_bytes()))
        self.install_attempted = True
        self.action("install")
        self.action("activate")
        self.action("configure")
        self.upload_image()
        native = self.action("native", id=self.attachment)
        require(native["marker"] and native["marker"]["job_id"] == 0, "NATIVE_PROVENANCE_NOT_PENDING")
        require(native["extra_peak"] == 37191400, "PEAK_CHANGED")
        for attempt in range(3):
            result = self.action("process", id=self.attachment)
            if result["job"]["status"] in ("completed", "completed_errors", "failed_systemic", "paused", "cancelled"):
                break
        require(result["job"]["status"] == "completed", "PILOT_JOB_NOT_COMPLETED")
        qa = self.action("qa", id=self.attachment)
        self.qa_http(qa)
        self.action("off", id=self.attachment)
        self.action("clean")
        self.success = True
        self.evidence["verdict"] = "READY FOR HUMAN PASS"

    def cleanup(self):
        if not self.success and self.install_attempted:
            try:
                self.action("rollback")
                self.evidence["verdict"] = "ROLLED BACK"
            except Exception as error:
                self.evidence["rollback_failure"] = str(error) if type(error) is RuntimeError else type(error).__name__
        if self.uploaded_endpoint:
            try:
                self.ftp.delete(self.remote_name)
                try:
                    self.ftp.size(self.remote_name)
                    raise RuntimeError("ENDPOINT_STILL_PRESENT")
                except ftplib.error_perm as error:
                    require(str(error).startswith("550"), "ENDPOINT_ABSENCE_UNCERTAIN")
                self.http(self.url, expected=404)
                self.evidence["temporary_endpoint"] = "PHYSICALLY ABSENT"
            except Exception as error:
                self.evidence["cleanup_failure"] = str(error) if type(error) is RuntimeError else type(error).__name__
        if self.ftp:
            try:
                self.ftp.quit()
            except Exception:
                self.ftp.close()
        self.evidence["ftp_closed"] = True
        self.evidence["askpass"] = "NOT USED"
        self.token = self.auth = ""
        self.cfg.clear()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--run", action="store_true")
    args = parser.parse_args()
    manifest = frozen_gate()
    previous = OUT / "pixel-only-pilot.json"
    require(not previous.exists(), "EXISTING_PILOT_RESULT_REQUIRES_REVIEW_NOT_RERUN")
    cfg = configuration()
    nonce = secrets.token_hex(12)
    token = secrets.token_hex(48)
    guard, endpoint = render(manifest, cfg, nonce, token, int(time.time()) + 7200)
    if not args.run:
        cfg.clear()
        print("Frozen 146-check gate, exact A/runtime and both PHP harness templates PASS. No network.")
        return 0
    pilot = Pilot(cfg, nonce, token, endpoint)
    try:
        pilot.run()
    except Exception as error:
        pilot.evidence["failure"] = str(error) if type(error) is RuntimeError else type(error).__name__
    finally:
        pilot.cleanup()
        for key in ("WP_APP_PASSWORD", "SFTP_PASSWORD", "SQL_PASSWORD"):
            cfg.pop(key, None)
        previous.write_text(json.dumps(pilot.evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"verdict": pilot.evidence["verdict"], "attachment": pilot.attachment,
                      "failure": pilot.evidence.get("failure"), "rollback": pilot.evidence.get("rollback_failure"),
                      "cleanup": pilot.evidence.get("temporary_endpoint"), "cleanup_failure": pilot.evidence.get("cleanup_failure")}))
    return 0 if pilot.success and not pilot.evidence.get("cleanup_failure") else 1


if __name__ == "__main__":
    raise SystemExit(main())
