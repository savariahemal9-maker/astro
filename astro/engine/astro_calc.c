/*
 * astro_calc — thin JSON wrapper around the Swiss Ephemeris.
 * All astronomical numbers in this project come from here. No approximations.
 *
 * Usage:
 *   astro_calc chart    --jd=<JD_UT> --lat=<deg> --lon=<deg> [common]
 *   astro_calc panchang --jd=<JD_UT of local midnight> --lat= --lon= [--alt=m] [--rise=conventional|hindu] [common]
 *   astro_calc info [common]
 * common: --ephe=<dir> --ayanamsa=lahiri|raman|kp|true_chitra --node=mean|true --require-swieph
 *
 * Exit 0 + JSON on success; exit 1 + {"error":...} on failure.
 * With --require-swieph the engine refuses to answer if Swiss Ephemeris data
 * files are missing (instead of silently falling back to the Moshier model).
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include "swephexp.h"

#define ENGINE_VERSION "1.0.0"

static int32 g_iflag = SEFLG_SWIEPH | SEFLG_SPEED;
static int g_require_swieph = 0;
static int g_node = SE_MEAN_NODE;
static const char *g_ayan_name = "lahiri";

static void fail(const char *msg) {
    printf("{\"error\":\"");
    for (const char *p = msg; *p; p++) { if (*p == '"' || *p == '\\') putchar('\\'); putchar(*p); }
    printf("\"}\n");
    swe_close();
    exit(1);
}

static double norm360(double x) { x = fmod(x, 360.0); return x < 0 ? x + 360.0 : x; }

/* Calculate body longitude; sidereal if sid != 0. Fails loudly on fallback. */
static void calc(double jd, int body, int sid, double *xx) {
    char serr[AS_MAXCH] = "";
    int32 fl = g_iflag | (sid ? SEFLG_SIDEREAL : 0);
    int32 ret = swe_calc_ut(jd, body, fl, xx, serr);
    if (ret < 0) fail(serr);
    if (g_require_swieph && !(ret & SEFLG_SWIEPH)) fail("Swiss Ephemeris data files not found (fallback refused). Check --ephe path.");
}

static double sun_sid(double jd)  { double x[6]; calc(jd, SE_SUN, 1, x); return x[0]; }
static double moon_sid(double jd) { double x[6]; calc(jd, SE_MOON, 1, x); return x[0]; }

/* Panchang element indices (0-based) */
static int tithi_idx(double jd)   { return (int)floor(norm360(moon_sid(jd) - sun_sid(jd)) / 12.0); }       /* 0..29 */
static int karana_idx(double jd)  { return (int)floor(norm360(moon_sid(jd) - sun_sid(jd)) / 6.0); }        /* 0..59 */
static int nak_idx(double jd)     { return (int)floor(moon_sid(jd) / (360.0 / 27.0)); }                     /* 0..26 */
static int yoga_idx(double jd)    { return (int)floor(norm360(sun_sid(jd) + moon_sid(jd)) / (360.0 / 27.0)); } /* 0..26 */

typedef int (*idxfn)(double);

/* Bisect to ~0.5 s precision for the moment f changes from value at a. */
static double bisect(idxfn f, double a, double b) {
    int va = f(a);
    while ((b - a) > 0.5 / 86400.0) {
        double m = (a + b) / 2.0;
        if (f(m) == va) a = m; else b = m;
    }
    return b;
}

/* Emit segments of element from start until the segment covering `until` has ended. */
static void emit_segments(const char *name, idxfn f, double start, double until) {
    const double step = 1.0 / 48.0; /* 30 min; every element lasts > 9 h */
    double t = start; int cur = f(t); int first = 1;
    printf("\"%s\":[", name);
    for (int guard = 0; guard < 48 * 4; guard++) {
        double t2 = t + step; int v2 = f(t2);
        if (v2 != cur) {
            double end = bisect(f, t, t2);
            printf("%s{\"index\":%d,\"end_jd\":%.8f}", first ? "" : ",", cur, end);
            first = 0;
            if (end >= until) { printf("]"); return; }
            cur = f(end); t = end; continue;
        }
        t = t2;
    }
    fail("panchang scan did not converge");
}

