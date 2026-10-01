<?php
namespace App\Interp;

use App\Calc\Zodiac;
use App\Core\Db;

/**
 * Ashtakoot Kundali Milan (8-factor match-making).
 * Operates purely on Moon sign (Rashi) and Moon nakshatra indices.
 */
final class KundaliMilan {
    // ─── Ashtakoot tables ─────────────────────────────────────────────────
    // Varna (caste): signs 0-11 → 0=Brahmin,1=Kshatriya,2=Vaishya,3=Shudra
    private const VARNA = [2,3,2,0,0,1,1,2,1,3,0,3];
    // Vashya groups: 0=Chatushpad,1=Manav,2=Vanchar,3=Jalchar,4=Keeta/Keet
    private const VASHYA_GRP = [0,0,1,3,0,1,1,0,1,0,1,4];
    private const VASHYA_SCORE = [
        [2,0,1,0,0], [0,2,0,1,0], [1,0,2,0,0], [0,1,0,2,0], [0,0,0,0,2]
    ];
    // Yoni (animal): nakshatra 0-26 → animal index
    private const YONI = [0,1,14,7,5,6,4,4,13,3,12,2,8,7,9,10,11,13,14,3,5,6,12,1,0,9,10];
    private const YONI_COMPAT = [
        [4,0,2,1,2,0,3,1,3,1,0,2,2,3,0],
        [0,4,2,2,0,2,0,1,2,2,1,0,2,1,2],
        [2,2,4,2,0,2,1,2,2,0,2,1,1,2,2],
        [1,2,2,4,2,1,2,2,1,2,1,2,2,2,1],
        [2,0,0,2,4,2,0,2,0,2,2,2,0,2,2],
        [0,2,2,1,2,4,2,0,2,0,2,1,2,2,0],
        [3,0,1,2,0,2,4,2,1,2,2,2,0,2,2],
        [1,1,2,2,2,0,2,4,2,1,0,2,2,1,2],
        [3,2,2,1,0,2,1,2,4,2,2,0,2,2,2],
        [1,2,0,2,2,0,2,1,2,4,2,2,1,2,2],
        [0,1,2,1,2,2,2,0,2,2,4,2,2,2,2],
        [2,0,1,2,2,1,2,2,0,2,2,4,2,2,2],
        [2,2,1,2,0,2,0,2,2,1,2,2,4,2,2],
        [3,1,2,2,2,2,2,1,2,2,2,2,2,4,0],
        [0,2,2,1,2,0,2,2,2,2,2,2,2,0,4],
    ];
    // Gana: nakshatra 0-26 → 0=Deva,1=Manav,2=Rakshasa
    private const GANA = [0,1,0,0,1,2,0,0,2,2,2,0,2,1,0,0,1,1,2,1,2,0,0,1,2,1,2];
    // Planet friends for Graha Maitri (sign lord comparison)
    private const FRIENDS = [
        'Sun'     => ['Moon','Mars','Jupiter'],
        'Moon'    => ['Sun','Mercury'],
        'Mars'    => ['Sun','Moon','Jupiter'],
        'Mercury' => ['Sun','Venus','Rahu'],
        'Jupiter' => ['Sun','Moon','Mars'],
        'Venus'   => ['Mercury','Saturn','Rahu'],
        'Saturn'  => ['Mercury','Venus','Rahu'],
        'Rahu'    => ['Mercury','Venus','Saturn'],
        'Ketu'    => ['Mercury','Venus','Saturn'],
    ];
    // Sign lords (0-11)
    private const SIGN_LORD = ['Mars','Venus','Mercury','Moon','Sun','Mercury','Venus','Mars','Jupiter','Saturn','Saturn','Jupiter'];
    // Bhakoot: sign number difference → score
    private const BHAKOOT_GOOD = [[1,7],[2,12],[3,11],[4,10],[5,9],[6,8]]; // pairs that give 7
    // Nadi: nakshatra 0-26 → 0=Aadi,1=Madhya,2=Antya
    private const NADI = [0,0,0,1,1,1,2,2,2,0,0,0,1,1,1,2,2,2,0,0,0,1,1,1,2,2,2];

