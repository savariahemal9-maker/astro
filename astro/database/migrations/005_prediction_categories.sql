-- Personalized prediction categories (managed at #/admin) and each user's saved choices
SET NAMES utf8mb4;
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
