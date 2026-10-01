<?php
namespace App\Api;

use App\Auth\{AdminAuth, AuthService};
use App\Calc\{Dasha, Ephemeris, KundaliService, PanchangService, TimeResolver, Varga};
use App\Core\{ApiException, Db, Request, Router};
use App\Geo\GeoService;
use App\I18n\Lang;
use App\Interp\RuleEngine;

final class Routes {
    public static function register(Router $r): void {
        // ---- public ----
        $r->add('GET', '/meta/settings', fn() => ['settings' => (new Ephemeris())->settings(), 'engine' => (new Ephemeris())->info()['engine'],
            'vargas' => array_map(fn($d) => 'D' . $d, Varga::SUPPORTED), 'ruleset' => RuleEngine::VERSION], false);
        $r->add('GET', '/meta/i18n', fn(Request $q) => Lang::all($q->lang()), false);
        $r->add('GET', '/meta/timezones', fn() => \DateTimeZone::listIdentifiers(), false);

        $r->add('POST', '/auth/register', function (Request $q) {
            self::limit('reg', 10); $in = $q->require(['name', 'email', 'password']);
            return AuthService::register($in['name'], $in['email'], $in['password'], $q->lang());
        }, false);
        $r->add('POST', '/auth/login', function (Request $q) {
            self::limit('login', 20); $in = $q->require(['email', 'password']);
            return AuthService::login($in['email'], $in['password'], (string) $q->input('client', 'web'));
        }, false);

        $r->add('POST', '/auth/forgot', function (Request $q) {
            self::limit('forgot', 5); $in = $q->require(['email']);
            // never build the link from the request's Host header (reset-link poisoning)
            if (($base = app_config()['app']['base_url'] ?? '') === '') throw new ApiException('not_configured', 'Password reset is not set up on this site yet.', 503);
            AuthService::forgotPassword((string) $in['email'], $base); return ['sent' => true];
        }, false);
        $r->add('POST', '/auth/reset', function (Request $q) {
            self::limit('reset', 10); $in = $q->require(['token', 'password']);
            AuthService::resetPassword((string) $in['token'], (string) $in['password']); return ['reset' => true];
        }, false);

        $r->add('GET', '/geo/search', function (Request $q) { self::limit('geo', 30); return GeoService::search((string) $q->input('q', '')); }, false);
        $r->add('GET', '/geo/timezone', function (Request $q) {
            $in = $q->require(['lat', 'lon']);
            return ['tzid' => GeoService::timezone((float) $in['lat'], (float) $in['lon'], strtolower((string) $q->input('country_code', '')))];
        }, false);
        $r->add('POST', '/birth/resolve', fn(Request $q) => ProfileService::validate($q->body)['_resolved'], false);

        $r->add('GET', '/panchang', function (Request $q) {
            $in = $q->require(['date', 'lat', 'lon', 'tzid']);
            $eph = new Ephemeris();
            $key = hash('sha256', json_encode(['v2', $in['date'], round((float) $in['lat'], 3), round((float) $in['lon'], 3), $in['tzid'], $eph->settings(), $eph->info()['engine']]));
            if ($c = Db::one('SELECT payload FROM panchang_cache WHERE cache_key = ?', [$key])) return json_decode($c['payload'], true);
            $p = (new PanchangService($eph))->compute((string) $in['date'], (float) $in['lat'], (float) $in['lon'], (string) $in['tzid']);
            Db::exec('REPLACE INTO panchang_cache (cache_key, payload) VALUES (?,?)', [$key, json_encode($p)]);
            return $p;
        }, false);
        // public rashifal for all 12 Moon signs (daily / weekly / monthly)
        $r->add('GET', '/rashifal', function (Request $q) {
            $period = (string) $q->input('period', 'daily');
            if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) throw new ApiException('validation', 'period must be daily, weekly or monthly', 422);
            $tz = new \DateTimeZone('Asia/Kolkata'); $d = (string) $q->input('date', '');
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $d, $tz) ?: new \DateTimeImmutable('today', $tz)) : new \DateTimeImmutable('today', $tz);
            $key = hash('sha256', json_encode(['rf2', $period, $date->format('Y-m-d'), $q->lang()]));
            if ($c = Db::one('SELECT payload FROM panchang_cache WHERE cache_key = ?', [$key])) return json_decode($c['payload'], true);
            $out = (new \App\Interp\Rashifal($q->lang()))->all($period, $date);
            Db::exec('REPLACE INTO panchang_cache (cache_key, payload) VALUES (?,?)', [$key, json_encode($out)]);
            return $out;
        }, false);

        // ---- authenticated ----
        $r->add('POST', '/auth/logout', function (Request $q) { AuthService::logout($q->token); return ['signed_out' => true]; });
        $r->add('GET', '/me', fn(Request $q, array $u) => AuthService::publicUser($u));
        $r->add('PATCH', '/me', fn(Request $q, array $u) => AuthService::updateProfile($u, $q->body));
        $r->add('POST', '/me/password', function (Request $q, array $u) {
            self::limit('pwchange', 10); $in = $q->require(['current_password', 'new_password']);
            AuthService::changePassword($u, (string) $in['current_password'], (string) $in['new_password'], (string) $q->token); return ['changed' => true];
        });

        $r->add('GET', '/profiles', fn(Request $q, array $u) => array_map([ProfileService::class, 'present'],
            Db::all('SELECT * FROM birth_profiles WHERE user_id = ? ORDER BY created_at DESC', [$u['id']])));
        $r->add('POST', '/profiles', fn(Request $q, array $u) => ProfileService::save((int) $u['id'], $q->body));
        $r->add('GET', '/profiles/{id}', fn(Request $q, array $u, array $a) => ProfileService::get((int) $u['id'], $a['id']));
        $r->add('PUT', '/profiles/{id}', fn(Request $q, array $u, array $a) => ProfileService::save((int) $u['id'], $q->body, $a['id']));
        $r->add('DELETE', '/profiles/{id}', function (Request $q, array $u, array $a) {
            ProfileService::get((int) $u['id'], $a['id']); Db::exec('DELETE FROM birth_profiles WHERE id=?', [$a['id']]); return ['deleted' => true];
        });

        $r->add('GET', '/profiles/{id}/kundali', function (Request $q, array $u, array $a) {
            [, $k] = ProfileService::kundali(ProfileService::get((int) $u['id'], $a['id'])); return $k;
        });
        $r->add('GET', '/profiles/{id}/transits', function (Request $q, array $u, array $a) {
            [$p, , $k] = self::ctx($q, $u, $a); [$jd] = self::at($q, $p);
            return (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']);
        });
        $r->add('GET', '/profiles/{id}/predictions', function (Request $q, array $u, array $a) {
            [$p, $kid, $k, $lang] = self::ctx($q, $u, $a);
            return self::prediction((string) $q->input('type', 'daily'), $q, $p, $kid, $k, $lang);
        });
        $r->add('GET', '/profiles/{id}/doshas', function (Request $q, array $u, array $a) {
            [$p, $kid, $k, $lang] = self::ctx($q, $u, $a); return self::doshas($q, $p, $kid, $k, $lang);
        });
        $adv = fn(Request $q, array $u) => new \App\Interp\Advanced($q->lang($u));
        $trAt = function (Request $q, array $p, array $k) { [$jd, $local] = self::at($q, $p); return [(new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']), $local]; };
        $r->add('GET', '/profiles/{id}/summary', function (Request $q, array $u, array $a) use ($adv) { [, , $k] = self::ctx($q, $u, $a); return $adv($q, $u)->summary($k); });
        $r->add('GET', '/profiles/{id}/dosha-report', function (Request $q, array $u, array $a) use ($adv, $trAt) {
            [$p, , $k] = self::ctx($q, $u, $a); [$tr, $local] = $trAt($q, $p, $k); return $adv($q, $u)->doshaReport($k, $tr) + ['as_on' => $local->format('Y-m-d')]; });
        $r->add('GET', '/profiles/{id}/priority-remedies', function (Request $q, array $u, array $a) use ($adv, $trAt) {
            [$p, , $k] = self::ctx($q, $u, $a); [$tr] = $trAt($q, $p, $k); return $adv($q, $u)->priorityRemedies($k, $tr); });
        $r->add('GET', '/profiles/{id}/gem-report', function (Request $q, array $u, array $a) use ($adv) { [, , $k] = self::ctx($q, $u, $a); return $adv($q, $u)->gemReport($k); });
        $r->add('GET', '/profiles/{id}/annual', function (Request $q, array $u, array $a) use ($adv) {
            [$p, , $k] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            $v = (new \App\Calc\VarshphalService())->compute($k, (int) $q->input('year', $local->format('Y')));
            return ['chart' => $v, 'reading' => $adv($q, $u)->annual($k, $v)]; });
        $r->add('GET', '/profiles/{id}/luck', function (Request $q, array $u, array $a) use ($adv) { [, , $k] = self::ctx($q, $u, $a); return $adv($q, $u)->luck($k); });
        $r->add('GET', '/profiles/{id}/yogas', function (Request $q, array $u, array $a) use ($adv) { [, , $k] = self::ctx($q, $u, $a); return $adv($q, $u)->yogas($k); });
        $r->add('GET', '/profiles/{id}/monthly-reading', function (Request $q, array $u, array $a) use ($adv) {
            [$p, , $k] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            $m = (string) $q->input('month', $local->format('Y-m')); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw new ApiException('validation', 'month must be YYYY-MM', 422);
            [$jd] = self::at($q, $p, "$m-15"); $tr = (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']);
            return $adv($q, $u)->monthly($k, $tr) + ['month' => $m]; });
        $r->add('GET', '/profiles/{id}/house-varsh', function (Request $q, array $u, array $a) use ($adv) {
            [$p, , $k] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p); return $adv($q, $u)->lkVarsh($k, (int) $q->input('year', $local->format('Y'))); });
        $r->add('GET', '/profiles/{id}/daily-reading', function (Request $q, array $u, array $a) use ($adv, $trAt) {
            [$p, , $k] = self::ctx($q, $u, $a); [$tr, $local] = $trAt($q, $p, $k);
            return $adv($q, $u)->daily($k, $tr) + ['date' => $local->format('Y-m-d'), 'transits' => $tr]; });
        // admin routes are guarded by admin sessions (separate admin accounts), not by site users
        $adm = fn(Request $q) => AdminAuth::authenticate($q->token);
        $r->add('POST', '/admin-auth/register', function (Request $q) { self::limit('adreg', 5); $in = $q->require(['name', 'email', 'password']); return AdminAuth::register($in['name'], $in['email'], $in['password']); }, false);
        $r->add('POST', '/admin-auth/login', function (Request $q) { self::limit('adlogin', 10); $in = $q->require(['email', 'password']); return AdminAuth::login($in['email'], $in['password']); }, false);
        $r->add('POST', '/admin-auth/logout', function (Request $q) { AdminAuth::logout($q->token); return ['signed_out' => true]; }, false);
        $r->add('GET', '/admin-auth/me', fn(Request $q) => AdminAuth::present($adm($q)), false);
        $r->add('GET', '/admin/admins', function (Request $q) use ($adm) { $adm($q); return array_map([AdminAuth::class, 'present'], Db::all('SELECT * FROM admins ORDER BY status, id')); }, false);
        $r->add('PATCH', '/admin/admins/{id}', function (Request $q, array $u, array $a) use ($adm) { $me = $adm($q);
            if ((int) $a['id'] === (int) $me['id']) throw new ApiException('validation', 'You cannot change your own admin status', 422);
            $st = (string) $q->input('status'); if (!in_array($st, ['active', 'disabled'], true)) throw new ApiException('validation', 'status must be active or disabled', 422);
            Db::exec('UPDATE admins SET status=? WHERE id=?', [$st, $a['id']]); if ($st !== 'active') Db::exec('DELETE FROM admin_tokens WHERE admin_id=?', [$a['id']]); return ['status' => $st]; }, false);
        $r->add('DELETE', '/admin/admins/{id}', function (Request $q, array $u, array $a) use ($adm) { $me = $adm($q);
            if ((int) $a['id'] === (int) $me['id']) throw new ApiException('validation', 'You cannot delete your own admin account', 422);
            Db::exec('DELETE FROM admins WHERE id=?', [$a['id']]); return ['deleted' => true]; }, false);
        $r->add('GET', '/admin/remedies', function (Request $q, array $u) use ($adm) { $adm($q); return Db::all('SELECT * FROM remedy_rules ORDER BY planet, house, dosha, priority DESC'); }, false);
        $save = function (Request $q, ?int $id) {
            $b = $q->body; $pl = $b['planet'] ?? null; $ds = $b['dosha'] ?? null;
            if (!$pl && !$ds) throw new ApiException('validation', 'planet or dosha required', 422);
            if (empty($b['text_en']) || empty($b['source'])) throw new ApiException('validation', 'text_en and source are required', 422);
            $v = [$pl ?: null, ($b['house'] ?? '') === '' ? null : (int) $b['house'], $ds ?: null, in_array($b['cond'] ?? 'any', ['any', 'weak', 'strong', 'malefic', 'benefic'], true) ? ($b['cond'] ?? 'any') : 'any',
                  (int) ($b['priority'] ?? 50), $b['text_en'], $b['text_hi'] ?? null, $b['text_gu'] ?? null, mb_substr($b['source'], 0, 255), ($b['status'] ?? 'draft') === 'approved' ? 'approved' : 'draft'];
            if ($id) { Db::exec('UPDATE remedy_rules SET planet=?,house=?,dosha=?,cond=?,priority=?,text_en=?,text_hi=?,text_gu=?,source=?,status=? WHERE id=?', array_merge($v, [$id])); return ['id' => $id]; }
            return ['id' => Db::insert('INSERT INTO remedy_rules (planet,house,dosha,cond,priority,text_en,text_hi,text_gu,source,status) VALUES (?,?,?,?,?,?,?,?,?,?)', $v)]; };
        $r->add('POST', '/admin/remedies', function (Request $q, array $u) use ($adm, $save) { $adm($q); return $save($q, null); }, false);
        $r->add('PUT', '/admin/remedies/{id}', function (Request $q, array $u, array $a) use ($adm, $save) { $adm($q); return $save($q, $a['id']); }, false);
        $r->add('DELETE', '/admin/remedies/{id}', function (Request $q, array $u, array $a) use ($adm) { $adm($q); Db::exec('DELETE FROM remedy_rules WHERE id=?', [$a['id']]); return ['deleted' => true]; }, false);
        // ---- personalized category predictions ----
        $r->add('GET', '/categories', function (Request $q, array $u) {
            $l = $q->lang($u); $mine = array_column(Db::all('SELECT category_id FROM user_categories WHERE user_id=?', [$u['id']]), 'category_id');
            return array_map(fn($c) => ['id' => (int) $c['id'], 'slug' => $c['slug'], 'name' => $c["name_$l"] ?: $c['name_en'], 'icon' => $c['icon'],
                'caution' => (bool) $c['caution'], 'selected' => in_array($c['id'], $mine)], Db::all('SELECT * FROM prediction_categories WHERE active=1 ORDER BY sort, id'));
        });
        $r->add('PUT', '/me/categories', function (Request $q, array $u) {
            $ids = array_values(array_unique(array_map('intval', (array) $q->input('ids', []))));
            Db::exec('DELETE FROM user_categories WHERE user_id=?', [$u['id']]);
            foreach ($ids as $cid) if (Db::one('SELECT id FROM prediction_categories WHERE id=? AND active=1', [$cid])) Db::exec('INSERT INTO user_categories (user_id, category_id) VALUES (?,?)', [$u['id'], $cid]);
            return ['ids' => array_map('intval', array_column(Db::all('SELECT category_id FROM user_categories WHERE user_id=?', [$u['id']]), 'category_id'))];
        });
        $r->add('GET', '/profiles/{id}/category-prediction', function (Request $q, array $u, array $a) {
            [$p, , $k, $l] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            $period = (string) $q->input('period', 'daily');
            if (!in_array($period, \App\Interp\CategoryPredictor::PERIODS, true)) throw new ApiException('validation', 'Unknown period', 422, ['field' => 'period']);
            $cat = Db::one('SELECT * FROM prediction_categories WHERE id=? AND active=1', [(int) $q->input('category', 0)]);
            if (!$cat) throw new ApiException('not_found', 'Category not found', 404);
            return (new \App\Interp\CategoryPredictor($l))->predict($k, $cat, $period, $local, (float) $p['lat'], (float) $p['lon']);
        });
        $r->add('GET', '/admin/categories', function (Request $q, array $u) use ($adm) { $adm($q); return Db::all('SELECT * FROM prediction_categories ORDER BY sort, id'); }, false);
        // Admin enters only names and simple advice; the astrology (planets, houses), icon and warning are chosen from the English name.
        $catSave = function (Request $q, ?int $id) {
            $in = $q->require(['name_en']); $nameEn = mb_substr(trim((string) $in['name_en']), 0, 80);
            $old = $id ? Db::one('SELECT * FROM prediction_categories WHERE id=?', [$id]) : null;
            if ($id && !$old) throw new ApiException('not_found', 'Category not found', 404);
            $slug = $old['slug'] ?? trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($nameEn)), '_');
            if ($slug === '') $slug = 'cat';
            for ($base = $slug, $i = 2; Db::one('SELECT id FROM prediction_categories WHERE slug=? AND id<>?', [$slug, $id ?? 0]); $i++) $slug = "{$base}_$i";
            $auto = $old ? [$old['planets'], $old['houses'], $old['icon'], (int) $old['caution']] : self::categoryAstro($nameEn);
            $txt = fn(string $k, int $max) => ($v = mb_substr(trim((string) $q->input($k, '')), 0, $max)) === '' ? null : $v;
            $v = ['slug' => $slug, 'name_en' => $nameEn, 'name_hi' => $txt('name_hi', 80), 'name_gu' => $txt('name_gu', 80),
                  'planets' => $auto[0], 'houses' => $auto[1], 'icon' => $auto[2], 'caution' => $auto[3], 'active' => (int) (bool) $q->input('active', true)];
            foreach (['dos', 'donts', 'upay'] as $k) foreach (['en', 'hi', 'gu'] as $l) $v["{$k}_$l"] = $txt("{$k}_$l", 4000);
            $cols = implode('=?, ', array_keys($v)) . '=?';
            if ($id) { Db::exec("UPDATE prediction_categories SET $cols WHERE id=?", [...array_values($v), $id]); return Db::one('SELECT * FROM prediction_categories WHERE id=?', [$id]); }
            $v['sort'] = (int) (Db::one('SELECT COALESCE(MAX(sort),0)+1 AS n FROM prediction_categories')['n'] ?? 1);
            return Db::one('SELECT * FROM prediction_categories WHERE id=?', [Db::insert('INSERT INTO prediction_categories SET ' . implode('=?, ', array_keys($v)) . '=?', array_values($v))]);
        };
        $r->add('POST', '/admin/categories', function (Request $q, array $u) use ($adm, $catSave) { $adm($q); return $catSave($q, null); }, false);
        $r->add('PUT', '/admin/categories/{id}', function (Request $q, array $u, array $a) use ($adm, $catSave) { $adm($q); return $catSave($q, (int) $a['id']); }, false);
        $r->add('DELETE', '/admin/categories/{id}', function (Request $q, array $u, array $a) use ($adm) { $adm($q); Db::exec('DELETE FROM prediction_categories WHERE id=?', [$a['id']]); return ['deleted' => true]; }, false);
<<<<<<< HEAD
        // ---- Kundali Milan ----
        $r->add('GET', '/milan', function (Request $q, array $u) {
            return \App\Interp\KundaliMilan::list((int) $u['id']); });
        $r->add('GET', '/milan/{id}', function (Request $q, array $u, array $a) {
            $lang = (string) $q->input('lang', 'en');
            $row = \App\Interp\KundaliMilan::get((int) $a['id'], (int) $u['id'], $lang);
            if (!$row) throw new ApiException('not_found', 'Not found', 404); return $row; });
        $r->add('POST', '/milan', function (Request $q, array $u) {
            $b = $q->body; $lang = (string) $q->input('lang', 'en');
            $ks = new \App\Calc\KundaliService();
            $compute = function (array $d) use ($ks): array {
                $birth = \App\Calc\TimeResolver::resolve($d['date'], $d['time'] ?? '12:00', $d['tz'] ?? 'Asia/Kolkata');
                return $ks->compute($birth, (float)($d['lat'] ?? 23.0), (float)($d['lon'] ?? 72.0)); };
            $boyK  = isset($b['boy_profile_id'])  ? self::kundaliById((int)$b['boy_profile_id'],  $u) : $compute($b['boy']);
            $girlK = isset($b['girl_profile_id']) ? self::kundaliById((int)$b['girl_profile_id'], $u) : $compute($b['girl']);
            $result = (new \App\Interp\KundaliMilan($lang))->calculate($boyK, $girlK);
            $boyName  = $b['boy_name']  ?? ($b['boy']['name']  ?? 'Boy');
            $girlName = $b['girl_name'] ?? ($b['girl']['name'] ?? 'Girl');
            $id = \App\Interp\KundaliMilan::save((int) $u['id'], $boyName, $girlName, $result);
            return ['id' => $id, 'boy_name' => $boyName, 'girl_name' => $girlName] + $result; });
        $r->add('DELETE', '/milan/{id}', function (Request $q, array $u, array $a) {
            \App\Core\Db::exec('DELETE FROM milan_reports WHERE id=? AND user_id=?', [$a['id'], $u['id']]); return ['deleted' => true]; });

=======
>>>>>>> 267f35bbbeef03a842e14c0ea5b2725111f7c76e
        // ---- admin: overview, users and their kundalis ----
        $r->add('GET', '/admin/ai-status', function (Request $q) use ($adm) { $adm($q); return \App\Interp\AiChat::status(); }, false);
        $r->add('GET', '/admin/claude-status', function (Request $q) use ($adm) { $adm($q); return \App\Interp\ClaudeChat::status(); }, false);
        $r->add('GET', '/admin/stats', function (Request $q, array $u) use ($adm) { $adm($q);
            $n = fn(string $sql) => (int) (Db::one($sql)['n'] ?? 0);
            return ['users' => $n('SELECT COUNT(*) n FROM users'), 'new_users_7d' => $n('SELECT COUNT(*) n FROM users WHERE created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY'),
                'premium' => $n("SELECT COUNT(*) n FROM users WHERE plan='premium'"), 'disabled' => $n('SELECT COUNT(*) n FROM users WHERE disabled=1'),
                'kundalis' => $n('SELECT COUNT(*) n FROM birth_profiles'), 'categories' => $n('SELECT COUNT(*) n FROM prediction_categories WHERE active=1'),
                'recent' => Db::all('SELECT id, name, email, created_at FROM users ORDER BY id DESC LIMIT 6')]; }, false);
        $r->add('GET', '/admin/users', function (Request $q, array $u) use ($adm) { $adm($q);
            $s = '%' . trim((string) $q->input('q', '')) . '%'; $page = max(1, (int) $q->input('page', 1)); $per = 25;
            $rows = Db::all('SELECT u.id, u.name, u.email, u.lang, u.plan, u.plan_expires, u.disabled, u.created_at, (SELECT COUNT(*) FROM birth_profiles b WHERE b.user_id=u.id) kundalis
                FROM users u WHERE u.name LIKE ? OR u.email LIKE ? ORDER BY u.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), [$s, $s]);
            return ['items' => array_slice($rows, 0, $per), 'page' => $page, 'has_more' => count($rows) > $per]; }, false);
        $r->add('GET', '/admin/users/{id}', function (Request $q, array $u, array $a) use ($adm) { $adm($q);
            $x = Db::one('SELECT id, name, email, lang, plan, plan_expires, disabled, created_at FROM users WHERE id=?', [$a['id']]);
            if (!$x) throw new ApiException('not_found', 'User not found', 404);
            return $x + ['profiles' => array_map([ProfileService::class, 'present'], Db::all('SELECT * FROM birth_profiles WHERE user_id=? ORDER BY id DESC', [$a['id']]))]; }, false);
        $r->add('PATCH', '/admin/users/{id}', function (Request $q, array $u, array $a) use ($adm) { $adm($q);
            if (($pl = $q->input('plan')) !== null) Db::exec('UPDATE users SET plan=?, plan_expires=? WHERE id=?', [$pl === 'premium' ? 'premium' : 'free',
                preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q->input('plan_expires', '')) ? $q->input('plan_expires') : null, $a['id']]);
            if (($d = $q->input('disabled')) !== null) { Db::exec('UPDATE users SET disabled=? WHERE id=?', [(int) (bool) $d, $a['id']]); if ($d) Db::exec('DELETE FROM api_tokens WHERE user_id=?', [$a['id']]); }
            return Db::one('SELECT id, name, email, plan, plan_expires, disabled FROM users WHERE id=?', [$a['id']]); }, false);
        $r->add('DELETE', '/admin/users/{id}', function (Request $q, array $u, array $a) use ($adm) { $adm($q);
            Db::exec('DELETE FROM users WHERE id=?', [$a['id']]); return ['deleted' => true]; }, false);
        $r->add('GET', '/admin/kundalis', function (Request $q, array $u) use ($adm) { $adm($q);
            $s = '%' . trim((string) $q->input('q', '')) . '%'; $page = max(1, (int) $q->input('page', 1)); $per = 25;
            $rows = Db::all('SELECT b.id, b.label, b.birth_date, b.birth_time, b.place_name, b.created_at, u.id user_id, u.name user_name, u.email FROM birth_profiles b JOIN users u ON u.id=b.user_id
                WHERE b.label LIKE ? OR b.place_name LIKE ? OR u.email LIKE ? ORDER BY b.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), [$s, $s, $s]);
            return ['items' => array_slice($rows, 0, $per), 'page' => $page, 'has_more' => count($rows) > $per]; }, false);
        $r->add('DELETE', '/admin/kundalis/{id}', function (Request $q, array $u, array $a) use ($adm) { $adm($q); Db::exec('DELETE FROM birth_profiles WHERE id=?', [$a['id']]); return ['deleted' => true]; }, false);
        $r->add('GET', '/profiles/{id}/planet-results', function (Request $q, array $u, array $a) {
            [, $kid, $k, $lang] = self::ctx($q, $u, $a);
            return self::cached($kid, 'planet_results', '', $lang, fn() => (new RuleEngine($lang))->planetResults($k));
        });
        $r->add('GET', '/profiles/{id}/varshphal', function (Request $q, array $u, array $a) {
            [$p, , $k] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            $y = (int) $q->input('year', $local->format('Y'));
            return (new \App\Calc\VarshphalService())->compute($k, $y);
        });
        $r->add('GET', '/profiles/{id}/gemstones', function (Request $q, array $u, array $a) {
            [, $kid, $k, $lang] = self::ctx($q, $u, $a); return self::gems($kid, $k, $lang);
        });
        $r->add('GET', '/profiles/{id}/full-report', function (Request $q, array $u, array $a) {
            [$p, $kid, $k, $lang] = self::ctx($q, $u, $a); [$jd, $local] = self::at($q, $p);
            $pred = []; foreach (['life', 'mdphal', 'dasha', 'monthly', 'daily'] as $t) $pred[$t] = self::prediction($t, $q, $p, $kid, $k, $lang);
            return ['profile' => $p, 'kundali' => $k, 'transits' => (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']),
                    'as_on' => $local->format('Y-m-d H:i T'), 'predictions' => $pred, 'doshas' => self::doshas($q, $p, $kid, $k, $lang),
                    'gemstones' => self::gems($kid, $k, $lang), 'planet_results' => self::cached($kid, 'planet_results', '', $lang, fn() => (new RuleEngine($lang))->planetResults($k)),
                    'varshphal' => $vp = (new \App\Calc\VarshphalService())->compute($k, (int) $local->format('Y')), 'generated_utc' => gmdate('c')]
                    + (function () use ($k, $p, $lang, $vp, $jd) { $A = new \App\Interp\Advanced($lang); $tr = (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']);
                        return ['summary' => $A->summary($k), 'dosha_report' => $A->doshaReport($k, $tr), 'priority_remedies' => $A->priorityRemedies($k, $tr),
                                'gem_report' => $A->gemReport($k), 'annual' => $A->annual($k, $vp), 'daily_reading' => $A->daily($k, $tr), 'luck' => $A->luck($k), 'yogas' => $A->yogas($k), 'monthly_reading' => $A->monthly($k, $tr)]; })();
        });
        $r->add('GET', '/profiles/{id}/report', function (Request $q, array $u, array $a) {
            $p = ProfileService::get((int) $u['id'], $a['id']); [$kid, $k] = ProfileService::kundali($p); $lang = $q->lang($u);
            return self::cached($kid, 'report', gmdate('Y-m'), $lang, fn() => (new RuleEngine($lang))->report($k,
                Dasha::current(['mahadasha' => $k['dasha']['mahadasha']], TimeResolver::nowJd())));
        });
        // personal remedy plan (life / date / week / month / most important) and pooja suggestions — all from this kundali
        $r->add('GET', '/profiles/{id}/remedy-plan', function (Request $q, array $u, array $a) {
            [$p, , $k, $l] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p); $rp = new \App\Interp\RemedyPlanner($l, (float) $p['lat'], (float) $p['lon']);
            return match ((string) $q->input('view', 'life')) {
                'life' => $rp->lifelong($k, $local->setTime(12, 0)->getTimestamp() / 86400 + 2440587.5), 'day' => $rp->day($k, $local), 'week' => $rp->week($k, $local),
                'month' => $rp->month($k, $local), 'important' => $rp->important($k, $local),
                default => throw new ApiException('validation', 'view must be life, day, week, month or important', 422) };
        });
        // Chat language: the chat's own picker (en / hi / gu / auto). auto = the script of the message, else the site language.
        $chatLang = function (Request $q, string $siteLang, string $msg): array {
            $c = (string) $q->input('chat_lang', 'auto'); $c = in_array($c, ['en', 'hi', 'gu', 'auto'], true) ? $c : 'auto';
            $eff = $c !== 'auto' ? $c : (preg_match('/\p{Gujarati}/u', $msg) ? 'gu' : (preg_match('/\p{Devanagari}/u', $msg) ? 'hi' : (preg_match('/^[\x00-\x7F\s]+$/', $msg) ? 'en' : $siteLang)));
            return [$c, $eff];
        };
        // free rule-based astrology chat, answered only from this kundali's data (saved as a thread)
        $r->add('POST', '/profiles/{id}/chat', function (Request $q, array $u, array $a) use ($chatLang) {
            self::limit('chat', 40); [$p, , $k, $site] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            $msg = mb_substr(trim((string) $q->input('message', '')), 0, 500); if ($msg === '') throw new ApiException('validation', 'Type a question', 422);
            [$cl, $l] = $chatLang($q, $site, $msg);
            $ctx = (array) $q->input('context', []); $ctx = ['topic' => $ctx['topic'] ?? null, 'period' => $ctx['period'] ?? null, 'offer' => $ctx['offer'] ?? null];
            $tid = ChatStore::open((int) $u['id'], (int) $q->input('thread', 0) ?: null, (int) $p['id'], 'rules', $cl, $msg);
            $hist = ChatStore::history($tid, 6); ChatStore::add($tid, true, [$msg]);
            $bot = new \App\Interp\ChatBot($l, (float) $p['lat'], (float) $p['lon']); $res = null;
            if (\App\Interp\AiChat::enabled()) {     // AI understands and words the answer; the facts still come from ChatBot
                $ai = new \App\Interp\AiChat($l);
                if ($intent = $ai->understand($msg, $hist, $ctx, $bot->topics())) {
                    $res = $bot->reply($k, $msg, $ctx, $local, (string) $p['label'], $intent);
                    $res = ($words = $ai->phrase($msg, $hist, $res['reply'])) ? ['reply' => $words, 'engine' => 'ai'] + $res : $res + ['engine' => 'rules', 'why' => \App\Interp\AiChat::$lastError];
                }
            }
            $res ??= $bot->reply($k, $msg, $ctx, $local, (string) $p['label']) + ['engine' => 'rules', 'why' => \App\Interp\AiChat::enabled() ? \App\Interp\AiChat::$lastError : 'no_key'];
            ChatStore::add($tid, false, $res['reply'], $res['context'] ?? null);
            return $res + ['thread' => $tid];
        });
        // separate AI astrologer chat powered by Claude (saved as a thread; history comes from the saved thread)
        $r->add('POST', '/profiles/{id}/claude-chat', function (Request $q, array $u, array $a) use ($chatLang) {
            self::limit('claude', 15); [$p, , $k, $site] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            $msg = mb_substr(trim((string) $q->input('message', '')), 0, 800); if ($msg === '') throw new ApiException('validation', 'Type a question', 422);
            if (!\App\Interp\ClaudeChat::enabled()) throw new ApiException('ai_unavailable', 'The AI astrologer is not set up yet.', 503);
            [$cl] = $chatLang($q, $site, $msg);
            $tid = ChatStore::open((int) $u['id'], (int) $q->input('thread', 0) ?: null, (int) $p['id'], 'claude', $cl, $msg);
            $hist = ChatStore::history($tid, 10); ChatStore::add($tid, true, [$msg]);
            $res = (new \App\Interp\ClaudeChat($site, (float) $p['lat'], (float) $p['lon'], $cl))->reply($k, $p, $msg, $hist, $local);
            if ($res['engine'] === 'claude') ChatStore::add($tid, false, $res['reply']);
            return $res + ['thread' => $tid];
        });
        // saved conversations
        $r->add('GET', '/chats', fn(Request $q, array $u) => ChatStore::list((int) $u['id'], $q->input('mode') === 'claude' ? 'claude' : 'rules'));
        $r->add('GET', '/chats/{tid}', fn(Request $q, array $u, array $a) => ChatStore::get((int) $u['id'], (int) $a['tid']));
        $r->add('DELETE', '/chats/{tid}', function (Request $q, array $u, array $a) { ChatStore::delete((int) $u['id'], (int) $a['tid']); return ['deleted' => true]; });
        $r->add('GET', '/profiles/{id}/poojas', function (Request $q, array $u, array $a) {
            [$p, , $k, $l] = self::ctx($q, $u, $a); [, $local] = self::at($q, $p);
            return (new \App\Interp\RemedyPlanner($l, (float) $p['lat'], (float) $p['lon']))->poojas($k, $local);
        });
        $r->add('GET', '/profiles/{id}/remedies', function (Request $q, array $u, array $a) {
            $period = (string) $q->input('period', 'common');
            if (!in_array($period, ['common', 'daily', 'weekly', 'monthly'], true)) throw new ApiException('validation', 'period must be common, daily, weekly or monthly', 422);
            [$p, $kid, $k, $lang] = self::ctx($q, $u, $a);
            [$jd, $local] = self::at($q, $p);
            if ($period === 'monthly') {
                $m = (string) $q->input('month', $local->format('Y-m'));
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw new ApiException('validation', 'month must be YYYY-MM', 422);
                [$jd, $local] = self::at($q, $p, $m . '-15');   // mid-month transits
            }
            $pk = ['common' => '', 'daily' => $local->format('Y-m-d'), 'weekly' => $local->format('o-\WW'), 'monthly' => $local->format('Y-m')][$period];
            return self::cached($kid, 'remedy_' . $period . '_' . $local->getTimezone()->getName(), $pk, $lang, function () use ($k, $p, $period, $lang, $jd, $local) {
                $tr = (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']);
                return (new RuleEngine($lang))->remedies($period, $k, $tr, (int) $local->format('w'))
                    + ['as_on' => $local->format($period === 'monthly' ? 'Y-m' : 'Y-m-d'), 'transits_used' => $tr];
            });
        });
        $r->add('POST', '/prashna', function (Request $q, array $u) {
            $in = $q->require(['lat', 'lon', 'tzid']);
            $now = new \DateTimeImmutable('now', new \DateTimeZone((string) $in['tzid']));
            $b = TimeResolver::resolve($now->format('Y-m-d'), $now->format('H:i:s'), (string) $in['tzid']);
            $k = (new KundaliService())->compute($b, (float) $in['lat'], (float) $in['lon']);
            return ['question' => mb_substr((string) $q->input('question', ''), 0, 500), 'chart' => $k,
                    'interpretation' => null, 'note' => 'Prashna interpretation rules are not implemented yet; chart data only.'];
        });
    }

    /** @return array{0:array,1:int,2:array,3:string} profile, kundali id, kundali, lang */
    private static function kundaliById(int $pid, array $u): array {
        $p = \App\Core\Db::one('SELECT * FROM birth_profiles WHERE id=? AND user_id=?', [$pid, $u['id']]);
        if (!$p) throw new ApiException('not_found', 'Profile not found', 404);
        $b = \App\Calc\TimeResolver::resolve($p['birth_date'], $p['birth_time'], $p['tzid']);
        return (new \App\Calc\KundaliService())->compute($b, (float)$p['lat'], (float)$p['lon']);
    }

    private static function ctx(Request $q, array $u, array $a): array {
        $p = ProfileService::get((int) $u['id'], $a['id']); [$kid, $k] = ProfileService::kundali($p);
        return [$p, $kid, $k, $q->lang($u)];
    }

    /** Target moment: ?date=YYYY-MM-DD (local noon) in ?tzid= (default: birth timezone); no date = now. */
    private static function at(Request $q, array $p, ?string $forceDate = null): array {
        try { $tz = new \DateTimeZone((string) $q->input('tzid', $p['tzid'])); } catch (\Exception) { throw new ApiException('invalid_timezone', 'Unknown timezone', 422); }
        $date = $forceDate ?? $q->input('date');
        if ($date === null || $date === '') { $local = new \DateTimeImmutable('now', $tz); return [TimeResolver::nowJd(), $local]; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) throw new ApiException('invalid_date', 'date must be YYYY-MM-DD', 422);
        $local = new \DateTimeImmutable($date . ' 12:00:00', $tz);
        return [$local->getTimestamp() / 86400 + 2440587.5, $local];
    }

    private static function prediction(string $type, Request $q, array $p, int $kid, array $k, string $lang): array {
        if (!in_array($type, ['life', 'dasha', 'mdphal', 'monthly', 'daily'], true)) throw new ApiException('validation', 'type must be life, dasha, mdphal, monthly or daily', 422);
        [$jd, $local] = self::at($q, $p);
        if ($type === 'monthly') [$jd, $local] = self::at($q, $p, $local->format('Y-m-15'));
        $pk = ['mdphal' => $local->format('Y-m'), 'life' => $local->format('Y-m'), 'dasha' => $local->format('Y-m-d'), 'monthly' => $local->format('Y-m'), 'daily' => $local->format('Y-m-d')][$type];
        return self::cached($kid, 'pred_' . $type . '_' . $local->getTimezone()->getName(), $pk, $lang, function () use ($type, $k, $p, $jd, $lang, $local) {
            $r = new RuleEngine($lang);
            if ($type === 'life') { $l = $r->life($k, $jd); $l['sections'] = $r->lifeSections($k)['items']; return $l; }
            if ($type === 'mdphal') return $r->mahadashaPhal($k, $jd);
            if ($type === 'dasha') return $r->dashaNow($k, $jd);
            $tr = (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']);
            return $r->horoscope($type, $k, $tr) + ['as_on' => $local->format('Y-m-d H:i T')];
        });
    }

    private static function doshas(Request $q, array $p, int $kid, array $k, string $lang): array {
        [$jd, $local] = self::at($q, $p);
        $tr = (new KundaliService())->transits($k, $jd, $p['lat'], $p['lon']);
        return ['calculated' => ['type' => 'calculated', 'mangal_dosha' => $k['analysis']['mangal_dosha'], 'kaal_sarp' => $k['analysis']['kaal_sarp'],
                    'sade_sati' => $tr['derived']['sade_sati'] + ['period' => $tr['sade_sati_cycle']], 'shani_dhaiya' => $tr['derived']['shani_dhaiya'],
                    'as_on' => $local->format('Y-m-d H:i T')],
                'interpretation' => self::cached($kid, 'doshas_' . $local->getTimezone()->getName(), $local->format('Y-m-d'), $lang, fn() => (new RuleEngine($lang))->doshas($k, $tr))];
    }

    private static function gems(int $kid, array $k, string $lang): array {
        return ['gemstones' => self::cached($kid, 'gemstones', '', $lang, fn() => (new RuleEngine($lang))->gemstones($k)),
                'influences' => self::cached($kid, 'influences', '', $lang, fn() => (new RuleEngine($lang))->influences($k))];
    }

    private static function cached(int $kid, string $kind, string $pk, string $lang, callable $make): array {
        $row = Db::one('SELECT payload_json FROM interpretations WHERE kundali_id=? AND kind=? AND period_key=? AND lang=? AND ruleset_version=?',
            [$kid, $kind, $pk, $lang, RuleEngine::VERSION]);
        if ($row) return json_decode($row['payload_json'], true);
        $data = $make();
        Db::exec('INSERT INTO interpretations (kundali_id, kind, period_key, lang, ruleset_version, payload_json) VALUES (?,?,?,?,?,?)',
            [$kid, $kind, $pk, $lang, RuleEngine::VERSION, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return $data;
    }

    /** Planets, houses, icon and warning flag for a new category, picked from keywords in its English name. */
    private static function categoryAstro(string $name): array {
        $n = strtolower($name);
        foreach ([
            ['stock|share|trading|invest|crypto|market', 'Jupiter,Mercury,Rahu', '5,8,11', 'trending_up', 1],
            ['sport|cricket|football|match|game', 'Mars,Sun', '3,5,6', 'sports_cricket', 1],
            ['lottery|bet|gambl|satta', 'Rahu,Jupiter', '5,8,11', 'casino', 1],
            ['love|romance|relationship|partner|dating', 'Venus,Moon', '5,7', 'favorite', 0],
            ['marriage|wedding|spouse|husband|wife', 'Venus,Jupiter', '7,2', 'diversity_1', 0],
            ['career|job|work|office|promotion', 'Sun,Saturn,Mercury', '10,6,2', 'work', 0],
            ['business|trade|shop|startup', 'Mercury,Jupiter', '7,10,11', 'storefront', 0],
            ['money|finance|wealth|income|saving', 'Jupiter,Venus', '2,11', 'savings', 0],
            ['property|home|house|land|vehicle|car', 'Mars,Saturn', '4,11', 'home', 0],
            ['child|kid|baby|pregnan', 'Jupiter', '5', 'child_care', 0],
            ['health|fitness|disease|medical', 'Sun,Moon,Mars', '1,6,8', 'health_and_safety', 0],
            ['education|study|exam|school|college', 'Mercury,Jupiter', '4,5,9', 'school', 0],
            ['travel|abroad|foreign|visa|journey', 'Rahu,Moon', '3,9,12', 'flight', 0],
            ['family|parent|mother|father', 'Moon,Sun', '2,4,9', 'family_restroom', 0],
            ['friend|social', 'Mercury,Venus', '3,11', 'group', 0],
            ['legal|court|case|dispute', 'Saturn,Mars', '6,7', 'gavel', 0],
            ['spiritual|religio|puja|meditation', 'Jupiter,Ketu', '9,12', 'self_improvement', 0],
        ] as [$re, $pl, $hs, $ic, $warn]) if (preg_match("/($re)/", $n)) return [$pl, $hs, $ic, $warn];
        return ['Jupiter,Moon', '1,9,11', 'star', 0];
    }

    private static function limit(string $bucket, int $perMinute): void {
        $key = $bucket . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'); $now = time();
        $row = Db::one('SELECT hits, window_start FROM rate_limits WHERE bucket=?', [$key]);
        if (!$row || $now - (int) $row['window_start'] >= 60) { Db::exec('REPLACE INTO rate_limits (bucket, hits, window_start) VALUES (?,1,?)', [$key, $now]); return; }
        if ((int) $row['hits'] >= $perMinute) throw new ApiException('rate_limited', 'Too many requests. Wait a minute and try again.', 429);
        Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket=?', [$key]);
    }
}