"""Read-only first-pilot preflight; never infer account usage from FTP lengths."""
import argparse
from datetime import datetime, timezone
import ftplib
import json
from pathlib import Path
import re
import socket
import ssl
import time
import urllib.error
import urllib.request


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    if root != Path(r"C:\Dev\git\wp-seed-pixel") or args.config.resolve() != Path(r"C:\Dev\git\therapsycorporel-site\.env"):
        raise SystemExit("AUTHORIZED_SCOPE_REQUIRED")
    output = args.output.resolve()
    if not output.is_relative_to(root / "reports/storage-m5.1/first-real-pilot"):
        raise SystemExit("EVIDENCE_SCOPE_REQUIRED")
    settings = {}
    for line in args.config.read_text(encoding="utf-8-sig").splitlines():
        match = re.match(r"^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$", line)
        if match:
            settings[match[1]] = match[2].strip().strip("\"").strip("'")
    required = ("WP_URL", "SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD")
    if any(not settings.get(key) for key in required) or settings["WP_URL"].rstrip("/") != "https://therapsycorporel.fr":
        raise SystemExit("CANONICAL_CONFIG_INVALID")
    evidence = {"utc": datetime.now(timezone.utc).isoformat(), "site": settings["WP_URL"],
                "https_requests": 0, "remote_writes": 0, "wordpress_bootstrap": False,
                "provider_usage": "UNKNOWN", "admission": "REFUSED_PENDING_ACCOUNTING",
                "quota_bytes": 1000000000, "ceiling_bytes": 600000000}
    ftp = None
    context = ssl.create_default_context()
    try:
        for attempt in range(2):
            evidence["https_requests"] += 1
            try:
                request = urllib.request.Request(settings["WP_URL"], headers={
                    "User-Agent": "WP-Seed-Pixel-M5.1-First-Pilot/1.0", "Cache-Control": "no-cache"})
                with urllib.request.urlopen(request, timeout=25, context=context) as response:
                    evidence["https_status"] = response.status
                    evidence["https_tls_verified"] = True
                    if response.status != 200 or response.url.rstrip("/") != settings["WP_URL"].rstrip("/"):
                        raise RuntimeError("HTTPS_IDENTITY_OR_STATUS")
                    response.read(8192)
                break
            except urllib.error.HTTPError as error:
                evidence["https_status"] = error.code
                raise RuntimeError("HTTPS_HTTP_FAILURE") from None
            except (TimeoutError, socket.timeout):
                if attempt:
                    raise RuntimeError("HTTPS_TWO_TIMEOUTS") from None
                time.sleep(15)
            except urllib.error.URLError as error:
                if isinstance(error.reason, (TimeoutError, socket.timeout)) and not attempt:
                    time.sleep(15)
                    continue
                raise RuntimeError("HTTPS_TRANSPORT_FAILURE") from None
        ftp = ftplib.FTP_TLS(context=context, timeout=25)
        ftp.connect(settings["SFTP_HOST"], 21)
        try:
            ftp.auth()
            ftplib.FTP.login(ftp, settings["SFTP_USER"], settings["SFTP_PASSWORD"])
            ftp.prot_p()
            evidence["transport"] = "FTPS"
        except ftplib.error_perm as error:
            if not str(error).startswith(("500", "502")) or isinstance(ftp.sock, ssl.SSLSocket):
                raise RuntimeError("FTPS_AUTH_FAILURE") from None
            ftplib.FTP.login(ftp, settings["SFTP_USER"], settings["SFTP_PASSWORD"])
            evidence["transport"] = "FTP established project mechanism; TLS unsupported"
        try:
            features = ftp.sendcmd("FEAT")
            evidence["quota_feature_advertised"] = any("QUOTA" in line.upper() for line in features.splitlines())
        except ftplib.error_perm as error:
            evidence["feat_status"] = str(error)[:3]
        try:
            reply = ftp.sendcmd("SITE QUOTA")
            evidence["site_quota_status"] = reply[:3]
            for value in settings.values():
                if len(value) >= 4:
                    reply = reply.replace(value, "[REDACTED]")
            evidence["quota_reply_redacted"] = reply[:2048]
            evidence["quota_scope_certified"] = False
        except ftplib.error_perm as error:
            evidence["site_quota_status"] = str(error)[:3]
            evidence["quota_scope_certified"] = False
    except Exception as error:
        evidence["failure"] = str(error) if type(error) is RuntimeError else type(error).__name__
    finally:
        if ftp is not None:
            try:
                ftp.quit()
            except Exception:
                ftp.close()
        settings.clear()
        evidence["ftp_session_closed"] = True
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(evidence, indent=2))
    return 1 if "failure" in evidence else 0


if __name__ == "__main__":
    raise SystemExit(main())
