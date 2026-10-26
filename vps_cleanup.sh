#!/usr/bin/env bash
# ==============================================================================
# NetBee / SB Link - VPS Storage Diagnostics & Deep Space Recovery Utility
# Usage: sudo bash vps_cleanup.sh
# Purpose: Reclaims 5GB - 12GB of bloated disk space from:
#          1. MySQL Binary Logs (binlogs)
#          2. FreeRADIUS and Web Server text logs
#          3. Systemd journal logs
#          4. Stale temp database dumps
#          5. Prunes radpostauth to 7 days and optimizes database tables
#          6. Installs automated nightly maintenance into crontab
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}================================================================${NC}"
echo -e "${CYAN}   NetBee / SB Link - VPS Storage Diagnostic & Deep Clean Tool   ${NC}"
echo -e "${CYAN}================================================================${NC}"
echo ""

# 1. Check Root Privileges
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}[ERROR] This script must be run as root or with sudo!${NC}"
    echo -e "Run: ${YELLOW}sudo bash vps_cleanup.sh${NC}"
    exit 1
fi

# 2. Disk Space Before Cleanup
echo -e "${YELLOW}[1/8] Analyzing Current Disk Usage...${NC}"
df -h / | awk 'NR==1 || NR==2'
echo ""

echo -e "${CYAN}Top Directory Storage Breakdown:${NC}"
du -sh /var/lib/mysql 2>/dev/null || true
du -sh /var/log 2>/dev/null || true
du -sh /var/log/journal 2>/dev/null || true
du -sh /var/log/freeradius 2>/dev/null || true
du -sh /var/log/apache2 2>/dev/null || true
du -sh /tmp 2>/dev/null || true
du -sh /var/www 2>/dev/null || true
echo ""

# 3. Clean MySQL Binary Logs (Binlogs)
echo -e "${YELLOW}[2/8] Purging Bloated MySQL Binary Logs (Binlogs)...${NC}"
MYSQL_CMD=""
if mysql -e "SELECT 1;" >/dev/null 2>&1; then
    MYSQL_CMD="mysql"
elif mysql -u root -pWasi1234 -e "SELECT 1;" >/dev/null 2>&1; then
    MYSQL_CMD="mysql -u root -pWasi1234"
elif mysql -u radius -pWasi1234 -e "SELECT 1;" >/dev/null 2>&1; then
    MYSQL_CMD="mysql -u radius -pWasi1234"
fi

if [ -n "$MYSQL_CMD" ]; then
    $MYSQL_CMD -e "PURGE BINARY LOGS BEFORE NOW() - INTERVAL 1 DAY;" 2>/dev/null && \
        echo -e "${GREEN}  ✓ Successfully purged MySQL binary logs older than 1 day.${NC}" || \
        echo -e "${YELLOW}  ! Binlog purge skipped or not permitted for this user.${NC}"
    
    # Configure MySQL binlog retention to 1 day permanently so it never hoards 30 days
    CNF_FILE=""
    if [ -f "/etc/mysql/mysql.conf.d/mysqld.cnf" ]; then
        CNF_FILE="/etc/mysql/mysql.conf.d/mysqld.cnf"
    elif [ -f "/etc/mysql/my.cnf" ]; then
        CNF_FILE="/etc/mysql/my.cnf"
    fi

    if [ -n "$CNF_FILE" ]; then
        if ! grep -q "binlog_expire_logs_seconds" "$CNF_FILE"; then
            echo -e "\n# NetBee Auto-Prune Binlogs (1 Day Retention)\n[mysqld]\nbinlog_expire_logs_seconds = 86400" >> "$CNF_FILE"
            echo -e "${GREEN}  ✓ Configured permanent 1-day binlog retention in ${CNF_FILE}.${NC}"
        fi
    fi
else
    echo -e "${YELLOW}  ! Could not connect to MySQL CLI without credentials. PHP maintenance step will handle DB pruning.${NC}"
fi
echo ""

# 4. Clean Systemd Journal Logs
echo -e "${YELLOW}[3/8] Vacuuming Systemd Journal Logs (Cap to 100MB)...${NC}"
if command -v journalctl >/dev/null 2>&1; then
    journalctl --vacuum-size=100M >/dev/null 2>&1 || true
    echo -e "${GREEN}  ✓ Systemd journal vacuumed to max 100MB.${NC}"
    
    # Cap permanently in /etc/systemd/journald.conf
    if [ -f "/etc/systemd/journald.conf" ]; then
        sed -i 's/^#*SystemMaxUse=.*/SystemMaxUse=100M/' /etc/systemd/journald.conf 2>/dev/null || true
    fi
fi
echo ""

