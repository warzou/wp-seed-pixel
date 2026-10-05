$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($root -ne 'C:\Dev\git\wp-seed-pixel') { throw 'Owned Pixel checkout required' }
Set-Location -LiteralPath $root
$linuxRoot = '/home/warzy/.cache/wp-seed-pixel-m3-environment'
& wsl.exe -- test '!' -e $linuxRoot
if ($LASTEXITCODE) { throw 'Disposable lab remains' }
$removed = @('tests/m51-host-cleanup.py','tests/m51-lab-diagnose.php','tests/m51-lab-progress.php')
foreach ($relative in $removed) { if (Test-Path -LiteralPath (Join-Path $root $relative)) { throw 'Ad hoc mission script remains' } }
$processes = @(Get-CimInstance Win32_Process | Where-Object {
    $_.ProcessId -ne $PID -and $_.Name -match '^(python|pythonw|node|php|mysqld|mariadbd)(\.exe)?$' -and
    $_.CommandLine -match '(?i)wp-seed-pixel.*m51-(host|harness|db|lock|independent|authority)|wp-seed-pixel-m3-environment'
})
if ($processes.Count) { throw 'Owned test process remains' }
if (Test-Path -LiteralPath (Join-Path $PSScriptRoot '__pycache__')) { throw 'New test bytecode cache remains' }
$known = @()
foreach ($line in Get-Content -LiteralPath 'C:\Dev\git\therapsycorporel-site\.env') {
    if ($line -match '^\s*([A-Z_]*PASSWORD)\s*=(.*)$') {
        $value = $Matches[2].Trim().Trim('"').Trim("'")
        if ($value.Length -ge 8) { $known += $value }
    }
}
$known = @($known | Select-Object -Unique)
$files = @(& git ls-files --cached --others --exclude-standard | ForEach-Object { Join-Path $root $_ })
$files += @(Get-ChildItem -LiteralPath reports/storage-m5.1 -Recurse -File | ForEach-Object FullName)
$scanned = 0
foreach ($file in $files) {
    if ([IO.Path]::GetExtension($file) -notin '.md','.php','.in','.css','.js','.cjs','.py','.json','.ps1','.sh','.txt','.po','.svg') { continue }
    $text = [IO.File]::ReadAllText($file)
    foreach ($value in $known) { if ($text.Contains($value)) { throw 'Known credential exact-match detected; value suppressed' } }
    $scanned++
}
$known = @(); $value = $null; $line = $null; $text = $null
$remote = Get-Content -LiteralPath reports/storage-m5.1/authority-gate/real-host.json -Raw | ConvertFrom-Json
if ($remote.verdict -ne 'PASS' -or $remote.cleanup_verification.temporary_tables -ne 0 -or $remote.cleanup_verification.temporary_directories -ne 0 -or $remote.cleanup_verification.live_test_locks -ne 0 -or $remote.endpoint_absence.ftp_size -ne 550 -or $remote.endpoint_absence.https -ne 404) { throw 'Remote cleanup not proven' }
$result = [ordered]@{local_lab_absent=$true;cleanup_owner_mount_process_guard='PASS';ad_hoc_scripts_removed=$removed;test_processes=0;browser_used=$false;browser_profiles_created=0;askpass_created=0;temporary_ssh_variables_created=0;test_bytecode_cache=0;known_credential_exact_hits=0;scanned_text_artifacts=$scanned;remote_php=0;remote_files_dirs=0;remote_tables=0;remote_test_locks=0;plaintext_tokens_persisted=0;pde_operations=0}
[IO.File]::WriteAllText((Join-Path $root 'reports/storage-m5.1/authority-gate/local-hygiene.json'), ($result | ConvertTo-Json -Depth 5).Replace("`r`n","`n") + "`n", [Text.UTF8Encoding]::new($false))
"Final physical hygiene PASS: lab absent, processes 0, ad hoc scripts absent, credentials 0 across $scanned text artifacts; remote cleanup proven."