    // ─── Factor names ──────────────────────────────────────────────────────
    private const FACTORS = [
        'varna'   => ['max'=>1,  'name'=>'Varna',   'name_hi'=>'वर्ण',   'name_gu'=>'વર્ણ'],
        'vashya'  => ['max'=>2,  'name'=>'Vashya',  'name_hi'=>'वश्य',   'name_gu'=>'વશ્ય'],
        'tara'    => ['max'=>3,  'name'=>'Tara',    'name_hi'=>'तारा',   'name_gu'=>'તારા'],
        'yoni'    => ['max'=>4,  'name'=>'Yoni',    'name_hi'=>'योनि',   'name_gu'=>'યોનિ'],
        'graha'   => ['max'=>5,  'name'=>'Graha Maitri','name_hi'=>'ग्रह मैत्री','name_gu'=>'ગ્રહ મૈત્રી'],
        'gana'    => ['max'=>6,  'name'=>'Gana',    'name_hi'=>'गण',     'name_gu'=>'ગણ'],
        'bhakoot' => ['max'=>7,  'name'=>'Bhakoot', 'name_hi'=>'भकूट',   'name_gu'=>'ભકૂટ'],
        'nadi'    => ['max'=>8,  'name'=>'Nadi',    'name_hi'=>'नाड़ी',   'name_gu'=>'નાડી'],
    ];

    public function __construct(private string $lang = 'en') {}

    /** Return one of three language variants. */
    private function tr(string $en, string $hi, string $gu): string {
        return match($this->lang) { 'hi' => $hi, 'gu' => $gu, default => $en };
    }

    /** Translate a list of proper nouns (animal names, gana, etc.) */
    private function trList(string $key, int $index): string {
        static $lists = [
            'varna' => [
                'en' => ['Brahmin','Kshatriya','Vaishya','Shudra'],
                'hi' => ['ब्राह्मण','क्षत्रिय','वैश्य','शूद्र'],
                'gu' => ['બ્રાહ્મણ','ક્ષત્રિય','વૈશ્ય','શૂદ્ર'],
            ],
            'vashya' => [
                'en' => ['Chatushpad','Manav','Vanchar','Jalchar','Keeta'],
                'hi' => ['चतुष्पद','मानव','वानचर','जलचर','कीट'],
                'gu' => ['ચતુષ્પદ','માનવ','વાનચર','જળચર','કીટ'],
            ],
            'tara' => [
                'en' => ['Janma','Sampat','Vipat','Kshema','Pratyak','Sadhana','Naidhana','Mitra','Atimitra'],
                'hi' => ['जन्म','सम्पत','विपत','क्षेम','प्रत्यक','साधन','नैधन','मित्र','अतिमित्र'],
                'gu' => ['જન્મ','સંપત','વિપત','ક્ષેમ','પ્રત્યક','સાધન','નૈધન','મિત્ર','અતિમિત્ર'],
            ],
            'animal' => [
                'en' => ['Horse','Elephant','Sheep','Serpent','Dog','Cat','Rat','Cow','Buffalo','Tiger','Hare','Monkey','Mongoose','Lion','Deer'],
                'hi' => ['घोड़ा','हाथी','भेड़','सर्प','कुत्ता','बिल्ली','चूहा','गाय','भैंस','बाघ','खरगोश','बंदर','नेवला','शेर','हिरण'],
                'gu' => ['ઘોડો','હાથી','ઘેટું','સર્પ','કૂતરો','બિલાડી','ઉંદર','ગાય','ભેંસ','વાઘ','સસલું','વાંદરો','નોળિયો','સિંહ','હરણ'],
            ],
            'gana' => [
                'en' => ['Deva','Manav','Rakshasa'],
                'hi' => ['देव','मानव','राक्षस'],
                'gu' => ['દેવ','માનવ','રાક્ષસ'],
            ],
            'nadi' => [
                'en' => ['Aadi (Vata)','Madhya (Pitta)','Antya (Kapha)'],
                'hi' => ['आदि (वात)','मध्य (पित्त)','अंत्य (कफ)'],
                'gu' => ['આદિ (વાત)','મધ્ય (પિત્ત)','અંત્ય (કફ)'],
            ],
        ];
        return $lists[$key][$this->lang][$index] ?? $lists[$key]['en'][$index] ?? '?';
    }

