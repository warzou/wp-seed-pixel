$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (-not (Test-Path -LiteralPath (Join-Path $root '.runtime/wordpress/wp-config.php'))) { throw 'Disposable runtime required' }
$php = $env:PIXEL_QA_PHP
$python = $env:PIXEL_QA_PYTHON
if (-not $php -or -not $python) { throw 'PIXEL_QA_PHP and PIXEL_QA_PYTHON required' }
$flags = @('-d', ('extension_dir=' + (Join-Path (Split-Path $php) 'ext')), '-d', 'extension=gd', '-d', 'extension=exif', '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', '-d', 'extension=mysqli', '-d', 'memory_limit=512M')
$output = Join-Path $root 'reports/storage-m1/regression'
New-Item -ItemType Directory -Path $output -Force | Out-Null
$paths = @('reports/adaptive/data/adaptive-tests.json', 'reports/color/gd-RESULT.json', 'reports/i18n/en_US.html', 'reports/i18n/fr_FR.html', 'reports/i18n/en_US-js.json', 'reports/i18n/fr_FR-js.json', 'reports/i18n/unit-qa.json')
$backup = @{}
foreach ($relative in $paths) {
    $path = Join-Path $root $relative
    if (Test-Path -LiteralPath $path -PathType Leaf) { $backup[$relative] = [IO.File]::ReadAllBytes($path) }
    New-Item -ItemType Directory -Path (Split-Path $path) -Force | Out-Null
}
try {
    & $python (Join-Path $root 'tests/make-fixtures.py')
    if ($LASTEXITCODE) { throw 'Fixture setup failed' }
    & $python (Join-Path $root 'tests/icc-fixtures.py')
    if ($LASTEXITCODE) { throw 'ICC fixture setup failed' }
    foreach ($test in @('i18n.php', 'adaptive.php', 'color.php')) {
        & $php @flags (Join-Path $root ('tests/' + $test))
        if ($LASTEXITCODE) { throw ('Regression failed: ' + $test) }
    }
    foreach ($relative in $paths) {
        $path = Join-Path $root $relative
        if (Test-Path -LiteralPath $path -PathType Leaf) { Copy-Item -LiteralPath $path -Destination (Join-Path $output ([IO.Path]::GetFileName($path))) -Force }
    }
} finally {
    foreach ($relative in $paths) {
        $path = Join-Path $root $relative
        if ($backup.ContainsKey($relative)) { [IO.File]::WriteAllBytes($path, $backup[$relative]) }
        elseif (Test-Path -LiteralPath $path -PathType Leaf) {
            $full = [IO.Path]::GetFullPath($path)
            if (-not $full.StartsWith($root + [IO.Path]::DirectorySeparatorChar)) { throw 'Unexpected cleanup path' }
            Remove-Item -LiteralPath $full
        }
    }
}
