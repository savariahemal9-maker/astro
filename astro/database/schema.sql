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