    /** Full Ashtakoot calculation */
    public function calculate(array $boyK, array $girlK): array {
        $bMoon = $this->moon($boyK);  $gMoon = $this->moon($girlK);
        $bNak  = $bMoon['nak'];       $gNak  = $gMoon['nak'];
        $bSign = $bMoon['sign'];      $gSign = $gMoon['sign'];

        $factors = [
            'varna'   => $this->varna($bSign, $gSign),
            'vashya'  => $this->vashya($bSign, $gSign),
            'tara'    => $this->tara($bNak, $gNak),
            'yoni'    => $this->yoni($bNak, $gNak),
            'graha'   => $this->graha($bSign, $gSign),
            'gana'    => $this->gana($bNak, $gNak),
            'bhakoot' => $this->bhakoot($bSign, $gSign),
            'nadi'    => $this->nadi($bNak, $gNak),
        ];

        $total = array_sum(array_column($factors, 'score'));
        $max   = 36;
        $pct   = round($total / $max * 100);
        [$verdict, $verdict_hi, $verdict_gu] = match(true) {
            $total >= 28 => ['Excellent match — highly compatible', 'उत्तम मिलान — अत्यंत अनुकूल', 'ઉત્તમ મિલાન — અત્યંત અનુકૂળ'],
            $total >= 21 => ['Good match — compatible with minor differences', 'अच्छा मिलान — मामूली भिन्नता के साथ अनुकूल', 'સારો મિલાન — નાના ભેદ સાથે અનુકૂળ'],
            $total >= 18 => ['Average match — workable with effort', 'सामान्य मिलान — प्रयास से चलेगा', 'સામાન્ય મિલાન — પ્રયત્ને ચાલે'],
            default      => ['Below average — significant incompatibilities', 'औसत से कम — महत्वपूर्ण असंगतियाँ', 'ઓછું — નોંધપાત્ર અસંગતતા'],
        };
        $mangal = ['boy' => $this->mangal($boyK), 'girl' => $this->mangal($girlK)];

        // Enrich each factor with meta
        foreach ($factors as $k => &$f) {
            $f['name'] = self::FACTORS[$k]['name_' . $this->lang] ?? self::FACTORS[$k]['name'];
            $f['max']  = self::FACTORS[$k]['max'];
        }

        return [
            'score' => $total, 'max' => $max, 'percent' => $pct,
            'verdict' => match($this->lang) { 'hi' => $verdict_hi, 'gu' => $verdict_gu, default => $verdict },
            'boy_moon'  => ['rashi' => Zodiac::SIGNS[$bSign], 'nakshatra' => Zodiac::NAKSHATRAS[$bNak], 'pada' => $bMoon['pada'], 'sign_idx' => $bSign, 'nak_idx' => $bNak],
            'girl_moon' => ['rashi' => Zodiac::SIGNS[$gSign], 'nakshatra' => Zodiac::NAKSHATRAS[$gNak], 'pada' => $gMoon['pada'], 'sign_idx' => $gSign, 'nak_idx' => $gNak],
            'mangal'  => $mangal,
            'factors' => $factors,
        ];
    }

    private function moon(array $k): array {
        foreach ($k['planets'] as $p) if ($p['name'] === 'Moon')
            return ['sign' => $p['sign'], 'nak' => Zodiac::nakshatra((float)$p['lon'])['index'],
                    'pada' => Zodiac::nakshatra((float)$p['lon'])['pada']];
        return ['sign' => 0, 'nak' => 0, 'pada' => 1];
    }

    private function mangal(array $k): array {
        // Use pre-computed analysis if available (checks from Lagna, Moon, and Venus)
        if (isset($k['analysis']['mangal_dosha'])) {
            $md = $k['analysis']['mangal_dosha'];
            $house = 0;
            foreach (['lagna', 'moon', 'venus'] as $from) {
                if (!empty($md['from'][$from]['present'])) { $house = $md['from'][$from]['house'] ?? 0; break; }
            }
            if (!$house) $house = $md['from']['lagna']['house'] ?? 0;
            $note = $md['present'] ? 'Mangal Dosha present — house ' . $house : 'No Mangal Dosha';
            return ['present' => (bool)$md['present'], 'house' => $house, 'note' => $note];
        }
        // Fallback: lagna-only check
        foreach ($k['planets'] as $p) if ($p['name'] === 'Mars') {
            $h = $p['house']; $present = in_array($h, [1,2,4,7,8,12], true);
            return ['present' => $present, 'house' => $h,
                'note' => $present ? 'Mangal Dosha present — house ' . $h : 'No Mangal Dosha'];
        }
        return ['present' => false, 'house' => 0, 'note' => 'No Mangal Dosha'];
    }

