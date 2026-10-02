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

INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('stock_market', 'Stock Market', 1);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('nifty_50', 'Nifty 50', 2);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('bank_nifty', 'Bank Nifty', 3);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('economy_banking', 'Economy & Banking', 4);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('weather', 'Weather', 5);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('national_security', 'National Security', 6);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('politics', 'Politics & Government', 7);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('international', 'International Relations', 8);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('agriculture', 'Agriculture', 9);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('public_health', 'Public Health', 10);
INSERT IGNORE INTO world_topics (slug, label_en, sort_order) VALUES ('transport', 'Transport', 11);

UPDATE world_topics SET label_hi='शेयर बाज़ार', label_gu='શેર બજાર' WHERE slug='stock_market';
UPDATE world_topics SET label_hi='निफ्टी 50', label_gu='નિફ્ટી 50' WHERE slug='nifty_50';
UPDATE world_topics SET label_hi='बैंक निफ्टी', label_gu='બૅન્ક નિફ્ટી' WHERE slug='bank_nifty';
UPDATE world_topics SET label_hi='अर्थव्यवस्था व बैंकिंग', label_gu='અર્થતંત્ર અને બૅન્કિંગ' WHERE slug='economy_banking';
UPDATE world_topics SET label_hi='मौसम', label_gu='હવામાન' WHERE slug='weather';
UPDATE world_topics SET label_hi='राष्ट्रीय सुरक्षा', label_gu='રાષ્ટ્રીય સુરક્ષા' WHERE slug='national_security';
UPDATE world_topics SET label_hi='राजनीति व शासन', label_gu='રાજકારણ' WHERE slug='politics';
UPDATE world_topics SET label_hi='अंतर्राष्ट्रीय संबंध', label_gu='આંતરરાષ્ટ્રીય સંબંધ' WHERE slug='international';
UPDATE world_topics SET label_hi='कृषि', label_gu='કૃષિ' WHERE slug='agriculture';
UPDATE world_topics SET label_hi='सार्वजनिक स्वास्थ्य', label_gu='જાહેર આરોગ્ય' WHERE slug='public_health';
UPDATE world_topics SET label_hi='परिवहन', label_gu='પરિવહન' WHERE slug='transport';

INSERT IGNORE INTO world_countries (id, name, iso_code, ref_date, ref_time, ref_place, ref_lat, ref_lon, ref_tzid, ref_source, ref_rationale) VALUES
(1, 'India', 'IND', '1947-08-15', '00:00:00', 'New Delhi, India', 28.6139, 77.2090, 'Asia/Kolkata',
 'Indian Independence 15 Aug 1947 00:00 IST New Delhi',
 'India became independent at midnight on 15 August 1947 in New Delhi. Most widely used reference chart for Indian mundane astrology.');
