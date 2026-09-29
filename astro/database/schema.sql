-- MySQL 8 / MariaDB 10.5+, utf8mb4
SET NAMES utf8mb4;

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  lang          ENUM('en','hi','gu') NOT NULL DEFAULT 'en',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,          -- sha256 of bearer token; raw token never stored
  client      VARCHAR(40) NOT NULL DEFAULT 'web', -- web | android | ios
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Birth details as entered AND as resolved. The resolved UT is what calculations use.
CREATE TABLE birth_profiles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  label           VARCHAR(120) NOT NULL,
  gender          ENUM('male','female','other') NULL,
  birth_date      DATE NOT NULL,
  birth_time      TIME NOT NULL,
  time_accuracy   ENUM('exact','approximate') NOT NULL DEFAULT 'exact',
  place_name      VARCHAR(255) NOT NULL,
  lat             DECIMAL(9,6) NOT NULL,
  lon             DECIMAL(9,6) NOT NULL,
  tzid            VARCHAR(64) NOT NULL,
  offset_minutes  SMALLINT NOT NULL,
  offset_source   ENUM('tzdb','manual') NOT NULL,
  dst_fold        ENUM('earlier','later') NULL,
  utc_datetime    DATETIME NOT NULL,
  jd_ut           DECIMAL(16,8) NOT NULL,
  confirmed_at    DATETIME NULL,                  -- user confirmed the resolved UTC/offset
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calculated data only. Keyed by settings + engine version so a settings change never serves stale charts.
CREATE TABLE kundalis (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id      INT UNSIGNED NOT NULL,
  settings_hash   CHAR(64) NOT NULL,
  engine_version  VARCHAR(40) NOT NULL,
  calc_json       LONGTEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_calc (profile_id, settings_hash, engine_version),
  FOREIGN KEY (profile_id) REFERENCES birth_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Interpretations (reports/remedies) are stored separately and always reference the kundali they came from.
CREATE TABLE interpretations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kundali_id      INT UNSIGNED NOT NULL,
  kind            VARCHAR(60) NOT NULL,
  period_key      VARCHAR(20) NOT NULL DEFAULT '',  -- e.g. 2026-09-27, 2026-W39, 2026-09
  lang            ENUM('en','hi','gu') NOT NULL,
  ruleset_version VARCHAR(20) NOT NULL,
  payload_json    LONGTEXT NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_interp (kundali_id, kind, period_key, lang, ruleset_version),
  FOREIGN KEY (kundali_id) REFERENCES kundalis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE panchang_cache (
  cache_key   CHAR(64) PRIMARY KEY,   -- sha256(date|lat|lon|tz|settings|engine)
  payload     LONGTEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE geo_cache (
  query_hash  CHAR(64) PRIMARY KEY,
  payload     LONGTEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  bucket      VARCHAR(100) PRIMARY KEY,
  hits        INT UNSIGNED NOT NULL,
  window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS remedy_rules (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  planet      VARCHAR(10) NULL,            -- Sun..Ketu, NULL for dosha rules
  house       TINYINT NULL,                -- 1..12, NULL = any house
  dosha       VARCHAR(20) NULL,            -- mangal, kaal_sarp, sade_sati, dhaiya, grahan, guru_chandal, kemadruma, pitra
  cond        ENUM('any','weak','strong','malefic','benefic') NOT NULL DEFAULT 'any',
  priority    SMALLINT NOT NULL DEFAULT 50,
  text_en     TEXT NOT NULL, text_hi TEXT NULL, text_gu TEXT NULL,   -- one step per line
  source      VARCHAR(255) NOT NULL,       -- e.g. "BPHS, Graha Shanti" or astrologer name
  status      ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (planet, house), INDEX (dosha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prediction_categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(40) NOT NULL UNIQUE,
  name_en     VARCHAR(80) NOT NULL,
  name_hi     VARCHAR(80) NULL,
  name_gu     VARCHAR(80) NULL,
  icon        VARCHAR(40) NOT NULL DEFAULT 'star',   -- Material Symbols icon name
  planets     VARCHAR(120) NOT NULL,                 -- significator planets, comma separated
  houses      VARCHAR(40) NOT NULL,                  -- houses from lagna, comma separated
  caution     BOOLEAN NOT NULL DEFAULT FALSE,        -- show a "not financial / betting advice" notice
  active      BOOLEAN NOT NULL DEFAULT TRUE,
  sort        INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_categories (
  user_id     INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, category_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES prediction_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO prediction_categories (slug, name_en, name_hi, name_gu, icon, planets, houses, caution, sort) VALUES
 ('career',  'Career',       'करियर',        'કારકિર્દી',      'work',            'Sun,Saturn,Mercury',   '10,6,2',   0, 1),
 ('love',    'Love',         'प्रेम',         'પ્રેમ',           'favorite',        'Venus,Moon',           '5,7',      0, 2),
 ('finance', 'Finance',      'धन',           'ધન',            'savings',         'Jupiter,Venus',        '2,11',     0, 3),
 ('stock',   'Stock Market', 'शेयर बाज़ार',    'શેર બજાર',       'trending_up',     'Jupiter,Mercury,Rahu', '5,8,11',   1, 4),
 ('sports',  'Sports',       'खेल',          'રમતગમત',         'sports_cricket',  'Mars,Sun',             '3,5,6',    1, 5),
 ('health',  'Health',       'स्वास्थ्य',       'સ્વાસ્થ્ય',        'health_and_safety','Sun,Moon,Mars',       '1,6,8',    0, 6),
 ('education','Education',   'शिक्षा',        'શિક્ષણ',          'school',          'Mercury,Jupiter',      '4,5,9',    0, 7),
 ('travel',  'Travel',       'यात्रा',         'પ્રવાસ',          'flight',          'Rahu,Moon',            '3,9,12',   0, 8);

ALTER TABLE prediction_categories
  ADD COLUMN dos_en TEXT NULL,
  ADD COLUMN dos_hi TEXT NULL,
  ADD COLUMN dos_gu TEXT NULL,
  ADD COLUMN donts_en TEXT NULL,
  ADD COLUMN donts_hi TEXT NULL,
  ADD COLUMN donts_gu TEXT NULL,
  ADD COLUMN upay_en TEXT NULL,
  ADD COLUMN upay_hi TEXT NULL,
  ADD COLUMN upay_gu TEXT NULL;
UPDATE prediction_categories SET dos_en = 'Plan your work and finish pending tasks first
Keep good relations with your boss and seniors
Learn a new skill that helps your job', dos_hi = 'अपना काम योजना से करें और पुराने काम पहले पूरे करें
बॉस और वरिष्ठों से अच्छे संबंध रखें
नौकरी में मदद करने वाला नया कौशल सीखें', dos_gu = 'કામનું આયોજન કરો અને બાકી કામ પહેલાં પૂરાં કરો
બોસ અને વરિષ્ઠો સાથે સારા સંબંધ રાખો
નોકરીમાં મદદરૂપ નવી આવડત શીખો', donts_en = 'Don\'t quit or change jobs in anger
Avoid arguments at the workplace', donts_hi = 'गुस्से में नौकरी न छोड़ें या न बदलें
कार्यस्थल पर बहस से बचें', donts_gu = 'ગુસ્સામાં નોકરી ન છોડો કે ન બદલો
કામના સ્થળે દલીલો ટાળો', upay_en = 'Offer water to the Sun every morning
Respect your father and elders', upay_hi = 'रोज़ सुबह सूर्य को जल चढ़ाएँ
पिता और बड़ों का सम्मान करें', upay_gu = 'રોજ સવારે સૂર્યને જળ ચઢાવો
પિતા અને વડીલોનું સન્માન કરો' WHERE slug = 'career';
UPDATE prediction_categories SET dos_en = 'Spend quality time with your partner
Talk openly and listen patiently
Show appreciation with small gestures', dos_hi = 'साथी के साथ अच्छा समय बिताएँ
खुलकर बात करें और धैर्य से सुनें
छोटे-छोटे काम से सराहना जताएँ', dos_gu = 'સાથી સાથે સારો સમય વિતાવો
ખુલ્લા મને વાત કરો અને ધીરજથી સાંભળો
નાની નાની બાબતોથી કદર બતાવો', donts_en = 'Don\'t doubt or check on your partner without reason
Avoid bringing up old fights', donts_hi = 'बिना कारण साथी पर शक न करें
पुराने झगड़े न दोहराएँ', donts_gu = 'કારણ વગર સાથી પર શંકા ન કરો
જૂના ઝઘડા ફરી ન ઉખેળો', upay_en = 'Wear clean, bright clothes and use a light perfume
On Fridays, gift something white or sweet to a girl or woman', upay_hi = 'साफ, चमकीले कपड़े पहनें और हल्की सुगंध लगाएँ
शुक्रवार को किसी कन्या या महिला को सफेद या मीठी चीज़ भेंट करें', upay_gu = 'સાફ, તેજસ્વી કપડાં પહેરો અને હળવી સુગંધ લગાવો
શુક્રવારે કોઈ કન્યા કે સ્ત્રીને સફેદ કે મીઠી વસ્તુ ભેટ આપો' WHERE slug = 'love';
UPDATE prediction_categories SET dos_en = 'Save a fixed part of your income
Keep a written record of spending
Pay old dues on time', dos_hi = 'आय का एक तय हिस्सा बचाएँ
खर्च का लिखित हिसाब रखें
पुराने बकाया समय पर चुकाएँ', dos_gu = 'આવકનો નક્કી ભાગ બચાવો
ખર્ચનો લેખિત હિસાબ રાખો
જૂની બાકી રકમ સમયસર ચૂકવો', donts_en = 'Don\'t lend large amounts without paperwork
Avoid impulse shopping and loans for luxuries', donts_hi = 'बिना लिखा-पढ़ी बड़ी रकम उधार न दें
शौक के लिए अचानक खरीदारी और कर्ज़ से बचें', donts_gu = 'લખાણ વગર મોટી રકમ ઉધાર ન આપો
શોખ માટે અચાનક ખરીદી અને લોન ટાળો', upay_en = 'Keep a small silver coin in your wallet or cash box
Donate some food or grain on Thursdays', upay_hi = 'बटुए या तिजोरी में चाँदी का छोटा सिक्का रखें
गुरुवार को कुछ अन्न या भोजन दान करें', upay_gu = 'પાકીટ કે તિજોરીમાં ચાંદીનો નાનો સિક્કો રાખો
ગુરુવારે થોડું અનાજ કે ભોજન દાન કરો' WHERE slug = 'finance';
UPDATE prediction_categories SET dos_en = 'Invest only money you can afford to lose
Research before every trade and set a stop-loss
Prefer long-term, diversified investing', dos_hi = 'केवल उतना पैसा लगाएँ जितना खोना सह सकें
हर सौदे से पहले जाँच करें और स्टॉप-लॉस रखें
लंबी अवधि और विविध निवेश को प्राथमिकता दें', dos_gu = 'ફક્ત એટલા જ પૈસા રોકો જેટલા ગુમાવવાનું પોસાય
દરેક સોદા પહેલાં તપાસ કરો અને સ્ટોપ-લોસ રાખો
લાંબા ગાળાના અને વૈવિધ્યસભર રોકાણને પસંદ કરો', donts_en = 'Don\'t borrow money to trade
Avoid tips, rumours and \'sure-shot\' schemes
Don\'t trade to recover losses quickly', donts_hi = 'उधार लेकर ट्रेडिंग न करें
टिप्स, अफवाहों और \'पक्के\' फायदे वाली योजनाओं से बचें
नुकसान जल्दी पूरा करने के लिए ट्रेड न करें', donts_gu = 'ઉધાર લઈને ટ્રેડિંગ ન કરો
ટિપ્સ, અફવાઓ અને \'પાક્કા\' ફાયદાની યોજનાઓ ટાળો
નુકસાન ઝડપથી પૂરું કરવા ટ્રેડ ન કરો', upay_en = 'Keep your investment papers in order and review them calmly
Feed birds or donate to the needy on Wednesdays', upay_hi = 'निवेश के कागज़ व्यवस्थित रखें और शांति से समीक्षा करें
बुधवार को पक्षियों को दाना डालें या ज़रूरतमंद को दान दें', upay_gu = 'રોકાણના કાગળો વ્યવસ્થિત રાખો અને શાંતિથી સમીક્ષા કરો
બુધવારે પક્ષીઓને ચણ નાખો અથવા જરૂરિયાતમંદને દાન આપો' WHERE slug = 'stock';
UPDATE prediction_categories SET dos_en = 'Practise regularly and warm up before playing
Focus on fitness, sleep and diet
Play as a team and follow your coach', dos_hi = 'नियमित अभ्यास करें और खेलने से पहले वार्म-अप करें
फिटनेस, नींद और खानपान पर ध्यान दें
टीम भावना से खेलें और कोच की बात मानें', dos_gu = 'નિયમિત પ્રેક્ટિસ કરો અને રમતાં પહેલાં વોર્મ-અપ કરો
ફિટનેસ, ઊંઘ અને ખોરાક પર ધ્યાન આપો
ટીમ ભાવનાથી રમો અને કોચની વાત માનો', donts_en = 'Don\'t play through an injury
Never bet money on matches', donts_hi = 'चोट के साथ न खेलें
मैचों पर कभी पैसा न लगाएँ', donts_gu = 'ઈજા સાથે ન રમો
મેચ પર ક્યારેય પૈસા ન લગાવો', upay_en = 'Do some exercise or a morning walk daily
Offer sweets at a Hanuman temple on Tuesdays', upay_hi = 'रोज़ थोड़ा व्यायाम या सुबह की सैर करें
मंगलवार को हनुमान मंदिर में मिठाई चढ़ाएँ', upay_gu = 'રોજ થોડી કસરત કે સવારની ચાલ કરો
મંગળવારે હનુમાન મંદિરમાં મીઠાઈ ચઢાવો' WHERE slug = 'sports';
UPDATE prediction_categories SET dos_en = 'Sleep on time and wake up early
Eat fresh, simple food and drink enough water
Walk or exercise daily', dos_hi = 'समय पर सोएँ और जल्दी उठें
ताज़ा, सादा भोजन करें और पर्याप्त पानी पिएँ
रोज़ टहलें या व्यायाम करें', dos_gu = 'સમયસર સૂઈ જાઓ અને વહેલા ઊઠો
તાજું, સાદું ભોજન લો અને પૂરતું પાણી પીઓ
રોજ ચાલો અથવા કસરત કરો', donts_en = 'Don\'t ignore small symptoms — see a doctor
Avoid junk food, smoking and alcohol', donts_hi = 'छोटे लक्षणों को अनदेखा न करें — डॉक्टर को दिखाएँ
जंक फूड, धूम्रपान और शराब से बचें', donts_gu = 'નાના લક્ષણોને અવગણશો નહીં — ડૉક્ટરને બતાવો
જંક ફૂડ, ધૂમ્રપાન અને દારૂ ટાળો', upay_en = 'Do deep breathing or pranayama for 10 minutes daily
Donate medicines or food to the sick when you can', upay_hi = 'रोज़ 10 मिनट गहरी साँस या प्राणायाम करें
संभव हो तो बीमारों को दवा या भोजन दान करें', upay_gu = 'રોજ 10 મિનિટ ઊંડા શ્વાસ કે પ્રાણાયામ કરો
શક્ય હોય તો બીમારોને દવા કે ભોજન દાન કરો' WHERE slug = 'health';
UPDATE prediction_categories SET dos_en = 'Make a study timetable and stick to it
Revise daily and practise old papers
Ask teachers when you have doubts', dos_hi = 'पढ़ाई का समय-सारणी बनाएँ और उसका पालन करें
रोज़ दोहराएँ और पुराने प्रश्नपत्र हल करें
शंका हो तो शिक्षक से पूछें', dos_gu = 'અભ્યાસનું સમયપત્રક બનાવો અને તેનું પાલન કરો
રોજ પુનરાવર્તન કરો અને જૂના પેપર ઉકેલો
શંકા હોય તો શિક્ષકને પૂછો', donts_en = 'Don\'t leave studies for the last minute
Avoid too much phone and social media while studying', donts_hi = 'पढ़ाई आखिरी समय पर न छोड़ें
पढ़ते समय ज़्यादा फोन और सोशल मीडिया से बचें', donts_gu = 'અભ્યાસ છેલ્લી ઘડી માટે ન છોડો
ભણતી વખતે વધુ પડતો ફોન અને સોશિયલ મીડિયા ટાળો', upay_en = 'Respect your teachers and elders
Keep your study table clean and face east or north while studying', upay_hi = 'शिक्षकों और बड़ों का सम्मान करें
पढ़ाई की मेज़ साफ रखें और पूर्व या उत्तर की ओर मुख करके पढ़ें', upay_gu = 'શિક્ષકો અને વડીલોનું સન્માન કરો
અભ્યાસનું ટેબલ સાફ રાખો અને પૂર્વ કે ઉત્તર તરફ મુખ રાખીને ભણો' WHERE slug = 'education';
UPDATE prediction_categories SET dos_en = 'Plan and book in advance
Keep documents and copies safe
Inform family about your travel plan', dos_hi = 'पहले से योजना बनाकर बुकिंग करें
दस्तावेज़ और उनकी कॉपी सुरक्षित रखें
परिवार को यात्रा की जानकारी दें', dos_gu = 'અગાઉથી આયોજન કરીને બુકિંગ કરો
દસ્તાવેજો અને તેની નકલ સુરક્ષિત રાખો
પરિવારને મુસાફરીની જાણ કરો', donts_en = 'Don\'t travel in a hurry or late at night if avoidable
Avoid carrying too much cash', donts_hi = 'जहाँ तक हो सके जल्दबाज़ी में या देर रात यात्रा न करें
ज़्यादा नकद साथ न रखें', donts_gu = 'શક્ય હોય તો ઉતાવળમાં કે મોડી રાત્રે મુસાફરી ન કરો
વધુ રોકડ સાથે ન રાખો', upay_en = 'Carry a small bottle of water from home
Feed a dog before a long journey', upay_hi = 'घर से पानी की छोटी बोतल साथ रखें
लंबी यात्रा से पहले कुत्ते को रोटी खिलाएँ', upay_gu = 'ઘરેથી પાણીની નાની બોટલ સાથે રાખો
લાંબી મુસાફરી પહેલાં કૂતરાને રોટલી ખવડાવો' WHERE slug = 'travel';

ALTER TABLE users
  ADD COLUMN disabled     BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN plan         ENUM('free','premium') NOT NULL DEFAULT 'free',
  ADD COLUMN plan_expires DATE NULL;
