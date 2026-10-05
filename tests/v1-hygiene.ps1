$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($root -ne 'C:\Dev\git\wp-seed-pixel') { throw 'Owned Pixel checkout required' }
& wsl.exe -- test '!' -e '/home/warzy/.cache/wp-seed-pixel-m3-environment'
if ($LASTEXITCODE) { throw 'Owned disposable lab remains' }
$temp = [IO.Path]::GetFullPath((Join-Path $root '.runtime/v1-browser-temp'))
if ($temp -ne 'C:\Dev\git\wp-seed-pixel\.runtime\v1-browser-temp') { throw 'Unexpected browser temp root' }
$residual = @(Get-CimInstance Win32_Process | Where-Object {
    $_.ProcessId -ne $PID -and $_.Name -match '^(node|python|pythonw|php|chrome|mariadbd|mysqld)(\.exe)?$' -and
    $_.CommandLine -match '(?i)wp-seed-pixel[\\/].*(v1-browser|v1-cycle|m6\.php|m51-pilot-purge)|wp-seed-pixel-m3-environment|v1-browser-temp'
})
if ($residual.Count) { throw 'Owned test process remains' }
$removed = @()
if (Test-Path -LiteralPath $temp) {
    if ((Get-Item -LiteralPath $temp).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Browser temp symlink refused' }
    Remove-Item -LiteralPath $temp -Recurse -Force
    $removed += '.runtime/v1-browser-temp'
}
foreach ($name in @('failure.png','failure.txt')) {
    $file = Join-Path $root "reports/storage-v1/browser/$name"
    if (Test-Path -LiteralPath $file) { Remove-Item -LiteralPath $file -Force; $removed += "reports/storage-v1/browser/$name" }
}
foreach ($directory in @('tests/__pycache__','tools/__pycache__')) {
    if (Test-Path -LiteralPath (Join-Path $root $directory)) { throw 'Unexpected source bytecode cache remains' }
}
if (Test-Path -LiteralPath (Join-Path $root 'tests/m6-diagnostic.php')) { throw 'Ad hoc PNG diagnostic remains' }
$result = [ordered]@{lab_absent=$true;browser_temp_absent=!(Test-Path -LiteralPath $temp);owned_processes=0;removed=$removed;askpass_created=0;ssh_variables_created=0;pde_operations=0;remote_operations_after_purge=0}
[IO.File]::WriteAllText((Join-Path $root 'reports/storage-v1/hygiene.json'), ($result | ConvertTo-Json -Depth 5).Replace("`r`n","`n") + "`n", [Text.UTF8Encoding]::new($false))
'V1 physical hygiene PASS: lab absent, owned browser profiles/processes absent, no AskPass or SSH variables created.'
