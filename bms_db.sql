-- =====================================================
-- 1. Create Database
-- =====================================================
CREATE DATABASE IF NOT EXISTS beautiful_minds_school
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE beautiful_minds_school;

-- =====================================================
-- 2. Remove Any Existing User Records (Clean Slate)
-- =====================================================
DROP USER IF EXISTS 'beautiful_minds_web'@'localhost';
DROP USER IF EXISTS 'beautiful_minds_web'@'127.0.0.1';
DROP USER IF EXISTS 'beautiful_minds_web'@'%';

-- =====================================================
-- 3. Create Website Database User (Strong Password)
-- =====================================================
CREATE USER 'beautiful_minds_web'@'localhost'
IDENTIFIED WITH mysql_native_password
BY 'B3autiful!M1nds2025';

CREATE USER 'beautiful_minds_web'@'127.0.0.1'
IDENTIFIED WITH mysql_native_password
BY 'B3autiful!M1nds2025';

-- =====================================================
-- 4. Grant Required Privileges
-- =====================================================
GRANT ALL PRIVILEGES
ON beautiful_minds_school.*
TO 'beautiful_minds_web'@'localhost';

GRANT ALL PRIVILEGES
ON beautiful_minds_school.*
TO 'beautiful_minds_web'@'127.0.0.1';

FLUSH PRIVILEGES;

-- =====================================================
-- 6. Create Applications Table (NO NULLS)
-- =====================================================
CREATE TABLE IF NOT EXISTS applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL DEFAULT '',
    date_of_birth DATE NOT NULL DEFAULT '1970-01-01',
    religion VARCHAR(50) NOT NULL DEFAULT '',
    class_interest VARCHAR(50) NOT NULL DEFAULT '',
    gender VARCHAR(10) NOT NULL DEFAULT '',
    address TEXT NOT NULL,
    student_phone VARCHAR(20) NOT NULL DEFAULT '',
    student_email VARCHAR(100) NOT NULL DEFAULT '',
    nationality VARCHAR(50) NOT NULL DEFAULT '',
    state VARCHAR(50) NOT NULL DEFAULT '',
    city VARCHAR(50) NOT NULL DEFAULT '',
    mother_name VARCHAR(100) NOT NULL DEFAULT '',
    father_name VARCHAR(100) NOT NULL DEFAULT '',
    mother_phone VARCHAR(20) NOT NULL DEFAULT '',
    father_phone VARCHAR(20) NOT NULL DEFAULT '',
    parent_email VARCHAR(100) NOT NULL DEFAULT '',
    parent_address TEXT NOT NULL,
    submission_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    application_id VARCHAR(50) NOT NULL DEFAULT '',
    ip_address VARCHAR(50) NOT NULL DEFAULT '',
    UNIQUE KEY (application_id),
    INDEX idx_status (status),
    INDEX idx_submission_date (submission_date),
    INDEX idx_class_interest (class_interest)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- altering table --
ALTER TABLE applications RENAME COLUMN class_of_interest TO class_interest;   
-- =====================================================
-- 7. Create Contacts Table (NO NULLS)
-- =====================================================
CREATE TABLE IF NOT EXISTS contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(100) NOT NULL DEFAULT '',
    phone VARCHAR(20) NOT NULL DEFAULT '',
    subject VARCHAR(200) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    submission_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('unread','read','archived') NOT NULL DEFAULT 'unread',
    ip_address VARCHAR(50) NOT NULL DEFAULT '',
    INDEX idx_status (status),
    INDEX idx_email (email),
    INDEX idx_submission_date (submission_date)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. Verification Queries
-- =====================================================
SELECT user, host, plugin FROM mysql.user WHERE user = 'beautiful_minds_web';
SHOW TABLES;
DESCRIBE applications;
SELECT * FROM applications;
DESCRIBE contacts;
SELECT * FROM contacts;

SELECT CURRENT_USER(), USER();