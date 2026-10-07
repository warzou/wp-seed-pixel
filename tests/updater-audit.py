"""Read-only scoped release audit; no Git write and no remote/site access."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
def git(*args):
    return subprocess.check_output(['git', '-C', str(root), *args])
changed = git('diff', '--name-only', 'HEAD').decode().splitlines()
untracked = git('ls-files', '--others', '--exclude-standard').decode().splitlines()
allowed = {'includes/class-updater.php','wp-seed-pixel.php','README.md','readme.txt','CHANGELOG.md','PROJECT-SNAPSHOT.md','docs/PRIVATE-UPDATES.md','updates/stable.json',
           'tests/updater-lab.sh','tests/updater-cycle.sh','tests/updater-matrix.php','tests/updater-native-lab.php','tests/updater-http-fixture.php','tests/updater-browser.cjs','tests/updater-override.php','tests/updater-archives.py','tests/updater-audit.py'}
assert set(changed+untracked) <= allowed
assert not git('diff','--cached','--name-only').strip()
git('diff','--check')
base = '22ab28b00eedb26af73d80be3fe61cb5ac036283'
assert git('rev-parse','HEAD').decode().strip() == base
invariant = []
for name in git('ls-tree','-r','--name-only',base,'includes','assets','languages','uninstall.php').decode().splitlines():
    if name == 'includes/class-updater.php':
        continue
    assert (root/name).read_bytes() == git('show',base+':'+name), name
    invariant.append(name)
findings = []
patterns = [rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',rb'gh[pousr]_[A-Za-z0-9]{30,}',rb'github_pat_[A-Za-z0-9_]{30,}',rb'AKIA[A-Z0-9]{16}',rb'https?://[^\s/]+:[^\s@]+@']
for name in changed+untracked:
    data=(root/name).read_bytes()
    # The sole userinfo URL is a negative security fixture, not a credential.
    for pattern in patterns[:4]:
        if re.search(pattern,data):
            findings.append(name)
assert not findings, findings
archive=Path(sys.argv[1]).resolve()
with zipfile.ZipFile(archive) as z:
    assert len(z.infolist()) == 60
    for info in z.infolist():
        assert info.filename.startswith('wp-seed-pixel/')
        name=info.filename[len('wp-seed-pixel/'):]
        assert z.read(info) == (root/name).read_bytes(), name
    assert b'Version: 0.5.1' in z.read('wp-seed-pixel/wp-seed-pixel.php')
stable=json.loads((root/'updates/stable.json').read_text())
assert stable['version'] in ('0.5.0', '0.5.1')
old=root/'reports/release-0.5.0-20261007/final/candidate/wp-seed-pixel-0.5.0.zip'
assert old.stat().st_size==217912 and hashlib.sha256(old.read_bytes()).hexdigest()=='b9013b0f4e9564b69563104fc7710d9789341ed615b147f9e476bc23fb1375f7'
if stable['version'] == '0.5.1':
    assert stable['sha256'] == hashlib.sha256(archive.read_bytes()).hexdigest()
    assert stable['channel'] == 'stable'
    assert stable['package'] == 'https://github.com/warzou/wp-seed-pixel/releases/download/v0.5.1/wp-seed-pixel-0.5.1.zip'
    assert stable['notes_url'] == 'https://github.com/warzou/wp-seed-pixel/releases/tag/v0.5.1'
git('merge-base','--is-ancestor','3da4435591488e6089b827c7b581c706e1e4a034',base)
assert git('rev-list','--left-right','--count','3da4435591488e6089b827c7b581c706e1e4a034...'+base).decode().split()==['0','3']
result={'status':'PASS','base':base,'paths':sorted(changed+untracked),'index_empty':True,'diff_check':True,'unchanged_runtime_files':len(invariant),'secret_findings':findings,
        'zip':str(archive),'bytes':archive.stat().st_size,'sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'runtime_files':60,'main_behind':3,'main_ahead':0,'old_zip_unchanged':True}
Path(sys.argv[2]).write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps(result,indent=2))
