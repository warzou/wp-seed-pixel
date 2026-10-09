$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
if($root -ne 'C:\Dev\git-worktrees\wp-seed-pixel-png-jpeg-explicit-conversion'){throw 'Unexpected source root'}
$source='/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion'
$unc='\\wsl.localhost\Ubuntu\home\warzy\.cache\wp-seed-pixel-060-metadata-lab'
$relative='/reports/release-0.6.0-20261009/candidate/wp-seed-pixel-0.6.0.zip'
$zip=Join-Path $root $relative.TrimStart('/').Replace('/','\')
$hash=(Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
function Lab($policy,$arguments) {
    & wsl -d Ubuntu -- env PIXEL_RELEASE_VERSION=0.6.0 "PIXEL_RELEASE_ZIP=$relative" "PIXEL_RELEASE_POLICY=$policy" bash "$source/tests/metadata-lab.sh" @arguments
    if($LASTEXITCODE -ne 0){throw 'Native final package gate failed'}
}
foreach($policy in 'absent','off') {
    Lab $policy @('native-update-private4')
    Lab $policy @('php',"$source/tests/metadata-release-runtime.php",'main')
}
Lab 'absent' @('clean-install-private4')
Lab 'absent' @('php',"$source/tests/metadata-release-runtime.php",'clean')
if((Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash -ne $hash){throw 'Frozen final ZIP changed'}
$out=Join-Path $root 'reports\release-0.6.0-20261009\install'
New-Item -ItemType Directory -Path $out -Force | Out-Null
foreach($file in 'install-absent.json','install-off.json','clean-install.json','release-runtime-main.json','release-runtime-clean.json') {
    Copy-Item -LiteralPath "$unc\evidence\$file" -Destination $out
}
Write-Output 'Final native ZIP clean installation, upgrade ON/OFF and exact runtime identity PASS.'
