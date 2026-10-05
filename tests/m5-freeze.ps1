param([switch]$Verify)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
if ((Resolve-Path $root).Path -ne 'C:\Dev\git\wp-seed-pixel') { throw 'Owned Pixel checkout required' }
$files = @((Get-Item -LiteralPath (Join-Path $root 'wp-seed-pixel.php')))
foreach ($dir in @('includes', 'assets', 'languages')) {
    $files += Get-ChildItem -LiteralPath (Join-Path $root $dir) -File -Recurse
}
$manifest = @($files | Sort-Object FullName | ForEach-Object {
    [ordered]@{ path = $_.FullName.Substring($root.Length + 1).Replace('\', '/'); sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }
})
$target = Join-Path $root 'reports/storage-m5/frozen-core.json'
$json = ConvertTo-Json -InputObject $manifest -Depth 4
if ($Verify) {
    $old = Get-Content -LiteralPath $target -Raw | ConvertFrom-Json
    if ((ConvertTo-Json -InputObject $old -Depth 4 -Compress) -cne (ConvertTo-Json -InputObject $manifest -Depth 4 -Compress)) { throw 'Frozen runtime changed' }
    "Frozen core verified: $($manifest.Count) files"
} else {
    [IO.File]::WriteAllText($target, $json.Replace("`r`n", "`n") + "`n", [Text.UTF8Encoding]::new($false))
    "Frozen core recorded: $($manifest.Count) files"
}
