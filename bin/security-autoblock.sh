#!/usr/bin/env bash

set -euo pipefail

###############################################################################
# Apache 404 auto blocker
#
# Scans Apache access logs for IP addresses that generate too many 404 errors.
# If an IP address has more than THRESHOLD 404 responses, it is blocked for
# BAN_HOURS days using ufw or iptables.
###############################################################################



# Mode of the script:
# report - only publish ip's with too many 404's
# block  - block ip's with too many 404
#
MODE="report"

# override default block mode by argument
#
if [[ "${1:-}" == "--report" ]]; then
    MODE="report"
elif [[ "${1:-}" == "--block" ]]; then
    MODE="block"
fi

# number of errors before triggering an block/report action
THRESHOLD=50

# number of day's an ip is blocked.
BAN_HOURS=96

LOG_FILES=(
    "/var/log/apache2/access.log"
    "/var/log/apache2/docs-cocos-software.access.log"
    "/var/log/apache2/wiki-cocos-software.access.log"
    "/var/log/apache2/other_vhosts_access.log"
)

STATE_FILE="/tmp/apache-404-autoblock/blocked_ips.tsv"
LOCK_FILE="/run/apache-404-autoblock.lock"

# Add trusted IP addresses here. These will never be blocked.
WHITELIST=(
    "127.0.0.1"
    "77.164.192.77"
    "45.81.52.1"
    "::1"
    # "YOUR.OFFICE.IP.HERE"
)

mkdir -p "$(dirname "$STATE_FILE")"

###############################################################################
# Helpers
###############################################################################

now_epoch() {
    date +%s
}

ban_until_epoch() {
    date -d "+${BAN_HOURS} hours" +%s
}

is_whitelisted() {
    local ip="$1"

    for allowed in "${WHITELIST[@]}"; do
        if [[ "$ip" == "$allowed" ]]; then
            return 0
        fi
    done

    return 1
}

is_valid_ip() {
    local ip="$1"

    # Basic IPv4 validation.
    if [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        local IFS='.'
        read -r a b c d <<< "$ip"

        for octet in "$a" "$b" "$c" "$d"; do
            if (( octet < 0 || octet > 255 )); then
                return 1
            fi
        done

        return 0
    fi

    # Basic IPv6 validation.
    if [[ "$ip" =~ ^[0-9a-fA-F:]+$ && "$ip" == *:* ]]; then
        return 0
    fi

    return 1
}

firewall_backend() {
    if command -v ufw >/dev/null 2>&1; then
        echo "ufw"
        return
    fi

    if command -v iptables >/dev/null 2>&1; then
        echo "iptables"
        return
    fi

    echo "none"
}

is_already_blocked() {
    local ip="$1"

    if [[ -f "$STATE_FILE" ]]; then
        awk -F '\t' -v ip="$ip" '$1 == ip { found = 1 } END { exit !found }' "$STATE_FILE"
        return
    fi

    return 1
}

record_block() {
    local ip="$1"
    local until="$2"
    local reason="$3"

    touch "$STATE_FILE"

    # Remove old record for this IP if it exists.
    awk -F '\t' -v ip="$ip" '$1 != ip' "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"

    printf '%s\t%s\t%s\n' "$ip" "$until" "$reason" >> "$STATE_FILE"
}

block_ip() {
    local ip="$1"
    local backend="$2"

    case "$backend" in
        ufw)
            ufw deny from "$ip" comment "auto-block apache 404 flood"
            ;;
        iptables)
            iptables -C INPUT -s "$ip" -j DROP 2>/dev/null || \
                iptables -I INPUT -s "$ip" -j DROP
            ;;
        *)
            echo "No supported firewall backend found. Cannot block $ip." >&2
            return 1
            ;;
    esac
}

unblock_ip() {
    local ip="$1"
    local backend="$2"

    case "$backend" in
        ufw)
            # UFW delete syntax must match the original rule.
            ufw delete deny from "$ip" || true
            ;;
        iptables)
            while iptables -C INPUT -s "$ip" -j DROP 2>/dev/null; do
                iptables -D INPUT -s "$ip" -j DROP || true
            done
            ;;
    esac
}

expire_old_blocks() {
    local backend="$1"
    local current_time
    current_time="$(now_epoch)"

    [[ -f "$STATE_FILE" ]] || return 0

    : > "${STATE_FILE}.tmp"

    while IFS=$'\t' read -r ip until reason; do
        [[ -n "${ip:-}" ]] || continue

        if [[ "$until" =~ ^[0-9]+$ ]] && (( until <= current_time )); then
            echo "Unblocking expired IP: $ip"
            unblock_ip "$ip" "$backend"
        else
            printf '%s\t%s\t%s\n' "$ip" "$until" "$reason" >> "${STATE_FILE}.tmp"
        fi
    done < "$STATE_FILE"

    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

scan_404_ips() {
    local files=()

    for file in "${LOG_FILES[@]}"; do
        if [[ -f "$file" ]]; then
            files+=("$file")
        fi
    done

    if (( ${#files[@]} == 0 )); then
        echo "No Apache access log files found." >&2
        return 0
    fi

    # Apache combined log format:
    # IP - - [date] "METHOD URL HTTP/x.x" STATUS SIZE ...
    #
    # This counts only rows where HTTP status is exactly 404.
    awk '
        $9 == 404 {
            count[$1]++
        }
        END {
            for (ip in count) {
                print ip, count[ip]
            }
        }
    ' "${files[@]}"
}

###############################################################################
# Main
###############################################################################

if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must be run as root." >&2
    exit 1
fi

exec 9>"$LOCK_FILE"

if ! flock -n 9; then
    echo "Another instance is already running."
    exit 0
fi

BACKEND="$(firewall_backend)"

if [[ "$BACKEND" == "none" ]]; then
    echo "Neither ufw nor iptables was found." >&2
    exit 1
fi

if [[ "$MODE" == "block" ]]; then
    expire_old_blocks "$BACKEND"
fi

while read -r ip count; do
    [[ -n "${ip:-}" ]] || continue
    [[ -n "${count:-}" ]] || continue

    if ! is_valid_ip "$ip"; then
        continue
    fi

    if is_whitelisted "$ip"; then
        continue
    fi

    if (( count > THRESHOLD )); then

        if is_already_blocked "$ip"; then
            continue
        fi

        until="$(ban_until_epoch)"
        reason="More than ${THRESHOLD} Apache 404 errors: ${count}"

	if [[ "$MODE" == "report" ]]; then
	    echo "REPORT: $ip would be blocked for ${BAN_HOURS} hours. Reason: $reason"
	elif [[ "$MODE" == "block" ]]; then
	    echo "Blocking $ip for ${BAN_HOURS} days. Reason: $reason"
	    block_ip "$ip" "$BACKEND"
	    record_block "$ip" "$until" "$reason"
	else
	    echo "Invalid MODE: $MODE. Use MODE=\"report\" or MODE=\"block\"." >&2
	    exit 1
	fi

    fi
done < <(scan_404_ips)
