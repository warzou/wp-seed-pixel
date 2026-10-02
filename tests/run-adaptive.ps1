param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Python,[Parameter(Mandatory=$true)][string]$Node,[Parameter(Mandatory=$true)][string]$NodeModules,[int]$Cycle=2)
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
if((Get-Location).Path -ne $root){throw 'Run in the Pixel project only.'}
$dest=Join-Path $root "reports/adaptive/cycle-$Cycle"
New-Item -ItemType Directory -Path $dest -Force | Out-Null
& $Python tools/prepare-runtime.py
if($LASTEXITCODE){throw 'Prepare failed'}
& $Python tests/make-fixtures.py
if($LASTEXITCODE){throw 'Fixtures failed'}
$a=@('-d',"extension_dir=$(Split-Path $Php)/ext",'-d','extension=gd','-d','extension=exif','-d','extension=mbstring','-d','extension=pdo_sqlite','-d','extension=mysqli','-d','memory_limit=512M','-d','disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec')
foreach($test in @('integration','adversarial','adaptive','adaptive-adversarial')){
    & $Php @a "tests/$test.php"
    if($LASTEXITCODE){throw "Failed $test"}
}
& $Python tests/visual-assertions.py $Php
if($LASTEXITCODE){throw 'Visual assertions failed'}
$oldPhp=$env:PIXEL_QA_PHP
$oldNode=$env:NODE_PATH
try{
    $env:PIXEL_QA_PHP=$Php
    $env:NODE_PATH=$NodeModules
    & $Node tests/browser.cjs
    if($LASTEXITCODE){throw 'Browser failed'}
}finally{
    $env:PIXEL_QA_PHP=$oldPhp
    $env:NODE_PATH=$oldNode
}
foreach($name in @('integration-results.json','adversarial-results.json','visual-assertions.json','browser-results.json')){
    $file=Join-Path $root "reports/final/$name"
    if(Test-Path -LiteralPath $file){Copy-Item -LiteralPath $file -Destination $dest}
}
Copy-Item -LiteralPath (Join-Path $root 'reports/adaptive/data/adaptive-tests.json') -Destination $dest
Copy-Item -LiteralPath (Join-Path $root 'reports/adaptive/data/adaptive-adversarial-tests.json') -Destination $dest
foreach($name in @('admin-1440.png','admin-820.png','admin-390.png','admin-320.png','media-action-1440.png','media-action-320.png')){
    Copy-Item -LiteralPath (Join-Path $root "reports/final/$name") -Destination $dest
}
& git -c "safe.directory=$root" diff --check
if($LASTEXITCODE){throw 'Diff check failed'}
Write-Output "Cycle $Cycle completed; all required test commands succeeded."