    private function varna(int $b, int $g): array {
        $bv = self::VARNA[$b]; $gv = self::VARNA[$g];
        $bn = $this->trList('varna', $bv); $gn = $this->trList('varna', $gv);
        $score = ($gv <= $bv) ? 1 : 0;
        $bLabel = $this->tr("Boy's varna", 'लड़के का वर्ण', 'છોકરાનો વર્ણ');
        $gLabel = $this->tr("Girl's varna", 'लड़की का वर्ण', 'છોકરીનો વર્ણ');
        $compat = $score
            ? $this->tr("Girl's varna is equal or lower — compatible.", 'लड़की का वर्ण समान या कम — अनुकूल.', 'છોકરીનો વર્ણ સમાન અથવા ઓછો — અનુકૂળ.')
            : $this->tr("Girl's varna is higher — not ideal, but other factors can compensate.", 'लड़की का वर्ण अधिक — आदर्श नहीं, पर अन्य गुण सहायक हो सकते हैं.', 'છોકરીનો વર્ણ ઊંચો — આદર્શ નહીં, પણ બીજા ગુણ ભરપાઈ કરી શકે.');
        return ['score' => $score, 'boy' => $bn, 'girl' => $gn,
            'detail' => "$bLabel: $bn (rank $bv). $gLabel: $gn (rank $gv). $compat"];
    }

    private function vashya(int $b, int $g): array {
        $bg = self::VASHYA_GRP[$b]; $gg = self::VASHYA_GRP[$g];
        $score = self::VASHYA_SCORE[$bg][$gg];
        $bn = $this->trList('vashya', $bg); $gn = $this->trList('vashya', $gg);
        $boy = $this->tr('Boy', 'लड़का', 'છોકરો'); $girl = $this->tr('Girl', 'लड़की', 'છોકરી');
        $desc = $this->tr('Vashya shows mutual attraction and loyalty. Higher score means stronger bond.', 'वश्य पारस्परिक आकर्षण और वफादारी दर्शाता है. अधिक अंक मजबूत बंधन को दर्शाते हैं.', 'વશ્ય પરસ્પર આકર્ષણ અને વફાદારી દર્શાવે છે. વધારે ગુણ મક્કમ સંબંધ સૂચવે છે.');
        return ['score' => $score, 'boy' => $bn, 'girl' => $gn,
            'detail' => "$boy: $bn, $girl: $gn. Score $score/2. $desc"];
    }

    private function tara(int $bNak, int $gNak): array {
        $bt = (($gNak - $bNak + 27) % 27) % 9;
        $gt = (($bNak - $gNak + 27) % 27) % 9;
        $good = [1,3,5,7];
        $bs = in_array($bt, $good, true); $gs = in_array($gt, $good, true);
        $score = ($bs && $gs) ? 3 : (($bs || $gs) ? 1.5 : 0);
        $btn = $this->trList('tara', $bt); $gtn = $this->trList('tara', $gt);
        $bLabel = $this->tr("Boy's tara from girl's nakshatra", 'लड़की के नक्षत्र से लड़के की तारा', 'છોકરીના નક્ષત્રથી છોકરાની તારા');
        $gLabel = $this->tr("Girl's tara from boy's nakshatra", 'लड़के के नक्षत्र से लड़की की तारा', 'છોકરાના નક્ષત્રથી છોકરીની તારા');
        $desc = $this->tr('Tara indicates health and well-being in the relationship.', 'तारा दंपत्ति के स्वास्थ्य और सुख का संकेत देता है.', 'તારા સંબંધમાં સ્વાસ્થ્ય અને સુખ સૂચવે છે.');
        return ['score' => $score, 'boy_tara' => $btn, 'girl_tara' => $gtn,
            'detail' => "$bLabel: $btn. $gLabel: $gtn. Score: $score/3. $desc"];
    }

