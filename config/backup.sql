-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: radius_admin
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `by_user` varchar(100) NOT NULL,
  `by_role` varchar(50) DEFAULT 'Franchise',
  `against_to` varchar(100) DEFAULT 'N/A',
  `against_role` varchar(50) DEFAULT '',
  `activity` varchar(255) NOT NULL,
  `station_ip` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,1,'2026-09-25 20:02:26','admin','Franchise','N/A','','Admin Login','119.156.28.215'),(2,1,'2026-09-25 18:12:26','admin','Franchise','rashidgujar18','User','User Activate','154.80.86.24'),(3,1,'2026-09-25 17:12:26','admin','Franchise','sajawalcktgc','User','User Activate','154.80.86.24'),(4,1,'2026-09-25 16:12:26','admin','Franchise','N/A','','Admin Login','102.88.112.152'),(5,1,'2026-09-25 20:24:19','Admin','Franchise','malikadnan16','User','Package Renewed & Expiry Updated',NULL),(6,1,'2026-09-25 20:27:12','Admin','Franchise','sherdiljall','User','Package Renewed & Expiry Updated',NULL),(7,1,'2026-09-25 20:29:43','Admin','Franchise','fahad','User','Package Renewed & Expiry Updated',NULL),(8,1,'2026-09-25 20:30:34','Admin','Franchise','abdhome','User','Package Renewed & Expiry Updated',NULL),(9,1,'2026-09-25 20:40:25','Admin','Franchise','malikadnan16','User','Profile Disabled',NULL),(10,1,'2026-09-25 20:40:30','Admin','Franchise','malikadnan16','User','Profile Enabled',NULL),(11,1,'2026-09-25 20:40:38','Admin','Franchise','malikadnan16','User','Profile Disabled',NULL),(12,1,'2026-09-25 20:40:46','Admin','Franchise','malikadnan16','User','Profile Disabled',NULL),(13,1,'2026-09-25 20:40:51','Admin','Franchise','malikadnan16','User','Profile Enabled',NULL),(14,1,'2026-09-25 20:40:56','Admin','Franchise','malikadnan16','User','Profile Disabled',NULL),(15,1,'2026-09-25 20:41:02','Admin','Franchise','malikadnan16','User','Profile Disabled',NULL),(16,1,'2026-09-25 21:37:30','Admin','Franchise','waseemmasat','User','Added Balance: 1000',NULL),(17,1,'2026-09-25 22:13:43','Admin','Franchise','wasi','User','Added Balance: 1200',NULL),(18,1,'2026-09-25 22:15:55','Admin','Franchise','wasi','User','Added Balance: 1000',NULL),(19,1,'2026-09-25 22:23:24','Admin','Franchise','wasi','User','Package Renewed & Expiry Updated',NULL),(20,2,'2026-09-25 22:51:45','Admin','Franchise','rrr','User','Package Renewed & Expiry Updated',NULL),(21,2,'2026-09-25 22:54:20','Admin','Franchise','vivo','User','Package Renewed & Expiry Updated',NULL),(22,2,'2026-09-25 22:54:29','Admin','Franchise','user1','User','Package Renewed & Expiry Updated',NULL);
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','suspended','expired') DEFAULT 'active',
  `expiry_date` date DEFAULT NULL,
  `max_routers` int(11) DEFAULT 1,
  `max_subscribers` int(11) DEFAULT 100,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `full_name` varchar(100) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `subarea` varchar(100) DEFAULT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `bank_details` text DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clients`
--

LOCK TABLES `clients` WRITE;
/*!40000 ALTER TABLE `clients` DISABLE KEYS */;
INSERT INTO `clients` VALUES (2,'Waseem CHR','waasimalik@gmail.com','$2y$10$j8Rfj8uD9KENStvvPAAtTedTh2bBYbTuZX.VVR.a8hZGtLUOKxmFC','03477442050','active','2026-09-27',1,100,'2026-09-25 21:30:27',NULL,NULL,NULL,NULL,NULL,0.00,NULL,NULL,NULL);
/*!40000 ALTER TABLE `clients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `global_settings`
--

DROP TABLE IF EXISTS `global_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `global_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) DEFAULT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `global_settings`
--

