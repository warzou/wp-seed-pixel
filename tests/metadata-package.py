"""Runtime-only private package and read-only isolation audit. Never publishes."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'reports/metadata-private1-20261007'
VERSION='0.6.0-private.1'
BASE='80dbca30dea54e857270f767ebd2c2d30765c222'

def git(*args):
    return subprocess.check_output(['git','-C',str(ROOT),*args])

def sha(data):return hashlib.sha256(data).hexdigest()

runtime=sorted([p.relative_to(ROOT).as_posix() for directory in ['assets','includes','languages'] for p in (ROOT/directory).iterdir() if p.is_file()]
               +['LICENSE','README.md','readme.txt','uninstall.php','wp-seed-pixel.php'])
assert len(runtime)==64
assert git('rev-parse','HEAD').decode().strip()==BASE
assert not git('diff','--cached','--name-only').strip()
git('diff','--check')
for name in ['includes/class-adaptive.php','includes/class-png-processor.php','includes/class-format-processor.php',
             'includes/class-format-conversion.php','includes/class-updater.php','includes/class-future-uploads.php',
             'includes/class-authority.php','includes/class-job-store.php','updates/stable.json']:
    assert (ROOT/name).read_bytes()==git('show',f'{BASE}:{name}'),name

paths=sorted(set(git('diff','--name-only').decode().splitlines()+git('ls-files','--others','--exclude-standard').decode().splitlines()))
findings=[]
for name in paths:
    assert not any(p in name.split('/') for p in ['reports','.runtime','.git','node_modules'])
    assert not name.lower().endswith(('.zip','.patch','.sql','.exe','.pdb'))
    assert '.codex-' not in name and 'askpass' not in name.lower()
    data=(ROOT/name).read_bytes()
    for pattern in [rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',rb'gh[pousr]_[A-Za-z0-9]{30,}',rb'github_pat_[A-Za-z0-9_]{30,}',rb'AKIA[A-Z0-9]{16}',rb'https?://[^\s/]+:[^\s@]+@']:
        if re.search(pattern,data):findings.append(name)
    if not name.endswith('.mo'):
        text=data.decode('utf-8')
        assert '\ufffd' not in text,name
assert not findings,findings
assert b'const WRITE_CERTIFIED = false;' in (ROOT/'includes/class-metadata.php').read_bytes()
assert b'Version: 0.6.0-private.1' in (ROOT/'wp-seed-pixel.php').read_bytes()

directory=OUT/'candidate';directory.mkdir(parents=True,exist_ok=True)
archive=directory/f'wp-seed-pixel-{VERSION}.zip'
manifest=directory/'runtime-manifest.json'
if sys.argv[1]=='build':
    assert not archive.exists(),'Refuse to overwrite an existing candidate'
    with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name in runtime:
            info=zipfile.ZipInfo('wp-seed-pixel/'+name,(2026,10,7,0,0,0))
            info.compress_type=zipfile.ZIP_DEFLATED;info.create_system=3;info.external_attr=0o100644<<16
            z.writestr(info,(ROOT/name).read_bytes())
    result={'version':VERSION,'status':'PARTIAL; transaction writes gated','runtime_files':len(runtime),
            'zip':archive.name,'bytes':archive.stat().st_size,'sha256':sha(archive.read_bytes()),
            'files':[{'file':name,'bytes':(ROOT/name).stat().st_size,'sha256':sha((ROOT/name).read_bytes())} for name in runtime]}
    manifest.write_text(json.dumps(result,indent=2),encoding='utf-8')
    (directory/(archive.name+'.sha256')).write_text(result['sha256']+'  '+archive.name+'\n')

result=json.loads(manifest.read_text())
assert archive.stat().st_size==result['bytes'] and sha(archive.read_bytes())==result['sha256']
with zipfile.ZipFile(archive) as z:
    assert z.namelist()==['wp-seed-pixel/'+name for name in runtime]
    for file in result['files']:
        current=(ROOT/file['file']).read_bytes()
        assert sha(current)==file['sha256'] and z.read('wp-seed-pixel/'+file['file'])==current
old=ROOT/'reports/release-0.5.1-20261007/final/candidate/wp-seed-pixel-0.5.1.zip'
assert old.stat().st_size==218958 and sha(old.read_bytes())=='9cf806c71e218454cbe6063d1b2f63a7be08fbcc808bd7f521c9d9a77e71b439'
audit={'status':'PASS scoped identity/hygiene audit; NOT full release gate','index_empty':True,'diff_check':True,
       'secret_findings':findings,'stable_manifest_unchanged':True,'official_051_zip_unchanged':True,
       'runtime_files':64,'zip_bytes':result['bytes'],'zip_sha256':result['sha256'],'paths':paths}
(OUT/'package-audit.json').write_text(json.dumps(audit,indent=2),encoding='utf-8')
print(json.dumps({k:v for k,v in audit.items() if k!='paths'},indent=2))
