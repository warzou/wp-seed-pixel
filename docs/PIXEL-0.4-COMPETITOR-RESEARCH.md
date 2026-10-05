# Pixel 0.4.0: competitor research

Research date: 2026-10-04. Official product documentation only. This is a bounded
architecture comparison, not a benchmark, licensing review or purchase advice.
Different free/paid editions and hosting backends have different capabilities;
marketing compression percentages are not measured Pixel quality evidence.

## Comparison matrix

| Product | Processing / master | Backup and disk | Resize / delivery | Bulk and dependency |
| --- | --- | --- | --- | --- |
| ShortPixel | Service-based optimization; replaces image files and selected sizes; lossless, lossy and Glossy policies. | Optional local backups consume hosting space; backup removal is distinct. | Original resizing plus optional modern-format outputs. | Background/Cron and CLI documented; API/service dependency. |
| Imagify | Service processing with returned local replacements; normal filename retained. | Local backup enables restoration; retaining it consumes disk. | Optional original resize and WebP/AVIF settings; native-size constraints documented. | Resumable/background bulk; service/API limits apply. |
| EWWW | Local compression tools with optional premium API modes. | Local/cloud backup choices; restoration depends on availability. | Explicit original preservation/resize controls; local modern-format features separate. | Hosting binary capabilities affect local feasibility; cloud is not mandatory for every operation. |
| Smush | API-based compression modes; originals and sizes configurable. | Optional originals backup increases uploads size and enables restoration. | Large-image/native-scaled controls and optional modern delivery. | Bulk/background workflow; delivery/CDN settings are separate from master storage. |

Sources and bounded conclusions for each row follow. No claim that every feature
is available in every edition or that any product is safe under every file graph.

## ShortPixel

Official workflow describes uploading images for processing and storing returned
optimized files locally. Its backup documentation separates server storage from
compression and provides explicit backup removal. Resizing and Cron processing
are separate documented controls.
[Workflow](https://shortpixel.com/kb/knowledge-base/article/how-does-the-plugin-work/),
[quality modes](https://shortpixel.com/knowledge-base/article/lossy-glossy-or-lossless-which-one-is-the-best-for-me/),
[backup location](https://shortpixel.com/kb/knowledge-base/article/will-backup-images-be-stored-on-our-own-server/),
[backup removal](https://shortpixel.com/knowledge-base/article/how-to-remove-the-backed-up-images-in-wordpress/),
[resize](https://shortpixel.com/kb/knowledge-base/article/can-shortpixel-automatically-resize-new-image-uploads/),
[background processing](https://shortpixel.com/knowledge-base/article/background-processing-using-cron-jobs-in-shortpixel-image-optimizer/).

Pixel lesson (design inference): separate optimization, master dimensions and backup
retention. Do not mistake transfer savings for hosting-space recovery. Do not copy
cloud dependence, require a subscription or delete another optimizer's backups.
Current ShortPixel responsibilities on a consumer are a separate audit, not changed
by this research.

## Imagify

Official material describes remote processing and local optimized files. Its
filename documentation explains replacement without renaming the normal image.
Settings distinguish backups, resizing and modern formats. Removing backups loses
restoration and related workflows; bulk pause/resume avoids needless repeat work
under the same compression setting.
[FAQ](https://imagify.io/faq/),
[filename behavior](https://imagify.io/documentation/imagify-change-image-name/),
[recommended settings](https://imagify.io/documentation/recommended-imagify-settings/),
[backup deletion consequences](https://imagify.io/documentation/safe-delete-original-images-backup/),
[restoration](https://imagify.io/documentation/restore-images-original/),
[pause/resume](https://imagify.io/documentation/stop-bulk-optimization-process/),
[background workflow](https://imagify.io/documentation/optimize-images-wordpress-plugin/).

Pixel lesson (design inference): stable operational URLs and clear restore
availability are understandable product patterns. Keep a reproducible policy
signature and completed-item state. Never show a Restore action after its only
recovery bytes have been purged. Pixel adopts the pattern, not Imagify's code or
cloud service.

## EWWW Image Optimizer

EWWW documents local jpegtran, optipng/pngout, gifsicle and other capabilities,
with additional API compression options. Restoring can use retained local or
time-limited cloud copies. Resize settings distinguish full-size handling and
preserving originals. These are capability-dependent workflows, not evidence that
plain GD achieves specialist lossless JPEG compression.
[Local options](https://docs.ewww.io/article/102-local-compression-options),
[restore](https://docs.ewww.io/article/58-restoring-original-images),
[resize policy](https://docs.ewww.io/article/41-resize-settings).

Pixel lesson (design inference): local-first is viable, but honest backend
limitations matter. Keep PHP/WordPress GD/Imagick as the portable core; optional
specialist encoders need a later license, installation, sandbox, argument-safety
and fallback decision. No binaries or API dependency are added now. Do not copy an
unused-size deletion option without a reference/ownership contract.

## Smush

Current official documentation separates compression, original optimization,
backup/restore, large-image controls and optional WebP/AVIF delivery. It explicitly
warns that keeping backup originals can enlarge uploads storage. Public plugin
documentation also identifies Smush API processing. Modern delivery and storage
retirement are therefore different responsibilities.
[Smush guide](https://wpmudev.com/docs/wpmu-dev-plugins/smush/),
[official WordPress plugin listing](https://wordpress.org/plugins/wp-smushit/).

Pixel lesson (design inference): expose understandable independent controls rather
than an all-in-one aggressive button. Format conversion, CDN, lazy loading and
markup rewriting do not belong in the initial storage MVP. A backup-enabled
optimization result must not be reported as equivalent to disk reclamation.

## Adopt, adapt, reject

| Pattern | Pixel decision | Reason |
| --- | --- | --- |
| Analyze existing attachments before processing | Adopt | Show actual file roles, uncertainty and net opportunity. |
| Optimize a normal local master at a stable URL | Adapt | Same-format/path only; journal, CAS and explicit rollback first. |
| Separate backup removal | Adopt with stronger verification | Purge must revalidate current state, ownership, references and hashes. |
| Pause/resume bulk with completed-item identity | Adopt | Browser closure must not restart or double-count completed work. |
| Separate resize, quality, metadata and formats | Adopt | Intent is explicit; no silent modern-format or privacy switch. |
| Local specialist binaries | Defer optional adapter | Useful compression potential, but not a portable mandatory core. |
| Mandatory cloud, credits or subscription | Reject for core | Pixel is locally executable without those dependencies. |
| CDN/markup/lazy-loading suite | Defer | Storage product does not need to become a delivery platform. |
| Blanket unused media/size or foreign-backup deletion | Reject | Unknown references and ownership cannot justify removal. |

## What is not established by this research

No products installed or executed, no private images uploaded, no compression
leaderboard and no disk-space benchmark. API edition/pricing and exact current
version matrices were not needed for the design. Documentation cannot establish
competitor crash atomicity, private-media safety or an individual host's quota.
Pixel must prove its own lifecycle, quality and real net byte accounting in the
milestones, rather than inherit those claims from product descriptions.

Conclusion: established patterns support the chosen architecture. The meaningful
Pixel distinction is a local, explicit and recoverable storage lifecycle with
honest accounting, not an unverified claim to beat competitors' compression.