    private function yoni(int $bNak, int $gNak): array {
        $by = self::YONI[$bNak]; $gy = self::YONI[$gNak];
        $raw = self::YONI_COMPAT[$by][$gy];
        $score = round($raw / 4 * 4);
        $ban = $this->trList('animal', $by); $gan = $this->trList('animal', $gy);
        $bLabel = $this->tr("Boy's yoni", 'लड़के की योनि', 'છોકરાની યોનિ');
        $gLabel = $this->tr("Girl's yoni", 'लड़की की योनि', 'છોકરીની યોનિ');
        $desc = $this->tr('Yoni represents physical and temperamental compatibility. Higher score = greater harmony.', 'योनि शारीरिक और स्वभाव अनुकूलता दर्शाती है. अधिक अंक = अधिक सामंजस्य.', 'યોનિ શારીરિક અને સ્વભાવ સુસંગતતા દર્શાવે છે. વધારે ગુણ = વધારે સૌહાર્દ.');
        return ['score' => $score, 'boy_animal' => $ban, 'girl_animal' => $gan,
            'detail' => "$bLabel: $ban, $gLabel: $gan. Score: $score/4. $desc"];
    }

    private function graha(int $bSign, int $gSign): array {
        $bl = self::SIGN_LORD[$bSign]; $gl = self::SIGN_LORD[$gSign];
        $bf = in_array($gl, self::FRIENDS[$bl] ?? [], true);
        $gf = in_array($bl, self::FRIENDS[$gl] ?? [], true);
        $score = ($bf && $gf) ? 5 : (($bf || $gf) ? 4 : ($bl === $gl ? 5 : 0));
        $bLabel = $this->tr("Boy's Moon sign lord", 'लड़के के चंद्र राशि का स्वामी', 'છોકરાની ચંદ્ર રાશિનો સ્વામી');
        $gLabel = $this->tr("Girl's Moon sign lord", 'लड़की के चंद्र राशि का स्वामी', 'છોકરીની ચંદ્ર રાશિનો સ્વામી');
        $compat = $bf && $gf
            ? $this->tr("Both lords are mutual friends — excellent mental compatibility.", 'दोनों स्वामी परस्पर मित्र हैं — उत्तम मानसिक अनुकूलता.', 'બંને સ્વામી પરસ્પર મિત્ર — ઉત્તમ માનસિક સુસંગતતા.')
            : ($bf || $gf
                ? $this->tr("One-sided friendship — generally compatible.", 'एकतरफा मित्रता — सामान्यतः अनुकूल.', 'એકતરફી મિત્રતા — સામાન્ય રીતે અનુકૂળ.')
                : ($bl === $gl
                    ? $this->tr("Same lord — strong compatibility.", 'समान स्वामी — मजबूत अनुकूलता.', 'સરખો સ્વામી — મક્કમ સુસંગતતા.')
                    : $this->tr("Lords are neutral or inimical — mental friction possible.", 'स्वामी तटस्थ या शत्रु — मानसिक तनाव संभव.', 'સ્વામી તટસ્થ અથવા શત્રુ — માનસિક ઘર્ષણ શક્ય.')));
        return ['score' => $score, 'boy_lord' => $bl, 'girl_lord' => $gl,
            'detail' => "$bLabel: $bl. $gLabel: $gl. $compat Score: $score/5."];
    }

