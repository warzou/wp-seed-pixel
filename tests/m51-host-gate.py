"""Bounded, ephemeral FTP(S)/HTTPS authority gate; credentials stay in memory."""
import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
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

EXPECTED = {"schema-cas.json": 29, "locks.json": 28, "independent.json": 3,
            "m1-prerequisite.json": 29, "m2-integration.json": 53,
            "m2-faults.json": 24, "m5-mixed.json": 18, "m5-order-security.json": 10}
RUNTIME_HASH = "145247be8c6fa0fcb9460ce947cade49ab43c270d3b3d8db223d715b1f210eba"


def require(ok, code):
    if not ok:
        raise RuntimeError(code)


def lab_gate(root):
    for engine in ("mariadb", "mysql"):
        folder = root / "reports/storage-m5.1/authority-gate" / engine
        for name, count in EXPECTED.items():
            checks = json.loads((folder / name).read_text(encoding="utf-8"))["checks"]
            require(len(checks) == count and all(v is True for v in checks.values()), "PHASE_A_FAILED")
        require(hashlib.sha256((folder / "runtime.sha256").read_bytes()).hexdigest() == RUNTIME_HASH, "RUNTIME_CHANGED")


def config(path):
    wanted = {"WP_URL", "SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD"}
    values = {}
    for line in path.read_text(encoding="utf-8-sig").splitlines():
        if line.strip().startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key in wanted:
            value = value.strip()
            if len(value) > 1 and value[0] == value[-1] and value[0] in ("'", '"'):
                value = value[1:-1]
            values[key] = value
    require(wanted <= values.keys() and all(values[k] for k in wanted), "CONFIG_MISSING")
    return values


def render(root, token_hash, nonce, deadline, web_root, private_parent, site_host):
    text = (root / "tests/m51-host-endpoint.php.in").read_text(encoding="utf-8")
    substitutions = {"__TOKEN_HASH__": token_hash, "__NONCE__": nonce, "__DEADLINE__": str(deadline),
                     "__WEB_ROOT__": web_root, "__PRIVATE_PARENT__": private_parent, "__SITE_HOST__": site_host}
    for label, relative in (("AUTHORITY", "includes/class-authority.php"), ("STORE", "includes/class-job-store.php")):
        payload = (root / relative).read_bytes()
        require(payload.startswith(b"<?php"), "SOURCE_PREFIX")
        substitutions["__" + label + "_SOURCE__"] = base64.b64encode(payload[5:]).decode()
        substitutions["__" + label + "_HASH__"] = hashlib.sha256(payload).hexdigest()
    for key, value in substitutions.items():
        text = text.replace(key, value)
    require(set(re.findall(r"__[A-Z_]+__", text)) <= {"__DIR__"}, "UNRESOLVED_TEMPLATE")
    return text.encode()


