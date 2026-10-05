"""Owner-authorized M4 purge of the one accepted photographic pilot only."""
import argparse
from datetime import datetime, timezone
import importlib.util
import io
import json
from pathlib import Path
import secrets
import subprocess
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    spec = importlib.util.spec_from_file_location('pixel_pilot', root / 'tests/m51-first-real-pilot.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    manifest = module.frozen_gate()
    output = module.OUT / 'pilot-purge.json'
    module.require(not output.exists(), 'PURGE_EVIDENCE_EXISTS_REVIEW_NOT_RERUN')
    cfg = module.configuration()
    nonce = secrets.token_hex(12)
    token = secrets.token_hex(48)
    _, base = module.render(manifest, cfg, nonce, token, int(time.time()) + 3600)
    common = base.decode().split('function pp_assert', 1)[1].split('register_shutdown_function', 1)[0]
    common = 'function pp_assert' + common
    template = (root / 'tests/m51-pilot-purge.php.in').read_text(encoding='utf-8')
    endpoint = template.replace('__COMMON__', common).replace('__TOKEN_HASH__', module.digest(token.encode())).replace('__DEADLINE__', str(int(time.time()) + 3600)).encode()
    lint = subprocess.run(['wsl.exe', '--', 'php', '-l'], input=endpoint, capture_output=True)
    module.require(lint.returncode == 0, 'PURGE_HARNESS_LINT')
    if not args.run:
        cfg.clear()
        print('Purge-only harness PHP lint and frozen candidate PASS; no network.')
        return 0
    pilot = module.Pilot(cfg, nonce, token, endpoint)
    pilot.remote_name = '/www/pixel-one-purge-' + nonce + '.php'
    pilot.url = module.SITE + '/pixel-one-purge-' + nonce + '.php'
    pilot.evidence.update({'utc': datetime.now(timezone.utc).isoformat(), 'scope': 'attachment 4395 / job 1 / item 1 only', 'human_pass': 'PASS', 'verdict': 'BLOCKED'})
    try:
        pilot.preflight()
        pilot.connect()
        pilot.upload_endpoint()
        pilot.action('check')
        pilot.action('purge')
        verify = pilot.action('verify')
        before = pilot.evidence['check']['native']
        module.require(verify['native'] == before, 'POST_PURGE_NATIVE')
        payload, _ = pilot.http(before['url'])
        module.require(module.digest(payload) == 'cf5c7a111b32f6f3cde57b9a55c24c40449cb6b0e88f4148ae518ac0ea5b0035', 'POST_PURGE_CANONICAL_SHA')
        rest, _ = pilot.http(module.SITE + '/wp-json/wp/v2/media/4395', headers={'Authorization': pilot.auth})
        data = json.loads(rest)
        module.require(data['id'] == 4395 and data['media_details']['width'] == 1536 and data['media_details']['height'] == 1024, 'POST_PURGE_REST')
        for size in data['media_details']['sizes'].values():
            url = size['source_url']
            body, _ = pilot.http(url)
            name = before['relative'].rsplit('/', 1)[0] + '/' + size['file']
            module.require(module.digest(body) == before['files'][name]['sha256'], 'POST_PURGE_DERIVATIVE')
        pilot.http(module.SITE + '/')
        pilot.evidence['http_qa'] = {'canonical_sha': 'PASS', 'rest': 'PASS', 'srcset_derivatives': 'PASS', 'bounded_site_health': 'PASS 200', 'media_library': 'native attachment/API PASS; admin UI not tested'}
        pilot.success = True
        pilot.evidence['verdict'] = 'FIRST REAL JPEG LIFECYCLE CERTIFIED ON THERAPSYCORPOREL'
    except Exception as error:
        pilot.evidence['failure'] = str(error) if type(error) is RuntimeError else type(error).__name__
    finally:
        pilot.cleanup()
        output.write_text(json.dumps(pilot.evidence, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'verdict': pilot.evidence['verdict'], 'failure': pilot.evidence.get('failure'), 'endpoint': pilot.evidence.get('temporary_endpoint'), 'cleanup_failure': pilot.evidence.get('cleanup_failure')}))
    return 0 if pilot.success and not pilot.evidence.get('cleanup_failure') else 1


if __name__ == '__main__':
    raise SystemExit(main())
