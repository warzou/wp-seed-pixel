# Metadata privacy - 0.6.0

Automatic privacy is ON by default for fresh single-site activation and for an
existing installation when an administrator first loads the new runtime. A
previous explicit ON/OFF value is preserved. The new option is independent of
lossy/lossless future-upload optimization; it does not reinterpret old settings.
Initialization/configuration records an authorized administrator, generation and
existing ID ceiling under the existing SQL site authority. It never scans media.
Until that administrator context exists, no administrator is guessed or elevated.
Network/multisite automation is not supported by the current graph coordinator.

Only a genuine wp_handle_upload record followed by add_attachment in the same
request can enroll a new JPEG/PNG. CLI/import/restore/sideload tools are excluded.
Generation/cutoff/current option are rechecked; old metadata updates do not enroll.

The preferred before-derivative sequence cannot safely use the current Jobs:
the native attachment metadata graph does not yet exist. Therefore core creates
its derivatives normally. The final create-context metadata filter freezes the
expected metadata hash. The corresponding added/updated_post_meta action runs
synchronously after native persistence, before the upload call returns. It invokes
the existing analyzer, graph plan, bounded storage admission, replace_one and step.
No private metadata is promised absent during the initial core upload interval.
If core has already persisted that exact final graph during size generation,
its subsequent identical update emits no action. The final filter detects this
verified no-op and invokes the same completion method against persisted metadata.
No additional WordPress metadata write is needed for that path.

Dirty supported graphs retain exact originals through existing Quarantine, without
additional encoding. Clean graphs produce no job/recovery. Refused/unsupported
graphs leave image bytes unchanged by Pixel and record a category/error-code-only
review state. No raw metadata values or exception messages are logged/returned.
No background retries or automatic purge. Interrupted transaction recovery still
belongs exclusively to the existing Jobs/journal lifecycle.

Privacy takes priority over automatically requested optimization. Current
architecture cannot retain independent metadata and optimization rollback domains
simultaneously; while privacy is pending, retained or needs review, future/legacy
optimization is blocked. Clean/no-privacy images may use normal future optimization
(at most its normal one encode). No format conversion is implied by privacy consent.
This is explicit serialization, not a claim of a combined optimizer.

Attachment UI rederives the graph from fresh snapshot hashes. Request-local cached
analysis is keyed by that snapshot; file changes cannot reuse a previous analysis.
Verified retained privacy state displays no Anonymize selection/action. Clean or
blocked/review state offers no ordinary anonymization. Exact restore with private
metadata makes the dedicated action available again. Pixel AJAX does not save
WordPress editorial fields or depend on the WordPress Update button.

Local candidate only. Two complete final regression cycles and install/update/UI
gates are required before a private.4 ZIP or DEV readiness verdict. DEV6594/6354
and production are not accessed by this implementation lot.
