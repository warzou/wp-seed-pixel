$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$lab='C:\Users\WaRZy\AppData\Local\Temp\codex-pixel-060-metadata-audit-20261007'
$php='C:\Dev\tools\wpsck-php\php-8.4.23\php.exe'
$python='C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$node='C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$out=Join-Path $project 'reports\metadata-private1-20261007'
$oldNodePath=$env:NODE_PATH
function Run-Checked([string]$binary,[string[]]$arguments) {
    $result=& $binary @arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw ($result -join "`n") }
    $result | ForEach-Object { $_.ToString() }
}
Push-Location $project
try {
    $env:NODE_PATH='C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    $runtime=@(Get-ChildItem -LiteralPath (Join-Path $project 'includes') -Filter '*.php') + @(Get-Item 'wp-seed-pixel.php','uninstall.php')
    $lines=@('SCOPED LOCAL CYCLES ONLY. WordPress transaction/recovery/full regression remain NOT CERTIFIED.')
    foreach($cycle in 1..2) {
        $lines+="CYCLE $cycle"
        foreach($file in $runtime) { $null=Run-Checked $php @('-n','-l',$file.FullName) }
        $lines+="$($runtime.Count) PHP 8.4.23 runtime syntax checks PASS"
        $null=Run-Checked $node @('--check','assets/metadata.js')
        $lines+=Run-Checked $php @('-n','-d','extension_dir=C:\Dev\tools\wpsck-php\php-8.4.23\ext','-d','extension=gd','-d','memory_limit=512M','tests/metadata.php',$lab)
        $lines+=Run-Checked $php @('-n','tests/metadata-security.php')
        $lines+=Run-Checked $python @('tests/metadata-fixtures.py','verify',$lab)
        (Run-Checked $php @('-n','tests/metadata-ui.php')) | Set-Content -LiteralPath (Join-Path $lab 'outputs\ui.html') -Encoding UTF8
        $lines+=Run-Checked $node @('tests/metadata-ui.cjs',$lab)
        $lines+=Run-Checked $python @('tests/metadata-package.py','verify')
    }
    $lines | Set-Content -LiteralPath (Join-Path $out 'scoped-final-cycles.txt') -Encoding UTF8
    $lines
} finally {
    $env:NODE_PATH=$oldNodePath
    Pop-Location
}
