-- Remedy rules: chart-specific remedies with source. Run once.
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
