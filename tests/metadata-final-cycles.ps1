$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$php = 'C:\Dev\tools\wpsck-php\php-8.4.23\php.exe'
$python = 'C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$node = 'C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$env:NODE_PATH = 'C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
$lab = 'C:\Users\WaRZy\AppData\Local\Temp\codex-pixel-060-metadata-audit-20261007'
$linux = '/home/warzy/.cache/wp-seed-pixel-060-metadata-lab'
$source = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion'
$unc = '\\wsl.localhost\Ubuntu\home\warzy\.cache\wp-seed-pixel-060-metadata-lab'
$out = Join-Path $root 'reports\metadata-private2-20261008'
function Checked($program, $arguments) {
    & $program @arguments
    if ($LASTEXITCODE -ne 0) { throw "Local command failed: $program ($LASTEXITCODE)" }
}
function Lab($arguments) {
    Checked 'wsl' (@('-e','env',"PIXEL_METADATA_SOURCE=$source",'bash',"$linux/frozen-lab.sh") + $arguments)
}
Push-Location $root
try {
    Checked 'wsl' @('-e','bash',"$source/tests/metadata-lab.sh",'freeze')
    Lab @('php',"$linux/project/wp-seed-pixel-m3-environment/tests/metadata-browser-state.php",'cleanup')
    foreach ($cycle in 1,2) {
        $dir = Join-Path $out "cycle-$cycle"
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        $phpFiles = @(Get-ChildItem includes -Filter '*.php' -Recurse) + @(Get-Item wp-seed-pixel.php,uninstall.php)
        foreach ($file in $phpFiles) {
            Checked $php @('-n','-l',$file.FullName)
        }
        Checked $node @('--check','assets/metadata.js')
        Checked $php @('-n','-d','extension_dir=C:\Dev\tools\wpsck-php\php-8.4.23\ext','-d','extension=gd','-d','memory_limit=512M','tests/metadata.php',$lab)
        Checked $php @('-n','tests/metadata-security.php')
        Checked $python @('tests/metadata-fixtures.py','verify',$lab)
        Copy-Item -LiteralPath "$lab\outputs\parser-checks.json","$lab\outputs\independent-proof.json" -Destination $dir
        Lab @('cycle',"$cycle")
        Lab @('php',"$linux/project/wp-seed-pixel-m3-environment/tests/metadata-browser-state.php",'prepare')
        try {
            Checked $node @('tests/metadata-native-browser.cjs',"$unc\browser-private.json",(Join-Path $dir 'browser'))
        } finally {
            Lab @('php',"$linux/project/wp-seed-pixel-m3-environment/tests/metadata-browser-state.php",'cleanup')
        }
        Lab @('export-cycle',"$cycle")
        $archive = Join-Path $dir 'native-evidence.tar'
        $entries = & tar -tf $archive
        if ($LASTEXITCODE -ne 0 -or @($entries | Where-Object { $_ -match '\.\.|^/|^[A-Za-z]:' }).Count) { throw 'Evidence path guard' }
        Checked 'tar' @('-xf',$archive,'-C',$dir)
        Checked $python @('tests/metadata-private2-package.py','verify')
        Checked 'git' @('diff','--check')
        $package = Get-Content -LiteralPath (Join-Path $out 'candidate\runtime-manifest.json') -Raw | ConvertFrom-Json
        @{cycle=$cycle; complete=$true; phpLint=$phpFiles.Count; permanentPurge=$false; remote=$false; frozenPackage=$package.sha256} | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $dir 'complete.json') -Encoding UTF8
        Write-Output "COMPLETE FINAL CYCLE $cycle PASS"
    }
} finally {
    Remove-Item Env:NODE_PATH -ErrorAction SilentlyContinue
    Pop-Location
}
