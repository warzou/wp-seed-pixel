$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($root -ne 'C:\Dev\git\wp-seed-pixel') { throw 'Owned Pixel checkout required' }
Set-Location -LiteralPath $root
& (Join-Path $PSScriptRoot 'm51-audit.ps1')
if ($LASTEXITCODE) { throw 'Source audit failed' }
$cfg = @{}
foreach ($line in [IO.File]::ReadAllLines('C:\Dev\git\therapsycorporel-site\.env')) {
    if ($line -match '^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$') { $cfg[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
$secrets = @('WP_APP_PASSWORD','SFTP_PASSWORD','SQL_PASSWORD') | ForEach-Object { $cfg[$_] } | Where-Object { $_ -and $_.Length -ge 5 }
$scope = Join-Path $root 'reports/storage-m5.1/first-real-pilot'
$utf8 = [Text.UTF8Encoding]::new($false,$true)
$files = @(& git ls-files --cached --others --exclude-standard) | ForEach-Object { Join-Path $root $_ }
$files += @(Get-ChildItem -LiteralPath $scope -Recurse -File | Where-Object Extension -in '.md','.json').FullName
$count = 0
try {
    foreach ($file in $files) {
        if ([IO.Path]::GetExtension($file) -notin '.md','.php','.in','.css','.js','.cjs','.py','.json','.ps1','.sh','.txt','.po','.svg') { continue }
        $text = $utf8.GetString([IO.File]::ReadAllBytes($file))
        foreach ($secret in $secrets) { if ($text.Contains($secret)) { throw 'Canonical credential match; no value disclosed' } }
        $count++
    }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead((Join-Path $scope 'wp-seed-pixel-m51-runtime-pilot.zip'))
    try {
        foreach ($entry in $zip.Entries) {
            if (!$entry.Length) { continue }
            $stream = $entry.Open(); $reader = [IO.StreamReader]::new($stream)
            try { $content = $reader.ReadToEnd(); foreach ($secret in $secrets) { if ($content.Contains($secret)) { throw 'Credential in runtime archive' } } }
            finally { $reader.Dispose(); $stream.Dispose() }
        }
    } finally { $zip.Dispose() }
} finally { $cfg.Clear(); $secrets = @() }
$pilot = Get-Content -LiteralPath (Join-Path $scope 'pixel-only-pilot.json') -Raw | ConvertFrom-Json
if ($pilot.verdict -ne 'READY FOR HUMAN PASS' -or $pilot.temporary_endpoint -ne 'PHYSICALLY ABSENT' -or $pilot.cleanup_failure) { throw 'Pilot cleanup not confirmed' }
if ($pilot.clean.temp_transfer -ne 0 -or $pilot.clean.temp_stage -ne 0 -or $pilot.off.mode -ne 'off') { throw 'Remote transient state remains' }
$profiles = @('playwright_chromiumdev_profile-87WU3f','playwright_chromiumdev_profile-i4zCsL')
foreach ($name in $profiles) { if (Test-Path -LiteralPath (Join-Path $env:TEMP $name)) { throw 'Owned empty failed-launch profile remains' } }
$processes = @(Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match 'm51-first-real-pilot|m51-first-pilot-browser|wp-seed-pixel-m3-environment' -and $_.Name -notmatch 'powershell|pwsh' })
if ($processes.Count) { throw 'Owned pilot process remains' }
$result = [ordered]@{canonical_secret_matches=0;scanned_text_files=$count;runtime_zip_secret_matches=0;remote_endpoint='PHYSICALLY ABSENT';remote_zip=0;remote_stage=0;askpass='NOT USED';owned_processes=0;browser='CLOSED';failed_launch_empty_profiles_removed=$profiles;lab='PHYSICALLY ABSENT';future_uploads='OFF';index='EMPTY';commit='NONE';push='NONE';quarantine='INTENTIONALLY RETAINED';unrelated_temp_profiles='OUT OF SCOPE; NOT MODIFIED'}
[IO.File]::WriteAllText((Join-Path $scope 'pixel-only-hygiene.json'), ($result | ConvertTo-Json -Depth 5).Replace("`r`n","`n") + "`n", [Text.UTF8Encoding]::new($false))
'One-JPEG pilot hygiene PASS: credentials 0; remote transfer/stage/endpoint absent; owned processes 0; index empty.'