    private function gana(int $bNak, int $gNak): array {
        $bg = self::GANA[$bNak]; $gg = self::GANA[$gNak];
        $score = match(true) {
            $bg === $gg          => 6,
            $bg === 0 && $gg === 1, $bg === 1 && $gg === 0 => 5,
            $bg === 0 && $gg === 2 => 1,
            $bg === 2 && $gg === 0 => 0,
            default              => 3,
        };
        $bgn = $this->trList('gana', $bg); $ggn = $this->trList('gana', $gg);
        $boy = $this->tr('Boy', 'लड़का', 'છોકરો'); $girl = $this->tr('Girl', 'लड़की', 'છોકરી');
        $ganaDesc = $this->tr('Gana indicates temperament and nature. Same gana is ideal. Rakshasa-Deva combination is the most challenging.', 'गण स्वभाव और प्रकृति दर्शाता है. समान गण आदर्श है. राक्षस-देव संयोग सबसे कठिन है.', 'ગણ સ્વભાવ અને પ્રકૃતિ દર્શાવે છે. સરખો ગણ આદર્શ છે. રાક્ષસ-દેવ સંયોગ સૌથી કઠિન છે.');
        $ganaWord = $this->tr('gana', 'गण', 'ગણ');
        return ['score' => $score, 'boy_gana' => $bgn, 'girl_gana' => $ggn,
            'detail' => "$boy: $bgn $ganaWord, $girl: $ggn $ganaWord. Score: $score/6. $ganaDesc"];
    }

    private function bhakoot(int $bSign, int $gSign): array {
        $diff = abs($bSign - $gSign) + 1; if ($diff > 7) $diff = 13 - $diff;
        $bad = [6, 8]; // 6/8 and 2/12 rashi pairs are inauspicious
        $bad2 = in_array(abs($bSign - $gSign) + 1, [2, 12], true) || in_array(13 - abs($bSign - $gSign) - 1, [2, 12], true);
        $isBad = in_array($diff, $bad, true) || $bad2;
        $score = $isBad ? 0 : 7;
        $bs = Zodiac::SIGNS[$bSign]; $gs = Zodiac::SIGNS[$gSign];
        $boy = $this->tr('Boy', 'लड़का', 'છોકરો'); $girl = $this->tr('Girl', 'लड़की', 'છોકરી');
        $bhakDesc = $isBad
            ? $this->tr('Unfavourable Bhakoot — 6/8 or 2/12 sign relationship indicates potential financial or family difficulties.', 'अशुभ भकूट — 6/8 या 2/12 राशि संबंध में आर्थिक या पारिवारिक कठिनाई संभव.', 'અશુભ ભકૂટ — 6/8 અથવા 2/12 રાશિ સંબંધ — આર્થિક અથવા કૌટુંબિક મુશ્કેલી શક્ય.')
            : $this->tr('Favourable Bhakoot — the sign combination supports prosperity and family happiness.', 'शुभ भकूट — राशि संयोग सुख-समृद्धि और पारिवारिक खुशी को सहायक है.', 'શુભ ભકૂટ — રાશિ સંયોગ સુખ-સમૃદ્ધિ અને કૌટુંબિક ખુશી માટે અનુકૂળ.');
        return ['score' => $score, 'boy_rashi' => $bs, 'girl_rashi' => $gs,
            'detail' => "$boy: $bs, $girl: $gs. Score: $score/7. $bhakDesc"];
    }

    private function nadi(int $bNak, int $gNak): array {
        $bn = self::NADI[$bNak]; $gn = self::NADI[$gNak];
        $same = ($bn === $gn);
        $score = $same ? 0 : 8;
        $bnn = $this->trList('nadi', $bn); $gnn = $this->trList('nadi', $gn);
        $boy = $this->tr('Boy', 'लड़का', 'છોકરો'); $girl = $this->tr('Girl', 'लड़की', 'છોકરી');
        $nadiDesc = $same
            ? $this->tr('Same Nadi — this is Nadi Dosha, the most significant dosha in matching. It can indicate health issues for children or marital difficulties. Consultation with an astrologer is strongly advised.', 'समान नाड़ी — यह नाड़ी दोष है, जो मिलान में सबसे महत्वपूर्ण दोष है. संतान के स्वास्थ्य या वैवाहिक कठिनाई संभव. ज्योतिषी से परामर्श अवश्य करें.', 'સરખી નાડી — આ નાડી દોષ છે, જે મિલાનનો સૌથી મહત્ત્વનો દોષ છે. સંતાનના સ્વાસ્થ્ય અથવા વૈવાહિક મુશ્કેલી શક્ય. જ્યોતિષીની સલાહ ચોક્કસ લો.')
            : $this->tr('Different Nadi — excellent! No Nadi Dosha. This is the highest-weighted factor.', 'भिन्न नाड़ी — उत्तम! नाड़ी दोष नहीं. यह सबसे अधिक भारांक वाला कारक है.', 'ભિન્ન નાડી — ઉત્તમ! નાડી દોષ નહીં. આ સૌથી વધારે ભારના ગુણ ધરાવતો ઘટક છે.');
        return ['score' => $score, 'boy_nadi' => $bnn, 'girl_nadi' => $gnn,
            'detail' => "$boy: $bnn, $girl: $gnn. Score: $score/8. $nadiDesc"];
    }

