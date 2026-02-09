

-- 1. Applications Table
CREATE TABLE IF NOT EXISTS `applications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `application_id` varchar(50) NOT NULL,
    `full_name` varchar(255) NOT NULL,
    `date_of_birth` date DEFAULT NULL,
    `gender` varchar(20) DEFAULT NULL,
    `religion` varchar(50) DEFAULT NULL,
    `class_interest` varchar(100) DEFAULT NULL,
    `nationality` varchar(100) DEFAULT 'Nigeria',
    `state` varchar(100) DEFAULT NULL,
    `city` varchar(100) DEFAULT NULL,
    `address` text,
    `student_phone` varchar(20) DEFAULT NULL,
    `student_email` varchar(191) DEFAULT NULL,
    `mother_name` varchar(255) DEFAULT NULL,
    `mother_phone` varchar(20) DEFAULT NULL,
    `father_name` varchar(255) DEFAULT NULL,
    `father_phone` varchar(20) DEFAULT NULL,
    `parent_email` varchar(191) NOT NULL,
    `parent_address` text,
    `status` enum(
        'pending',
        'reviewed',
        'accepted',
        'rejected'
    ) DEFAULT 'pending',
    `submission_date` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_application_id` (`application_id`),
    KEY `idx_parent_email` (`parent_email`),
    KEY `idx_status` (`status`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 2. Contacts Table
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `email` varchar(191) NOT NULL,
    `phone` varchar(20) DEFAULT NULL,
    `subject` varchar(255) DEFAULT 'General Inquiry',
    `message` text NOT NULL,
    `submission_date` datetime DEFAULT CURRENT_TIMESTAMP,
    `status` enum('new', 'read', 'replied') DEFAULT 'new',
    PRIMARY KEY (`id`),
    KEY `idx_email` (`email`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;