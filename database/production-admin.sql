-- Run this after database/schema.sql on production.
-- Login email: admin@yulcaribe.com
-- Temporary password was generated outside this file; change it after first login.

INSERT INTO users (id, role, name, email, phone, password_hash, status)
VALUES (
    1,
    'super_admin',
    'YulCaribe Admin',
    'admin@yulcaribe.com',
    NULL,
    '$2y$12$3a0aAikK3W88QDFYIfhGneyS6gP5tWwnZk7jzGT2T3cOSdNWghmRa',
    'active'
)
ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    name = VALUES(name),
    email = VALUES(email),
    phone = VALUES(phone),
    password_hash = VALUES(password_hash),
    status = VALUES(status);

UPDATE users
SET status = 'passive'
WHERE email IN ('danisman@demo.local', 'musteri@demo.local');
