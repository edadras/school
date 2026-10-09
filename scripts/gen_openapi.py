#!/usr/bin/env python3
"""Regenerates docs/openapi.yaml from the real route table (so it cannot drift from the code).
Hand-written operation descriptions live in docs/openapi.notes.yaml and win over generated ones.
Usage: python3 scripts/gen_openapi.py   (needs `php artisan` to work in backend/)"""
import json, re, subprocess, sys, pathlib, yaml

root = pathlib.Path(__file__).resolve().parent.parent
routes = json.loads(subprocess.check_output(['php', 'artisan', 'route:list', '--json'], cwd=root / 'backend', stderr=subprocess.DEVNULL))
notes_file = root / 'docs' / 'openapi.notes.yaml'
notes = yaml.safe_load(notes_file.read_text())['paths'] if notes_file.exists() else {}

paths = {}
for r in routes:
    uri = r['uri']
    if not uri.startswith('api/v1/'):
        continue
    path = '/' + uri[len('api/v1/'):]
    mw = r['middleware']
    perms = [m.split(':', 1)[1] for m in mw if m.endswith(tuple(['RequirePermission']) ) is False and m.startswith('App\\Modules\\Tenancy\\Http\\RequirePermission:')]
    plat = [m.split(':', 1)[1] for m in mw if m.startswith('App\\Modules\\Tenancy\\Http\\RequirePlatformRole:')]
    public = not any('Authenticate' in m for m in mw)
    throttle = [m.split(':', 1)[1] for m in mw if m.startswith('throttle:') or 'ThrottleRequests:' in m]
    tag = path.strip('/').split('/')[0]
    action = r['action'].split('\\')[-1] if r['action'] != 'Closure' else 'closure'
    for method in r['method'].split('|'):
        if method in ('HEAD', 'OPTIONS'):
            continue
        params = [{'name': n, 'in': 'path', 'required': True, 'schema': {'type': 'string'}} for n in re.findall(r'\{(\w+)\??\}', path)]
        if not public and not path.startswith('/platform') and not path.startswith('/auth') and not path.startswith('/schools') and not path.startswith('/broadcasting'):
            params.append({'$ref': '#/components/parameters/SchoolId'})
        desc = []
        if perms: desc.append('Permission (any of): ' + ', '.join(perms[0].split(',')))
        if plat: desc.append('Platform role: ' + plat[0])
        if throttle: desc.append('Throttle: ' + ', '.join(throttle))
        op = {'tags': [tag], 'summary': f'{action}', 'responses': {'200': {'description': 'OK'}, '401': {'description': 'Unauthenticated'}, '403': {'description': 'Forbidden / school not resolved'}, '422': {'$ref': '#/components/responses/Invalid'}}}
        if desc: op['description'] = '\n'.join(desc)
        if params: op['parameters'] = params
        if public: op['security'] = []
        hand = (notes.get(path) or {}).get(method.lower())
        if hand:
            op.update({k: v for k, v in hand.items() if k not in ('parameters',)})
        paths.setdefault(path, {})[method.lower()] = op

spec = {
    'openapi': '3.0.3',
    'info': {'title': 'School Platform API', 'version': '1.0.0',
             'description': 'Generated from the Laravel route table (scripts/gen_openapi.py). Persian messages in `message`. Multi-tenant: send `X-School-Id` when the user belongs to several schools; it is a selector only and is verified against active memberships.'},
    'servers': [{'url': '/api/v1'}],
    'security': [{'bearer': []}],
    'components': {
        'securitySchemes': {'bearer': {'type': 'http', 'scheme': 'bearer'}},
        'parameters': {'SchoolId': {'name': 'X-School-Id', 'in': 'header', 'required': False, 'schema': {'type': 'integer'}, 'description': 'Selector only; must match an active membership, else 403 school_not_resolved.'}},
        'responses': {'Forbidden': {'description': 'Not permitted / school not resolved'}, 'Invalid': {'description': 'Validation error', 'content': {'application/json': {'schema': {'type': 'object', 'properties': {'message': {'type': 'string'}, 'errors': {'type': 'object'}}}}}}},
    },
    'paths': dict(sorted(paths.items())),
}
(root / 'docs' / 'openapi.yaml').write_text(yaml.safe_dump(spec, allow_unicode=True, sort_keys=False, width=140))
print(f'{sum(len(v) for v in paths.values())} operations, {len(paths)} paths')
