$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
if ($root -ne 'C:\Dev\git-worktrees\wp-seed-pixel-png-jpeg-explicit-conversion') { throw 'Unexpected source root' }
$manifest = Get-Content -LiteralPath (Join-Path $root 'reports\metadata-private2-20261008\owned-fixtures.json') -Raw | ConvertFrom-Json
if ($manifest.root -ne $root) { throw 'Ownership manifest mismatch' }
$allowed = @((Join-Path $root '.runtime\fixtures'), (Join-Path $root '.runtime\format-fixtures'))
$actual = @(Get-ChildItem -LiteralPath $allowed -File -Recurse)
if ($actual.Count -ne $manifest.files.Count) { throw 'Fixture inventory changed' }
foreach ($file in $manifest.files) {
    $path = [IO.Path]::GetFullPath($file.path)
    if (-not ($allowed | Where-Object { $path.StartsWith($_ + '\', [StringComparison]::OrdinalIgnoreCase) })) { throw 'Fixture outside owned roots' }
    if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -ne $file.sha256) { throw 'Fixture content changed' }
}
foreach ($file in $manifest.files) { Remove-Item -LiteralPath $file.path }
foreach ($path in $allowed) {
    if (@(Get-ChildItem -LiteralPath $path -Force).Count -ne 0) { throw 'Unexpected fixture residue' }
    Remove-Item -LiteralPath $path
}
$temporary = 'C:\Users\WaRZy\AppData\Local\Temp\codex-pixel-060-metadata-audit-20261007'
$resolved = (Resolve-Path -LiteralPath $temporary).Path
if ($resolved -ne $temporary -or -not (Test-Path -LiteralPath (Join-Path $temporary 'cases.json'))) { throw 'Temporary ownership guard failed' }
Remove-Item -LiteralPath $resolved -Recurse
$preliminary = Join-Path $root 'reports\metadata-private2-20261008\browser-preliminary'
if (Test-Path -LiteralPath $preliminary) {
    if ((Resolve-Path -LiteralPath $preliminary).Path -ne $preliminary) { throw 'Unexpected preliminary path' }
    Remove-Item -LiteralPath $preliminary -Recurse
}
if (Test-Path -LiteralPath $temporary) { throw 'Temporary laboratory remains' }
Write-Output 'Owned Windows fixtures and temporary laboratory physically absent.'
