# Changelog

## 0.4.0 - private V1 candidate (not publicly released)

- Accepted M1-M5.1 analysis, immutable plans, fenced DB ownership, verified JPEG
  native replacement, private recovery, explicit restore/purge and bounded jobs.
- Explicit future-upload opt-in with durable provenance/cutoff. Existing media
  never enroll automatically; upgrades do not enable PNG or destructive work.
- M6 lossless PNG IDAT recompression, same chunks/filtered pixels/dimensions,
  bounded 8-bit non-interlaced RGB/RGBA/gray/gray-alpha/indexed scope. Animation,
  16-bit/interlaced, oversized, malformed and no-benefit cases stay unchanged.
- Native media selection for storage lots, complete EN/fr_FR host controls,
  truthful attachment recovery state, private usage/peak reserve and history cap.
- Legacy derivative engine identity remains 0.3.1; no implicit regeneration.
- Real one-JPEG TherapsyCorporel pilot 4395 accepted, then its verified source
  purged through M4. No second real image and no PDE validation in this lot.


## 0.3.2 - unpublished i18n candidate

Standard French gettext POT/PO/MO catalogue, localized diagnostic headings and
presentation-only engine messages. Stored engine evidence, presets, processing
and image algorithms are unchanged. Bulk counters use WordPress plural rules
for zero, one and multiple images. Text domain loading is registered on init.
No automatic optimization setting is enabled by an update. Engine signature
version stays 0.3.1 so the presentation update does not invalidate generations.
Local catalogue QA is separate from real WordPress/consumer DEV certification.


## 0.3.1 - Unpublished candidate

- Validate bounded, complete RGB ICC profiles; reject malformed chunks and tags.
- Convert through Imagick/LittleCMS to a lossless sRGB working reference before
  resizing, orientation/metadata sanitization and adaptive quality measurement.
- Keep canonical masters byte-exact; never reuse a working PNG as a master URL.
- Retain honest GD-only/CMYK skips and clean working references after every job.
- Add synthetic sRGB, Display P3, orientation, invalid-profile and fallback tests.
- No automatic regeneration, setting migration or consuming-site hotpatch.

## 0.3.0 - 2026-10-02

- Add native Media Library actions, attachment detail panels and selected bulk jobs.
- Reuse the existing resumable engine pipeline; cap selections at 1000 editable items.
- Distinguish current results, skips and failures using human-readable progress.
- Simplify settings and move numeric IDs and engine diagnostics behind disclosure.
- Separate transfer reduction from additional disk usage and source reuse.
- Improve scoped responsive controls and warn about known active optimizers.
- Keep the 0.2.0 image engine and its profiles unchanged.

## 0.2.0 - 2026-10-02

- Add a bounded local Balanced intent instead of automatic high-quality inflation.
- Evaluate independent MASTER candidates with stratified block SSIM and RGB PSNR.
- Separate thumbnail and view quality floors; retain metadata-safe sources.
- Expose resource kind, decision, algorithm/config version, byte and time statistics.
- Preserve fixed/custom API profiles, legacy mappings and settings on upgrade.
- Add synthetic research/holdout, actual PHP benchmark, adaptive security and upgrade tests.
- No AI runtime, external optimizer, network dependency or consuming-site deployment.

## 0.1.0 - 2026-10-02

- Add local WordPress JPEG derivative pipeline and immutable master checks.
- Add validated web and participant_album presets, no enlargement or cropping.
- Add manual media action, optional upload automation and resumable batch cursor.
- Add scoped administration, capabilities/nonces and bounded retries.
- Add atomic metadata compare-and-swap, generation journals and ownership cleanup.
- Skip unsupported formats, profiles and colors explicitly.
- Add adversarial, browser, orientation and final-archive validation.
