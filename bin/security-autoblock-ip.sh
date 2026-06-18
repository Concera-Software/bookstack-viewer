#!/usr/bin/env bash

set -euo pipefail

###############################################################################
# Apache auto blocker
#
# Scans Apache access logs and reports or blocks IP addresses based on:
# - too many selected HTTP status codes
# - suspicious user-agent keywords
# - permanent IP block file
#
# MODE:
#   report = only report what would be blocked
#   block  = actually block using ufw or iptables
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
THRESHOLD=100

# number of day's an ip is blocked.
BAN_HOURS=120

# HTTP status codes to count.
# Add or remove codes as needed.
BLOCK_STATUS_CODES=(
    "400"
    "401"
    "403"
    "404"
    "405"
    "408"
    "429"
)

# If an IP produces more than this number of suspicious user-agent hits,
# it will be blocked.
USER_AGENT_THRESHOLD=10

# Case-insensitive words or fragments to search for in user agents.
# Keep this list specific enough to avoid blocking normal browsers.
BLOCK_USER_AGENT_WORDS=(
    "sqlmap"
    "nikto"
    "acunetix"
    "nessus"
    "masscan"
    "nmap"
    "zgrab"
    "dirbuster"
    "gobuster"
    "wpscan"
    "python-requests"
    "curl"
    "wget"
    "libwww-perl"
    "java/"
    "go-http-client"
    "httpclient"
    "scrapy"
)

LOG_FILES=(
    "/var/log/apache2/access.log"
    "/var/log/apache2/other_vhosts_access.log"
)

STATE_DIR="/var/www/bookstack-viewer/enabled/tmp"
STATE_FILE="${STATE_DIR}/temporary-blocks.tsv"
PERMANENT_BLOCK_FILE="/var/www/bookstack-viewer/enabled/app/permanent-ip-blocks.txt"
LOCK_FILE="/run/apache-404-autoblock.lock"

# Add trusted IP addresses here. These will never be blocked.
WHITELIST=(
    "127.0.0.1"
    "::1"
    # "YOUR.OFFICE.IP.HERE"
)

###############################################################################
# Command-line mode override
###############################################################################

if [[ "${1:-}" == "--report" ]]; then
    MODE="report"
elif [[ "${1:-}" == "--block" ]]; then
    MODE="block"
fi

###############################################################################
# Helpers
###############################################################################

now_epoch() {
    date +%s
}

