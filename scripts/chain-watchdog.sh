#!/bin/bash
# ============================================================
# TPIX Chain Watchdog — ตรวจสอบและ restart เชนอัตโนมัติ
# ติดตั้ง cron: */5 * * * * /root/chain-watchdog.sh >> /var/log/tpix-watchdog.log 2>&1
# Developed by Xman Studio
# ============================================================

set -euo pipefail

# ─── Config ───
INFRA_DIR="${TPIX_INFRA_DIR:-$HOME/tpix-infrastructure}"
RPC_URL="http://127.0.0.1:8545"
LOG_FILE="/var/log/tpix-watchdog.log"
MAX_BLOCK_AGE=30           # block ต้องไม่เก่ากว่า 30 วินาที (block time = 2s)
MAX_RESTART_PER_HOUR=3     # restart ได้สูงสุด 3 ครั้ง/ชม. (ป้องกัน restart loop)
RESTART_COUNTER_FILE="/tmp/tpix-restart-counter"

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

log() {
    echo "[$(timestamp)] $1"
}

# ─── ตรวจ restart counter (ป้องกัน restart loop) ───
check_restart_limit() {
    if [ -f "$RESTART_COUNTER_FILE" ]; then
        local last_hour
        last_hour=$(stat -c %Y "$RESTART_COUNTER_FILE" 2>/dev/null || echo 0)
        local now
        now=$(date +%s)
        local diff=$((now - last_hour))

        if [ $diff -gt 3600 ]; then
            # เกิน 1 ชม. → reset counter
            echo "0" > "$RESTART_COUNTER_FILE"
        fi

        local count
        count=$(cat "$RESTART_COUNTER_FILE" 2>/dev/null || echo 0)
        if [ "$count" -ge "$MAX_RESTART_PER_HOUR" ]; then
            log "CRITICAL: Restarted $count times in last hour. Skipping to prevent loop."
            log "CRITICAL: Manual intervention required! Check: docker logs tpix-chain-node"
            return 1
        fi
    else
        echo "0" > "$RESTART_COUNTER_FILE"
    fi
    return 0
}

increment_restart_counter() {
    local count
    count=$(cat "$RESTART_COUNTER_FILE" 2>/dev/null || echo 0)
    echo $((count + 1)) > "$RESTART_COUNTER_FILE"
}

# ─── Check 1: Docker container running? ───
check_container() {
    local status
    status=$(docker inspect -f '{{.State.Status}}' tpix-chain-node 2>/dev/null || echo "not_found")

    if [ "$status" != "running" ]; then
        log "ERROR: Container tpix-chain-node is '$status'"
        return 1
    fi
    return 0
}

# ─── Check 2: RPC responding? ───
check_rpc() {
    local response
    response=$(curl -s --max-time 10 "$RPC_URL" \
        -X POST -H "Content-Type: application/json" \
        -d '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' 2>/dev/null)

    if [ -z "$response" ]; then
        log "ERROR: RPC not responding (timeout)"
        return 1
    fi

    local result
    result=$(echo "$response" | grep -o '"result":"[^"]*"' | cut -d'"' -f4)

    if [ -z "$result" ]; then
        log "ERROR: RPC returned invalid response: $response"
        return 1
    fi

    echo "$result"
    return 0
}

# ─── Check 3: Blocks progressing? ───
check_block_progress() {
    # เรียก block number 2 ครั้ง ห่าง 6 วินาที
    local block1
    block1=$(check_rpc) || return 1

    sleep 6

    local block2
    block2=$(check_rpc) || return 1

    if [ "$block1" = "$block2" ]; then
        log "ERROR: Block not progressing! Stuck at $block1 for 6 seconds"
        return 1
    fi

    # แปลง hex เป็น decimal
    local block_dec
    block_dec=$(printf "%d" "$block2" 2>/dev/null || echo 0)
    log "OK: Chain alive, block $block2 (decimal: $block_dec)"
    return 0
}

# ─── Check 4: Memory usage ───
check_memory() {
    local mem_pct
    mem_pct=$(docker stats tpix-chain-node --no-stream --format "{{.MemPerc}}" 2>/dev/null | tr -d '%' || echo "0")

    # ตัด ทศนิยม
    local mem_int
    mem_int=$(echo "$mem_pct" | cut -d'.' -f1)

    if [ "${mem_int:-0}" -gt 85 ]; then
        log "WARNING: Chain node memory at ${mem_pct}%"
        return 1
    fi
    return 0
}

# ─── Restart chain ───
restart_chain() {
    local reason="$1"
    log "RESTARTING chain — reason: $reason"

    if ! check_restart_limit; then
        return 1
    fi

    increment_restart_counter

    cd "$INFRA_DIR"

    # Restart เฉพาะ chain node (ไม่กระทบ Blockscout)
    log "Stopping tpix-chain-node..."
    docker compose restart tpix-chain-node 2>&1

    # รอให้ node เริ่ม seal
    log "Waiting 15s for node to start sealing..."
    sleep 15

    # ตรวจว่า restart สำเร็จ
    if check_rpc > /dev/null 2>&1; then
        log "RESTART SUCCESS: Chain is back online"

        # Restart Blockscout backend ด้วย (เพื่อ re-sync)
        log "Restarting Blockscout backend to re-sync..."
        docker compose restart blockscout-backend 2>&1 || true

        return 0
    else
        log "RESTART FAILED: Chain still not responding after restart"
        return 1
    fi
}

# ─── Main ───
main() {
    # Check 1: Container
    if ! check_container; then
        restart_chain "container not running"
        exit $?
    fi

    # Check 2: RPC
    if ! check_rpc > /dev/null 2>&1; then
        restart_chain "RPC not responding"
        exit $?
    fi

    # Check 3: Block progress (สำคัญที่สุด!)
    if ! check_block_progress; then
        restart_chain "blocks not progressing (chain stuck)"
        exit $?
    fi

    # Check 4: Memory (warning only, restart ถ้าเกิน 85%)
    if ! check_memory; then
        restart_chain "memory usage too high"
        exit $?
    fi
}

main "$@"
