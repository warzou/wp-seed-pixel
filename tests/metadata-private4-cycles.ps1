param([switch]$ResumeCycle1AfterRuntime,[string]$ReleaseVersion='0.6.0-private.4')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
if ($root -ne 'C:\Dev\git-worktrees\wp-seed-pixel-png-jpeg-explicit-conversion') {throw 'Unexpected worktree'}
$php='C:\Dev\tools\wpsck-php\php-8.4.23\php.exe'
$python='C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$node='C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$source='/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion'
$linux='/home/warzy/.cache/wp-seed-pixel-060-metadata-lab'
$unc='\\wsl.localhost\Ubuntu\home\warzy\.cache\wp-seed-pixel-060-metadata-lab'
$fixtures='C:\Users\WaRZy\AppData\Local\Temp\codex-pixel-060-metadata-audit-20261007'
$out=Join-Path $root $(if($ReleaseVersion -eq '0.6.0'){'reports\release-0.6.0-20261009'}else{'reports\metadata-private4-20261008'})
function Checked($binary,$arguments) { & $binary @arguments; if($LASTEXITCODE -ne 0){throw "Local gate failed: $binary ($LASTEXITCODE)"} }
function Lab($arguments) {Checked wsl (@('-d','Ubuntu','--','bash',"$source/tests/metadata-lab.sh")+$arguments)}
function Runtime {
    $files=@(Get-ChildItem assets,includes,languages -File)+@(Get-Item LICENSE,README.md,readme.txt,uninstall.php,wp-seed-pixel.php)
    return @($files | Sort-Object FullName | ForEach-Object {@{file=$_.FullName.Substring($root.Length+1).Replace('\','/');sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLower()}})
}
$previous=$env:NODE_PATH
Push-Location $root
try {
    $env:NODE_PATH='C:\Users\WaRZy\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    $frozen=Runtime | ConvertTo-Json -Depth 4 -Compress
    foreach($cycle in 1,2) {
        $dir=Join-Path $out "cycle-$cycle";New-Item -ItemType Directory -Path $dir -Force | Out-Null
        $phpFiles=@(Get-ChildItem includes -Filter '*.php')+@(Get-Item wp-seed-pixel.php,uninstall.php)
        foreach($file in $phpFiles){Checked $php @('-n','-l',$file.FullName)}
        Checked $node @('--check','assets/metadata.js')
        Checked $php @('-n','-d','extension_dir=C:\Dev\tools\wpsck-php\php-8.4.23\ext','-d','extension=gd','-d','memory_limit=512M','tests/metadata.php',$fixtures)
        Checked $php @('-n','tests/metadata-security.php')
        Checked $php @('-n','tests/metadata-public-graph-plan.php',$fixtures)
        Checked $python @('tests/metadata-fixtures.py','verify',$fixtures)
        Lab @('php',"$source/tests/metadata-uploads.php")
        Copy-Item -LiteralPath "$unc\evidence\uploads.json" -Destination $dir
        if($ResumeCycle1AfterRuntime -and $cycle -eq 1){
            foreach($file in ($frozen|ConvertFrom-Json)){
                $copy=Join-Path "$unc\project\wp-seed-pixel-m3-environment\.runtime\wordpress\wp-content\plugins\wp-seed-pixel" $file.file
                if((Get-FileHash -LiteralPath $copy -Algorithm SHA256).Hash.ToLower() -ne $file.sha256){throw 'Runtime resume identity mismatch'}
            }
            foreach($name in 'transaction','admission','pipeline','native-graph','runtime-review','graph-crashes','updater'){
                if(-not(Test-Path -LiteralPath "$unc\evidence\runtime-1\$name.json")){throw 'Runtime resume evidence missing'}
            }
            Write-Output 'Cycle 1 resumes after verified unchanged runtime gates; only native browser fixture/capture assertions changed.'
        }else{Checked wsl @('-d','Ubuntu','--','bash',"$source/tests/metadata-runtime-gates.sh","$cycle")}
        Lab @('php',"$source/tests/metadata-graph-transaction.php",$linux,"final-cycle-$cycle-$(Get-Date -Format 'HHmmss')")
        Lab @('php',"$linux/project/wp-seed-pixel-m3-environment/tests/metadata-browser-state.php",'cleanup')
        Lab @('php',"$linux/project/wp-seed-pixel-m3-environment/tests/metadata-browser-state.php",'prepare')
        try {Checked $node @('tests/metadata-native-browser.cjs',"$unc\browser-private.json",(Join-Path $dir 'browser'))}
        finally {Lab @('php',"$linux/project/wp-seed-pixel-m3-environment/tests/metadata-browser-state.php",'cleanup')}
        if(Test-Path -LiteralPath "$unc\legacy-$cycle") {Lab @('retry-legacy',"$cycle","pre-final-$cycle")}
        Lab @('legacy',"$cycle")
        if((Runtime | ConvertTo-Json -Depth 4 -Compress) -ne $frozen){throw 'Frozen runtime changed'}
        Checked git @('diff','--check')
        Copy-Item -LiteralPath "$fixtures\outputs\parser-checks.json","$fixtures\outputs\independent-proof.json" -Destination $dir
        Copy-Item -LiteralPath "$unc\evidence\runtime-$cycle" -Destination (Join-Path $dir 'native') -Recurse -Force
        Copy-Item -LiteralPath "$unc\evidence\cycle-$cycle" -Destination (Join-Path $dir 'legacy') -Recurse -Force
        @{cycle=$cycle;complete=$true;version=$ReleaseVersion;runtime=($frozen|ConvertFrom-Json);lint=$phpFiles.Count;remote=$false;permanentPurge=$false;installUpdate='separate post-build gate'} | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $dir 'complete.json') -Encoding UTF8
        Write-Output "PRIVATE.4 COMPLETE REGRESSION CYCLE $cycle PASS"
    }
} finally {$env:NODE_PATH=$previous;Pop-Location}