class SessionTLSFTP(ftplib.FTP_TLS):
    def ntransfercmd(self, cmd, rest=None):
        connection, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            connection = self.context.wrap_socket(connection, server_hostname=self.host, session=self.sock.session)
        return connection, size


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("--site", required=True)
    parser.add_argument("--web-root", required=True)
    parser.add_argument("--private-parent", required=True)
    parser.add_argument("--ftp-root", required=True)
    parser.add_argument("--ftp-port", type=int, required=True)
    parser.add_argument("--reuse-preflight", action="store_true")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    require(str(root).lower() == r"c:\dev\git\wp-seed-pixel", "PIXEL_CHECKOUT_REQUIRED")
    lab_gate(root)
    require(args.config.resolve() == Path(r"C:\Dev\git\therapsycorporel-site\.env"), "CANONICAL_CONFIG_REQUIRED")
    require(args.site == "https://therapsycorporel.fr" and args.web_root == "/home/therapc/www"
            and args.private_parent == "/home/therapc" and args.ftp_root == "/www" and args.ftp_port == 21, "AUTHORIZED_SCOPE_REQUIRED")
    settings = config(args.config)
    require(settings["WP_URL"].rstrip("/") == args.site, "SITE_DIVERGENCE")
    token = secrets.token_hex(48)
    nonce = secrets.token_hex(16)
    name = "pixel-m51-" + nonce + ".php"
    url = args.site + "/" + name
    context = ssl.create_default_context()
    evidence = {"phase_a": "388 PASS", "site": args.site, "requests": 0, "verdict": "BLOCKED", "waves": {}}
    if args.reuse_preflight:
        previous_path = root / "reports/storage-m5.1/authority-gate/real-host.json"
        previous = json.loads(previous_path.read_text(encoding="utf-8"))
        require(previous.get("preflight_https") == 200 and previous.get("requests") == 1
                and previous.get("failure") == "UNRESOLVED_TEMPLATE" and "transport" not in previous
                and 0 <= time.time() - previous_path.stat().st_mtime < 180, "FRESH_PREFLIGHT_REQUIRED")
        evidence["requests"] = 1
        evidence["preflight_reused"] = True
        evidence["local_preflight_only_stop"] = previous["failure"]
    ftp = None
    uploaded = False
    init_attempted = False
    cleanup_proven = False
    source = b""

    def request(target, authenticated=False):
        headers = {"User-Agent": "WP-Seed-Pixel-M5.1-Authority-Gate/1.0", "Cache-Control": "no-cache"}
        if authenticated:
            headers["X-Pixel-Preflight"] = token
        evidence["requests"] += 1
        try:
            with urllib.request.urlopen(urllib.request.Request(target, headers=headers), timeout=35, context=context) as response:
                return response.status, {k.lower(): v for k, v in response.headers.items()}, response.read(131072)
        except urllib.error.HTTPError as error:
            return error.code, {k.lower(): v for k, v in error.headers.items()}, b""

    def call(action, **params):
        status, headers, body = request(url + "?" + urllib.parse.urlencode(dict(action=action, **params)), True)
        require(status == 200, "ENDPOINT_HTTP_" + str(status))
        require("no-store" in headers.get("cache-control", "") and "noindex" in headers.get("x-robots-tag", ""), "ENDPOINT_CACHE_HEADERS")
        data = json.loads(body)
        require(data.get("ok") is True, "ENDPOINT_FAILED")
        return data["result"]

    try:
        preflight = 200 if args.reuse_preflight else None
        for attempt in range(0 if args.reuse_preflight else 2):
            try:
                status, _, _ = request(args.site + "/")
                require(status == 200, "PREFLIGHT_HTTP_" + str(status))
                preflight = status
                break
            except (TimeoutError, socket.timeout):
                if attempt:
                    raise RuntimeError("PREFLIGHT_TWO_TIMEOUTS") from None
                time.sleep(15)
            except urllib.error.URLError as error:
                if isinstance(error.reason, (TimeoutError, socket.timeout)) and not attempt:
                    time.sleep(15)
                    continue
                raise RuntimeError("PREFLIGHT_TRANSPORT_FAILED") from None
        require(preflight == 200, "PREFLIGHT_FAILED")
        evidence["preflight_https"] = 200
        source = render(root, hashlib.sha256(token.encode()).hexdigest(), nonce, int(time.time()) + 480,
                        args.web_root, args.private_parent, urllib.parse.urlsplit(args.site).hostname)
        lint = subprocess.run(["wsl.exe", "--", "bash", "/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh", "-l"], input=source, capture_output=True, timeout=30)
        require(lint.returncode == 0 and b"No syntax errors" in lint.stdout, "GENERATED_PHP_LINT")
        evidence["generated_php_lint"] = "PASS"
        ftp = SessionTLSFTP(context=context, timeout=25)
        ftp.connect(settings["SFTP_HOST"], args.ftp_port)
        try:
            ftp.auth()
            ftplib.FTP.login(ftp, settings["SFTP_USER"], settings["SFTP_PASSWORD"])
            ftp.prot_p()
            evidence["transport"] = "FTPS verified TLS with encrypted data"
        except ftplib.error_perm as error:
            require(str(error).startswith(("500", "502")) and not isinstance(ftp.sock, ssl.SSLSocket), "FTPS_FAILURE")
            ftplib.FTP.login(ftp, settings["SFTP_USER"], settings["SFTP_PASSWORD"])
            evidence["transport"] = "FTP established project mechanism; TLS unsupported"
        ftp.cwd(args.ftp_root)
        require(name not in ftp.nlst(), "ENDPOINT_COLLISION")
        uploaded = True
        ftp.storbinary("STOR " + name, io.BytesIO(source))
        readback = io.BytesIO()
        ftp.retrbinary("RETR " + name, readback.write)
        require(readback.getvalue() == source, "FTP_READBACK_DIFFERENT")
        evidence["endpoint_readback_identical"] = True
        status, headers, _ = request(url)
        require(status == 404 and "no-store" in headers.get("cache-control", ""), "UNAUTHENTICATED_ENDPOINT")
        evidence["unauthenticated_http"] = 404
        init_attempted = True
        initial = call("init")
        evidence["runtime"] = {k: v for k, v in initial.items() if k != "server_time"}
        require(initial["private_mode"] == "0700" and initial["private_outside_webroot"] and initial["same_device"], "PRIVATE_SCOPE")
        offset = initial["server_time"] - time.time()
        for count in (2, 10):
            at = time.time() + offset + 3
            with ThreadPoolExecutor(max_workers=count) as pool:
                futures = [pool.submit(call, "worker", wave=count, slot=i, at=at) for i in range(count)]
                rows = [f.result() for f in futures]
            owners = [r for r in rows if r["owned"]]
            evidence["waves"][str(count)] = {"workers": rows, "authoritative_owners": len(owners), "flock_successes": sum(r["flock"] for r in rows)}
            require(len(owners) == 1 and owners[0].get("released") is True, "AUTHORITY_OWNER_COUNT")
            winner = owners[0]
            require(all(winner["at"] <= r["claim_at"] <= winner["end"] and r["claim_code"] == "pixel_locked" for r in rows if not r["owned"]), "CONCURRENT_OVERLAP_NOT_PROVEN")
            require(all(r["db_server_hash"] == initial["db_server_hash"] for r in rows), "DATABASE_TOPOLOGY_DIVERGENCE")
            evidence["waves"][str(count)]["overlap_verified"] = True
        suite = call("suite")
        evidence["real_host_semantics"] = suite
        require(len(suite["checks"]) == 12 and all(suite["checks"].values()), "HOST_SEMANTICS_FAILED")
        evidence["verdict"] = "PASS"
    except Exception as error:
        evidence["failure"] = str(error) if isinstance(error, RuntimeError) else type(error).__name__
    finally:
        if uploaded and ftp:
            if init_attempted:
                try:
                    evidence["cleanup"] = call("cleanup")
                    evidence["cleanup_verification"] = call("verify")
                    cleanup_proven = all(v == 0 for v in evidence["cleanup_verification"].values())
                    require(cleanup_proven, "REMOTE_STATE_REMAINS")
                except Exception as error:
                    evidence["cleanup_failure"] = str(error) if isinstance(error, RuntimeError) else type(error).__name__
                    evidence["verdict"] = "BLOCKED"
            else:
                cleanup_proven = True
                evidence["cleanup_verification"] = {"temporary_tables": 0, "temporary_directories": 0, "live_test_locks": 0}
            try:
                require(cleanup_proven, "KEEP_ENDPOINT_FOR_TARGETED_CLEANUP")
                ftp.delete(name)
                require(name not in ftp.nlst(), "FTP_ENDPOINT_REMAINS")
                ftp.voidcmd("TYPE I")
                try:
                    ftp.size(name)
                    raise RuntimeError("FTP_SIZE_ENDPOINT_REMAINS")
                except ftplib.error_perm as error:
                    require(str(error).startswith("550"), "FTP_SIZE_NOT_CONFIRMED")
                status, _, _ = request(url)
                require(status == 404, "HTTPS_ENDPOINT_REMAINS")
                evidence["endpoint_absence"] = {"ftp_listing": True, "ftp_size": 550, "https": 404}
            except Exception as error:
                evidence["endpoint_cleanup_failure"] = str(error) if isinstance(error, RuntimeError) else type(error).__name__
                evidence["verdict"] = "BLOCKED"
            ftp.close()
        elif ftp:
            ftp.close()
        settings.clear()
        token = ""
        source = b""
        destination = root / "reports/storage-m5.1/authority-gate/real-host.json"
        destination.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"verdict": evidence["verdict"], "requests": evidence["requests"], "failure": evidence.get("failure"), "cleanup_failure": evidence.get("cleanup_failure"),
                      "cleanup": evidence.get("cleanup_verification"), "endpoint_absence": evidence.get("endpoint_absence"),
                      "owners": {k: v["authoritative_owners"] for k, v in evidence["waves"].items()}}))
    return 0 if evidence["verdict"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
