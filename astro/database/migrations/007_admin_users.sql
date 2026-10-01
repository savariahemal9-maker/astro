-- Admin user management and future premium plans
ALTER TABLE users
  ADD COLUMN disabled     BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN plan         ENUM('free','premium') NOT NULL DEFAULT 'free',
  ADD COLUMN plan_expires DATE NULL;
