# Bounded lossless PNG - V1

The accepted Jobs/MasterStorage coordinator publishes PNG candidates exactly as
JPEG candidates, using the same private recovery, DB fencing, immutable before
graph, CAS metadata, verification, restore and explicit per-version purge.
Private escrow filenames retain the legacy .jpg suffix for journal compatibility;
their content is byte-exact PNG, never public and never a format conversion.

M6 compresses the original decompressed filtered scanlines with PHP zlib level 9.
It changes only contiguous IDAT chunks. Every other chunk, including IHDR/PLTE,
tRNS/iCCP/sRGB/gAMA/cHRM/text/rights, survives byte-for-byte in the same order.
Candidate read-back requires identical filtered bytes and all other chunks;
GD additionally decodes the candidate. Tests compare every decoded RGBA pixel,
including alpha, independently of the encoder. Profile semantics are unchanged.

V1 scope: 8-bit non-interlaced RGB, RGBA, grayscale, grayscale-alpha, indexed with
palette/transparency, valid chunk CRCs, <=4,194,304 pixels and <=16 MiB input.
At most 512 chunks; exact stream length and scanline filter bytes are checked.
Memory admission is conservative and runtime-budget aware. No external encoder
or new dependency. Animation, 16-bit, sub-byte palette, interlace, oversized,
unknown critical chunks or corrupt/trailing data are safely refused/reviewed.
No gamma/profile conversion, palette quantization, flattening or format change.

Replace only above both 5% and 64 KiB savings. The latter reserves room for
mandatory durable journals; quarantine still consumes the full source until
separately approved purge. Net accounting must include actual journal/temporary
state. Efficient PNGs skip without a candidate. The source remains untouched.

Future upload settings default to JPEG only, including migration of settings
without a formats key. PNG needs an explicit new administrator selection.
Old PNGs never enroll automatically. The native media selector freezes <=500
IDs for a selected existing-media plan; other entries are excluded from effects.

Validation is isolated synthetic WordPress/MariaDB only. No real consumer PNG
or PDE media has been processed. PHP/WP minimums remain declared floors, not a
freshly certified complete version/OS matrix. See reports/storage-v1 evidence.

## Native persistent-state gate

The encoder's minimum 64 KiB/5% saving is not the sole native replacement gate.
Before publication, the common storage coordinator additionally reserves 64 KiB
plus four times the serialized before/after native graph. A metadata-heavy PNG
that cannot cover this durable evidence is skipped without changing its master.
Reported net reclaim still uses actual retained evidence bytes, not this reserve.