    /** Save a milan report to DB. */
    public static function save(int $userId, string $boyName, string $girlName, array $result): int {
        return Db::insert('INSERT INTO milan_reports (user_id,boy_name,girl_name,score,result_json,created_at) VALUES (?,?,?,?,?,NOW())',
            [$userId, $boyName, $girlName, $result['score'], json_encode($result, JSON_UNESCAPED_UNICODE)]);
    }

    public static function list(int $userId): array {
        $rows = Db::all('SELECT id,boy_name,girl_name,result_json,created_at FROM milan_reports WHERE user_id=? ORDER BY created_at DESC', [$userId]);
        foreach ($rows as &$r) {
            $j = json_decode($r['result_json'] ?? '{}', true);
            $r['score'] = $j['score'] ?? 0;
            unset($r['result_json']);
        }
        return $rows;
    }

    /** Regenerate factor details in given language from stored moon data. */
    public function recalcDetails(array $result): array {
        $bSign = $result['boy_moon']['sign_idx']  ?? array_search($result['boy_moon']['rashi'],     Zodiac::SIGNS,      true) ?: 0;
        $gSign = $result['girl_moon']['sign_idx'] ?? array_search($result['girl_moon']['rashi'],    Zodiac::SIGNS,      true) ?: 0;
        $bNak  = $result['boy_moon']['nak_idx']   ?? array_search($result['boy_moon']['nakshatra'], Zodiac::NAKSHATRAS, true) ?: 0;
        $gNak  = $result['girl_moon']['nak_idx']  ?? array_search($result['girl_moon']['nakshatra'],Zodiac::NAKSHATRAS, true) ?: 0;
        $factors = [
            'varna'   => $this->varna($bSign, $gSign),   'vashya' => $this->vashya($bSign, $gSign),
            'tara'    => $this->tara($bNak, $gNak),       'yoni'   => $this->yoni($bNak, $gNak),
            'graha'   => $this->graha($bSign, $gSign),    'gana'   => $this->gana($bNak, $gNak),
            'bhakoot' => $this->bhakoot($bSign, $gSign),  'nadi'   => $this->nadi($bNak, $gNak),
        ];
        foreach ($factors as $k => &$f) {
            $f['name'] = self::FACTORS[$k]['name_' . $this->lang] ?? self::FACTORS[$k]['name'];
            $f['max']  = self::FACTORS[$k]['max'];
        }
        $total = $result['score'];
        $result['factors'] = $factors;
        $result['verdict'] = match(true) {
            $total >= 28 => $this->tr('Excellent match — highly compatible',       'उत्तम मिलान — अत्यंत अनुकूल',                    'ઉત્તમ મિલાન — અત્યંત અનુકૂળ'),
            $total >= 21 => $this->tr('Good match — compatible with minor differences','अच्छा मिलान — मामूली भिन्नता के साथ अनुकूल',  'સારો મિલાન — નાના ભેદ સાથે અનુકૂળ'),
            $total >= 18 => $this->tr('Average match — workable with effort',      'सामान्य मिलान — प्रयास से चलेगा',               'સામાન્ય મિલાન — પ્રયત્ને ચાલે'),
            default      => $this->tr('Below average — significant incompatibilities','औसत से कम — महत्वपूर्ण असंगतियाँ',             'ઓછું — નોંધપાત્ર અસંગતતા'),
        };
        return $result;
    }

    public static function get(int $id, int $userId, string $lang = 'en'): ?array {
        $r = Db::one('SELECT * FROM milan_reports WHERE id=? AND user_id=?', [$id, $userId]);
        if (!$r) return null;
        $r['result'] = json_decode($r['result_json'], true);
        if ($lang !== 'en' && isset($r['result']['factors'])) {
            $r['result'] = (new self($lang))->recalcDetails($r['result']);
        }
        return $r;
    }
}