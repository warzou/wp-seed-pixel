param([switch]$FinalRelease)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
if($root -ne 'C:\Dev\git-worktrees\wp-seed-pixel-png-jpeg-explicit-conversion'){throw 'Unexpected source root'}
$record=if($FinalRelease){'reports\release-0.6.0-20261009\owned-fixtures.json'}else{'reports\metadata-private4-20261008\owned-fixtures.json'}
$origin=if($FinalRelease){'Synthetic fixture generators executed for release 0.6.0'}else{'Synthetic fixture generators executed for this local private.4 lot'}
$m=Get-Content -LiteralPath (Join-Path $root $record) -Raw | ConvertFrom-Json
if($m.root -ne $root -or $m.origin -ne $origin){throw 'Ownership marker mismatch'}
$allowed=@((Join-Path $root '.runtime\fixtures'),(Join-Path $root '.runtime\format-fixtures'))
$actual=@(Get-ChildItem -LiteralPath $allowed -File -Recurse)
if($actual.Count -ne $m.files.Count){throw 'Unexpected fixture inventory'}
foreach($f in $m.files){
    $path=[IO.Path]::GetFullPath($f.path)
    if(-not($allowed | Where-Object {$path.StartsWith($_+'\',[StringComparison]::OrdinalIgnoreCase)})){throw 'Fixture outside owned roots'}
    $item=Get-Item -LiteralPath $path
    if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Fixture link refused'}
    if((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -ne $f.sha256){throw 'Fixture changed since ownership record'}
}
foreach($f in $m.files){Remove-Item -LiteralPath $f.path}
foreach($path in $allowed){if(@(Get-ChildItem -LiteralPath $path -Force).Count){throw 'Unexpected fixture directory residue'};Remove-Item -LiteralPath $path}
$temp='C:\Users\WaRZy\AppData\Local\Temp\codex-pixel-060-metadata-audit-20261007'
if((Resolve-Path -LiteralPath $temp).Path -ne $temp -or -not(Test-Path -LiteralPath (Join-Path $temp 'cases.json'))){throw 'Temporary lab ownership failed'}
if(@(Get-ChildItem -LiteralPath $temp -Force -Recurse | Where-Object {$_.Attributes -band [IO.FileAttributes]::ReparsePoint}).Count){throw 'Temporary lab contains a link'}
Remove-Item -LiteralPath $temp -Recurse
if(Test-Path -LiteralPath $temp){throw 'Temporary lab remains'}
Write-Output 'Owned Windows fixtures and temporary laboratory physically absent.'