ban_until_epoch() {
    date -d "+${BAN_HOURS} hours" +%s
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

is_valid_ip() {
    local ip="$1"

    if [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        local IFS='.'
        local a b c d
        read -r a b c d <<< "$ip"

        for octet in "$a" "$b" "$c" "$d"; do
            if (( octet < 0 || octet > 255 )); then
                return 1
            fi
        done

        return 0
    fi

    if [[ "$ip" =~ ^[0-9a-fA-F:]+$ && "$ip" == *:* ]]; then
        return 0
    fi

    return 1
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

is_already_recorded() {
    local ip="$1"

    if [[ -f "$STATE_FILE" ]]; then
        awk -F '\t' -v ip="$ip" '$1 == ip { found = 1 } END { exit !found }' "$STATE_FILE"
        return
    fi

    return 1
}

is_permanently_listed() {
    local ip="$1"

    [[ -f "$PERMANENT_BLOCK_FILE" ]] || return 1

    grep -Ev '^\s*($|#)' "$PERMANENT_BLOCK_FILE" | awk '{print $1}' | grep -Fxq "$ip"
}

record_temporary_block() {
    local ip="$1"
    local until="$2"
    local reason="$3"

    touch "$STATE_FILE"

    awk -F '\t' -v ip="$ip" '$1 != ip' "$STATE_FILE" > "${STATE_FILE}.tmp"
    mv "${STATE_FILE}.tmp" "$STATE_FILE"

    printf '%s\t%s\t%s\n' "$ip" "$until" "$reason" >> "$STATE_FILE"
}

block_ip() {
    local ip="$1"
    local backend="$2"
    local reason="$3"

    if is_whitelisted "$ip"; then
        echo "SKIP-WHITELIST: $ip"
        return 0
    fi

    case "$backend" in
        ufw)
            ufw deny from "$ip" comment "$reason"
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
            ufw delete deny from "$ip" || true
            ;;
        iptables)
            while iptables -C INPUT -s "$ip" -j DROP 2>/dev/null; do
                iptables -D INPUT -s "$ip" -j DROP || true
            done
            ;;
    esac
}

expire_old_temporary_blocks() {
    local backend="$1"
    local current_time
    current_time="$(now_epoch)"

    [[ -f "$STATE_FILE" ]] || return 0

    : > "${STATE_FILE}.tmp"

    while IFS=$'\t' read -r ip until reason; do
        [[ -n "${ip:-}" ]] || continue

        if is_permanently_listed "$ip"; then
            printf '%s\t%s\t%s\n' "$ip" "$until" "$reason" >> "${STATE_FILE}.tmp"
            continue
        fi

        if [[ "$until" =~ ^[0-9]+$ ]] && (( until <= current_time )); then
            echo "Unblocking expired temporary IP: $ip"
            unblock_ip "$ip" "$backend"
        else
            printf '%s\t%s\t%s\n' "$ip" "$until" "$reason" >> "${STATE_FILE}.tmp"
        fi
    done < "$STATE_FILE"

    mv "${STATE_FILE}.tmp" "$STATE_FILE"
}

ensure_permanent_blocks() {
    local backend="$1"

    [[ -f "$PERMANENT_BLOCK_FILE" ]] || return 0

    grep -Ev '^\s*($|#)' "$PERMANENT_BLOCK_FILE" | awk '{print $1}' | while read -r ip; do
        [[ -n "$ip" ]] || continue

        if ! is_valid_ip "$ip"; then
            echo "SKIP-INVALID-PERMANENT-IP: $ip"
            continue
        fi

        if is_whitelisted "$ip"; then
            echo "SKIP-WHITELIST-PERMANENT-IP: $ip"
            continue
        fi

        if [[ "$MODE" == "report" ]]; then
            echo "REPORT: permanent block would be enforced for $ip"
        else
            echo "Ensuring permanent block for $ip"
            block_ip "$ip" "$backend" "permanent apache auto-block"
        fi
    done
}

###############################################################################
# Log scanning
###############################################################################

existing_log_files() {
    local file

    for file in "${LOG_FILES[@]}"; do
        if [[ -f "$file" ]]; then
            printf '%s\n' "$file"
        fi
    done
}

status_code_regex() {
    local joined=""
    local code

    for code in "${BLOCK_STATUS_CODES[@]}"; do
        if [[ -z "$joined" ]]; then
            joined="$code"
        else
            joined="${joined}|${code}"
        fi
    done

    echo "^(${joined})$"
}

user_agent_regex() {
    local joined=""
    local word

    for word in "${BLOCK_USER_AGENT_WORDS[@]}"; do
        if [[ -z "$joined" ]]; then
            joined="$word"
        else
            joined="${joined}|${word}"
        fi
    done

    echo "$joined"
}

scan_status_code_abuse() {
    mapfile -t files < <(existing_log_files)

    if (( ${#files[@]} == 0 )); then
        echo "No Apache access log files found." >&2
        return 0
    fi

    local status_regex
    status_regex="$(status_code_regex)"

    awk -v status_regex="$status_regex" '
        $9 ~ status_regex {
            count[$1]++
        }
        END {
            for (ip in count) {
                print ip, count[ip]
            }
        }
    ' "${files[@]}"
}

scan_user_agent_abuse() {
    mapfile -t files < <(existing_log_files)

    if (( ${#files[@]} == 0 )); then
        return 0
    fi

    local ua_regex
    ua_regex="$(user_agent_regex)"

    awk -v ua_regex="$ua_regex" '
        BEGIN {
            IGNORECASE = 1
        }

        {
            line = $0
            ip = $1

            if (line ~ ua_regex) {
                count[ip]++
            }
        }

        END {
            for (ip in count) {
                print ip, count[ip]
            }
        }
    ' "${files[@]}"
}

handle_candidate_block() {
    local ip="$1"
    local count="$2"
    local reason="$3"
    local backend="$4"
    local permanent="${5:-no}"

    if ! is_valid_ip "$ip"; then
        return 0
    fi

    if is_whitelisted "$ip"; then
        echo "SKIP-WHITELIST: $ip"
        return 0
    fi

    if [[ "$permanent" != "yes" ]] && is_already_recorded "$ip"; then
        return 0
    fi

    if [[ "$MODE" == "report" ]]; then
        echo "REPORT: $ip would be blocked. Count: $count. Reason: $reason"
        return 0
    fi

    if [[ "$MODE" != "block" ]]; then
        echo "Invalid MODE: $MODE. Use MODE=\"report\" or MODE=\"block\"." >&2
        exit 1
    fi

    echo "Blocking $ip. Count: $count. Reason: $reason"
    block_ip "$ip" "$backend" "$reason"

    if [[ "$permanent" != "yes" ]]; then
        local until
        until="$(ban_until_epoch)"
        record_temporary_block "$ip" "$until" "$reason"
    fi
}

###############################################################################
# Main
###############################################################################

if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must be run as root." >&2
    exit 1
fi

if [[ "$MODE" != "report" && "$MODE" != "block" ]]; then
    echo "Invalid MODE: $MODE. Use MODE=\"report\" or MODE=\"block\"." >&2
    exit 1
fi

if [ ! -d "$STATE_DIR" ]; then
	mkdir -p "$STATE_DIR"
fi
touch "$STATE_FILE"
touch "$PERMANENT_BLOCK_FILE"

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
    expire_old_temporary_blocks "$BACKEND"
fi

ensure_permanent_blocks "$BACKEND"

scan_status_code_abuse | while read -r ip count; do
    [[ -n "${ip:-}" ]] || continue
    [[ -n "${count:-}" ]] || continue

    if (( count > THRESHOLD )); then
        handle_candidate_block \
            "$ip" \
            "$count" \
            "More than ${THRESHOLD} selected Apache error responses in ${BAN_HOURS}h ban window: ${count}" \
            "$BACKEND"
    fi
done

scan_user_agent_abuse | while read -r ip count; do
    [[ -n "${ip:-}" ]] || continue
    [[ -n "${count:-}" ]] || continue

    if (( count > USER_AGENT_THRESHOLD )); then
        handle_candidate_block \
            "$ip" \
            "$count" \
            "More than ${USER_AGENT_THRESHOLD} suspicious user-agent matches in ${BAN_HOURS}h ban window: ${count}" \
            "$BACKEND"
    fi
done


