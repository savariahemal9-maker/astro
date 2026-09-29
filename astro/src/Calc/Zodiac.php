<?php
namespace App\Calc;

/** Fixed reference tables. Names are English keys; translations live in lang/*.php */
final class Zodiac {
    public const SIGNS = ['Aries','Taurus','Gemini','Cancer','Leo','Virgo','Libra','Scorpio','Sagittarius','Capricorn','Aquarius','Pisces'];
    public const SIGN_LORDS = ['Mars','Venus','Mercury','Moon','Sun','Mercury','Venus','Mars','Jupiter','Saturn','Saturn','Jupiter'];
    public const NAKSHATRAS = ['Ashwini','Bharani','Krittika','Rohini','Mrigashira','Ardra','Punarvasu','Pushya','Ashlesha',
        'Magha','Purva Phalguni','Uttara Phalguni','Hasta','Chitra','Swati','Vishakha','Anuradha','Jyeshtha',
        'Mula','Purva Ashadha','Uttara Ashadha','Shravana','Dhanishta','Shatabhisha','Purva Bhadrapada','Uttara Bhadrapada','Revati'];
    public const DASHA_ORDER = ['Ketu','Venus','Sun','Moon','Mars','Rahu','Jupiter','Saturn','Mercury'];
    public const DASHA_YEARS = ['Ketu'=>7,'Venus'=>20,'Sun'=>6,'Moon'=>10,'Mars'=>7,'Rahu'=>18,'Jupiter'=>16,'Saturn'=>19,'Mercury'=>17];
    public const TITHIS = ['Pratipada','Dwitiya','Tritiya','Chaturthi','Panchami','Shashthi','Saptami','Ashtami','Navami',
        'Dashami','Ekadashi','Dwadashi','Trayodashi','Chaturdashi','Purnima'];
    public const YOGAS = ['Vishkambha','Priti','Ayushman','Saubhagya','Shobhana','Atiganda','Sukarma','Dhriti','Shula','Ganda',
        'Vriddhi','Dhruva','Vyaghata','Harshana','Vajra','Siddhi','Vyatipata','Variyana','Parigha','Shiva','Siddha','Sadhya',
        'Shubha','Shukla','Brahma','Indra','Vaidhriti'];
    public const KARANAS_MOVABLE = ['Bava','Balava','Kaulava','Taitila','Garaja','Vanija','Vishti'];
    public const WEEKDAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    public const WEEKDAY_LORDS = ['Sun','Moon','Mars','Mercury','Jupiter','Venus','Saturn'];
    public const EXALTATION = ['Sun'=>0,'Moon'=>1,'Mars'=>9,'Mercury'=>5,'Jupiter'=>3,'Venus'=>11,'Saturn'=>6];
    public const OWN_SIGNS = ['Sun'=>[4],'Moon'=>[3],'Mars'=>[0,7],'Mercury'=>[2,5],'Jupiter'=>[8,11],'Venus'=>[1,6],'Saturn'=>[9,10]];
    public const NAK_SPAN = 360 / 27;

    public static function norm(float $d): float { $d = fmod($d, 360.0); return $d < 0 ? $d + 360.0 : $d; }
    public static function signOf(float $lon): int { return min(11, (int) floor(self::norm($lon) / 30)); }

    public static function nakshatra(float $lon): array {
        $lon = self::norm($lon);
        $i = min(26, (int) floor($lon / self::NAK_SPAN));
        $within = $lon - $i * self::NAK_SPAN;
        return ['index' => $i, 'name' => self::NAKSHATRAS[$i], 'pada' => min(4, (int) floor($within / (self::NAK_SPAN / 4)) + 1),
                'lord' => self::DASHA_ORDER[$i % 9], 'fraction' => $within / self::NAK_SPAN];
    }

    public static function karanaName(int $i): string {
        if ($i === 0) return 'Kimstughna';
        if ($i >= 57) return ['Shakuni', 'Chatushpada', 'Naga'][$i - 57];
        return self::KARANAS_MOVABLE[($i - 1) % 7];
    }

    public static function tithiName(int $i): array {
        return ['index' => $i, 'paksha' => $i < 15 ? 'Shukla' : 'Krishna',
                'name' => $i === 29 ? 'Amavasya' : self::TITHIS[$i % 15]];
    }

    /** Traditional dignity by sign only. Null for Rahu/Ketu (schools differ). */
    public static function dignity(string $planet, int $sign): ?string {
        if (!isset(self::EXALTATION[$planet])) return null;
        if (self::EXALTATION[$planet] === $sign) return 'exalted';
        if ((self::EXALTATION[$planet] + 6) % 12 === $sign) return 'debilitated';
        if (in_array($sign, self::OWN_SIGNS[$planet], true)) return 'own';
        return null;
    }

    public static function dms(float $deg): string {
        $d = floor($deg); $mf = ($deg - $d) * 60; $m = floor($mf); $s = floor(($mf - $m) * 60);
        return sprintf("%d°%02d'%02d\"", $d, $m, $s);
    }
}
