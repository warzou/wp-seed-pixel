"""Immutable runtime-only local candidate. No publication or site access."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'reports/metadata-private2-20261008'
VERSION='0.6.0-private.2'
BASE='80dbca30dea54e857270f767ebd2c2d30765c222'
def git(*args): return subprocess.check_output(['git','-C',str(ROOT),*args])
def sha(data): return hashlib.sha256(data).hexdigest()
runtime=sorted([p.relative_to(ROOT).as_posix() for d in ['assets','includes','languages'] for p in (ROOT/d).iterdir() if p.is_file()]+['LICENSE','README.md','readme.txt','uninstall.php','wp-seed-pixel.php'])
assert len(runtime)==64
assert git('rev-parse','HEAD').decode().strip()==BASE
assert not git('diff','--cached','--name-only').strip()
git('diff','--check')
for name in ['includes/class-adaptive.php','includes/class-png-processor.php','includes/class-format-processor.php','includes/class-updater.php','includes/class-future-uploads.php','includes/class-authority.php','includes/class-job-store.php','updates/stable.json']:
    assert (ROOT/name).read_bytes()==git('show',f'{BASE}:{name}'),name
paths=sorted(set(git('diff','--name-only').decode().splitlines()+git('ls-files','--others','--exclude-standard').decode().splitlines()))
for name in paths:
    assert not any(part in name.split('/') for part in ['reports','.runtime','.git','node_modules'])
    assert not name.lower().endswith(('.zip','.patch','.sql','.exe','.pdb','.jpg','.jpeg','.png'))
    assert '.codex-' not in name and 'askpass' not in name.lower()
    data=(ROOT/name).read_bytes()
    for pattern in [rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',rb'gh[pousr]_[A-Za-z0-9]{30,}',rb'github_pat_[A-Za-z0-9_]{30,}',rb'AKIA[A-Z0-9]{16}',rb'https?://[^\s/]+:[^\s@]+@']:
        assert not re.search(pattern,data),'Credential signature: '+name
    if not name.endswith('.mo'): assert '\ufffd' not in data.decode('utf-8'),name
assert b'const WRITE_CERTIFIED = true;' in (ROOT/'includes/class-metadata.php').read_bytes()
assert b'Version: 0.6.0-private.2' in (ROOT/'wp-seed-pixel.php').read_bytes()
old=ROOT/'reports/release-0.5.1-20261007/final/candidate/wp-seed-pixel-0.5.1.zip'
assert old.stat().st_size==218958 and sha(old.read_bytes())=='9cf806c71e218454cbe6063d1b2f63a7be08fbcc808bd7f521c9d9a77e71b439'
old=ROOT/'reports/metadata-private1-20261007/candidate/wp-seed-pixel-0.6.0-private.1.zip'
assert old.stat().st_size==235201 and sha(old.read_bytes())=='48ecbe9b9d4f091f45f83fc28ab0a36e7ffcca8c83db0a526cf69b4a325c4cca'
directory=OUT/'candidate';directory.mkdir(parents=True,exist_ok=True)
archive=directory/f'wp-seed-pixel-{VERSION}.zip';manifest=directory/'runtime-manifest.json'
if sys.argv[1]=='build':
    assert not archive.exists(),'Do not overwrite a frozen candidate'
    with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name in runtime:
            info=zipfile.ZipInfo('wp-seed-pixel/'+name,(2026,10,8,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.create_system=3;info.external_attr=0o100644<<16
            z.writestr(info,(ROOT/name).read_bytes())
    record={'version':VERSION,'bytes':archive.stat().st_size,'sha256':sha(archive.read_bytes()),'runtime_files':len(runtime),'files':[{'file':name,'sha256':sha((ROOT/name).read_bytes())} for name in runtime]}
    manifest.write_text(json.dumps(record,indent=2),encoding='utf-8')
    (directory/(archive.name+'.sha256')).write_text(record['sha256']+'  '+archive.name+'\n',encoding='ascii')
record=json.loads(manifest.read_text())
assert archive.stat().st_size==record['bytes'] and sha(archive.read_bytes())==record['sha256']
with zipfile.ZipFile(archive) as z:
    assert z.namelist()==['wp-seed-pixel/'+name for name in runtime]
    for f in record['files']:
        data=(ROOT/f['file']).read_bytes();assert sha(data)==f['sha256'] and z.read('wp-seed-pixel/'+f['file'])==data
audit={'version':VERSION,'package_identity':'PASS','runtime_files':64,'bytes':record['bytes'],'sha256':record['sha256'],'secret_findings':0,'index_empty':True,'diff_check':True,'private1_zip_unchanged':True,'official051_zip_unchanged':True,'stable_manifest_unchanged':True,'paths':paths}
(OUT/'package-audit.json').write_text(json.dumps(audit,indent=2),encoding='utf-8')
print(json.dumps({k:v for k,v in audit.items() if k!='paths'},indent=2))
