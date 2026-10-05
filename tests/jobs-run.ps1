$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$php = 'C:\Dev\tools\wpsck-php\php-8.4.23\php.exe'
$python = 'C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$flags = @('-d', ('extension_dir=' + (Join-Path (Split-Path $php) 'ext')), '-d', 'extension=gd', '-d', 'extension=exif', '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', '-d', 'extension=mysqli', '-d', 'memory_limit=512M')
$m1 = Join-Path $root 'reports/storage-m1'
$backup = @{}
Get-ChildItem -LiteralPath $m1 -Recurse -File | ForEach-Object { $backup[$_.FullName] = [IO.File]::ReadAllBytes($_.FullName) }
$junction = Join-Path $root '.runtime/wordpress/wp-content/uploads/m1-link-test'
try {
    & $python (Join-Path $root 'tests/make-fixtures.py')
    if ($LASTEXITCODE) { throw 'Fixture generation failed' }
    & $python (Join-Path $root 'tests/icc-fixtures.py')
    if ($LASTEXITCODE) { throw 'ICC fixture generation failed' }
    foreach ($test in @('storage-install.php', 'storage-analyzer.php', 'storage-edge.php')) {
        & $php @flags (Join-Path $PSScriptRoot $test)
        if ($LASTEXITCODE) { throw $test }
    }
    New-Item -ItemType Junction -Path $junction -Target (Join-Path $root '.runtime/fixtures') | Out-Null
    foreach ($test in @('storage-matrix.php', 'storage-rescan.php')) {
        & $php @flags (Join-Path $PSScriptRoot $test)
        if ($LASTEXITCODE) { throw $test }
    }
    Copy-Item -LiteralPath (Join-Path $m1 'integration.json') -Destination (Join-Path $root '.runtime/m1-integration.json')
    $out = Join-Path $root 'reports/storage-m2/m1-regression'
    New-Item -ItemType Directory -Path $out -Force | Out-Null
    foreach ($name in @('integration.json', 'edge.json', 'matrix.json', 'rescan.json')) { Copy-Item -LiteralPath (Join-Path $m1 $name) -Destination (Join-Path $out $name) -Force }
    & $php @flags (Join-Path $PSScriptRoot 'jobs.php')
    if ($LASTEXITCODE) { throw 'M2 integration failed' }
} finally {
    if (Test-Path -LiteralPath $junction) {
        $resolved = [IO.Path]::GetFullPath($junction)
        if (-not $resolved.StartsWith($root + '\.runtime\')) { throw 'Unexpected junction target' }
        Remove-Item -LiteralPath $resolved -Force
    }
    foreach ($path in $backup.Keys) { [IO.File]::WriteAllBytes($path, $backup[$path]) }
}
