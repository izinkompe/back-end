-- PanganTani: skema referensi MySQL 8 / MariaDB 10.6, InnoDB + utf8mb4.
-- Jalur instalasi utama: php artisan migrate. Jangan import SQL ini lalu migrate pada DB yang sama.
-- Jika memilih import manual, tabel migrations di akhir menandai baseline sebagai sudah diterapkan.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL,
 email VARCHAR(255) NOT NULL UNIQUE, email_verified_at TIMESTAMP NULL,
 password VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, phone VARCHAR(30) NULL,
 organization VARCHAR(255) NULL, preferences JSON NULL, remember_token VARCHAR(100) NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, INDEX users_role_index(role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE password_reset_tokens (
 email VARCHAR(255) PRIMARY KEY, token VARCHAR(255) NOT NULL, created_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE personal_access_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tokenable_type VARCHAR(255) NOT NULL,
 tokenable_id BIGINT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL, token VARCHAR(64) NOT NULL UNIQUE,
 abilities TEXT NULL, last_used_at TIMESTAMP NULL, expires_at TIMESTAMP NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 INDEX personal_access_tokens_tokenable_type_tokenable_id_index(tokenable_type,tokenable_id), INDEX personal_access_tokens_expires_at_index(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE addresses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(255) NOT NULL, address TEXT NOT NULL, area VARCHAR(255) NOT NULL,
 latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL, instructions TEXT NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE farmer_verifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 description TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', reason TEXT NULL,
 reviewer_id BIGINT UNSIGNED NULL, reviewed_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 INDEX farmer_verifications_status_index(status), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE offers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, farmer_id BIGINT UNSIGNED NOT NULL,
 category_id BIGINT UNSIGNED NULL, address_id BIGINT UNSIGNED NULL, name VARCHAR(255) NOT NULL,
 description TEXT NULL, surplus_reason TEXT NULL, `condition` TEXT NULL, harvest_date DATE NULL,
 stock_g BIGINT UNSIGNED NOT NULL DEFAULT 0, reserved_g BIGINT UNSIGNED NOT NULL DEFAULT 0, sold_g BIGINT UNSIGNED NOT NULL DEFAULT 0,
 price_per_kg INT UNSIGNED NOT NULL DEFAULT 0, minimum_price INT UNSIGNED NOT NULL DEFAULT 0,
 closes_at TIMESTAMP NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', area VARCHAR(255) NULL,
 latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 INDEX offers_closes_at_index(closes_at), INDEX offers_status_index(status), INDEX offers_category_id_status_index(category_id,status),
 FOREIGN KEY(farmer_id) REFERENCES users(id), FOREIGN KEY(category_id) REFERENCES categories(id), FOREIGN KEY(address_id) REFERENCES addresses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE price_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, offer_id BIGINT UNSIGNED NOT NULL,
 starts_at DATETIME NOT NULL, price_per_kg INT UNSIGNED NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 UNIQUE KEY price_steps_offer_id_starts_at_unique(offer_id,starts_at), FOREIGN KEY(offer_id) REFERENCES offers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE pickup_slots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, offer_id BIGINT UNSIGNED NOT NULL,
 starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, FOREIGN KEY(offer_id) REFERENCES offers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, number VARCHAR(50) NOT NULL UNIQUE,
 buyer_id BIGINT UNSIGNED NOT NULL, farmer_id BIGINT UNSIGNED NOT NULL, offer_id BIGINT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(100) NOT NULL, request_hash VARCHAR(64) NOT NULL,
 quantity_g BIGINT UNSIGNED NOT NULL, price_per_kg INT UNSIGNED NOT NULL, total_idr BIGINT UNSIGNED NOT NULL,
 product_snapshot JSON NOT NULL, pickup_snapshot JSON NOT NULL, proposed_pickup JSON NULL,
 pickup_starts_at DATETIME NOT NULL, pickup_ends_at DATETIME NOT NULL, payment_due_at DATETIME NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment', payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
 stock_state VARCHAR(20) NOT NULL DEFAULT 'reserved', received_g BIGINT UNSIGNED NULL,
 completed_at TIMESTAMP NULL, issue_reason TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 UNIQUE KEY orders_buyer_id_idempotency_key_unique(buyer_id,idempotency_key), INDEX orders_payment_due_at_index(payment_due_at), INDEX orders_status_index(status),
 FOREIGN KEY(buyer_id) REFERENCES users(id), FOREIGN KEY(farmer_id) REFERENCES users(id), FOREIGN KEY(offer_id) REFERENCES offers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 gateway_order_id VARCHAR(50) NOT NULL UNIQUE, status VARCHAR(30) NOT NULL DEFAULT 'not_started',
 snap_token VARCHAR(255) NULL, redirect_url TEXT NULL, transaction_id VARCHAR(255) NULL,
 initiated_at TIMESTAMP NULL, checked_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE complaints (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 description TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'open', response TEXT NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, INDEX complaints_status_index(status),
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE refunds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 amount_idr BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'requested', reason TEXT NOT NULL,
 reference VARCHAR(255) NULL, recorded_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE media (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 attachable_type VARCHAR(255) NULL, attachable_id BIGINT UNSIGNED NULL, kind VARCHAR(30) NOT NULL,
 path VARCHAR(255) NOT NULL, mime VARCHAR(255) NOT NULL, size INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, INDEX media_attachable_type_attachable_id_index(attachable_type,attachable_id),
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 event_key VARCHAR(255) NULL, title VARCHAR(255) NOT NULL, link VARCHAR(255) NOT NULL,
 read_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 UNIQUE KEY notifications_user_id_event_key_unique(user_id,event_key), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE favorites (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(20) NOT NULL, value VARCHAR(255) NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 UNIQUE KEY favorites_user_id_kind_value_unique(user_id,kind,value), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE food_needs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, buyer_id BIGINT UNSIGNED NOT NULL,
 commodity VARCHAR(255) NOT NULL, quantity_g BIGINT UNSIGNED NOT NULL, area VARCHAR(255) NOT NULL,
 needed_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 INDEX food_needs_needed_at_index(needed_at), FOREIGN KEY(buyer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE need_responses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, food_need_id BIGINT UNSIGNED NOT NULL,
 farmer_id BIGINT UNSIGNED NOT NULL, offer_id BIGINT UNSIGNED NOT NULL, pickup_slot_id BIGINT UNSIGNED NOT NULL,
 quantity_g BIGINT UNSIGNED NOT NULL, price_per_kg INT UNSIGNED NOT NULL, message TEXT NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 FOREIGN KEY(food_need_id) REFERENCES food_needs(id), FOREIGN KEY(farmer_id) REFERENCES users(id),
 FOREIGN KEY(offer_id) REFERENCES offers(id), FOREIGN KEY(pickup_slot_id) REFERENCES pickup_slots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE emission_factors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id BIGINT UNSIGNED NOT NULL,
 kg_co2e_per_kg DECIMAL(12,6) NOT NULL, unit VARCHAR(255) NOT NULL DEFAULT 'kgCO2e/kg',
 source TEXT NOT NULL, version VARCHAR(255) NOT NULL, method TEXT NOT NULL, assumptions TEXT NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 FOREIGN KEY(category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE distributions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 received_g BIGINT UNSIGNED NOT NULL, emission_factor_id BIGINT UNSIGNED NULL,
 estimated_co2e_kg DECIMAL(18,6) NULL, factor_snapshot JSON NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(emission_factor_id) REFERENCES emission_factors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(255) NOT NULL, subject_type VARCHAR(255) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
 `before` JSON NULL, `after` JSON NULL, reason TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 INDEX audit_logs_subject_type_subject_id_index(subject_type,subject_id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE migrations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO migrations (migration,batch) VALUES ('2026_11_22_000001_create_pangantani_tables',1);
