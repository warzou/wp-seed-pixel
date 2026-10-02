# Architecture

## Components

`Presets -> Engine -> WP_Image_Editor -> Files -> Store`

0.2.0 Balanced adds `Adaptive` inside Engine. At most three independent candidates
per size, intent-specific local gates and source reuse share the same atomic
publication and batch pipeline. `files` includes non-owned `kind=master` resources;
they are never renamed or deleted, and add zero disk bytes. Fixed/custom profiles
remain compatible. See ADAPTIVE-ALGORITHM.md for the exact versioned contract.

The admin controller, automation and batch controller call the same PHP API.
WordPress owns the attachment, master, native metadata and URLs. Pixel only adds
`seed-pixel-*` size entries to native attachment metadata. Its internal manifest
lives separately in protected `_seed_pixel_manifest` postmeta, never registered
for public REST. There is one current Pixel preset generation per attachment.

The manifest records master SHA-256, configuration SHA-256, generation, backend,
output hashes/dimensions/quality/bytes, added disk bytes and timings. The small
`_seed_pixel_job` record carries pending/processing/success/skipped/failed status.
No sensitive metadata is registered for public REST.

## Publication protocol

1. Acquire attachment flock in the site's uploads workspace.
2. Recover abandoned journals for this attachment; never follow symlinks.
3. Resolve the WordPress original (`original_image` for scaled images), MIME,
   dimensions, JPEG markers, EXIF capability, memory and disk estimates.
4. Hash the master; validate preset and foreign size-key collisions.
5. Decode independently from the master for each derivative. Orient first,
   resize only if needed, set explicit JPEG quality AFTER resize, save to stage.
6. Validate bounds, JPEG markers, sensitive metadata, hash and positive bytes.
7. Persist a relative-path ownership journal, rename generation files atomically.
8. Recheck master/attached path and output integrity.
9. Require metadata filters to leave the candidate unchanged; atomically replace
   the single metadata row only if its serialized old value still matches.
10. Read back and verify the protected manifest. Retain prior generations so
    static HTML and external cached URLs do not become broken images.

The multiple file renames are not a filesystem transaction. Readers remain on
the old metadata until the complete new file set exists. A process crash before
the metadata commit leaves old references valid; the next retry removes only
unreferenced hash-matching files from the journal. A crash after the native commit
retains the new references and recovers the protected manifest when its exact
file mappings, hashes and master match. The two metadata records are not a SQL
transaction; the journal bridges that interval. This does not synchronize snapshots and SQL
backups; operators still need both.

Metadata is committed by compare-and-swap instead of blind `update_post_meta`.
The official metadata filter is consulted but arbitrary filtered mutation causes
a refusal. The custom completion hook is the supported observer. This deliberate
choice does not emulate every generic post-meta lifecycle notification.

History is protected `_seed_pixel_history` metadata, reserved before decoding.
Twenty previous generations maximum; further regeneration is refused until an
operator explicitly prunes after reviewing caches and external URLs. The PHP
prune API additionally checks WordPress metadata, content and option references.
It cannot discover an external browser/CDN cache. Default regeneration never
automatically deletes old published URLs. Attachment deletion / opt-in uninstall
clean only proven-owned unreferenced history files, not masters.

## Batch and automation

One administrator-driven batch per site, frozen upper attachment ID and initial
image count. One step handles one ID; cursor and active ID persist before decode.
Counters reflect completed work, not projected savings. New uploads after the
ceiling are not silently included. Deletions can make processed count lower than
the initial count. Up to 1000 failed IDs are retained for two explicit retries.
Beyond that bound, media statuses identify failures for manual processing.

Automation schedules a single WP-Cron task only when native metadata is first
added. No recursive metadata generation. Locked tasks have two bounded delayed
retries. Regeneration by another optimizer does not trigger an automatic rewrite.

## Sources

- [WP_Image_Editor](https://developer.wordpress.org/reference/classes/wp_image_editor/)
- [Original image path](https://developer.wordpress.org/reference/functions/wp_get_original_image_path/)
- [Attachment metadata](https://developer.wordpress.org/reference/functions/wp_generate_attachment_metadata/)
- [Output format filter](https://developer.wordpress.org/reference/hooks/image_editor_output_format/)
- [Metadata filter](https://developer.wordpress.org/reference/hooks/wp_update_attachment_metadata/)

These APIs were checked against the official reference and the installed local
WordPress 7.1.2 source. No WordPress core or commercial plugin is bundled.
