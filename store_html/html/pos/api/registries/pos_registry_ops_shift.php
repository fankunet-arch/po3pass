<?php
/**
 * Toptea Store POS - Shift Handlers
 * Extracted from pos_registry_ops.php
 *
 * [GEMINI FIX 2025-11-16]
 * - 修复了 handle_shift_start 中 json_ok() 的参数颠倒问题 (Bug: Argument #2 was array)。
 * - 确保 handle_shift_force_start 的 json_ok() 调用正确。
 */

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_shift_handler.php                 */
/* -------------------------------------------------------------------------- */
function handle_shift_status(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    
    $stmt_store = $pdo->prepare("SELECT eod_cutoff_hour FROM kds_stores WHERE id = ?");
    $stmt_store->execute([$store_id]);
    $eod_cutoff_hour = (int)($stmt_store->fetchColumn() ?: 3);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $now_madrid = new DateTime('now', $tzMadrid);
    
    $today_cutoff_dt_madrid = (clone $now_madrid)->setTime($eod_cutoff_hour, 0, 0);
    $cutoff_dt_utc_str = (clone $today_cutoff_dt_madrid)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $stmt_any = $pdo->prepare(
        "SELECT s.id, s.user_id, s.start_time, u.display_name
         FROM pos_shifts s
         LEFT JOIN kds_users u ON s.user_id = u.id AND s.store_id = u.store_id
         WHERE s.store_id=? AND s.status='ACTIVE'
         ORDER BY s.id ASC LIMIT 1"
    );
    $stmt_any->execute([$store_id]);
    $active_shift = $stmt_any->fetch(PDO::FETCH_ASSOC);

    if (!$active_shift) {
        unset($_SESSION['pos_shift_id']);
        json_ok(['has_active_shift'=>false, 'ghost_shift_detected'=>false], 'No active shift.');
    }

    $is_ghost = ($active_shift['start_time'] < $cutoff_dt_utc_str);

    if ($is_ghost) {
        if ((int)$active_shift['user_id'] === $user_id) {
            unset($_SESSION['pos_shift_id']);
        }
        // [A2 UTC SYNC] 格式化鬼班次时间
        $ghost_start_local = fmt_local($active_shift['start_time'], 'Y-m-d H:i', APP_DEFAULT_TIMEZONE);
        json_ok([
            'has_active_shift' => false,
            'ghost_shift_detected' => true,
            'ghost_shift_user_name' => $active_shift['display_name'] ?? '未知员工',
            'ghost_shift_start_time' => $ghost_start_local // e.g., "2025-11-08 15:30"
        ], 'Ghost shift detected.');
    } else {
        if ((int)$active_shift['user_id'] === $user_id) {
            $_SESSION['pos_shift_id'] = (int)$active_shift['id'];
            json_ok(['has_active_shift'=>true, 'shift'=>$active_shift], 'Active shift found for current user.');
        } else {
            unset($_SESSION['pos_shift_id']);
            json_ok(['has_active_shift'=>false, 'ghost_shift_detected'=>false], 'Another user shift is active.');
        }
    }
}
function handle_shift_start(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $starting_float = (float)($input_data['starting_float'] ?? -1);
    if ($starting_float < 0) json_error('Invalid starting_float.', 422);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $chk = $pdo->prepare("SELECT id FROM pos_shifts WHERE user_id=? AND store_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $chk->execute([$user_id, $store_id]);
    if ($existing_id = $chk->fetchColumn()) {
        $_SESSION['pos_shift_id'] = (int)$existing_id;
        if ($tx_started && $pdo->inTransaction()) $pdo->commit();
        json_ok(['shift_id' => $existing_id], 'Shift already active (reused).');
    }

    $chk_ghost = $pdo->prepare("SELECT id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' LIMIT 1 FOR UPDATE");
    $chk_ghost->execute([$store_id]);
    if ($chk_ghost->fetchColumn()) {
         if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
         json_error('Cannot start shift, another shift is still active.', 409);
    }

    $uuid = bin2hex(random_bytes(16));
    $ins = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $ins->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
    $shift_id = (int)$pdo->lastInsertId();

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    $_SESSION['pos_shift_id'] = $shift_id;

    // [B1.2] 查询上一班的估清快照
    $stmt_snapshot = $pdo->prepare("SELECT sold_out_state_snapshot FROM pos_daily_tracking WHERE store_id = ?");
    $stmt_snapshot->execute([$store_id]);
    $snapshot_json = $stmt_snapshot->fetchColumn();
    $snapshot = $snapshot_json ? json_decode($snapshot_json, true) : [];
    
    $prompt_decision = false;
    $snapshot_count = 0;
    
    if (!empty($snapshot)) {
        $prompt_decision = true;
        $snapshot_count = count($snapshot);
        // [B1.2] 将快照应用到当前的估清表
        $sql_apply = "INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at) VALUES (:store_id, :menu_item_id, 1, :now) ON DUPLICATE KEY UPDATE is_sold_out = 1, updated_at = :now";
        $stmt_apply = $pdo->prepare($sql_apply);
        foreach ($snapshot as $menu_item_id) {
            $stmt_apply->execute([
                ':store_id' => $store_id,
                ':menu_item_id' => (int)$menu_item_id,
                ':now' => $now_utc_str
            ]);
        }
    }

    // [GEMINI FIX] 修复 json_ok 参数颠倒
    // 错误 (旧): json_ok('Shift started.', [ ...data... ]);
    // 正确 (新): json_ok([ ...data... ], 'Shift started.');
    json_ok([
        'shift'=>[ 'id'=>$shift_id, 'start_time'=>$now_utc_str, 'starting_float'=>(float)$starting_float ],
        // [B1.2] 返回估清决策
        'prompt_sold_out_decision' => $prompt_decision,
        'snapshot_item_count' => $snapshot_count
    ], 'Shift started.');
}
function handle_shift_end(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $counted_cash = (float)($input_data['counted_cash'] ?? -1);
    
    if ($shift_id <= 0) json_error('No active shift in session.', 400);
    if ($counted_cash < 0) json_error('Invalid counted_cash.', 422);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $lock = $pdo->prepare("SELECT id, start_time, starting_float FROM pos_shifts WHERE id=? AND user_id=? AND store_id=? AND status='ACTIVE' FOR UPDATE");
    $lock->execute([$shift_id, $user_id, $store_id]);
    $shift = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
        json_error('Active shift not found or already ended.', 404);
    }

    // 依赖: compute_expected_cash (来自 pos_repo.php)
    // [A2 UTC SYNC] $shift['start_time'] 已经是 UTC 字符串
    $totals = compute_expected_cash($pdo, $store_id, $shift['start_time'], $now_utc_str, (float)$shift['starting_float']);
    $expected_cash = (float)$totals['expected_cash'];
    $cash_diff     = round((float)$counted_cash - $expected_cash, 2);

    $upd = $pdo->prepare("UPDATE pos_shifts SET end_time=?, status='ENDED', counted_cash=? WHERE id=?");
    $upd->execute([$now_utc_str, $counted_cash, $shift_id]);

    if (table_exists($pdo, 'pos_eod_records')) {
        $ins = $pdo->prepare("INSERT INTO pos_eod_records
          (shift_id, store_id, user_id, started_at, ended_at, starting_float,
           cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $shift_id, $store_id, $user_id, $shift['start_time'], $now_utc_str, (float)$totals['starting_float'],
            (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
            (float)$totals['cash_refunds'], $expected_cash, (float)$counted_cash, (float)$cash_diff
        ]);
        $eod_id = (int)$pdo->lastInsertId();
    } else {
        $eod_id = null;
    }

    // [B1.2] 写入估清快照
    $stmt_sold_out = $pdo->prepare("SELECT menu_item_id FROM pos_product_availability WHERE store_id = ? AND is_sold_out = 1");
    $stmt_sold_out->execute([$store_id]);
    $sold_out_ids = $stmt_sold_out->fetchAll(PDO::FETCH_COLUMN, 0);
    $snapshot_json = empty($sold_out_ids) ? NULL : json_encode($sold_out_ids);
    
    $sql_update_tracking = "
        INSERT INTO pos_daily_tracking (store_id, sold_out_state_snapshot, snapshot_taken_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sold_out_state_snapshot = VALUES(sold_out_state_snapshot),
            snapshot_taken_at = VALUES(snapshot_taken_at)
    ";
    $pdo->prepare($sql_update_tracking)->execute([$store_id, $snapshot_json, $now_utc_str]);
    // [B1.2] 估清快照结束

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    unset($_SESSION['pos_shift_id']);

    json_ok([
        'eod_id' => $eod_id,
        'eod' => [
            'shift_id'       => $shift_id,
            'started_at'     => $shift['start_time'],
            'ended_at'       => $now_utc_str,
            'starting_float' => $totals['starting_float'],
            'cash_sales'     => $totals['cash_sales'],
            'cash_in'        => $totals['cash_in'],
            'cash_out'       => $totals['cash_out'],
            'cash_refunds'   => $totals['cash_refunds'],
            'expected_cash'  => $totals['expected_cash'],
            'counted_cash'   => (float)$counted_cash,
            'cash_diff'      => $cash_diff,
        ],
    ], 'Shift ended.');
}

function handle_shift_force_start(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $starting_float = (float)($input_data['starting_float'] ?? -1);
    if ($starting_float < 0) json_error('Invalid starting_float for new shift.', 422);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');
    
    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $stmt_ghosts = $pdo->prepare("SELECT id, start_time, starting_float, user_id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' FOR UPDATE");
    $stmt_ghosts->execute([$store_id]);
    $ghosts = $stmt_ghosts->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ghosts)) {
         if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
         json_error('No ghost shifts found. Please try starting a normal shift.', 404, ['redirect_action' => 'start']);
    }

    $closer_name = $_SESSION['pos_display_name'] ?? ('User #' . $user_id);
    
    // [GEMINI FIX] 确保 EOD ID 和 $totals 变量在循环外初始化
    $eod_id = null;
    $totals = []; // 确保 $totals 被定义
    $shift_id = null; // 确保 $shift_id 被定义
    $shift = ['start_time' => null]; // 确保 $shift 被定义
    $counted_cash = 0.0; // 确保 $counted_cash 被定义
    $cash_diff = 0.0; // 确保 $cash_diff 被定义

    foreach ($ghosts as $ghost) {
        $ghost_id = (int)$ghost['id'];
        $shift_id = $ghost_id; // 捕获最后一个被关闭的 shift ID
        $shift['start_time'] = $ghost['start_time']; // 捕获开始时间
        
        // 依赖: compute_expected_cash (来自 pos_repo.php)
        // [A2 UTC SYNC] $ghost['start_time'] 已经是 UTC
        $totals = compute_expected_cash($pdo, $store_id, $ghost['start_time'], $now_utc_str, (float)$ghost['starting_float']);
        $counted_cash = 0.0; // 强制关闭时，清点现金为0
        $cash_diff = 0.0; // 强制关闭时，差异为0

        $upd = $pdo->prepare(
            "UPDATE pos_shifts SET 
                end_time = ?, status = 'FORCE_CLOSED', counted_cash = ?, expected_cash = ?, 
                cash_variance = ?, payment_summary = ?, admin_reviewed = 0 
             WHERE id = ?"
        );
        $upd->execute([
            $now_utc_str,
            $counted_cash, // 强制关闭时，清点现金为0
            (float)$totals['expected_cash'],
            (float)$totals['expected_cash'] * -1, // 差异 = 0 - 理论
            json_encode(['note' => 'Forcibly closed by ' . $closer_name]),
            $ghost_id
        ]);
        
        if (table_exists($pdo, 'pos_eod_records')) {
            $ins = $pdo->prepare("INSERT INTO pos_eod_records
              (shift_id, store_id, user_id, started_at, ended_at, starting_float,
               cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $ghost_id, $store_id, $ghost['user_id'], $ghost['start_time'], $now_utc_str, (float)$totals['starting_float'],
                (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
                (float)$totals['cash_refunds'], (float)$totals['expected_cash'], $counted_cash, (float)$totals['expected_cash'] * -1
            ]);
            $eod_id = (int)$pdo->lastInsertId(); // 获取 EOD ID
        }
    }
    
    $uuid = bin2hex(random_bytes(16));
    $ins_new = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $ins_new->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
    $new_shift_id = (int)$pdo->lastInsertId();
    
    // [B1.2] 查询上一班的估清快照 (此逻辑在强制关班时也应执行)
    $stmt_snapshot = $pdo->prepare("SELECT sold_out_state_snapshot FROM pos_daily_tracking WHERE store_id = ?");
    $stmt_snapshot->execute([$store_id]);
    $snapshot_json = $stmt_snapshot->fetchColumn();
    $snapshot = $snapshot_json ? json_decode($snapshot_json, true) : [];
    
    $prompt_decision = false;
    $snapshot_count = 0;
    
    if (!empty($snapshot)) {
        $prompt_decision = true;
        $snapshot_count = count($snapshot);
        // [B1.2] 将快照应用到当前的估清表
        $sql_apply = "INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at) VALUES (:store_id, :menu_item_id, 1, :now) ON DUPLICATE KEY UPDATE is_sold_out = 1, updated_at = :now";
        $stmt_apply = $pdo->prepare($sql_apply);
        foreach ($snapshot as $menu_item_id) {
            $stmt_apply->execute([
                ':store_id' => $store_id,
                ':menu_item_id' => (int)$menu_item_id,
                ':now' => $now_utc_str
            ]);
        }
    }
    // [B1.2] 估清快照结束

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    
    // [GEMINI FIX] 此处 session 必须设置为 *新* 班次 ID
    $_SESSION['pos_shift_id'] = $new_shift_id; 

    // [GEMINI FIX] 此处返回的 EOD 报告应是 *被关闭* 的班次信息
    json_ok([
        'eod_id' => $eod_id, // 最后一个被关闭的 EOD 记录 ID
        'eod' => [
            'shift_id'       => $shift_id, // 最后一个被关闭的 Shift ID
            'started_at'     => $shift['start_time'],
            'ended_at'       => $now_utc_str,
            'starting_float' => $totals['starting_float'] ?? 0.0,
            'cash_sales'     => $totals['cash_sales'] ?? 0.0,
            'cash_in'        => $totals['cash_in'] ?? 0.0,
            'cash_out'       => $totals['cash_out'] ?? 0.0,
            'cash_refunds'   => $totals['cash_refunds'] ?? 0.0,
            'expected_cash'  => $totals['expected_cash'] ?? 0.0,
            'counted_cash'   => (float)$counted_cash,
            'cash_diff'      => (float)($totals['expected_cash'] ?? 0.0) * -1, // 强制关闭的差异
        ],
        // [GEMINI FIX] 同时返回新班次的信息
        'new_shift' => [
            'id' => $new_shift_id,
            'start_time' => $now_utc_str,
            'starting_float' => (float)$starting_float
        ],
        // [B1.2] 返回估清决策
        'prompt_sold_out_decision' => $prompt_decision,
        'snapshot_item_count' => $snapshot_count
    ], 'Shift forced closed, new shift started.');
}