$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($root -ne 'C:\Dev\git\wp-seed-pixel') { throw 'Owned Pixel checkout required' }
Set-Location -LiteralPath $root
& "$PSScriptRoot/m5-freeze.ps1" -Verify
$files = @('PROJECT-SNAPSHOT.md', 'docs/STORAGE-BULK.md', 'includes/class-jobs.php',
    'includes/class-quarantine.php', 'includes/class-bulk-admin.php', 'includes/class-quarantine-admin.php',
    'includes/class-job-store.php', 'includes/class-jobs-admin.php', 'includes/i18n-messages.php',
    'tools/i18n.py', 'tools/i18n-fr.json', 'assets/bulk.js', 'assets/bulk.css',
    'tests/runtime.php', 'tests/m3-runtime.php', 'tests/local-php.cjs',
    'tests/adversarial.php', 'tests/adaptive-adversarial.php')
$files += @(Get-ChildItem tests/m5* -File | ForEach-Object { $_.FullName.Substring($root.Length + 1) })
$files += @(Get-ChildItem reports/storage-m5 -Recurse -File | Where-Object Extension -in '.md','.json' | ForEach-Object { $_.FullName.Substring($root.Length + 1) })
$utf8 = [Text.UTF8Encoding]::new($false, $true)
$rows = @()
foreach ($relative in ($files | Sort-Object -Unique)) {
    $path = Join-Path $root $relative
    $bytes = [IO.File]::ReadAllBytes($path)
    $text = $utf8.GetString($bytes)
    if (($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) -or $text.Contains("`r") -or $text.Contains([char]0xfffd)) { throw "Encoding failed: $relative" }
    if ($text -match '-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AKIA[A-Z0-9]{16}|sk-(?:proj-)?[A-Za-z0-9_-]{40,}') { throw "Credential pattern detected: $relative" }
    $rows += [ordered]@{path=$relative;bytes=$bytes.Length;sha256=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash}
}
$source = @(& git ls-files --cached --others --exclude-standard)
if ($LASTEXITCODE) { throw 'Git inventory failed' }
$forbidden = @($source | Where-Object { $_ -match '(?i)(^|/)(\.codex-[^/]*|.*askpass.*|.*\.tmp\.[^/]*|.*\.(zip|patch|sql|exe|pdb))$' })
if ($forbidden.Count) { throw 'Forbidden source artifact' }
& git diff --check
if ($LASTEXITCODE) { throw 'Diff check failed' }
$index = @(& git diff --cached --name-only)
if ($index.Count) { throw 'Index not empty' }
$zip = Join-Path $root 'dist/wp-seed-pixel-0.3.2.zip'
$sha = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
if ((Get-Item $zip).Length -ne 455161 -or $sha -ne 'B685DD3A2F340862C08626D872710410A934327FD2E069B9BBE115C9BA2F337B') { throw 'Stable artifact changed' }
$head = & git rev-parse HEAD
if ($head -ne '3da4435591488e6089b827c7b581c706e1e4a034') { throw 'HEAD changed' }
$result = [ordered]@{head=$head;branch=(& git branch --show-current);scanned_text_files=$rows.Count;utf8_no_bom_lf=$true;credential_pattern_hits=0;forbidden_source_artifacts=0;diff_check='PASS';index=$index;stable_zip_bytes=455161;stable_zip_sha256=$sha;scope='M5 changes and evidence, not a universal inherited-checkout guarantee';files=$rows}
[IO.File]::WriteAllText((Join-Path $root 'reports/storage-m5/source-audit.json'), ($result | ConvertTo-Json -Depth 6).Replace("`r`n","`n") + "`n", [Text.UTF8Encoding]::new($false))
"M5 bounded source audit PASS: $($rows.Count) UTF-8/LF text files; index empty; artifact unchanged"
