CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key, setting_value) VALUES
    ('hero_card_icon', '🏡'),
    ('hero_card_title', 'ARVE''S House'),
    ('hero_card_subtitle', 'Simple stay. Greater memories.'),
    ('hero_card_bg_start', '#c9a27f'),
    ('hero_card_bg_end', '#7b5841')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
