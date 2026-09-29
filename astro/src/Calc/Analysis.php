<?php
namespace App\Calc;

/** Deterministic rule checks on calculated positions (no interpretation text). Each result names its rule. */
final class Analysis {
    public const VARGA_NAMES = ['D1' => 'Rashi', 'D2' => 'Hora', 'D3' => 'Drekkana', 'D4' => 'Chaturthamsha', 'D7' => 'Saptamsha',
        'D9' => 'Navamsha', 'D10' => 'Dashamsha', 'D12' => 'Dwadashamsha', 'D16' => 'Shodashamsha', 'D20' => 'Vimshamsha',
        'D24' => 'Chaturvimshamsha', 'D27' => 'Saptavimshamsha', 'D30' => 'Trimshamsha', 'D40' => 'Khavedamsha',
        'D45' => 'Akshavedamsha', 'D60' => 'Shashtiamsha'];
    // Classical combustion orbs (degrees from the Sun); second value applies when retrograde
    private const COMBUST = ['Moon' => [12, 12], 'Mars' => [17, 17], 'Mercury' => [14, 12], 'Jupiter' => [11, 11], 'Venus' => [10, 8], 'Saturn' => [15, 15]];
    private const KAAL_SARP = ['Anant', 'Kulik', 'Vasuki', 'Shankhpal', 'Padma', 'Mahapadma', 'Takshak', 'Karkotak', 'Shankhachud', 'Ghatak', 'Vishdhar', 'Sheshnag'];
    public const NATURAL_BENEFICS = ['Jupiter', 'Venus', 'Mercury', 'Moon'];

    public static function combust(array $p, float $sunLon): bool {
        if (!isset(self::COMBUST[$p['name']])) return false;
        $d = abs(fmod($p['lon'] - $sunLon + 540, 360) - 180);
        return $d <= self::COMBUST[$p['name']][$p['retrograde'] ? 1 : 0];
    }

    public static function houseLord(int $lagnaSign, int $house): string { return Zodiac::SIGN_LORDS[($lagnaSign + $house - 1) % 12]; }

    /** Houses (1..12) ruled by each of the seven planets for this lagna. */
    public static function lordships(int $lagnaSign): array {
        $out = [];
        for ($h = 1; $h <= 12; $h++) $out[self::houseLord($lagnaSign, $h)][] = $h;
        return $out;
    }

    public static function natal(array $lagna, array $planets): array {
        $by = []; foreach ($planets as $p) $by[$p['name']] = $p;
        $mars = $by['Mars'];
        $from = fn(int $ref) => ($mars['sign'] - $ref + 12) % 12 + 1;
        $mangal = [];
        foreach (['lagna' => $lagna['sign'], 'moon' => $by['Moon']['sign'], 'venus' => $by['Venus']['sign']] as $k => $ref) {
            $h = $from($ref); $mangal[$k] = ['house' => $h, 'present' => in_array($h, [1, 2, 4, 7, 8, 12], true)];
        }
        $rahu = $by['Rahu']['lon']; $side = [];
        foreach (['Sun', 'Moon', 'Mars', 'Mercury', 'Jupiter', 'Venus', 'Saturn'] as $n) $side[] = Zodiac::norm($by[$n]['lon'] - $rahu) < 180 ? 1 : 0;
        $ks = array_sum($side) === 7 || array_sum($side) === 0;
        return [
            'mangal_dosha' => [
                'present' => $mangal['lagna']['present'] || $mangal['moon']['present'],
                'from' => $mangal, 'mars_sign' => $mars['sign_name'],
                'mitigation_own_or_exalted' => in_array($mars['sign'], [0, 7, 9], true),
                'rule' => 'Mars in house 1, 2, 4, 7, 8 or 12 counted from Lagna or Moon (Venus shown for reference); Mars in Aries, Scorpio or Capricorn noted as a common mitigation',
            ],
            'kaal_sarp' => [
                'present' => $ks, 'type' => $ks ? self::KAAL_SARP[$by['Rahu']['house'] - 1] : null,
                'direction' => $ks ? (array_sum($side) === 7 ? 'rahu_to_ketu' : 'ketu_to_rahu') : null, 'rahu_house' => $by['Rahu']['house'],
                'rule' => 'All seven planets within the 180° arc on one side of the Rahu–Ketu axis; type named by Rahu\'s house',
            ],
            'lordships' => self::lordships($lagna['sign']),
        ];
    }
}
