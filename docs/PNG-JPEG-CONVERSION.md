# Explicit PNG to JPEG conversion

## Status and scope

0.5.0 promotes the certified private.5 runtime after frozen 0.4.0. It adds
one explicit attachment action, not a new menu, upload setting or bulk format
operation. 0.4.0 and its exact accepted ZIP remain untouched. No real media
migration or remote deployment is authorized by release preparation.

## Decision and quality

An administrator chooses Analyze in the existing Pixel attachment panel. This
creates private evidence, not a WordPress attachment mutation. Three actual
master candidates (90, 94, 98) are measured with the existing sampled SSIM >=
0.995 and PSNR >= 40 recommendation guards, and verified 1x1 JPEG component
sampling. An explicit quality override affects only the chosen master. Native
derivatives retain the conservative 98/100 encoder and independent quality floor.

The opportunity needs at least 256 KiB AND 25% saving, both on the master and on
the complete native graph. This is a conservative product admission rule, not
a photographic classifier or statistical claim. The broader synthetic matrix
tests photograph-like texture, large texture, gray, transparent, palette,
logo, lines, screenshot, small file, ICC and provenance. Small flat/text/line
fixtures are refused for insufficient benefit. A gray fixture may also be
refused: opacity alone never justifies conversion. Uncompressed graphics can
remain explicit candidates if their technical and benefit checks pass; comparison
and author intent, not an alleged AI photo diagnosis, govern the final choice.

Charlotte Q100, Q98 and Q94 have an owner Human Pass for that image only.
These image-specific approvals do not weaken the recommendation floor. The existing
metric samples blocks; it is not a full-image or universal perceptual guarantee.

## Metadata policy

Supported initial scope is bounded 8-bit, non-interlaced PNG, grayscale or RGB,
with no alpha/palette/transparency requirement. RGBA is conservatively refused
even when apparently opaque. Original dimensions and decoded orientation remain.
Untagged RGB uses an explicitly documented web sRGB assumption. Embedded sRGB
is accepted. ICC, gAMA/cHRM, EXIF, unknown textual/privacy metadata and other
unsupported target metadata are refused rather than silently discarded.

caBX is recognized as provenance-bearing, without authenticating its signature
or claiming anything about the source's authorship. The encoder cannot migrate
signed Content Credentials faithfully; the JPEG is checked for unexpected
APP metadata. Independent acknowledgement of this loss is mandatory. The
original and every native PNG remain byte-exact in recovery. Copying caBX bytes
to JPEG would not preserve a valid signed format binding and is not attempted.

## WordPress migration and references

An immutable native snapshot includes raw relevant metadata, GUID, MIME, file
graph, hashes and ownership. Source and target tables must be InnoDB. Existing
Pixel generations, scaled originals, incomplete/shared inventories or unknown
native size contracts are refused in this first scoped implementation.

Each exact PNG crop is converted independently from its private recovery copy,
preserving crop/dimensions and avoiding a second encode from a lossy master.
JPEG files receive unique, bounded ASCII names in the same uploads directory.
An atomic no-clobber link publishes each verified private inode; unsupported
filesystems or extra links fail closed. No flock is used for ownership. The
shared M5.1 SQL session owns both site and attachment. Only after all verified
resources exist does an InnoDB transaction update `_wp_attached_file`, native
metadata, Pixel witness and `post_mime_type`. GUID, ID, alt, captions, membership
and unrelated rows remain unchanged. Active srcset points only to JPEG files.

Known direct references are scanned in post content, metadata and options,
including raw, URL-encoded and JSON-escaped names. Results contain IDs, not
private content. No generic database replacement is performed. ID-based native
WordPress consumers resolve the new graph; existing Divi/direct PNG addresses
continue to work because all old PNG resources remain. This protects known
and unknown direct consumers during review. It does not prove any uninspected
real-site layout compatible; fresh attachment-specific checks precede a pilot.

## Recovery, failure and deletion

Intention, encoding slots, ready candidate, publication, DB switch, restore and
purge have checksummed durable evidence. A killed worker cannot steal a live
SQL session or operate with an old token. An incomplete but owned encoding slot
can be cleaned and regenerated; an already published candidate identity is
reused. Unknown files/identities are never guessed away. Unwitnessed filesystem
evidence fails closed for review, rather than claiming automatic repair of
every possible storage fault. The UI exposes explicit safe resumption.

