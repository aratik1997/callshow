-- Yaniv online — database schema
-- Import this via phpMyAdmin (cPanel) or `mysql -u root -p yaniv < schema.sql` locally.

CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(6) NOT NULL UNIQUE,
  status ENUM('waiting','playing','round_end','finished') NOT NULL DEFAULT 'waiting',
  yaniv_threshold INT NOT NULL DEFAULT 5,
  turn_seat INT NOT NULL DEFAULT 0,
  awaiting_draw TINYINT(1) NOT NULL DEFAULT 0,
  draw_pile LONGTEXT NOT NULL DEFAULT ('[]'),
  discard_pile LONGTEXT NOT NULL DEFAULT ('[]'),
  current_throw LONGTEXT NULL,
  pending_throw LONGTEXT NULL,
  awaiting_show TINYINT(1) NOT NULL DEFAULT 0,
  turn_deadline DATETIME NULL,
  round_number INT NOT NULL DEFAULT 1,
  last_round_result LONGTEXT NULL,
  round_history LONGTEXT NULL,
  winner_name VARCHAR(50) NULL,
  bell_rung_at DATETIME NULL,
  bell_rung_by VARCHAR(50) NULL,
  bell_target VARCHAR(50) NULL,
  bell_target_seat INT NULL,
  last_kick_at DATETIME NULL,
  last_kick_by VARCHAR(50) NULL,
  last_kicked_name VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  token VARCHAR(64) NOT NULL,
  name VARCHAR(50) NOT NULL,
  seat INT NOT NULL,
  hand LONGTEXT NOT NULL DEFAULT ('[]'),
  score INT NOT NULL DEFAULT 0,
  is_host TINYINT(1) NOT NULL DEFAULT 0,
  eliminated TINYINT(1) NOT NULL DEFAULT 0,
  has_called TINYINT(1) NOT NULL DEFAULT 0,
  last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_players_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  INDEX (room_id),
  UNIQUE KEY uniq_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  INDEX (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kicks (
  token VARCHAR(64) NOT NULL PRIMARY KEY,
  kicked_by VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  seat INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  message VARCHAR(200) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_chat_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  INDEX (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
