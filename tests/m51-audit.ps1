$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($root -ne 'C:\Dev\git\wp-seed-pixel') { throw 'Owned Pixel checkout required' }
Set-Location -LiteralPath $root
$baseline = Get-Content -LiteralPath reports/storage-m5.1/BASELINE.json -Raw | ConvertFrom-Json
$source = @(& git ls-files --cached --others --exclude-standard)
if ($LASTEXITCODE) { throw 'Source inventory failed' }
$changes = @()
foreach ($before in $baseline) {
    $path = Join-Path $root $before.file
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Inherited file missing: $($before.file)" }
    $after = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($after -ne $before.sha256) { $changes += $before.file }
}
$added = @($source | Where-Object { $_ -notin $baseline.file })
$utf8 = [Text.UTF8Encoding]::new($false, $true)
$scanned = 0
foreach ($relative in $source) {
    if ($relative -match '(?i)(^|/)(\.codex-[^/]*|.*askpass.*|.*\.tmp\.[^/]*|.*\.(zip|patch|sql|exe|pdb))$') { throw "Forbidden artifact: $relative" }
    if ([IO.Path]::GetExtension($relative) -notin '.md','.php','.in','.css','.js','.cjs','.py','.json','.ps1','.sh','.txt','.po','.svg') { continue }
    $bytes = [IO.File]::ReadAllBytes((Join-Path $root $relative)); $text = $utf8.GetString($bytes)
    if (($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) -or $text.Contains([char]0xfffd)) { throw "UTF-8 error: $relative" }
    if ($relative -in $changes -or $relative -in $added) { if ($text.Contains("`r")) { throw "LF error: $relative" } }
    if ($text -match '-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AKIA[A-Z0-9]{16}|sk-(?:proj-)?[A-Za-z0-9_-]{40,}') { throw "Credential pattern in: $relative" }
    $scanned++
}
foreach ($path in @(Get-ChildItem reports/storage-m5.1 -Recurse -File | Where-Object Extension -in '.md','.json')) {
    $text = $utf8.GetString([IO.File]::ReadAllBytes($path.FullName))
    if ($text -match '-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AKIA[A-Z0-9]{16}|sk-(?:proj-)?[A-Za-z0-9_-]{40,}') { throw 'Credential pattern in M5.1 evidence' }
}
& git diff --check
if ($LASTEXITCODE) { throw 'Diff check failed' }
$index = @(& git diff --cached --name-only)
if ($index.Count) { throw 'Index not empty' }
$head = & git rev-parse HEAD
if ($head -ne '3da4435591488e6089b827c7b581c706e1e4a034') { throw 'HEAD changed' }
$zip = Join-Path $root 'dist/wp-seed-pixel-0.3.2.zip'
$sha = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
if ((Get-Item $zip).Length -ne 455161 -or $sha -ne 'B685DD3A2F340862C08626D872710410A934327FD2E069B9BBE115C9BA2F337B') { throw 'Stable artifact changed' }
$result = [ordered]@{head=$head;branch=(& git branch --show-current);divergence=(& git rev-list --left-right --count origin/main...HEAD);index=$index;scanned_text_files=$scanned;credential_pattern_hits=0;forbidden_artifacts=0;diff_check='PASS';baseline_files=$baseline.Count;changed_from_baseline=$changes;added_since_baseline=$added;inherited_files_deleted=0;stable_artifact_unchanged=$true}
[IO.File]::WriteAllText((Join-Path $root 'reports/storage-m5.1/source-audit.json'), ($result | ConvertTo-Json -Depth 7).Replace("`r`n","`n") + "`n", [Text.UTF8Encoding]::new($false))
"M5.1 audit PASS: $scanned source text files; $($changes.Count) changed/$($added.Count) added; index empty; stable artifact unchanged"