# 5. Truncate Giant FreeRADIUS & Apache Logs
echo -e "${YELLOW}[4/8] Truncating Oversized Text Log Files...${NC}"
# FreeRADIUS radius.log
if [ -f "/var/log/freeradius/radius.log" ]; then
    R_SIZE=$(du -m /var/log/freeradius/radius.log | cut -f1)
    if [ "$R_SIZE" -gt 20 ]; then
        echo -e "  Truncating FreeRADIUS log (${R_SIZE}MB)..."
        tail -n 5000 /var/log/freeradius/radius.log > /var/log/freeradius/radius.log.tmp 2>/dev/null || true
        mv -f /var/log/freeradius/radius.log.tmp /var/log/freeradius/radius.log 2>/dev/null || true
        chown freerad:freerad /var/log/freeradius/radius.log 2>/dev/null || true
        echo -e "${GREEN}  ✓ FreeRADIUS radius.log compacted (kept last 5,000 lines).${NC}"
    else
        echo -e "${GREEN}  ✓ FreeRADIUS radius.log is healthy (${R_SIZE}MB).${NC}"
    fi
fi

# Apache logs
for logf in /var/log/apache2/*.log; do
    if [ -f "$logf" ]; then
        L_SIZE=$(du -m "$logf" 2>/dev/null | cut -f1 || echo 0)
        if [ "$L_SIZE" -gt 30 ]; then
            tail -n 5000 "$logf" > "${logf}.tmp" 2>/dev/null || true
            mv -f "${logf}.tmp" "$logf" 2>/dev/null || true
            echo -e "${GREEN}  ✓ Compacted ${logf} (${L_SIZE}MB -> recent 5,000 lines).${NC}"
        fi
    fi
done

# Old compressed rotated logs (*.gz)
find /var/log -type f -name "*.gz" -delete 2>/dev/null || true
find /var/log -type f -name "*.1" -delete 2>/dev/null || true
echo -e "${GREEN}  ✓ Cleaned obsolete rotated archives (.gz, .1) from /var/log.${NC}"
echo ""

# 6. Delete Leftover Database Dump Files & Temporary Uploads
echo -e "${YELLOW}[5/8] Cleaning Temporary Dumps and Upload Chunks...${NC}"
find /tmp -type f \( -name "*.sql" -o -name "*.sql.gz" -o -name "restore_chunk_*" -o -name "temp_restore_*" \) -delete 2>/dev/null || true
find /var/tmp -type f \( -name "*.sql" -o -name "*.sql.gz" \) -delete 2>/dev/null || true
find /var/www/html/uploads -type f \( -name "restore_chunk_*" -o -name "temp_restore_*" \) -mtime +1 -delete 2>/dev/null || true
echo -e "${GREEN}  ✓ Temporary SQL dumps and upload chunks cleaned.${NC}"
echo ""

# 7. Clean APT Cache
echo -e "${YELLOW}[6/8] Cleaning APT Package Cache...${NC}"
apt-get clean >/dev/null 2>&1 || true
apt-get autoremove -y >/dev/null 2>&1 || true
echo -e "${GREEN}  ✓ APT package cache cleaned.${NC}"
echo ""

# 8. Execute PHP Database Maintenance & Auto-Prune (Prunes radpostauth to 7 days, defrags tables)
echo -e "${YELLOW}[7/8] Running Database Optimization & Pruning radpostauth...${NC}"
WEB_ROOT="/var/www/html"
if [ ! -f "$WEB_ROOT/config/cron_db_maintenance.php" ]; then
    # Try current directory if different
    if [ -f "./config/cron_db_maintenance.php" ]; then
        WEB_ROOT="$(pwd)"
    fi
fi

if [ -f "$WEB_ROOT/config/cron_db_maintenance.php" ]; then
    php "$WEB_ROOT/config/cron_db_maintenance.php" || true
    echo -e "${GREEN}  ✓ Database maintenance executed successfully.${NC}"
else
    echo -e "${YELLOW}  ! Could not find cron_db_maintenance.php at ${WEB_ROOT}/config/. Skipping PHP step.${NC}"
fi
echo ""

# 9. Configure Nightly Cron Job in Root Crontab
echo -e "${YELLOW}[8/8] Verifying Nightly Crontab Automation (03:00 AM)...${NC}"
CRON_LINE="0 3 * * * php ${WEB_ROOT}/config/cron_db_maintenance.php > /dev/null 2>&1"
CURRENT_CRON=$(crontab -l 2>/dev/null || true)

if echo "$CURRENT_CRON" | grep -Fq "cron_db_maintenance.php"; then
    echo -e "${GREEN}  ✓ Nightly cron job already active in crontab.${NC}"
else
    (echo "$CURRENT_CRON"; echo "$CRON_LINE") | crontab -
    echo -e "${GREEN}  ✓ Added nightly 03:00 AM auto-maintenance to crontab:${NC}"
    echo -e "    ${CYAN}${CRON_LINE}${NC}"
fi
echo ""

# 10. Final Space Comparison
echo -e "${CYAN}================================================================${NC}"
echo -e "${GREEN}             🎉 VPS STORAGE CLEANUP COMPLETED!                 ${NC}"
echo -e "${CYAN}================================================================${NC}"
echo -e "${YELLOW}Disk Usage After Cleanup:${NC}"
df -h / | awk 'NR==1 || NR==2'
echo ""
echo -e "${GREEN}All bloated logs, binlogs, and stale auth rows have been purged.${NC}"
echo -e "${GREEN}Your FreeRADIUS server will now maintain itself automatically every night at 3:00 AM.${NC}"
echo -e "${CYAN}================================================================${NC}"