static double rise_set(double jd0, int body, int32 what, double *geo, int32 extra) {
    double tret = 0; char serr[AS_MAXCH] = "";
    int32 r = swe_rise_trans(jd0, body, NULL, g_iflag & ~SEFLG_SPEED, what | extra, geo, 1013.25, 15, &tret, serr);
    if (r == -1) fail(serr);
    if (r == -2) return -1; /* circumpolar: no event */
    return tret;
}

static void print_common(double jd) {
    char ver[AS_MAXCH]; swe_version(ver);
    printf("\"engine\":{\"name\":\"astro_calc\",\"version\":\"%s\",\"swisseph\":\"%s\",\"ayanamsa\":\"%s\",\"node\":\"%s\"},",
           ENGINE_VERSION, ver, g_ayan_name, g_node == SE_TRUE_NODE ? "true" : "mean");
    printf("\"ayanamsa_deg\":%.8f,", swe_get_ayanamsa_ut(jd));
}

int main(int argc, char **argv) {
    if (argc < 2) fail("mode required: chart|panchang|info");
    const char *mode = argv[1];
    double jd = NAN, lat = NAN, lon = NAN, alt = 0;
    const char *ephe = "./ephe", *rise = "conventional";
    for (int i = 2; i < argc; i++) {
        char *a = argv[i];
        if (!strncmp(a, "--jd=", 5)) jd = atof(a + 5);
        else if (!strncmp(a, "--lat=", 6)) lat = atof(a + 6);
        else if (!strncmp(a, "--lon=", 6)) lon = atof(a + 6);
        else if (!strncmp(a, "--alt=", 6)) alt = atof(a + 6);
        else if (!strncmp(a, "--ephe=", 7)) ephe = a + 7;
        else if (!strncmp(a, "--rise=", 7)) rise = a + 7;
        else if (!strncmp(a, "--ayanamsa=", 11)) g_ayan_name = a + 11;
        else if (!strcmp(a, "--node=true")) g_node = SE_TRUE_NODE;
        else if (!strcmp(a, "--node=mean")) g_node = SE_MEAN_NODE;
        else if (!strcmp(a, "--require-swieph")) g_require_swieph = 1;
        else fail("unknown argument");
    }
    swe_set_ephe_path((char *)ephe);

    int sidm;
    if (!strcmp(g_ayan_name, "lahiri")) sidm = SE_SIDM_LAHIRI;
    else if (!strcmp(g_ayan_name, "raman")) sidm = SE_SIDM_RAMAN;
    else if (!strcmp(g_ayan_name, "kp")) sidm = SE_SIDM_KRISHNAMURTI;
    else if (!strcmp(g_ayan_name, "true_chitra")) sidm = SE_SIDM_TRUE_CITRA;
    else fail("unsupported ayanamsa");
    swe_set_sid_mode(sidm, 0, 0);

    if (!strcmp(mode, "info")) {
        double x[6]; calc(2451545.0, SE_SUN, 0, x);
        printf("{"); print_common(2451545.0); printf("\"ok\":true}\n");
        swe_close(); return 0;
    }
    if (isnan(jd) || isnan(lat) || isnan(lon)) fail("--jd, --lat and --lon are required");
    if (lat < -90 || lat > 90 || lon < -180 || lon > 180) fail("latitude/longitude out of range");
    if (jd < 1721425.5 || jd > 2816787.5) fail("date outside supported range (1 CE - 3000 CE)");

    if (!strcmp(mode, "chart")) {
        static const int bodies[] = {SE_SUN, SE_MOON, SE_MARS, SE_MERCURY, SE_JUPITER, SE_VENUS, SE_SATURN};
        static const char *names[] = {"Sun", "Moon", "Mars", "Mercury", "Jupiter", "Venus", "Saturn"};
        printf("{"); print_common(jd);
        printf("\"jd_ut\":%.8f,\"planets\":[", jd);
        double x[6];
        for (int i = 0; i < 7; i++) {
            double xt[6]; calc(jd, bodies[i], 0, xt); /* tropical apparent, for verification */
            calc(jd, bodies[i], 1, x);
            printf("{\"name\":\"%s\",\"lon\":%.8f,\"lat\":%.8f,\"speed\":%.8f,\"retro\":%s,\"tropical_lon\":%.8f},",
                   names[i], x[0], x[1], x[3], x[3] < 0 ? "true" : "false", xt[0]);
        }
        calc(jd, g_node, 1, x);
        printf("{\"name\":\"Rahu\",\"lon\":%.8f,\"lat\":0,\"speed\":%.8f,\"retro\":true},", x[0], x[3]);
        printf("{\"name\":\"Ketu\",\"lon\":%.8f,\"lat\":0,\"speed\":%.8f,\"retro\":true}],", norm360(x[0] + 180.0), x[3]);

        double cusps[13], ascmc[10], cusps2[13], ascmc2[10];
        char serr[AS_MAXCH] = "";
        if (swe_houses_ex2(jd, SEFLG_SIDEREAL, lat, lon, 'W', cusps, ascmc, NULL, NULL, serr) < 0) fail(serr);
        if (swe_houses_ex2(jd + 1.0 / 1440.0, SEFLG_SIDEREAL, lat, lon, 'W', cusps2, ascmc2, NULL, NULL, serr) < 0) fail(serr);
        double rate = ascmc2[0] - ascmc[0]; if (rate < -180) rate += 360; if (rate > 180) rate -= 360;
        printf("\"ascendant\":{\"lon\":%.8f,\"deg_per_min\":%.8f},\"mc\":%.8f}\n", ascmc[0], rate, ascmc[1]);
        swe_close(); return 0;
    }

    if (!strcmp(mode, "panchang")) {
        double geo[3] = {lon, lat, alt};
        int32 extra = !strcmp(rise, "hindu") ? SE_BIT_HINDU_RISING : 0;
        double sr = rise_set(jd, SE_SUN, SE_CALC_RISE, geo, extra);
        if (sr < 0 || sr > jd + 1.0) fail("no sunrise on this date at this latitude");
        double ss = rise_set(sr, SE_SUN, SE_CALC_SET, geo, extra);
        double sr2 = rise_set(sr + 0.01, SE_SUN, SE_CALC_RISE, geo, extra);
        double mr = rise_set(jd, SE_MOON, SE_CALC_RISE, geo, extra);
        double ms = rise_set(jd, SE_MOON, SE_CALC_SET, geo, extra);
        printf("{"); print_common(sr);
        printf("\"sunrise_jd\":%.8f,\"sunset_jd\":%.8f,\"next_sunrise_jd\":%.8f,", sr, ss, sr2);
        printf("\"moonrise_jd\":%.8f,\"moonset_jd\":%.8f,", (mr > 0 && mr < jd + 1) ? mr : -1, (ms > 0 && ms < jd + 1) ? ms : -1);
        printf("\"sun_sid_at_sunrise\":%.8f,\"moon_sid_at_sunrise\":%.8f,", sun_sid(sr), moon_sid(sr));
        emit_segments("tithi", tithi_idx, sr, sr2); printf(",");
        emit_segments("nakshatra", nak_idx, sr, sr2); printf(",");
        emit_segments("yoga", yoga_idx, sr, sr2); printf(",");
        emit_segments("karana", karana_idx, sr, sr2);
        printf("}\n");
        swe_close(); return 0;
    }
    fail("unknown mode");
    return 1;
}
