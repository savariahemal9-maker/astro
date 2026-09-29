"""Independent cross-check of engine planetary positions against astropy (different code base/theory).
pip install astropy ;  python3 tests/crosscheck_astropy.py
Compares tropical apparent geocentric longitudes (true equinox of date) for random instants 1900-2100.
astropy's built-in ephemeris is lower precision (observed ~1" Sun, ~20" Moon, up to ~90" Saturn), so tolerances
are per body. This catches gross errors (wrong time scale, frame or body); the finest varga used (D60) is 30'.
"""
import json, random, subprocess, sys, os
from astropy.time import Time
from astropy.coordinates import get_body, GeocentricTrueEcliptic, solar_system_ephemeris
from astropy.utils import iers
import astropy.units as u
iers.conf.auto_download = False
iers.conf.iers_degraded_accuracy = 'ignore'

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BIN, EPHE = f"{ROOT}/engine/bin/astro_calc", f"{ROOT}/engine/ephe"
BODIES = ['Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn']
TOL = {'Sun': 10, 'Mercury': 15, 'Venus': 30, 'Moon': 45, 'Mars': 60, 'Jupiter': 150, 'Saturn': 150}  # arcsec
random.seed(42)
worst, fails, n = {}, 0, 0
with solar_system_ephemeris.set('builtin'):
    for _ in range(300):
        jd = random.uniform(2415020.5, 2488069.5)  # 1900..2100
        out = json.loads(subprocess.run([BIN, 'chart', f'--jd={jd:.8f}', '--lat=0', '--lon=0', f'--ephe={EPHE}', '--require-swieph'],
                                        capture_output=True, text=True, check=True).stdout)
        t = Time(jd, format='jd', scale='ut1')
        for p in out['planets']:
            if p['name'] not in BODIES: continue
            c = get_body(p['name'].lower(), t).transform_to(GeocentricTrueEcliptic(equinox=t))
            d = (p['tropical_lon'] - c.lon.deg + 540) % 360 - 180
            as_ = abs(d) * 3600; n += 1
            worst[p['name']] = max(worst.get(p['name'], 0), as_)
            if as_ > TOL[p['name']]: fails += 1; print(f"FAIL {p['name']} jd={jd:.4f} diff={as_:.1f}\"")
for b in BODIES: print(f"{b:8s} worst diff {worst[b]:6.1f}\"  (tol {TOL[b]}\")")
print(f"{n - fails}/{n} within tolerance")
sys.exit(1 if fails else 0)
