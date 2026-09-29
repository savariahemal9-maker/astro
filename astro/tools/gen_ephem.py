"""Generates data/ephem/*.bin: Chebyshev fits of Swiss Ephemeris output (Lahiri) for pure-PHP evaluation.
Run once, offline: pip install pyswisseph numpy ; python3 tools/gen_ephem.py [--check]
Every series is fitted in JD(UT) and checked against Swiss Ephemeris at random points; generation aborts if a fit exceeds its limit."""
import swisseph as swe, numpy as np, struct, os, sys, json
from numpy.polynomial import chebyshev as C
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
swe.set_ephe_path(ROOT + '/engine/ephe'); swe.set_sid_mode(swe.SIDM_LAHIRI)
F = swe.FLG_SWIEPH
JD0, JD1 = 2378496.5, 2597641.5          # 1800-01-01 .. 2400-01-01
AS = 1 / 3600
def body(b, i): return lambda jd: swe.calc_ut(jd, b, F)[0][i]
def nut(i): return lambda jd: swe.calc_ut(jd, swe.ECL_NUT, F)[0][i]
def aeff(jd): return swe.get_ayanamsa_ex_ut(jd, F)[1]
def stcorr(jd):  # Swiss sidereal time minus the simple formula used in PHP (PhpEngine::gast), degrees
    import math; d = jd - 2451545.0; t = d / 36525
    g = 280.46061837 + 360.98564736629 * d + 0.000387933 * t * t - t ** 3 / 38710000
    n = swe.calc_ut(jd, swe.ECL_NUT, F)[0]
    return ((swe.sidtime(jd) * 15 - g - n[2] * math.cos(math.radians(n[0]))) + 180) % 360 - 180
# name: (func, segment days, degree, is_angle, max error in degrees)
SERIES = {
 'sun': (body(0, 0), 16, 12, True, .01*AS), 'moon': (body(1, 0), 4, 14, True, 3*AS),
 'moonlat': (body(1, 1), 8, 14, False, .05*AS), 'moondist': (body(1, 2), 8, 12, False, 1e-9),
 'mercury': (body(2, 0), 8, 14, True, 3*AS), 'venus': (body(3, 0), 8, 14, True, 3*AS),
 'mars': (body(4, 0), 8, 12, True, 3*AS), 'jupiter': (body(5, 0), 16, 12, True, 3*AS),
 'saturn': (body(6, 0), 16, 12, True, 3*AS), 'meannode': (body(10, 0), 32, 8, True, 3*AS),
 'truenode': (body(11, 0), 4, 14, True, 1*AS),
 'dpsi': (nut(2), 8, 14, False, .01*AS), 'eps': (nut(0), 8, 14, False, .01*AS), 'aeff': (aeff, 8, 14, False, .01*AS), 'stcorr': (stcorr, 8, 12, False, .01*AS),
}
def fit(f, seg, deg, ang):
    n = int(np.ceil((JD1 - JD0) / seg)); x = np.cos(np.pi * (np.arange(2 * deg) + .5) / (2 * deg))
    out = np.empty((n, deg + 1))
    for k in range(n):
        a = JD0 + k * seg; y = np.array([f(a + (xx + 1) / 2 * seg) for xx in x])
        if ang: y = np.rad2deg(np.unwrap(np.deg2rad(y)))
        out[k] = C.chebfit(x, y, deg)
    return out
def ev(c, seg, jd, ang):
    k = int((jd - JD0) // seg); x = 2 * ((jd - JD0) - k * seg) / seg - 1; v = C.chebval(x, c[k]); return v % 360 if ang else v
os.makedirs(ROOT + '/data/ephem', exist_ok=True); rng = np.random.default_rng(1); meta = {'jd0': JD0, 'jd1': JD1, 'swisseph': swe.version, 'series': {}}
for name, (f, seg, deg, ang, lim) in SERIES.items():
    meta['series'][name] = {'seg': seg, 'n': deg + 1, 'angle': ang}
    if os.path.exists(f'{ROOT}/data/ephem/{name}.bin') and '--force' not in sys.argv: continue
    c = fit(f, seg, deg, ang); err = 0
    for jd in rng.uniform(JD0, JD1, 3000):
        d = ev(c, seg, jd, ang) - f(jd); d = (d + 180) % 360 - 180 if ang else d; err = max(err, abs(d))
    print(f'{name:9s} segs={len(c):6d} maxerr={err/AS if name!="moondist" else err:.4g}{"" if name=="moondist" else chr(34)}', flush=True)
    if err > lim: sys.exit(f'FIT FAILED for {name}')
    open(f'{ROOT}/data/ephem/{name}.bin', 'wb').write(c.astype('<f8').tobytes())
    meta['series'][name] = {'seg': seg, 'n': deg + 1, 'angle': ang}
json.dump(meta, open(ROOT + '/data/ephem/meta.json', 'w'), indent=1)
