-- Run once on existing databases (v0.2)
ALTER TABLE interpretations MODIFY kind VARCHAR(60) NOT NULL;
DELETE FROM kundalis;