Public PNG and JPEG permissions, owner and group are witnessed as well as byte
and inode identity. A permission change after analysis blocks publication;
changes to the retained graph disable restore/purge. Resumption never replaces
an already witnessed original or private backup with a foreign inode.

Restore is offered only when both coherent current state and complete original
evidence pass. It restores raw metadata/MIME and the exact PNG graph, then
removes owned JPEG resources and now-unneeded private copies. Changes by an
outside writer, missing originals or foreign inodes disable restoration rather
than overwriting another writer. Such divergence requires diagnosis.

Permanent deletion needs separate JPEG acceptance and old-URL acknowledgement.
Known active PNG references block it. Unknown external references cannot be
certified; their risk is explicitly disclosed. Purge removes old public PNGs
and recovery PNGs, is resumable per inode, and permanently removes rollback.

## Accounting and platform

The panel distinguishes original master, old/new native graphs, active-byte
saving, recovery, compatibility, temporary candidates, audit and peak reserve.
No net space is claimed while retained copies consume it. Net values are
logical file bytes, not filesystem block allocation, DB-row overhead or a
measured hosting quota. Whole-account live capacity admission remains M5.1.

This increment requires the existing Linux/private-recovery prerequisites.
Local certification uses real WordPress, Imagick/GD and MariaDB SQL ownership.
It does not claim a new real-NFS run; accepted M5.1 authority remains unchanged,
and unsupported hard-link/directory-sync behavior refuses publication.

## Per-image quality profiles (0.5.0; certified in private.5)

New analyses measure exact master candidates: Web Q90, Good quality Q94 and
Best quality Q98. Sampled SSIM >= 0.995, RGB PSNR >= 40 and existing master/graph
benefit gates remain unchanged. The smallest passing candidate is recommended.
Quality failure alone does not disable a technically safe, beneficial master.
No passing choice means no recommendation or default: an explicit user choice
is required before preparing comparison. Technical, metadata, native-derivative
and master/graph benefit gates remain mandatory. No global option, upload
conversion or bulk expansion.

Labelled radios reuse verified artifacts, update comparison and reset browser
approval. Conversion checks the submitted profile against the durable choice.
Failing selections open comparison and require independent acknowledgement of
the unvalidated compression level. The server refuses conversion without it.
Profile, actual encoder quality, guard result, metrics and explicit override
persist in the existing journal; interrupted publication reuses that approval.
The human-passed post-conversion hierarchy remains unchanged.

Native derivatives retain their conservative encoder and independent guards,
using the exact PNG crops; radio changes do not re-encode them. Every profile
slot is journalled before encoding. Peak reservation adds two bounded candidate
allowances of eight bytes per pixel; measured temporary usage includes every
cached JPEG. Publication removes all cached master candidates, including the
selected comparison copy. Exact original recovery and compatibility URLs remain.
Interrupted selection/cleanup resumes under the same SQL authority. Existing
private.3 journals without profiles retain their lifecycle; update processes no
image. Discard/restore remove only witnessed owned resources.

Charlotte Q90 and Q94 fail the unchanged sampled SSIM guard. The owner visually
accepted Q94 and real Q98; this does not lower generic guards. Q98 remains the
only passing new profile and recommended default for Charlotte. Q94 and Q90
remain explicit choices with an additional acknowledgement; Q90 Human Pass
is pending. No remote conversion is authorized by this policy change.

## Accepted real pilot evidence

The accepted DEV attachment 6354 kept its ID after explicit Q98 conversion.
Its active JPEG is 805,265 bytes (63.2% master reduction). The exact original
PNG remains retained for restoration; provenance loss was disclosed. Native
derivatives, srcset, REST and browser consumers were certified with zero broken
known references. The owner approved the real Q98 image and result panel.
Release preparation does not reconvert it or change historical database state.
The pending image-specific Q90 Human Pass is not a generic release prerequisite:
non-validated choices require comparison and independent server-enforced consent.

## Test reproduction

Generate `tests/format-fixtures.py`, prepare `tests/format-lab.sh`, and run
`tests/format-cycle.sh 1` and `2` in the guarded disposable Linux environment.
`PIXEL_PRIVATE_PNG_FIXTURE` may reference an external authorized regression PNG;
no private bytes enter the repository or ZIP. Browser tests use headless Chrome
on localhost, never the owner's visible browser. See ignored final reports for
actual results, final package identity and hygiene.
