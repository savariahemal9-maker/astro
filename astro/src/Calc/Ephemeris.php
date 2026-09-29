<?php
namespace App\Calc;

/**
 * Runs the Swiss Ephemeris engine (engine/bin/astro_calc). The only source of astronomical data.
 * Any engine failure throws; there is deliberately no fallback.
 */
final class Ephemeris {
    private array $cfg; private array $calc;
    public function __construct(?array $engineCfg = null, ?array $calcCfg = null) {
        $this->cfg = $engineCfg ?? app_config()['engine'];
        $this->calc = $calcCfg ?? app_config()['calc'];
    }

    public function settings(): array {
        return ['ayanamsa' => $this->calc['ayanamsa'], 'node' => $this->calc['node'], 'rise' => $this->calc['rise'],
                'house_system' => 'whole_sign', 'zodiac' => 'sidereal', 'positions' => 'geocentric apparent',
                'dasha_year_days' => $this->calc['dasha_year_days']];
    }

    private ?PhpEngine $php = null;
    private function php(): ?PhpEngine {
        if (($this->cfg['backend'] ?? 'php') !== 'php') return null;
        if ($this->calc['ayanamsa'] !== 'lahiri') throw new CalcException('PHP engine data is generated for Lahiri only');
        return $this->php ??= new PhpEngine($this->cfg['data'], $this->calc['node'], $this->calc['rise']);
    }

    public function chart(float $jdUt, float $lat, float $lon): array {
        if ($e = $this->php()) return $e->chart($jdUt, $lat, $lon);
        return $this->run(['chart', '--jd=' . sprintf('%.8f', $jdUt), '--lat=' . $lat, '--lon=' . $lon]);
    }

    public function panchang(float $jdLocalMidnightUt, float $lat, float $lon): array {
        if ($e = $this->php()) return $e->panchang($jdLocalMidnightUt, $lat, $lon);
        return $this->run(['panchang', '--jd=' . sprintf('%.8f', $jdLocalMidnightUt), '--lat=' . $lat, '--lon=' . $lon,
                           '--rise=' . $this->calc['rise']]);
    }

    public function isFast(): bool { return $this->php() !== null; }
    /** Sidereal longitude of one body (used for scans such as Sade Sati dates). */
    public function siderealLon(string $body, float $jd): float {
        if ($e = $this->php()) return $e->sid($body, $jd);
        foreach ($this->chart($jd, 0, 0)['planets'] as $p) if ($p['name'] === $body) return $p['lon'];
        throw new CalcException("$body not available");
    }

    public function info(): array { return ($e = $this->php()) ? $e->info() : $this->run(['info']); }

    private function run(array $args): array {
        $bin = $this->cfg['binary'];
        if (!is_executable($bin)) throw new CalcException('Calculation engine not installed. Run engine/build.sh on the server.');
        $cmd = array_merge([$bin], $args, [
            '--ephe=' . $this->cfg['ephe'], '--ayanamsa=' . $this->calc['ayanamsa'],
            '--node=' . $this->calc['node'], '--require-swieph',
        ]);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); // array form: no shell
        if (!is_resource($p)) throw new CalcException('Could not start calculation engine');
        stream_set_timeout($pipes[1], (int) $this->cfg['timeout']);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        $data = json_decode((string) $out, true);
        if (!is_array($data)) throw new CalcException('Calculation engine returned invalid output');
        if ($code !== 0 || isset($data['error'])) throw new CalcException('Calculation failed: ' . ($data['error'] ?? 'unknown'));
        return $data;
    }
}
