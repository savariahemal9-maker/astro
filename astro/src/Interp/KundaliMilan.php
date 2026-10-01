<?php
namespace App\Interp;

use App\Calc\Zodiac;
use App\Core\Db;
use App\I18n\Lang;

/**
 * Ashtakoot Kundali Milan (8 koota, 36 gunas) from the Moon of boy and girl.
 * Only the raw Moon data and Mangal status are stored; every read recomputes the
 * result in the requested language, so language switches and rule fixes apply to old reports too.
 */
final class KundaliMilan {
    // Varna by sign: 0 Brahmin, 1 Kshatriya, 2 Vaishya, 3 Shudra (water, fire, earth, air)
    private const VARNA = [1, 2, 3, 0, 1, 2, 3, 0, 1, 2, 3, 0];
    // Vashya groups: 0 Chatushpad, 1 Manav, 2 Jalchar, 3 Vanchar, 4 Keet
    private const VASHYA_PTS = [
        [2, 1, 1, 0.5, 1], [1, 2, 0.5, 0, 1], [1, 0.5, 2, 1, 1], [0.5, 0, 1, 2, 0], [1, 1, 1, 0, 2],
    ];
    // Yoni by nakshatra: 0 Horse 1 Elephant 2 Sheep 3 Serpent 4 Dog 5 Cat 6 Rat 7 Cow 8 Buffalo 9 Tiger 10 Deer 11 Monkey 12 Mongoose 13 Lion
    private const YONI = [0, 1, 2, 3, 3, 4, 5, 2, 5, 6, 6, 7, 8, 9, 8, 9, 10, 10, 4, 11, 12, 11, 13, 0, 13, 7, 1];
    private const YONI_PTS = [
        [4, 2, 2, 3, 2, 2, 2, 1, 0, 1, 3, 3, 2, 1],
        [2, 4, 3, 3, 2, 2, 2, 2, 3, 1, 2, 3, 2, 0],
        [2, 3, 4, 2, 1, 2, 1, 3, 3, 1, 2, 0, 3, 1],
        [3, 3, 2, 4, 2, 1, 1, 1, 1, 2, 2, 2, 0, 2],
        [2, 2, 1, 2, 4, 2, 1, 2, 2, 1, 0, 2, 1, 1],
        [2, 2, 2, 1, 2, 4, 0, 2, 2, 1, 3, 3, 2, 1],
        [2, 2, 1, 1, 1, 0, 4, 2, 2, 2, 2, 2, 1, 2],
        [1, 2, 3, 1, 2, 2, 2, 4, 3, 0, 3, 2, 2, 1],
        [0, 3, 3, 1, 2, 2, 2, 3, 4, 1, 2, 2, 2, 1],
        [1, 1, 1, 2, 1, 1, 2, 0, 1, 4, 1, 1, 2, 1],
        [3, 2, 2, 2, 0, 3, 2, 3, 2, 1, 4, 2, 2, 1],
        [3, 3, 0, 2, 2, 3, 2, 2, 2, 1, 2, 4, 3, 2],
        [2, 2, 3, 0, 1, 2, 1, 2, 2, 2, 2, 3, 4, 2],
        [1, 0, 1, 2, 1, 1, 2, 1, 1, 1, 1, 2, 2, 4],
    ];
    // Gana by nakshatra: 0 Deva, 1 Manushya, 2 Rakshasa; points [boy][girl]
    private const GANA = [0, 1, 2, 1, 0, 1, 0, 0, 2, 2, 1, 1, 0, 2, 0, 2, 0, 2, 2, 1, 1, 0, 2, 2, 1, 1, 0];
    private const GANA_PTS = [[6, 6, 1], [5, 6, 0], [1, 0, 6]];
    // Nadi by nakshatra: 0 Aadi, 1 Madhya, 2 Antya
    private const NADI = [0, 1, 2, 2, 1, 0, 0, 1, 2, 2, 1, 0, 0, 1, 2, 2, 1, 0, 0, 1, 2, 2, 1, 0, 0, 1, 2];
    private const LORD = ['Mars', 'Venus', 'Mercury', 'Moon', 'Sun', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Saturn', 'Jupiter'];
    // Natural friendships (Parashari); anything not listed is neutral
    private const FRIEND = ['Sun' => ['Moon', 'Mars', 'Jupiter'], 'Moon' => ['Sun', 'Mercury'], 'Mars' => ['Sun', 'Moon', 'Jupiter'],
        'Mercury' => ['Sun', 'Venus'], 'Jupiter' => ['Sun', 'Moon', 'Mars'], 'Venus' => ['Mercury', 'Saturn'], 'Saturn' => ['Mercury', 'Venus']];
    private const ENEMY = ['Sun' => ['Venus', 'Saturn'], 'Moon' => [], 'Mars' => ['Mercury'], 'Mercury' => ['Moon'],
        'Jupiter' => ['Mercury', 'Venus'], 'Venus' => ['Sun', 'Moon'], 'Saturn' => ['Sun', 'Moon', 'Mars']];
    private const MAX = ['varna' => 1, 'vashya' => 2, 'tara' => 3, 'yoni' => 4, 'graha' => 5, 'gana' => 6, 'bhakoot' => 7, 'nadi' => 8];

    /** [en, hi, gu] */
    private const L = [
        'varna' => [['Brahmin', 'Kshatriya', 'Vaishya', 'Shudra'], ['ब्राह्मण', 'क्षत्रिय', 'वैश्य', 'शूद्र'], ['બ્રાહ્મણ', 'ક્ષત્રિય', 'વૈશ્ય', 'શૂદ્ર']],
        'vashya' => [['Chatushpad', 'Manav', 'Jalchar', 'Vanchar', 'Keet'], ['चतुष्पद', 'मानव', 'जलचर', 'वनचर', 'कीट'], ['ચતુષ્પદ', 'માનવ', 'જળચર', 'વનચર', 'કીટ']],
        'tara' => [['Janma', 'Sampat', 'Vipat', 'Kshema', 'Pratyari', 'Sadhaka', 'Vadha', 'Mitra', 'Ati-Mitra'],
            ['जन्म', 'सम्पत', 'विपत', 'क्षेम', 'प्रत्यरि', 'साधक', 'वध', 'मित्र', 'अति-मित्र'], ['જન્મ', 'સંપત', 'વિપત', 'ક્ષેમ', 'પ્રત્યરિ', 'સાધક', 'વધ', 'મિત્ર', 'અતિ-મિત્ર']],
        'yoni' => [['Horse', 'Elephant', 'Sheep', 'Serpent', 'Dog', 'Cat', 'Rat', 'Cow', 'Buffalo', 'Tiger', 'Deer', 'Monkey', 'Mongoose', 'Lion'],
            ['अश्व', 'गज', 'मेष', 'सर्प', 'श्वान', 'मार्जार', 'मूषक', 'गौ', 'महिष', 'व्याघ्र', 'मृग', 'वानर', 'नकुल', 'सिंह'],
            ['અશ્વ', 'ગજ', 'મેષ', 'સર્પ', 'શ્વાન', 'માર્જાર', 'મૂષક', 'ગૌ', 'મહિષ', 'વ્યાઘ્ર', 'મૃગ', 'વાનર', 'નકુલ', 'સિંહ']],
        'gana' => [['Deva', 'Manushya', 'Rakshasa'], ['देव', 'मनुष्य', 'राक्षस'], ['દેવ', 'મનુષ્ય', 'રાક્ષસ']],
        'nadi' => [['Aadi', 'Madhya', 'Antya'], ['आदि', 'मध्य', 'अन्त्य'], ['આદિ', 'મધ્ય', 'અંત્ય']],
        'name' => [['varna' => 'Varna', 'vashya' => 'Vashya', 'tara' => 'Tara', 'yoni' => 'Yoni', 'graha' => 'Graha Maitri', 'gana' => 'Gana', 'bhakoot' => 'Bhakoot', 'nadi' => 'Nadi'],
            ['varna' => 'वर्ण', 'vashya' => 'वश्य', 'tara' => 'तारा', 'yoni' => 'योनि', 'graha' => 'ग्रह मैत्री', 'gana' => 'गण', 'bhakoot' => 'भकूट', 'nadi' => 'नाड़ी'],
            ['varna' => 'વર્ણ', 'vashya' => 'વશ્ય', 'tara' => 'તારા', 'yoni' => 'યોનિ', 'graha' => 'ગ્રહ મૈત્રી', 'gana' => 'ગણ', 'bhakoot' => 'ભકૂટ', 'nadi' => 'નાડી']],
        'area' => [['varna' => 'Work and ego', 'vashya' => 'Mutual attraction', 'tara' => 'Destiny and well-being', 'yoni' => 'Physical intimacy', 'graha' => 'Mental match and friendship', 'gana' => 'Temperament', 'bhakoot' => 'Love, family and prosperity', 'nadi' => 'Health and children'],
            ['varna' => 'कार्य और अहं', 'vashya' => 'आपसी आकर्षण', 'tara' => 'भाग्य और कुशलता', 'yoni' => 'शारीरिक अनुकूलता', 'graha' => 'मानसिक मेल और मित्रता', 'gana' => 'स्वभाव', 'bhakoot' => 'प्रेम, परिवार और समृद्धि', 'nadi' => 'स्वास्थ्य और संतान'],
            ['varna' => 'કાર્ય અને અહં', 'vashya' => 'પરસ્પર આકર્ષણ', 'tara' => 'ભાગ્ય અને કુશળતા', 'yoni' => 'શારીરિક અનુકૂળતા', 'graha' => 'માનસિક મેળ અને મિત્રતા', 'gana' => 'સ્વભાવ', 'bhakoot' => 'પ્રેમ, પરિવાર અને સમૃદ્ધિ', 'nadi' => 'સ્વાસ્થ્ય અને સંતાન']],
        // about each koota, then result text for full / partial / zero points
        'about' => [
            ['varna' => 'Varna compares the spiritual temperament and working nature of the two Moon signs. The boy\'s varna should be the same as or higher than the girl\'s.',
             'vashya' => 'Vashya shows how naturally the two people are drawn to and influence each other.',
             'tara' => 'Tara counts the nakshatras between the two birth stars in both directions and shows destiny, health and well-being after marriage.',
             'yoni' => 'Yoni compares the animal nature of the two birth stars and shows physical and intimate harmony.',
             'graha' => 'Graha Maitri compares the lords of the two Moon signs. It is the main test of mental compatibility, understanding and friendship.',
             'gana' => 'Gana compares temperament: Deva (gentle), Manushya (balanced) and Rakshasa (strong-willed).',
             'bhakoot' => 'Bhakoot compares the positions of the two Moon signs from each other and governs love, family welfare and finances.',
             'nadi' => 'Nadi carries the highest weight. It relates to health, physiology and children; the same Nadi for both is Nadi Dosha.'],
            ['varna' => 'वर्ण दोनों चंद्र राशियों के आध्यात्मिक स्वभाव और कार्य-प्रकृति की तुलना करता है। वर का वर्ण कन्या के समान या उससे उच्च होना चाहिए।',
             'vashya' => 'वश्य बताता है कि दोनों एक-दूसरे की ओर कितनी सहजता से आकर्षित होते हैं और एक-दूसरे को प्रभावित करते हैं।',
             'tara' => 'तारा दोनों जन्म नक्षत्रों के बीच दोनों दिशाओं में गिनती करता है और विवाह के बाद भाग्य, स्वास्थ्य और कुशलता बताता है।',
             'yoni' => 'योनि दोनों जन्म नक्षत्रों के पशु-स्वभाव की तुलना करती है और शारीरिक व दांपत्य सामंजस्य बताती है।',
             'graha' => 'ग्रह मैत्री दोनों चंद्र राशियों के स्वामियों की तुलना करती है। यह मानसिक अनुकूलता, समझ और मित्रता की मुख्य कसौटी है।',
             'gana' => 'गण स्वभाव की तुलना करता है: देव (सौम्य), मनुष्य (संतुलित) और राक्षस (दृढ़ इच्छाशक्ति)।',
             'bhakoot' => 'भकूट दोनों चंद्र राशियों की एक-दूसरे से स्थिति देखता है और प्रेम, पारिवारिक सुख व धन से जुड़ा है।',
             'nadi' => 'नाड़ी को सबसे अधिक अंक मिलते हैं। यह स्वास्थ्य, शरीर-प्रकृति और संतान से जुड़ी है; दोनों की एक ही नाड़ी होना नाड़ी दोष है।'],
            ['varna' => 'વર્ણ બંને ચંદ્ર રાશિના આધ્યાત્મિક સ્વભાવ અને કાર્ય-પ્રકૃતિની તુલના કરે છે. વરનો વર્ણ કન્યાના વર્ણ જેટલો અથવા તેનાથી ઊંચો હોવો જોઈએ.',
             'vashya' => 'વશ્ય બતાવે છે કે બંને એકબીજા તરફ કેટલી સહજતાથી આકર્ષાય છે અને એકબીજાને પ્રભાવિત કરે છે.',
             'tara' => 'તારા બંને જન્મ નક્ષત્ર વચ્ચે બંને દિશામાં ગણતરી કરે છે અને લગ્ન પછી ભાગ્ય, સ્વાસ્થ્ય અને કુશળતા બતાવે છે.',
             'yoni' => 'યોનિ બંને જન્મ નક્ષત્રના પશુ-સ્વભાવની તુલના કરે છે અને શારીરિક તથા દાંપત્ય સુમેળ બતાવે છે.',
             'graha' => 'ગ્રહ મૈત્રી બંને ચંદ્ર રાશિના સ્વામીઓની તુલના કરે છે. આ માનસિક અનુકૂળતા, સમજ અને મિત્રતાની મુખ્ય કસોટી છે.',
             'gana' => 'ગણ સ્વભાવની તુલના કરે છે: દેવ (સૌમ્ય), મનુષ્ય (સંતુલિત) અને રાક્ષસ (દૃઢ ઇચ્છાશક્તિ).',
             'bhakoot' => 'ભકૂટ બંને ચંદ્ર રાશિની એકબીજાથી સ્થિતિ જુએ છે અને પ્રેમ, પારિવારિક સુખ તથા ધન સાથે જોડાયેલ છે.',
             'nadi' => 'નાડીને સૌથી વધુ ગુણ મળે છે. તે સ્વાસ્થ્ય, શરીર-પ્રકૃતિ અને સંતાન સાથે જોડાયેલ છે; બંનેની એક જ નાડી હોવી એ નાડી દોષ છે.'],
        ],
        'res' => [
            ['full' => 'Full points: this area is well matched.', 'part' => 'Partial points: workable, with some differences to understand.', 'zero' => 'No points: this area needs care, understanding and remedies.'],
            ['full' => 'पूर्ण अंक: यह क्षेत्र अच्छी तरह मेल खाता है।', 'part' => 'आंशिक अंक: चल सकता है, कुछ भिन्नताओं को समझना होगा।', 'zero' => 'शून्य अंक: इस क्षेत्र में सावधानी, समझ और उपाय की आवश्यकता है।'],
            ['full' => 'પૂર્ણ ગુણ: આ ક્ષેત્ર સારી રીતે મેળ ખાય છે.', 'part' => 'આંશિક ગુણ: ચાલી શકે, થોડા ભેદ સમજવા પડશે.', 'zero' => 'શૂન્ય ગુણ: આ ક્ષેત્રમાં કાળજી, સમજ અને ઉપાયની જરૂર છે.'],
        ],
        'verdict' => [
            ['ex' => 'Excellent match', 'vg' => 'Very good match', 'ok' => 'Acceptable match', 'low' => 'Not recommended'],
            ['ex' => 'उत्तम मिलान', 'vg' => 'बहुत अच्छा मिलान', 'ok' => 'स्वीकार्य मिलान', 'low' => 'अनुशंसित नहीं'],
            ['ex' => 'ઉત્તમ મિલાન', 'vg' => 'ખૂબ સારો મિલાન', 'ok' => 'સ્વીકાર્ય મિલાન', 'low' => 'ભલામણ નથી'],
        ],
        'summary' => [
            ['ex' => '{s} of 36 gunas match. This is an excellent match; the couple is likely to share understanding, affection and family happiness.',
             'vg' => '{s} of 36 gunas match. This is a very good match with strong overall compatibility.',
             'ok' => '{s} of 36 gunas match. Traditionally 18 or more gunas are acceptable for marriage; the weaker areas below deserve attention.',
             'low' => '{s} of 36 gunas match. Traditionally at least 18 gunas are needed, so this match is not recommended without a detailed study of both full charts.'],
            ['ex' => '36 में से {s} गुण मिलते हैं। यह उत्तम मिलान है; दंपत्ति में समझ, स्नेह और पारिवारिक सुख की अच्छी संभावना है।',
             'vg' => '36 में से {s} गुण मिलते हैं। यह बहुत अच्छा मिलान है और कुल अनुकूलता मज़बूत है।',
             'ok' => '36 में से {s} गुण मिलते हैं। परंपरा के अनुसार विवाह के लिए 18 या अधिक गुण स्वीकार्य हैं; नीचे दिए कमज़ोर क्षेत्रों पर ध्यान दें।',
             'low' => '36 में से {s} गुण मिलते हैं। परंपरा के अनुसार कम से कम 18 गुण आवश्यक हैं, इसलिए दोनों पूर्ण कुंडलियों के विस्तृत अध्ययन के बिना यह मिलान अनुशंसित नहीं है।'],
            ['ex' => '36 માંથી {s} ગુણ મળે છે. આ ઉત્તમ મિલાન છે; દંપતીમાં સમજ, સ્નેહ અને પારિવારિક સુખની સારી સંભાવના છે.',
             'vg' => '36 માંથી {s} ગુણ મળે છે. આ ખૂબ સારો મિલાન છે અને કુલ અનુકૂળતા મજબૂત છે.',
             'ok' => '36 માંથી {s} ગુણ મળે છે. પરંપરા મુજબ લગ્ન માટે 18 કે વધુ ગુણ સ્વીકાર્ય છે; નીચે આપેલા નબળા ક્ષેત્રો પર ધ્યાન આપો.',
             'low' => '36 માંથી {s} ગુણ મળે છે. પરંપરા મુજબ ઓછામાં ઓછા 18 ગુણ જરૂરી છે, તેથી બંનેની પૂર્ણ કુંડળીના વિગતવાર અભ્યાસ વિના આ મિલાન ભલામણપાત્ર નથી.'],
        ],
        'dosha' => [
            ['nadi' => 'Nadi Dosha', 'bhakoot' => 'Bhakoot Dosha', 'mangal' => 'Mangal Dosha', 'present' => 'Present', 'absent' => 'Not present', 'cancelled' => 'Cancelled',
             'nadi_yes' => 'Both have {n} Nadi. Nadi Dosha is traditionally the most serious dosha in matching and is linked to health and children.',
             'nadi_no' => 'The two Nadis are different, so there is no Nadi Dosha.',
             'nadi_cancel' => 'It is cancelled here because {why}.',
             'nadi_why_rashi' => 'both share the same Moon sign but have different nakshatras',
             'nadi_why_nak' => 'both share the same nakshatra but have different Moon signs',
             'nadi_why_pada' => 'both share the same nakshatra but in different padas',
             'bhakoot_yes' => 'The Moon signs are in a {t} relation from each other, which forms Bhakoot Dosha.',
             'bhakoot_no' => 'The Moon signs are in a favourable relation, so there is no Bhakoot Dosha.',
             'bhakoot_cancel' => 'It is cancelled here because the two Moon-sign lords are {why}.',
             'same_lord' => 'the same planet', 'friends' => 'friends',
             'mangal_both' => 'Both are Manglik, so the Mangal Dosha of one balances the other.',
             'mangal_none' => 'Neither is Manglik.',
             'mangal_one' => '{who} is Manglik and the other is not. Traditionally this needs remedies or a Manglik partner; the full charts should be checked for cancellation.',
             'manglik' => 'Manglik (Mars in house {h})', 'not_manglik' => 'Not Manglik'],
            ['nadi' => 'नाड़ी दोष', 'bhakoot' => 'भकूट दोष', 'mangal' => 'मंगल दोष', 'present' => 'उपस्थित', 'absent' => 'नहीं है', 'cancelled' => 'निरस्त',
             'nadi_yes' => 'दोनों की {n} नाड़ी है। परंपरा में नाड़ी दोष मिलान का सबसे गंभीर दोष माना जाता है और यह स्वास्थ्य व संतान से जुड़ा है।',
             'nadi_no' => 'दोनों की नाड़ी अलग है, इसलिए नाड़ी दोष नहीं है।',
             'nadi_cancel' => 'यहाँ यह निरस्त है क्योंकि {why}।',
             'nadi_why_rashi' => 'दोनों की चंद्र राशि एक है पर नक्षत्र अलग हैं',
             'nadi_why_nak' => 'दोनों का नक्षत्र एक है पर चंद्र राशि अलग है',
             'nadi_why_pada' => 'दोनों का नक्षत्र एक है पर चरण अलग हैं',
             'bhakoot_yes' => 'दोनों चंद्र राशियाँ एक-दूसरे से {t} स्थिति में हैं, जिससे भकूट दोष बनता है।',
             'bhakoot_no' => 'दोनों चंद्र राशियाँ अनुकूल स्थिति में हैं, इसलिए भकूट दोष नहीं है।',
             'bhakoot_cancel' => 'यहाँ यह निरस्त है क्योंकि दोनों राशि स्वामी {why} हैं।',
             'same_lord' => 'एक ही ग्रह', 'friends' => 'मित्र',
             'mangal_both' => 'दोनों मांगलिक हैं, इसलिए एक का मंगल दोष दूसरे से संतुलित हो जाता है।',
             'mangal_none' => 'दोनों में से कोई मांगलिक नहीं है।',
             'mangal_one' => '{who} मांगलिक हैं और दूसरे नहीं। परंपरा में इसके लिए उपाय या मांगलिक साथी आवश्यक है; निरस्ती के लिए पूर्ण कुंडलियाँ देखें।',
             'manglik' => 'मांगलिक (मंगल भाव {h} में)', 'not_manglik' => 'मांगलिक नहीं'],
            ['nadi' => 'નાડી દોષ', 'bhakoot' => 'ભકૂટ દોષ', 'mangal' => 'મંગળ દોષ', 'present' => 'હાજર', 'absent' => 'નથી', 'cancelled' => 'નિરસ્ત',
             'nadi_yes' => 'બંનેની {n} નાડી છે. પરંપરામાં નાડી દોષ મિલાનનો સૌથી ગંભીર દોષ ગણાય છે અને તે સ્વાસ્થ્ય તથા સંતાન સાથે જોડાયેલ છે.',
             'nadi_no' => 'બંનેની નાડી અલગ છે, તેથી નાડી દોષ નથી.',
             'nadi_cancel' => 'અહીં તે નિરસ્ત છે કારણ કે {why}.',
             'nadi_why_rashi' => 'બંનેની ચંદ્ર રાશિ એક છે પણ નક્ષત્ર અલગ છે',
             'nadi_why_nak' => 'બંનેનું નક્ષત્ર એક છે પણ ચંદ્ર રાશિ અલગ છે',
             'nadi_why_pada' => 'બંનેનું નક્ષત્ર એક છે પણ ચરણ અલગ છે',
             'bhakoot_yes' => 'બંને ચંદ્ર રાશિ એકબીજાથી {t} સ્થિતિમાં છે, જેનાથી ભકૂટ દોષ બને છે.',
             'bhakoot_no' => 'બંને ચંદ્ર રાશિ અનુકૂળ સ્થિતિમાં છે, તેથી ભકૂટ દોષ નથી.',
             'bhakoot_cancel' => 'અહીં તે નિરસ્ત છે કારણ કે બંને રાશિ સ્વામી {why} છે.',
             'same_lord' => 'એક જ ગ્રહ', 'friends' => 'મિત્ર',
             'mangal_both' => 'બંને માંગલિક છે, તેથી એકનો મંગળ દોષ બીજાથી સંતુલિત થાય છે.',
             'mangal_none' => 'બેમાંથી કોઈ માંગલિક નથી.',
             'mangal_one' => '{who} માંગલિક છે અને બીજા નથી. પરંપરામાં આ માટે ઉપાય અથવા માંગલિક સાથી જરૂરી છે; નિરસ્તી માટે પૂર્ણ કુંડળી જુઓ.',
             'manglik' => 'માંગલિક (મંગળ ભાવ {h} માં)', 'not_manglik' => 'માંગલિક નથી'],
        ],
        'bhakoot_type' => [[2 => '2/12 (Dwi-Dwadash)', 5 => '5/9 (Nav-Pancham)', 6 => '6/8 (Shadashtak)'],
            [2 => '2/12 (द्वि-द्वादश)', 5 => '5/9 (नव-पंचम)', 6 => '6/8 (षडाष्टक)'], [2 => '2/12 (દ્વિ-દ્વાદશ)', 5 => '5/9 (નવ-પંચમ)', 6 => '6/8 (ષડાષ્ટક)']],
    ];

    private int $li;
    public function __construct(private string $lang = 'en') { $this->li = ['en' => 0, 'hi' => 1, 'gu' => 2][$lang] ?? 0; $this->lang = ['en', 'hi', 'gu'][$this->li]; }

    private function L(string $k) { return self::L[$k][$this->li]; }
    private function sign(int $i): string { return Lang::t($this->lang, 'astro.signs.' . Zodiac::SIGNS[$i]); }
    private function nak(int $i): string { return Lang::t($this->lang, 'astro.nakshatras.' . Zodiac::NAKSHATRAS[$i]); }
    private function planet(string $p): string { return Lang::t($this->lang, 'astro.planets.' . $p); }

    /** Raw Moon data + Mangal status from a computed kundali (what we store). */
    public static function raw(array $k): array {
        $m = ['sign' => 0, 'nak' => 0, 'pada' => 1, 'deg' => 0.0];
        foreach ($k['planets'] as $p) if ($p['name'] === 'Moon') {
            $n = Zodiac::nakshatra((float) $p['lon']);
            $m = ['sign' => (int) $p['sign'], 'nak' => $n['index'], 'pada' => $n['pada'], 'deg' => round(fmod((float) $p['lon'], 30.0), 2)];
        }
        $md = $k['analysis']['mangal_dosha'] ?? null; $house = 0; $present = false;
        if ($md) { $present = (bool) $md['present'];
            foreach (['lagna', 'moon'] as $f) if (!empty($md['from'][$f]['present'])) { $house = (int) $md['from'][$f]['house']; break; } }
        else foreach ($k['planets'] as $p) if ($p['name'] === 'Mars') { $house = (int) $p['house']; $present = in_array($house, [1, 2, 4, 7, 8, 12], true); }
        return $m + ['mangal' => $present, 'mars_house' => $house];
    }

    /** Raw data from a stored row (also understands reports saved by the first version). */
    private static function rawFromStored(array $j): array {
        if (($j['v'] ?? 0) >= 2) return [$j['boy'], $j['girl']];
        $f = fn($m, $mg) => ['sign' => (int) ($m['sign_idx'] ?? 0), 'nak' => (int) ($m['nak_idx'] ?? 0), 'pada' => (int) ($m['pada'] ?? 1), 'deg' => 0.0,
            'mangal' => !empty($mg['present']), 'mars_house' => (int) ($mg['house'] ?? 0)];
        return [$f($j['boy_moon'] ?? [], $j['mangal']['boy'] ?? []), $f($j['girl_moon'] ?? [], $j['mangal']['girl'] ?? [])];
    }

    private static function vashyaGroup(int $sign, float $deg): int {
        return match ($sign) { 0, 1 => 0, 2, 5, 6, 10 => 1, 3, 11 => 2, 4 => 3, 7 => 4, 8 => $deg < 15 ? 1 : 0, 9 => $deg < 15 ? 0 : 2, default => 1 };
    }
    private static function rel(string $a, string $b): int { // 2 friend, 1 neutral, 0 enemy (as seen by $a)
        return $a === $b || in_array($b, self::FRIEND[$a], true) ? 2 : (in_array($b, self::ENEMY[$a], true) ? 0 : 1);
    }
    private static function taraIdx(int $from, int $to): int { return (($to - $from + 27) % 27) % 9; } // 0 Janma … 8 Ati-Mitra

    /** Points for each koota (language independent). */
    public static function points(array $b, array $g): array {
        $bv = self::VARNA[$b['sign']]; $gv = self::VARNA[$g['sign']];
        $bw = self::vashyaGroup($b['sign'], (float) $b['deg']); $gw = self::vashyaGroup($g['sign'], (float) $g['deg']);
        $bt = self::taraIdx($g['nak'], $b['nak']); $gt = self::taraIdx($b['nak'], $g['nak']);
        $bad = [2, 4, 6];
        $bl = self::LORD[$b['sign']]; $gl = self::LORD[$g['sign']];
        $r = self::rel($bl, $gl) + self::rel($gl, $bl);
        $graha = $bl === $gl ? 5 : [0 => 0, 1 => 0.5, 2 => 3, 3 => 4, 4 => 5][$r];
        if ($r === 2 && self::rel($bl, $gl) !== 1) $graha = 1; // friend + enemy
        $pos = (($g['sign'] - $b['sign'] + 12) % 12) + 1; $bk = in_array($pos, [2, 12, 5, 9, 6, 8], true);
        return [
            'varna' => $bv <= $gv ? 1 : 0,
            'vashya' => self::VASHYA_PTS[$bw][$gw],
            'tara' => (in_array($bt, $bad, true) ? 0 : 1.5) + (in_array($gt, $bad, true) ? 0 : 1.5),
            'yoni' => self::YONI_PTS[self::YONI[$b['nak']]][self::YONI[$g['nak']]],
            'graha' => $graha,
            'gana' => self::GANA_PTS[self::GANA[$b['nak']]][self::GANA[$g['nak']]],
            'bhakoot' => $bk ? 0 : 7,
            'nadi' => self::NADI[$b['nak']] === self::NADI[$g['nak']] ? 0 : 8,
            '_m' => compact('bv', 'gv', 'bw', 'gw', 'bt', 'gt', 'bl', 'gl', 'pos'),
        ];
    }

    /** Full, language-specific report from raw data. */
    public function report(array $b, array $g, string $boyName, string $girlName): array {
        $p = self::points($b, $g); $m = $p['_m']; unset($p['_m']);
        $total = array_sum($p);
        $names = $this->L('name'); $areas = $this->L('area'); $about = $this->L('about'); $res = $this->L('res');
        $val = [
            'varna' => [$this->L('varna')[$m['bv']], $this->L('varna')[$m['gv']]],
            'vashya' => [$this->L('vashya')[$m['bw']], $this->L('vashya')[$m['gw']]],
            'tara' => [$this->L('tara')[$m['bt']], $this->L('tara')[$m['gt']]],
            'yoni' => [$this->L('yoni')[self::YONI[$b['nak']]], $this->L('yoni')[self::YONI[$g['nak']]]],
            'graha' => [$this->planet($m['bl']), $this->planet($m['gl'])],
            'gana' => [$this->L('gana')[self::GANA[$b['nak']]], $this->L('gana')[self::GANA[$g['nak']]]],
            'bhakoot' => [$this->sign($b['sign']), $this->sign($g['sign'])],
            'nadi' => [$this->L('nadi')[self::NADI[$b['nak']]], $this->L('nadi')[self::NADI[$g['nak']]]],
        ];
        $kootas = [];
        foreach (self::MAX as $k => $max) {
            $s = $p[$k];
            $kootas[] = ['key' => $k, 'name' => $names[$k], 'area' => $areas[$k], 'max' => $max, 'score' => $s,
                'boy' => $val[$k][0], 'girl' => $val[$k][1], 'about' => $about[$k], 'result' => $res[$s >= $max ? 'full' : ($s > 0 ? 'part' : 'zero')]];
        }
        $band = $total >= 33 ? 'ex' : ($total >= 25 ? 'vg' : ($total >= 18 ? 'ok' : 'low'));
        $D = $this->L('dosha');
        // Nadi dosha + cancellation
        $nadiSame = $p['nadi'] === 0; $why = null;
        if ($nadiSame) {
            if ($b['sign'] === $g['sign'] && $b['nak'] !== $g['nak']) $why = 'nadi_why_rashi';
            elseif ($b['nak'] === $g['nak'] && $b['sign'] !== $g['sign']) $why = 'nadi_why_nak';
            elseif ($b['nak'] === $g['nak'] && $b['pada'] !== $g['pada']) $why = 'nadi_why_pada';
        }
        $nadi = ['name' => $D['nadi'], 'state' => !$nadiSame ? 'absent' : ($why ? 'cancelled' : 'present'),
            'text' => $nadiSame ? str_replace('{n}', $val['nadi'][0], $D['nadi_yes']) . ($why ? ' ' . str_replace('{why}', $D[$why], $D['nadi_cancel']) : '') : $D['nadi_no']];
        // Bhakoot dosha + cancellation (same lord or mutual friends)
        $bk = $p['bhakoot'] === 0; $pos = min($m['pos'], 14 - $m['pos']);
        $bkWhy = !$bk ? null : ($m['bl'] === $m['gl'] ? 'same_lord' : (self::rel($m['bl'], $m['gl']) === 2 && self::rel($m['gl'], $m['bl']) === 2 ? 'friends' : null));
        $bhakoot = ['name' => $D['bhakoot'], 'state' => !$bk ? 'absent' : ($bkWhy ? 'cancelled' : 'present'),
            'text' => $bk ? str_replace('{t}', $this->L('bhakoot_type')[$pos] ?? '', $D['bhakoot_yes']) . ($bkWhy ? ' ' . str_replace('{why}', $D[$bkWhy], $D['bhakoot_cancel']) : '') : $D['bhakoot_no']];
        // Mangal
        $mg = fn($x) => $x['mangal'] ? str_replace('{h}', (string) $x['mars_house'], $D['manglik']) : $D['not_manglik'];
        $mText = $b['mangal'] && $g['mangal'] ? $D['mangal_both'] : (!$b['mangal'] && !$g['mangal'] ? $D['mangal_none']
            : str_replace('{who}', $b['mangal'] ? $boyName : $girlName, $D['mangal_one']));
        $mangal = ['name' => $D['mangal'], 'state' => $b['mangal'] !== $g['mangal'] ? 'present' : ($b['mangal'] ? 'cancelled' : 'absent'),
            'text' => $mText, 'boy' => $mg($b), 'girl' => $mg($g)];
        $doshas = array_map(fn($d) => $d + ['label' => $D[$d['state']]], [$nadi, $bhakoot, $mangal]);
        $person = fn($x) => ['rashi' => $this->sign($x['sign']), 'lord' => $this->planet(self::LORD[$x['sign']]), 'nakshatra' => $this->nak($x['nak']), 'pada' => $x['pada'],
            'varna' => $this->L('varna')[self::VARNA[$x['sign']]], 'vashya' => $this->L('vashya')[self::vashyaGroup($x['sign'], (float) $x['deg'])],
            'yoni' => $this->L('yoni')[self::YONI[$x['nak']]], 'gana' => $this->L('gana')[self::GANA[$x['nak']]], 'nadi' => $this->L('nadi')[self::NADI[$x['nak']]], 'mangal' => $mg($x)];
        return ['score' => $total, 'max' => 36, 'percent' => (int) round($total / 36 * 100), 'band' => $band,
            'verdict' => $this->L('verdict')[$band], 'summary' => str_replace('{s}', (string) $total, $this->L('summary')[$band]),
            'boy' => $person($b), 'girl' => $person($g), 'kootas' => $kootas, 'doshas' => $doshas];
    }

    // ---------- storage ----------
    private static bool $ready = false;
    public static function ensure(): void {
        if (self::$ready) return;
        Db::exec("CREATE TABLE IF NOT EXISTS milan_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, boy_name VARCHAR(120) NOT NULL, girl_name VARCHAR(120) NOT NULL,
            score DECIMAL(4,1) NOT NULL DEFAULT 0, result_json MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    public static function save(int $userId, string $boyName, string $girlName, array $boy, array $girl): int {
        self::ensure();
        $score = array_sum(array_diff_key(self::points($boy, $girl), ['_m' => 1]));
        return Db::insert('INSERT INTO milan_reports (user_id,boy_name,girl_name,score,result_json,created_at) VALUES (?,?,?,?,?,NOW())',
            [$userId, mb_substr($boyName, 0, 120), mb_substr($girlName, 0, 120), $score, json_encode(['v' => 2, 'boy' => $boy, 'girl' => $girl])]);
    }

    public static function list(int $userId): array {
        self::ensure();
        $rows = Db::all('SELECT id,boy_name,girl_name,result_json,created_at FROM milan_reports WHERE user_id=? ORDER BY created_at DESC', [$userId]);
        foreach ($rows as &$r) {
            [$b, $g] = self::rawFromStored(json_decode($r['result_json'] ?: '{}', true) ?: []);
            $r['score'] = array_sum(array_diff_key(self::points($b, $g), ['_m' => 1]));
            unset($r['result_json']);
        }
        return $rows;
    }

    public static function get(int $id, int $userId, string $lang = 'en'): ?array {
        self::ensure();
        $r = Db::one('SELECT id,boy_name,girl_name,created_at,result_json FROM milan_reports WHERE id=? AND user_id=?', [$id, $userId]);
        if (!$r) return null;
        [$b, $g] = self::rawFromStored(json_decode($r['result_json'] ?: '{}', true) ?: []);
        unset($r['result_json']);
        return $r + (new self($lang))->report($b, $g, $r['boy_name'], $r['girl_name']);
    }

    public static function delete(int $id, int $userId): void {
        self::ensure();
        Db::exec('DELETE FROM milan_reports WHERE id=? AND user_id=?', [$id, $userId]);
    }
}
