
# Block 158.158.121.236
# Reason: More than 200 selected Apache error responses in 120h ban window: 585
# User-Agent: -
iptables -C "$CHAIN" -s "158.158.121.236" -m comment --comment "-" -j DROP 2>/dev/null || \
    iptables -A "$CHAIN" -s "158.158.121.236" -m comment --comment "-" -j DROP

# Block 185.177.72.10
# Reason: More than 200 selected Apache error responses in 120h ban window: 4019
# User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 curl/8.7.1
iptables -C "$CHAIN" -s "185.177.72.10" -m comment --comment "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 curl/8.7.1" -j DROP 2>/dev/null || \
    iptables -A "$CHAIN" -s "185.177.72.10" -m comment --comment "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 curl/8.7.1" -j DROP

# Block 185.177.72.11
# Reason: More than 200 selected Apache error responses in 120h ban window: 879
# User-Agent: - Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36
iptables -C "$CHAIN" -s "185.177.72.11" -m comment --comment "- Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36" -j DROP 2>/dev/null || \
    iptables -A "$CHAIN" -s "185.177.72.11" -m comment --comment "- Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36" -j DROP

# Block 144.126.146.83
# Reason: More than 200 selected Apache error responses in 120h ban window: 255
# User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.129 Safari/537.36
iptables -C "$CHAIN" -s "144.126.146.83" -m comment --comment "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.129 Safari/537.36" -j DROP 2>/dev/null || \
    iptables -A "$CHAIN" -s "144.126.146.83" -m comment --comment "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.129 Safari/537.36" -j DROP

# Block 185.177.72.13
# Reason: More than 200 selected Apache error responses in 120h ban window: 7339
# User-Agent: curl/8.7.1 Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36
iptables -C "$CHAIN" -s "185.177.72.13" -m comment --comment "curl/8.7.1 Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" -j DROP 2>/dev/null || \
    iptables -A "$CHAIN" -s "185.177.72.13" -m comment --comment "curl/8.7.1 Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" -j DROP
