# Security

Use GitHub's private vulnerability reporting on this repository's Security tab
when available. If it is unavailable, open a public issue requesting a private
contact channel without including exploit details or sensitive information.
Include a minimal reproduction, versions, impact and non-sensitive logs.
Never send passwords, tokens, private photographs or database backups.

## Threat model

Untrusted images, attachment IDs and administrator requests are validated.
HTTP mutation requires POST, nonce and capabilities. The PHP API is trusted
server-side code; an integrator must authorize its own entry points. It is not
an unauthenticated REST endpoint.

Uploads must be local regular files within the current site root, without
symlink components. File deletion requires a generated name, SHA-256 ownership
and absence of attachment references. Foreign files are never garbage-collected.
Advisory locks cover Pixel jobs; metadata compare-and-swap detects other writers.

The host filesystem and WordPress database are trusted infrastructure. A local
attacker able to rewrite uploads, metadata or plugin code is outside the security
boundary. PHP image decoders must receive upstream security updates. Limits
reduce risk but do not constitute a decoder sandbox or a guarantee against OOM.

No new public REST endpoint or internal manifest exposure, network API, credentials, CDN, frontend assets, global
security headers or telemetry is introduced. Metadata is stripped from JPEG
derivatives, but originals remain unchanged and may contain private metadata.
The protected manifest remains private when the plugin is inactive. WordPress
still exposes its normal derivative size URLs for public media.

## Recovery

Take an independent database/uploads backup before library operations. A failed
generation preserves the last committed metadata and referenced files. Recovery
journals are processed when that attachment is retried. There is no global
automatic orphan collector. Changed/shared derivative files remain for manual
investigation, rather than being deleted on assumptions.

See docs/COMPATIBILITY.md for tested and untested environments. This preview
has not been audited by an independent security professional.
# Adaptive source reuse

Balanced may serve the existing MASTER only after JPEG marker checks exclude
private metadata and ICC. It never treats a master reference as owned output.
Quality failure on an unsafe source refuses publication; it does not leak EXIF.
The local sampled metric is not a privacy classifier or an AI model. Production
optimization requires no network or remote scoring. Human review remains required.
