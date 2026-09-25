CREATE DATABASE IF NOT EXISTS `sblink_network`;
USE `sblink_network`;

CREATE TABLE IF NOT EXISTS `super_admins` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `company_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `expiry_date` DATE DEFAULT NULL,
  `max_routers` INT(11) DEFAULT 1,
  `max_subscribers` INT(11) DEFAULT 100,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `nas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT(11) NOT NULL,
  `nasname` VARCHAR(128) NOT NULL,
  `shortname` VARCHAR(32) DEFAULT NULL,
  `type` VARCHAR(30) DEFAULT 'other',
  `ports` INT(11) DEFAULT NULL,
  `secret` VARCHAR(60) NOT NULL,
  `server` VARCHAR(64) DEFAULT NULL,
  `community` VARCHAR(50) DEFAULT NULL,
  `description` VARCHAR(200) DEFAULT NULL,
  `api_port` INT(11) DEFAULT 8728,
  `api_user` VARCHAR(50) DEFAULT NULL,
  `api_password` VARCHAR(255) DEFAULT NULL,
  `coa_port` INT(11) DEFAULT 3799,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `radacct` (
  `radacctid` BIGINT(21) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `acctsessionid` VARCHAR(64) NOT NULL DEFAULT '',
  `acctuniqueid` VARCHAR(32) NOT NULL DEFAULT '',
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `groupname` VARCHAR(64) NOT NULL DEFAULT '',
  `realm` VARCHAR(64) DEFAULT '',
  `nasipaddress` VARCHAR(15) NOT NULL DEFAULT '',
  `nasportid` VARCHAR(32) DEFAULT NULL,
  `nasporttype` VARCHAR(32) DEFAULT NULL,
  `acctstarttime` DATETIME DEFAULT NULL,
  `acctupdatetime` DATETIME DEFAULT NULL,
  `acctstoptime` DATETIME DEFAULT NULL,
  `acctinterval` INT(12) DEFAULT NULL,
  `acctsessiontime` INT(12) UNSIGNED DEFAULT NULL,
  `acctauthentic` VARCHAR(32) DEFAULT NULL,
  `connectinfo_start` VARCHAR(128) DEFAULT NULL,
  `connectinfo_stop` VARCHAR(128) DEFAULT NULL,
  `acctinputoctets` BIGINT(20) DEFAULT NULL,
  `acctoutputoctets` BIGINT(20) DEFAULT NULL,
  `calledstationid` VARCHAR(50) NOT NULL DEFAULT '',
  `callingstationid` VARCHAR(50) NOT NULL DEFAULT '',
  `acctterminatecause` VARCHAR(32) NOT NULL DEFAULT '',
  `servicetype` VARCHAR(32) DEFAULT NULL,
  `framedprotocol` VARCHAR(32) DEFAULT NULL,
  `framedipaddress` VARCHAR(15) NOT NULL DEFAULT '',
  `acctstartdelay` INT(12) DEFAULT NULL,
  `acctstopdelay` INT(12) DEFAULT NULL,
  `xascendsessionsvrkey` VARCHAR(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `super_admins` (`username`, `email`, `password`, `name`) VALUES ('admin', 'admin@sblink.net', '$2y$10$7/O/zB2QG9nO.jI/3POfB.p0n.6vGZ9y7UeGk1J9W4iC6Xo0hFp8u', 'Super Admin');
