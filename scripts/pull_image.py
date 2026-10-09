"""Downloads and unpacks a container image WITHOUT a Docker daemon (used to run livekit-egress natively for the recording test).
Usage: python3 scripts/pull_image.py livekit/egress v1.8.4 /opt/egress-root   (registry: Google's Docker Hub mirror, anonymous)"""
import json,sys,urllib.request,subprocess,os
repo,tag,dest=sys.argv[1:4]
import time
def get(url,tok,accept=None,raw=False):
    for a in range(12):
        try: return _get(url,tok,accept,raw)
        except urllib.error.HTTPError as e:
            if e.code!=429: raise
            print('429, retry',a,flush=True); time.sleep(20)
    raise SystemExit('rate limited')
def _get(url,tok,accept=None,raw=False):
    h={}
    if accept:h['Accept']=accept
    r=urllib.request.urlopen(urllib.request.Request(url,headers=h),timeout=120)
    return r if raw else json.load(r)
tok='x'
acc='application/vnd.oci.image.index.v1+json,application/vnd.docker.distribution.manifest.list.v2+json,application/vnd.oci.image.manifest.v1+json,application/vnd.docker.distribution.manifest.v2+json'
m=get(f"https://mirror.gcr.io/v2/{repo}/manifests/{tag}",tok,acc)
if 'manifests' in m:
    d=[x for x in m['manifests'] if x['platform']['architecture']=='amd64' and x['platform']['os']=='linux'][0]['digest']
    m=get(f"https://mirror.gcr.io/v2/{repo}/manifests/{d}",tok,acc)
os.makedirs(dest,exist_ok=True)
for i,l in enumerate(m['layers']):
    print(i,l['size'],flush=True)
    r=get(f"https://mirror.gcr.io/v2/{repo}/blobs/{l['digest']}",tok,raw=True)
    p=subprocess.Popen(['tar','-xz','-C',dest,'--exclude=dev/*'],stdin=subprocess.PIPE)
    while True:
        b=r.read(1<<20)
        if not b:break
        p.stdin.write(b)
    p.stdin.close();p.wait()
print('done')
