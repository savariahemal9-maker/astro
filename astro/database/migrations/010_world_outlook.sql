-- World / Country Outlook module
CREATE TABLE IF NOT EXISTS world_countries (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  iso_code      CHAR(3) NOT NULL DEFAULT '',
  enabled       BOOLEAN NOT NULL DEFAULT TRUE,
  ref_date      DATE NOT NULL,
  ref_time      TIME NOT NULL DEFAULT '00:00:00',
  ref_place     VARCHAR(255) NOT NULL,
  ref_lat       DECIMAL(9,6) NOT NULL,
  ref_lon       DECIMAL(9,6) NOT NULL,
  ref_tzid      VARCHAR(60) NOT NULL,
  ref_source    VARCHAR(255) NOT NULL DEFAULT '',
  ref_rationale TEXT,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_topics (
  id         SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(60) NOT NULL UNIQUE,
  label_en   VARCHAR(120) NOT NULL,
  label_hi   VARCHAR(120) NOT NULL DEFAULT '',
  label_gu   VARCHAR(120) NOT NULL DEFAULT '',
  enabled    BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_reports (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope        ENUM('india','country','world') NOT NULL,
  country_id   INT UNSIGNED NULL,
  topic_slug   VARCHAR(60) NOT NULL,
  period       ENUM('daily','weekly','monthly','yearly') NOT NULL,
  date_from    DATE NOT NULL,
  date_to      DATE NOT NULL,
  lang         CHAR(2) NOT NULL DEFAULT 'en',
  content      TEXT NOT NULL,
  calc_json    TEXT NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cache_key    CHAR(32) NOT NULL UNIQUE,
  INDEX idx_scope_country (scope, country_id),
  INDEX idx_topic (topic_slug),
  INDEX idx_date (date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO world_topics (slug, label_en, label_hi, label_gu, sort_order) VALUES
('stock_market',      'Stock Market',          'शेयर बाज़ार',           'શેર બજાર',              1),
('nifty_50',          'Nifty 50',              'निफ्टी 50',              'નિફ્ટી 50',             2),
('bank_nifty',        'Bank Nifty',            'बैंक निफ्टी',            'બૅન્ક નિફ્ટી',          3),
('economy_banking',   'Economy & Banking',     'अर्थव्यवस्था व बैंकिंग', 'અર્થતંત્ર અને બૅન્કિંગ', 4),
('weather',           'Weather',               'मौसम',                  'હવામાન',                5),
('national_security', 'National Security',     'राष्ट्रीय सुरक्षा',     'રાષ્ટ્રીય સુરક્ષા',      6),
('politics',          'Politics & Government', 'राजनीति व शासन',        'રાજકારણ',               7),
('international',     'International Relations','अंतर्राष्ट्रीय संबंध',  'આંતરરાષ્ટ્રીય સંબંધ',  8),
('agriculture',       'Agriculture',           'कृषि',                  'કૃષિ',                  9),
('public_health',     'Public Health',         'सार्वजनिक स्वास्थ्य',   'જાહેર આરોગ્ય',         10),
('transport',         'Transport',             'परिवहन',                'પરિવહન',                11);

-- India: Independence 15 Aug 1947 midnight IST New Delhi (most widely used mundane chart)
INSERT IGNORE INTO world_countries (id, name, iso_code, ref_date, ref_time, ref_place, ref_lat, ref_lon, ref_tzid, ref_source, ref_rationale) VALUES
(1, 'India', 'IND', '1947-08-15', '00:00:00', 'New Delhi, India', 28.6139, 77.2090, 'Asia/Kolkata',
 'Indian Independence — 15 August 1947, 00:00 IST, New Delhi',
 'India became independent at the stroke of midnight on 15 August 1947 in New Delhi. This is the most widely used reference chart for Indian mundane astrology.');
