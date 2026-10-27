import argparse
import grp
import json
import pwd
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser(description='Exercise the root hosting stub through an isolated Apache HTTP server.')
parser.add_argument('--httpd', default='/usr/sbin/apache2')
parser.add_argument('--module-dir', default='/usr/lib/apache2/modules')
parser.add_argument('--library-path')
parser.add_argument('--work-dir')
parser.add_argument('--report')
args = parser.parse_args()
binary = Path(args.httpd)
modules = Path(args.module_dir)
env = dict(os.environ)
if args.library_path:
    env['LD_LIBRARY_PATH'] = args.library_path
source = Path(__file__).resolve().parents[1] / 'stubs/root-htaccess.stub'
results = []
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None
opener = urllib.request.build_opener(NoRedirect)

with tempfile.TemporaryDirectory(prefix='http-', dir=args.work_dir) as temporary:
    workspace = Path(temporary)
    for prefix in ['', '/nested']:
        scenario = workspace / ('root' if not prefix else 'subdirectory')
        project = scenario / 'www' / prefix.lstrip('/')
        project.mkdir(parents=True)
        (project / 'public/storage').mkdir(parents=True)
        (project / 'storage').mkdir()
        (project / 'vendor').mkdir()
        (project / '.git').mkdir()
        (project / '.well-known/acme-challenge').mkdir(parents=True)
        shutil.copyfile(source, project / '.htaccess')
        for filename, content in {
            'public/index.php': 'FRONT_CONTROLLER', 'public/asset.txt': 'PUBLIC_ASSET',
            'public/storage/photo.txt': 'STORAGE_ASSET', 'storage/private.txt': 'PRIVATE_SECRET',
            '.env': 'ENV_SECRET', '.env.server': 'ENV_SECRET', '.env.backup.123': 'ENV_SECRET',
            'vendor/autoload.php': 'VENDOR_SECRET', '.git/config': 'GIT_SECRET',
            'composer.json': 'COMPOSER_SECRET', 'README.md': 'ROOT_SECRET',
            'public/.env.server': 'PUBLIC_ENV_SECRET', '.well-known/acme-challenge/check': 'ACME_OK',
        }.items():
            (project / filename).write_text(content)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        configuration = [f'ServerRoot "{scenario}"', f'Listen 127.0.0.1:{port}', 'ServerName 127.0.0.1',
            f'PidFile "{scenario}/httpd.pid"', f'ErrorLog "{scenario}/error.log"',
            f'DocumentRoot "{scenario}/www"', f'User {pwd.getpwuid(os.getuid()).pw_name}', f'Group {grp.getgrgid(os.getgid()).gr_name}', 'LogLevel warn',
            'EnableSendfile Off', 'TypesConfig /etc/mime.types']
        for name in ['mpm_event', 'authz_core', 'authz_host', 'dir', 'mime', 'rewrite', 'headers']:
            configuration.append(f'LoadModule {name}_module "{modules}/mod_{name}.so"')
        configuration += [f'<Directory "{scenario}/www">', 'AllowOverride All', 'Options FollowSymLinks', 'Require all granted', '</Directory>']
        config = scenario / 'httpd.conf'
        config.write_text('\n'.join(configuration))
        process = subprocess.Popen([str(binary), '-f', str(config), '-DFOREGROUND'], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            for attempt in range(100):
                if process.poll() is not None:
                    raise RuntimeError(process.communicate()[1].decode() + (scenario / 'error.log').read_text())
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            for path, status, expected in [
                ('/', 200, 'FRONT_CONTROLLER'), ('/account/profile?x=1', 200, 'FRONT_CONTROLLER'),
                ('/asset.txt', 200, 'PUBLIC_ASSET'), ('/storage/photo.txt', 200, 'STORAGE_ASSET'),
                ('/storage/private.txt', 200, 'FRONT_CONTROLLER'), ('/vendor/autoload.php', 200, 'FRONT_CONTROLLER'),
                ('/README.md', 200, 'FRONT_CONTROLLER'), ('/.env', 403, None), ('/.env.server', 403, None),
                ('/.env.backup.123', 403, None), ('/public/.env.server', 403, None), ('/.git/config', 403, None),
                ('/composer.json', 403, None), ('/.well-known/acme-challenge/check', 200, 'ACME_OK'),
                ('/public/asset.txt', 301, None), ('/public/account/profile', 301, None),
            ]:
                url = f'http://127.0.0.1:{port}{prefix}{path}'
                try:
                    response = opener.open(url, timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                body = response.read().decode()
                if response.status != status or (expected is not None and body != expected):
                    raise RuntimeError(f'{prefix}{path}: expected {status}/{expected}, got {response.status}/{body[:250]}; log: {(scenario / "error.log").read_text()}')
                if status == 301:
                    destination = response.headers['Location']
                    if '/public/' in destination or not destination.endswith(prefix + path.removeprefix('/public')):
                        raise RuntimeError(f'Incorrect redirect: {destination}')
                    try:
                        with urllib.request.urlopen(destination, timeout=5) as redirected:
                            if redirected.status != 200:
                                raise RuntimeError('Redirect did not reach public resource.')
                    except urllib.error.HTTPError as error:
                        raise RuntimeError(f'Redirect {url} -> {destination}: {error}; {(scenario / "error.log").read_text()}') from error
                results.append({'path': prefix + path, 'status': status})
        finally:
            process.terminate()
            try:
                process.communicate(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.communicate()
if args.report:
    Path(args.report).write_text(json.dumps({'server': subprocess.check_output([str(binary), '-v'], env=env).decode(), 'checks': results}, indent=2))
print(f'Apache HTTP checks passed: {len(results)} (root and URL-subdirectory deployments).')