LOCK TABLES `global_settings` WRITE;
/*!40000 ALTER TABLE `global_settings` DISABLE KEYS */;
INSERT INTO `global_settings` VALUES (1,'app_name','SB Link Network'),(2,'timezone','Asia/Karachi'),(3,'currency','PKR');
/*!40000 ALTER TABLE `global_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nas`
--

DROP TABLE IF EXISTS `nas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `nasname` varchar(128) NOT NULL,
  `shortname` varchar(32) DEFAULT NULL,
  `type` varchar(30) DEFAULT 'other',
  `ports` int(11) DEFAULT NULL,
  `secret` varchar(60) NOT NULL,
  `server` varchar(64) DEFAULT NULL,
  `community` varchar(50) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `api_port` int(11) DEFAULT 8728,
  `api_user` varchar(50) DEFAULT NULL,
  `api_password` varchar(255) DEFAULT NULL,
  `coa_port` int(11) DEFAULT 3799,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `nas_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nas`
--

LOCK TABLES `nas` WRITE;
/*!40000 ALTER TABLE `nas` DISABLE KEYS */;
INSERT INTO `nas` VALUES (4,2,'192.168.20.1',NULL,'other',NULL,'1122',NULL,NULL,NULL,8728,'admin','1122',3799,'2026-09-25 21:46:03');
/*!40000 ALTER TABLE `nas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `package_requests`
--

DROP TABLE IF EXISTS `package_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `package_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `subscriber_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  KEY `subscriber_id` (`subscriber_id`),
  KEY `package_id` (`package_id`),
  CONSTRAINT `package_requests_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `package_requests_ibfk_2` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `package_requests_ibfk_3` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `package_requests`
--

LOCK TABLES `package_requests` WRITE;
/*!40000 ALTER TABLE `package_requests` DISABLE KEYS */;
INSERT INTO `package_requests` VALUES (8,2,102,22,'approved','2026-09-25 21:56:45','balance',NULL),(9,2,102,24,'approved','2026-09-25 22:47:49','balance',NULL);
/*!40000 ALTER TABLE `package_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `packages`
--

DROP TABLE IF EXISTS `packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `rate_limit` varchar(50) NOT NULL,
  `validity_days` int(11) NOT NULL DEFAULT 30,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `data_limit_gb` int(11) DEFAULT 0 COMMENT '0 means unlimited',
  `speed_scheduler` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `packages_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `packages`
--

LOCK TABLES `packages` WRITE;
/*!40000 ALTER TABLE `packages` DISABLE KEYS */;
INSERT INTO `packages` VALUES (22,2,'1Mbps','No Limit',30,0.00,0,NULL),(23,2,'2Mbps','No Limit',30,0.00,0,NULL),(24,2,'3Mbps','No Limit',30,0.00,0,NULL),(25,2,'4Mbps','No Limit',30,0.00,0,NULL),(26,2,'5Mbps','No Limit',30,0.00,0,NULL),(27,2,'6Mbps','No Limit',30,0.00,0,NULL),(28,2,'7Mbps','No Limit',30,0.00,0,NULL),(29,2,'8Mbps','No Limit',30,0.00,0,NULL),(30,2,'9Mbps','No Limit',30,0.00,0,NULL),(31,2,'10Mbps','No Limit',30,0.00,0,NULL),(32,2,'12Mbps','No Limit',30,0.00,0,NULL),(33,2,'15Mbps','No Limit',30,0.00,0,NULL),(34,2,'20Mbps','No Limit',30,0.00,0,NULL),(35,2,'25Mbps','No Limit',30,0.00,0,NULL),(36,2,'30Mbps','No Limit',30,0.00,0,NULL),(37,2,'40Mbps','No Limit',30,0.00,0,NULL),(38,2,'50Mbps','No Limit',30,0.00,0,NULL),(39,2,'80Mbps','No Limit',30,0.00,0,NULL),(40,2,'100Mbps','No Limit',30,0.00,0,NULL),(41,2,'200Mbps','No Limit',30,0.00,0,NULL),(42,2,'500Mbps','No Limit',30,0.00,0,NULL),(43,2,'1MB Package','1m/1m',30,0.00,0,NULL),(44,2,'10mb Package','10m/10m',30,0.00,0,NULL),(45,2,'open package','No Limit',30,0.00,0,NULL);
/*!40000 ALTER TABLE `packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `radacct`
--

DROP TABLE IF EXISTS `radacct`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `radacct` (
  `radacctid` bigint(21) NOT NULL AUTO_INCREMENT,
  `acctsessionid` varchar(64) NOT NULL DEFAULT '',
  `acctuniqueid` varchar(32) NOT NULL DEFAULT '',
  `username` varchar(64) NOT NULL DEFAULT '',
  `groupname` varchar(64) NOT NULL DEFAULT '',
  `realm` varchar(64) DEFAULT '',
  `nasipaddress` varchar(15) NOT NULL DEFAULT '',
  `nasportid` varchar(32) DEFAULT NULL,
  `nasporttype` varchar(32) DEFAULT NULL,
  `acctstarttime` datetime DEFAULT NULL,
  `acctupdatetime` datetime DEFAULT NULL,
  `acctstoptime` datetime DEFAULT NULL,
  `acctinterval` int(12) DEFAULT NULL,
  `acctsessiontime` int(12) unsigned DEFAULT NULL,
  `acctauthentic` varchar(32) DEFAULT NULL,
  `connectinfo_start` varchar(128) DEFAULT NULL,
  `connectinfo_stop` varchar(128) DEFAULT NULL,
  `acctinputoctets` bigint(20) DEFAULT NULL,
  `acctoutputoctets` bigint(20) DEFAULT NULL,
  `calledstationid` varchar(50) NOT NULL DEFAULT '',
  `callingstationid` varchar(50) NOT NULL DEFAULT '',
  `acctterminatecause` varchar(32) NOT NULL DEFAULT '',
  `servicetype` varchar(32) DEFAULT NULL,
  `framedprotocol` varchar(32) DEFAULT NULL,
  `framedipaddress` varchar(15) NOT NULL DEFAULT '',
  `acctstartdelay` int(12) DEFAULT NULL,
  `acctstopdelay` int(12) DEFAULT NULL,
  `xascendsessionsvrkey` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`radacctid`),
  KEY `username` (`username`),
  KEY `framedipaddress` (`framedipaddress`),
  KEY `acctsessionid` (`acctsessionid`),
  KEY `acctsessiontime` (`acctsessiontime`),
  KEY `acctstarttime` (`acctstarttime`),
  KEY `acctstoptime` (`acctstoptime`),
  KEY `nasipaddress` (`nasipaddress`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `radacct`
--

LOCK TABLES `radacct` WRITE;
/*!40000 ALTER TABLE `radacct` DISABLE KEYS */;
/*!40000 ALTER TABLE `radacct` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `radcheck`
--

DROP TABLE IF EXISTS `radcheck`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `radcheck` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '',
  `attribute` varchar(64) NOT NULL DEFAULT '',
  `op` char(2) NOT NULL DEFAULT '==',
  `value` varchar(253) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `username` (`username`(32))
) ENGINE=InnoDB AUTO_INCREMENT=1148 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `radcheck`
--

LOCK TABLES `radcheck` WRITE;
/*!40000 ALTER TABLE `radcheck` DISABLE KEYS */;
INSERT INTO `radcheck` VALUES (1,'ibrargrid','Cleartext-Password',':=','1234'),(2,'D Adrees','Cleartext-Password',':=','1234'),(3,'D Adrees','Auth-Type',':=','Reject'),(4,'D Jaabi','Cleartext-Password',':=','1234'),(5,'ublp','Cleartext-Password',':=','1234'),(6,'ublp','Auth-Type',':=','Reject'),(7,'AHMED IRFAN','Cleartext-Password',':=','1234'),(8,'ahsanyd','Cleartext-Password',':=','1234'),(9,'ahsanyd','Auth-Type',':=','Reject'),(10,'waqarbhai','Cleartext-Password',':=','1234'),(12,'jabirshah','Cleartext-Password',':=','1234'),(14,'rodioffice','Cleartext-Password',':=','1234'),(16,'kfz','Cleartext-Password',':=','1234'),(18,'rodimudasirkhan','Cleartext-Password',':=','1234'),(20,'4','Cleartext-Password',':=','1234'),(21,'03455835039','Cleartext-Password',':=','1234'),(22,'sohailrana16','Cleartext-Password',':=','1234'),(24,'kashi','Cleartext-Password',':=','1234'),(26,'fadimalik','Cleartext-Password',':=','1234'),(27,'babloo','Cleartext-Password',':=','1234'),(29,'chachaqurban','Cleartext-Password',':=','1234'),(31,'3','Cleartext-Password',':=','1234'),(32,'khalidep','Cleartext-Password',':=','1234'),(34,'jaafirshaikh','Cleartext-Password',':=','1234'),(36,'cctv','Cleartext-Password',':=','1234'),(37,'saddamfaisal','Cleartext-Password',':=','1234'),(39,'irfanptcl','Cleartext-Password',':=','1234'),(41,'babloocctv','Cleartext-Password',':=','1234'),(42,'bopp','Cleartext-Password',':=','1234'),(44,'javedkhan','Cleartext-Password',':=','1234'),(46,'doctorshaukat','Cleartext-Password',':=','1234'),(48,'ranafaizan','Cleartext-Password',':=','1234'),(50,'ublps','Cleartext-Password',':=','1234'),(52,'6542060301','Cleartext-Password',':=','1234'),(53,'sooba','Cleartext-Password',':=','1234'),(55,'shedoo','Cleartext-Password',':=','1234'),(57,'jangeerharya','Cleartext-Password',':=','1234'),(59,'33','Cleartext-Password',':=','1234'),(60,'waqarmobile','Cleartext-Password',':=','1234'),(62,'Naveed','Cleartext-Password',':=','1234'),(64,'saleemb','Cleartext-Password',':=','1234'),(65,'Office','Cleartext-Password',':=','1234'),(66,'sabiraraen','Cleartext-Password',':=','1234'),(68,'041','Cleartext-Password',':=','1234'),(69,'bilalmalana','Cleartext-Password',':=','1234'),(71,'babu','Cleartext-Password',':=','1234'),(73,'ishfaqahmed','Cleartext-Password',':=','1234'),(75,'azeemdp','Cleartext-Password',':=','1234'),(77,'siyamkhanniazi','Cleartext-Password',':=','1234'),(79,'monamobile','Cleartext-Password',':=','1234'),(81,'aligari','Cleartext-Password',':=','1234'),(83,'tayyab18','Cleartext-Password',':=','1234'),(85,'gmk','Cleartext-Password',':=','1234'),(87,'alimandimlvr','Cleartext-Password',':=','1234'),(89,'huzaifasheikh','Cleartext-Password',':=','1234'),(91,'mansoorgc','Cleartext-Password',':=','1234'),(93,'ilyasniazi','Cleartext-Password',':=','1234'),(95,'amms','Cleartext-Password',':=','1234'),(97,'nadeemkhan','Cleartext-Password',':=','1234'),(99,'irfanmobileshop','Cleartext-Password',':=','1234'),(101,'03015663745','Cleartext-Password',':=','12345678@'),(102,'1919','Cleartext-Password',':=','1234'),(103,'saleemgolchok','Cleartext-Password',':=','1234'),(105,'waseemthal','Cleartext-Password',':=','1234'),(107,'fahad','Cleartext-Password',':=','7867'),(109,'usmanbw','Cleartext-Password',':=','1234'),(111,'sos','Cleartext-Password',':=','1234'),(112,'ggs16','Cleartext-Password',':=','1234'),(114,'alirana18','Cleartext-Password',':=','1234'),(116,'naeemshaikh','Cleartext-Password',':=','1234'),(118,'ameerhamza1','Cleartext-Password',':=','1234'),(119,'ameerhamza2','Cleartext-Password',':=','1234'),(120,'Home','Cleartext-Password',':=','1234'),(121,'manakatous','Cleartext-Password',':=','1234'),(123,'abdhome','Cleartext-Password',':=','1234'),(124,'telenor','Cleartext-Password',':=','1234'),(125,'khurramshehzadgc','Cleartext-Password',':=','1234'),(126,'sherdiljall','Cleartext-Password',':=','1234'),(127,'malikadnan16','Cleartext-Password',':=','1234'),(971,'','Expiration',':=','01 Jan 1970 01:00:00'),(972,'waseemmasat','Cleartext-Password',':=','1234'),(1043,'malikadnan16','Expiration',':=','01 Oct 2026 12:00:00'),(1044,'sherdiljall','Expiration',':=','02 Oct 2026 12:00:00'),(1045,'khurramshehzadgc','Expiration',':=','04 Oct 2026 12:00:00'),(1046,'telenor','Expiration',':=','02 Oct 2026 12:00:00'),(1047,'abdhome','Expiration',':=','04 Oct 2026 12:00:00'),(1048,'manakatous','Expiration',':=','09 Oct 2026 12:00:00'),(1049,'Home','Expiration',':=','03 Oct 2026 12:00:00'),(1050,'ameerhamza2','Expiration',':=','06 Oct 2026 12:00:00'),(1051,'ameerhamza1','Expiration',':=','05 Oct 2026 12:00:00'),(1052,'naeemshaikh','Expiration',':=','01 Oct 2026 12:00:00'),(1053,'alirana18','Expiration',':=','30 Sep 2026 12:00:00'),(1054,'ggs16','Expiration',':=','01 Oct 2026 12:00:00'),(1055,'sos','Expiration',':=','01 Oct 2026 12:00:00'),(1056,'usmanbw','Expiration',':=','01 Oct 2026 12:00:00'),(1057,'fahad','Expiration',':=','01 Oct 2026 12:00:00'),(1058,'waseemthal','Expiration',':=','04 Oct 2026 12:00:00'),(1059,'saleemgolchok','Expiration',':=','04 Oct 2026 12:00:00'),(1060,'1919','Expiration',':=','06 Oct 2026 12:00:00'),(1061,'3015663745','Expiration',':=','01 Oct 2026 12:00:00'),(1062,'irfanmobileshop','Expiration',':=','30 Sep 2026 12:00:00'),(1063,'nadeemkhan','Expiration',':=','03 Oct 2026 12:00:00'),(1064,'amms','Expiration',':=','01 Oct 2026 12:00:00'),(1065,'ilyasniazi','Expiration',':=','04 Oct 2026 12:00:00'),(1066,'mansoorgc','Expiration',':=','01 Oct 2026 12:00:00'),(1067,'huzaifasheikh','Expiration',':=','02 Oct 2026 12:00:00'),(1068,'alimandimlvr','Expiration',':=','05 Oct 2026 12:00:00'),(1069,'gmk','Expiration',':=','01 Oct 2026 12:00:00'),(1070,'tayyab18','Expiration',':=','05 Oct 2026 12:00:00'),(1071,'aligari','Expiration',':=','30 Sep 2026 12:00:00'),(1072,'monamobile','Expiration',':=','04 Oct 2026 12:00:00'),(1073,'siyamkhanniazi','Expiration',':=','30 Sep 2026 12:00:00'),(1074,'azeemdp','Expiration',':=','30 Sep 2026 12:00:00'),(1075,'ishfaqahmed','Expiration',':=','05 Oct 2026 12:00:00'),(1076,'babu','Expiration',':=','01 Oct 2026 12:00:00'),(1077,'bilalmalana','Expiration',':=','01 Oct 2026 12:00:00'),(1078,'41','Expiration',':=','05 Oct 2026 12:00:00'),(1079,'sabiraraen','Expiration',':=','18 Oct 2026 12:00:00'),(1080,'Office','Expiration',':=','01 Oct 2026 12:00:00'),(1081,'saleemb','Expiration',':=','01 Oct 2026 12:00:00'),(1082,'Naveed','Expiration',':=','03 Oct 2026 12:00:00'),(1083,'waqarmobile','Expiration',':=','01 Oct 2026 12:00:00'),(1084,'33','Expiration',':=','03 Oct 2026 12:00:00'),(1085,'jangeerharya','Expiration',':=','01 Oct 2026 12:00:00'),(1086,'shedoo','Expiration',':=','05 Oct 2026 12:00:00'),(1087,'sooba','Expiration',':=','01 Oct 2026 12:00:00'),(1088,'6542060301','Expiration',':=','02 Oct 2026 12:00:00'),(1089,'ublps','Expiration',':=','21 Oct 2026 12:00:00'),(1090,'ranafaizan','Expiration',':=','04 Oct 2026 12:00:00'),(1091,'doctorshaukat','Expiration',':=','01 Oct 2026 12:00:00'),(1092,'javedkhan','Expiration',':=','04 Oct 2026 12:00:00'),(1093,'bopp','Expiration',':=','01 Oct 2026 12:00:00'),(1094,'babloocctv','Expiration',':=','19 Oct 2026 12:00:00'),(1095,'irfanptcl','Expiration',':=','01 Oct 2026 12:00:00'),(1096,'saddamfaisal','Expiration',':=','01 Oct 2026 12:00:00'),(1097,'cctv','Expiration',':=','03 Oct 2026 12:00:00'),(1098,'jaafirshaikh','Expiration',':=','03 Oct 2026 12:00:00'),(1099,'khalidep','Expiration',':=','30 Sep 2026 12:00:00'),(1100,'3','Expiration',':=','02 Oct 2026 12:00:00'),(1101,'chachaqurban','Expiration',':=','01 Oct 2026 12:00:00'),(1102,'babloo','Expiration',':=','02 Oct 2026 12:00:00'),(1103,'fadimalik','Expiration',':=','24 Oct 2026 12:00:00'),(1104,'kashi','Expiration',':=','30 Sep 2026 12:00:00'),(1105,'sohailrana16','Expiration',':=','02 Oct 2026 12:00:00'),(1106,'3455835039','Expiration',':=','02 Oct 2026 12:00:00'),(1107,'4','Expiration',':=','01 Oct 2026 12:00:00'),(1108,'rodimudasirkhan','Expiration',':=','01 Oct 2026 12:00:00'),(1109,'kfz','Expiration',':=','22 Oct 2026 12:00:00'),(1110,'rodioffice','Expiration',':=','22 Oct 2026 12:00:00'),(1111,'jabirshah','Expiration',':=','04 Oct 2026 12:00:00'),(1112,'waqarbhai','Expiration',':=','02 Oct 2026 12:00:00'),(1113,'wasi','Cleartext-Password',':=','1122'),(1117,'wasi','Expiration',':=','23 Apr 2027 01:00:00'),(1118,'majidkhan','Cleartext-Password',':=','1234'),(1119,'nomansaeed','Cleartext-Password',':=','1234'),(1120,'qaismughal','Cleartext-Password',':=','1234'),(1121,'adu','Cleartext-Password',':=','1234'),(1122,'akramsabir','Cleartext-Password',':=','1234'),(1123,'shahidfarooqhr','Cleartext-Password',':=','1234'),(1124,'imranch','Cleartext-Password',':=','1234'),(1125,'umarp','Cleartext-Password',':=','1234'),(1126,'shafqatjall','Cleartext-Password',':=','1234'),(1127,'iqrargolchok','Cleartext-Password',':=','1234'),(1128,'yasirgolchok','Cleartext-Password',':=','1234'),(1129,'saifrokhri','Cleartext-Password',':=','1234'),(1130,'asadad','Cleartext-Password',':=','1234'),(1131,'asadad','Auth-Type',':=','Reject'),(1132,'drashraf','Cleartext-Password',':=','1234'),(1133,'lcs','Cleartext-Password',':=','1234'),(1134,'default-trial','Cleartext-Password',':=',''),(1135,'laptop','Cleartext-Password',':=','1122'),(1136,'a55','Cleartext-Password',':=','1122'),(1137,'test','Cleartext-Password',':=','1122'),(1138,'chr user','Cleartext-Password',':=','1122'),(1139,'user1','Cleartext-Password',':=',''),(1141,'vivo','Cleartext-Password',':=','1122'),(1142,'rrr','Cleartext-Password',':=','rrr'),(1144,'vivo','Expiration',':=','26 Sep 2026 22:54:00'),(1145,'user1','Expiration',':=','27 Sep 2026 22:54:00'),(1147,'rrr','Expiration',':=','24 Nov 2026 22:56:45');
/*!40000 ALTER TABLE `radcheck` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `radreply`
--

DROP TABLE IF EXISTS `radreply`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `radreply` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '',
  `attribute` varchar(64) NOT NULL DEFAULT '',
  `op` char(2) NOT NULL DEFAULT '=',
  `value` varchar(253) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `username` (`username`(32))
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `radreply`
--

LOCK TABLES `radreply` WRITE;
/*!40000 ALTER TABLE `radreply` DISABLE KEYS */;
INSERT INTO `radreply` VALUES (1,'D Adrees','Mikrotik-Rate-Limit','=','6M/6M'),(2,'D Jaabi','Mikrotik-Rate-Limit','=','8M/8M'),(3,'ublp','Mikrotik-Rate-Limit','=','2M/2M'),(4,'AHMED IRFAN','Mikrotik-Rate-Limit','=','4M/4M'),(5,'ahsanyd','Mikrotik-Rate-Limit','=','100M/100M'),(6,'jabirshah','Mikrotik-Rate-Limit','=','6M/6M'),(7,'rodioffice','Mikrotik-Rate-Limit','=','5M/5M'),(8,'kfz','Mikrotik-Rate-Limit','=','8M/8M'),(9,'rodimudasirkhan','Mikrotik-Rate-Limit','=','5M/5M'),(10,'4','Mikrotik-Rate-Limit','=','30M/30M'),(11,'03455835039','Mikrotik-Rate-Limit','=','15M/15M'),(12,'sohailrana16','Mikrotik-Rate-Limit','=','5M/5M'),(13,'kashi','Mikrotik-Rate-Limit','=','3M/3M'),(14,'fadimalik','Mikrotik-Rate-Limit','=','7M/7M'),(15,'babloo','Mikrotik-Rate-Limit','=','8M/8M'),(16,'chachaqurban','Mikrotik-Rate-Limit','=','5M/5M'),(17,'3','Mikrotik-Rate-Limit','=','4M/4M'),(18,'khalidep','Mikrotik-Rate-Limit','=','8M/8M'),(19,'jaafirshaikh','Mikrotik-Rate-Limit','=','4M/4M'),(20,'cctv','Mikrotik-Rate-Limit','=','25M/25M'),(21,'saddamfaisal','Mikrotik-Rate-Limit','=','80M/80M'),(22,'irfanptcl','Mikrotik-Rate-Limit','=','8M/8M'),(23,'babloocctv','Mikrotik-Rate-Limit','=','20M/20M'),(24,'bopp','Mikrotik-Rate-Limit','=','5M/5M'),(25,'javedkhan','Mikrotik-Rate-Limit','=','10M/10M'),(26,'doctorshaukat','Mikrotik-Rate-Limit','=','10M/10M'),(27,'ranafaizan','Mikrotik-Rate-Limit','=','3M/3M'),(28,'ublps','Mikrotik-Rate-Limit','=','4M/4M'),(29,'6542060301','Mikrotik-Rate-Limit','=','50M/50M'),(30,'sooba','Mikrotik-Rate-Limit','=','4M/4M'),(31,'shedoo','Mikrotik-Rate-Limit','=','4M/4M'),(32,'jangeerharya','Mikrotik-Rate-Limit','=','10M/10M'),(33,'33','Mikrotik-Rate-Limit','=','7M/7M'),(34,'waqarmobile','Mikrotik-Rate-Limit','=','30M/30M'),(35,'Naveed','Mikrotik-Rate-Limit','=','7M/7M'),(36,'saleemb','Mikrotik-Rate-Limit','=','8M/8M'),(37,'Office','Mikrotik-Rate-Limit','=','10M/10M'),(38,'sabiraraen','Mikrotik-Rate-Limit','=','4M/4M'),(39,'041','Mikrotik-Rate-Limit','=','8M/8M'),(40,'bilalmalana','Mikrotik-Rate-Limit','=','4M/4M'),(41,'babu','Mikrotik-Rate-Limit','=','8M/8M'),(42,'ishfaqahmed','Mikrotik-Rate-Limit','=','8M/8M'),(43,'azeemdp','Mikrotik-Rate-Limit','=','8M/8M'),(44,'siyamkhanniazi','Mikrotik-Rate-Limit','=','8M/8M'),(45,'monamobile','Mikrotik-Rate-Limit','=','8M/8M'),(46,'aligari','Mikrotik-Rate-Limit','=','8M/8M'),(47,'tayyab18','Mikrotik-Rate-Limit','=','8M/8M'),(48,'gmk','Mikrotik-Rate-Limit','=','20M/20M'),(49,'alimandimlvr','Mikrotik-Rate-Limit','=','7M/7M'),(50,'huzaifasheikh','Mikrotik-Rate-Limit','=','10M/10M'),(51,'mansoorgc','Mikrotik-Rate-Limit','=','4M/4M'),(52,'ilyasniazi','Mikrotik-Rate-Limit','=','4M/4M'),(53,'amms','Mikrotik-Rate-Limit','=','10M/10M'),(54,'nadeemkhan','Mikrotik-Rate-Limit','=','10M/10M'),(55,'irfanmobileshop','Mikrotik-Rate-Limit','=','20M/20M'),(56,'03015663745','Mikrotik-Rate-Limit','=','20M/20M'),(57,'1919','Mikrotik-Rate-Limit','=','8M/8M'),(58,'saleemgolchok','Mikrotik-Rate-Limit','=','8M/8M'),(59,'waseemthal','Mikrotik-Rate-Limit','=','20M/20M'),(61,'usmanbw','Mikrotik-Rate-Limit','=','15M/15M'),(62,'sos','Mikrotik-Rate-Limit','=','30M/30M'),(63,'ggs16','Mikrotik-Rate-Limit','=','7M/7M'),(64,'alirana18','Mikrotik-Rate-Limit','=','4M/4M'),(65,'naeemshaikh','Mikrotik-Rate-Limit','=','7M/7M'),(66,'ameerhamza1','Mikrotik-Rate-Limit','=','15M/15M'),(67,'ameerhamza2','Mikrotik-Rate-Limit','=','15M/15M'),(68,'Home','Mikrotik-Rate-Limit','=','30M/30M'),(69,'manakatous','Mikrotik-Rate-Limit','=','3M/3M'),(72,'telenor','Mikrotik-Rate-Limit','=','6M/6M'),(75,'malikadnan16','Mikrotik-Rate-Limit','=','10M/10M'),(76,'sherdiljall','Mikrotik-Rate-Limit','=','20M/20M'),(77,'fahad','Mikrotik-Rate-Limit','=','15M/15M'),(78,'abdhome','Mikrotik-Rate-Limit','=','100M/100M'),(79,'waseemmasat','Mikrotik-Rate-Limit','=','1M/1M'),(81,'wasi','Mikrotik-Rate-Limit','=','1M/1M'),(82,'test','Mikrotik-Rate-Limit','=','1m/1m'),(83,'chr user','Mikrotik-Rate-Limit','=','10m/10m'),(86,'vivo','Mikrotik-Rate-Limit','=','10m/10m'),(87,'user1','Mikrotik-Rate-Limit','=','10m/10m');
/*!40000 ALTER TABLE `radreply` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscribers`
--

DROP TABLE IF EXISTS `subscribers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `package_id` int(11) DEFAULT NULL,
  `username` varchar(64) NOT NULL,
  `password` varchar(64) NOT NULL,
  `service_type` varchar(30) DEFAULT 'pppoe',
  `status` varchar(20) DEFAULT 'active',
  `expiry_date` datetime DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `full_name` varchar(100) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `subarea` varchar(100) DEFAULT NULL,
  `latitude` varchar(50) DEFAULT NULL,
  `longitude` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `client_id` (`client_id`),
  KEY `package_id` (`package_id`),
  CONSTRAINT `subscribers_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscribers_ibfk_2` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscribers`
--

LOCK TABLES `subscribers` WRITE;
/*!40000 ALTER TABLE `subscribers` DISABLE KEYS */;
INSERT INTO `subscribers` VALUES (80,2,27,'majidkhan','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(81,2,31,'nomansaeed','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(82,2,25,'qaismughal','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(83,2,27,'adu','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(84,2,31,'akramsabir','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(85,2,24,'shahidfarooqhr','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(86,2,24,'imranch','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(87,2,31,'umarp','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(88,2,27,'shafqatjall','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(89,2,25,'iqrargolchok','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(90,2,25,'yasirgolchok','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(91,2,29,'saifrokhri','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(92,2,25,'asadad','1234','pppoe','disabled',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(93,2,25,'drashraf','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(94,2,25,'lcs','1234','pppoe','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:46:06'),(95,2,NULL,'default-trial','','hotspot','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(96,2,45,'laptop','1122','hotspot','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(97,2,45,'a55','1122','hotspot','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(98,2,43,'test','1122','hotspot','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(99,2,44,'chr user','1122','hotspot','active',NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(100,2,44,'user1','','hotspot','active','2026-09-27 22:54:00',NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(101,2,44,'vivo','1122','hotspot','active','2026-09-26 22:54:00',NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-25 21:50:22'),(102,2,24,'rrr','rrr','hotspot','active','2026-11-24 22:56:45','',0.00,'Rosca Superstore','','','','','','','',NULL,NULL,'2026-09-25 21:50:55');
/*!40000 ALTER TABLE `subscribers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `super_admins`
--

DROP TABLE IF EXISTS `super_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `super_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `super_admins`
--

LOCK TABLES `super_admins` WRITE;
/*!40000 ALTER TABLE `super_admins` DISABLE KEYS */;
INSERT INTO `super_admins` VALUES (1,'admin','admin@sblink.local','$2y$10$AvsBZceYBoEb0YQwl94xM.A2zEEomsxMptLMY6xZXRWOq/K..Z5gy','Super Admin','2026-09-25 17:35:45');
/*!40000 ALTER TABLE `super_admins` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-26  0:32:01
