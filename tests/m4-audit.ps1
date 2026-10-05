$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
Set-Location -LiteralPath $root
$out = Join-Path $root 'reports/storage-m4'
$baseline = Get-Content -LiteralPath (Join-Path $out 'BASELINE-SOURCE-HASHES.json') -Raw | ConvertFrom-Json
$frozen = Get-Content -LiteralPath (Join-Path $out 'FROZEN-PRODUCTION-HASHES.json') -Raw | ConvertFrom-Json
$map = @{}
foreach($entry in $baseline) { $map[$entry.path] = $entry.sha }
$paths = @(& git ls-files --cached --others --exclude-standard) | Sort-Object -Unique
if($LASTEXITCODE) { throw 'Git inventory failed' }
$rows = @(); $changed = @(); $added = @(); $secretHits = @(); $encoding = @()
$utf8 = [Text.UTF8Encoding]::new($false, $true)
foreach($relative in $paths) {
    $file = Join-Path $root $relative
    $sha = (Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash
    $rows += [pscustomobject]@{path=$relative;sha=$sha;bytes=(Get-Item -LiteralPath $file).Length}
    if($map.ContainsKey($relative)) { if($map[$relative] -ne $sha) { $changed += $relative } }
    else { $added += $relative }
    if($relative -match '(?i)(^|/)(\.codex-[^/]*|.*askpass.*|.*\.tmp\.[^/]*|.*\.(zip|patch|sql|exe|pdb))$') { throw "Forbidden source artifact: $relative" }
    if($relative.EndsWith('.mo')) { continue }
    if($relative -match '\.(png|jpe?g|gif|ico|woff2?|ttf)$') {
        if(!$map.ContainsKey($relative) -or $map[$relative] -ne $sha) { throw "Unreviewed media source: $relative" }
        continue
    }
    $bytes = [IO.File]::ReadAllBytes($file)
    $text = $utf8.GetString($bytes)
    if(($changed -contains $relative) -or ($added -contains $relative)) {
        if(($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) -or $text.Contains("`r")) { $encoding += $relative }
    }
    if($text -match '-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AKIA[A-Z0-9]{16}') { $secretHits += $relative }
}
$missing = @($baseline | Where-Object { $_.path -notin $paths } | ForEach-Object path)
if($missing.Count -or $encoding.Count -or $secretHits.Count) { throw ('Source integrity failure: ' + (@($missing)+@($encoding)+@($secretHits) -join ', ')) }
foreach($entry in $frozen) { if((Get-FileHash -LiteralPath (Join-Path $root $entry.path) -Algorithm SHA256).Hash -ne $entry.sha) { throw "Freeze changed: $($entry.path)" } }
& git diff --check
if($LASTEXITCODE) { throw 'git diff --check failed' }
$index = @(& git diff --cached --name-only)
if($index.Count) { throw 'Index must stay empty' }
$zip = Join-Path $root 'dist/wp-seed-pixel-0.3.2.zip'
$zipSha = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
if($zipSha -ne 'B685DD3A2F340862C08626D872710410A934327FD2E069B9BBE115C9BA2F337B' -or (Get-Item -LiteralPath $zip).Length -ne 455161) { throw 'Accepted artifact changed' }
$result = [ordered]@{baseline=$baseline.Count;source_paths=$paths.Count;changed_preexisting=$changed;added=$added;missing=$missing;freeze=$frozen.Count;utf8_no_bom_lf=$true;high_confidence_secret_hits=$secretHits;index=$index;diff_check='PASS';head=(& git rev-parse HEAD);origin_main=(& git rev-parse origin/main);accepted_zip_sha256=$zipSha}
[IO.File]::WriteAllText((Join-Path $out 'source-audit.json'), ($result | ConvertTo-Json -Depth 5) + "`n", [Text.UTF8Encoding]::new($false))
[IO.File]::WriteAllText((Join-Path $out 'FINAL-SOURCE-HASHES.json'), ($rows | ConvertTo-Json -Depth 5) + "`n", [Text.UTF8Encoding]::new($false))
"M4 source audit PASS: $($paths.Count) source paths, $($changed.Count) preexisting changed, $($added.Count) added, 0 missing, $($frozen.Count) frozen production files"
