-- Saved chat conversations (basic chat + AI Astrologer). The app also creates these on first use (src/Api/ChatStore.php).
CREATE TABLE IF NOT EXISTS chat_threads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, profile_id INT UNSIGNED NOT NULL,
  mode VARCHAR(10) NOT NULL, title VARCHAR(160) NOT NULL, lang VARCHAR(5) NOT NULL DEFAULT 'auto', ctx TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, mode, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, thread_id INT UNSIGNED NOT NULL, role VARCHAR(4) NOT NULL, body MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
