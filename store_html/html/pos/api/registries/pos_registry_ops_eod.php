<?php
/**
 * Toptea Store POS - EOD (End Of Day) Handlers
 * Extracted from pos_registry_ops.php
 */

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_summary_handler.php               */
/* -------------------------------------------------------------------------- */
function handle_eod_get_preview(PDO $pdo, array $config, array $input_data): void {
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 1);

    $target_business_date = null;
    $date_input = $_GET['target_business_date'] ?? $input_data['target_business_date'] ?? null;
    
    // 1. 确定目标营业日 (马德里本地日期)
    if ($date_input) {
        $d = DateTime::createFromFormat('Y-m-d', $date_input, $tzMadrid);
        if ($d !== false) $target_business_date = $d->format('Y-m-d');
    }
    if ($target_business_date === null) {
        // [A2 UTC SYNC] POS 端始终查询“今天”
        $target_business_date = (new DateTime('today', $tzMadrid))->format('Y-m-d');
    }
    
    // 2. [A2 UTC SYNC] 将本地营业日转换为 UTC 时间窗口
    [$bd_start_utc_dt, $bd_end_utc_dt] = to_utc_window($target_business_date, null, APP_DEFAULT_TIMEZONE);
    $bd_start_utc_str = $bd_start_utc_dt->format('Y-m-d H:i:s');
    $bd_end_utc_str   = $bd_end_utc_dt->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $eod_table = 'pos_eod_reports';
    $sql_check = "SELECT * FROM `{$eod_table}` WHERE store_id=:sid AND report_date = :bd LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([':sid' => $store_id, ':bd' => $target_business_date]);
    $existing_report = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existing_report) {
        // [A3 UTC DISPLAY FIX]
        // 移除: $existing_report['executed_at'] = fmt_local(...)
        // 理由: 必须发送原始 UTC 字符串到前端。
        json_ok(['is_submitted' => true, 'existing_report' => $existing_report]);
    }

    // 依赖: getInvoiceSummaryForPeriod (来自 pos_repo.php)
    // [A2 UTC SYNC] 传入 UTC 窗口
    $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $bd_start_utc_str, $bd_end_utc_str);
    
    $preview_data = [
        'transactions_count'   => $full_summary['summary']['transactions_count'],
        'system_gross_sales'   => $full_summary['summary']['system_gross_sales'],
        'system_discounts'     => $full_summary['summary']['system_discounts'],
        'system_net_sales'     => $full_summary['summary']['system_net_sales'],
        'system_tax'           => $full_summary['summary']['system_tax'],
        'payments'             => $full_summary['payments'],
        'report_date'          => $target_business_date,
        'is_submitted'         => false
    ];
    json_ok($preview_data);
}
function handle_eod_submit_report(PDO $pdo, array $config, array $input_data): void {
    $json_data = $input_data;
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 1);

    // 1. 确定目标营业日 (马德里本地日期)
    $target_business_date = null;
    $date_input = $_GET['target_business_date'] ?? $json_data['target_business_date'] ?? null;
    if ($date_input) {
        $d = DateTime::createFromFormat('Y-m-d', $date_input, $tzMadrid);
        if ($d !== false) $target_business_date = $d->format('Y-m-d');
    }
    if ($target_business_date === null) {
        $target_business_date = (new DateTime('today', $tzMadrid))->format('Y-m-d');
    }
    
    // 2. [A2 UTC SYNC] 将本地营业日转换为 UTC 时间窗口
    [$bd_start_utc_dt, $bd_end_utc_dt] = to_utc_window($target_business_date, null, APP_DEFAULT_TIMEZONE);
    $bd_start_utc_str = $bd_start_utc_dt->format('Y-m-d H:i:s');
    $bd_end_utc_str   = $bd_end_utc_dt->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $eod_table = 'pos_eod_reports';

    $sql_check = "SELECT * FROM `{$eod_table}` WHERE store_id=:sid AND report_date = :bd LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([':sid' => $store_id, ':bd' => $target_business_date]);
    if ($stmt_check->fetch(PDO::FETCH_ASSOC)) {
        json_error('该业务日已完成日结，不可重复提交。', 409);
    }

    $counted_cash = isset($json_data['counted_cash']) ? (float)$json_data['counted_cash'] : 0.0;
    $notes = isset($json_data['notes']) ? trim($json_data['notes']) : '';

    // 依赖: getInvoiceSummaryForPeriod (来自 pos_repo.php)
    // [A2 UTC SYNC] 传入 UTC 窗口
    $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $bd_start_utc_str, $bd_end_utc_str);
    $summary = $full_summary['summary'];
    $payments_breakdown = $full_summary['payments'];
    
    $cash_discrepancy = $counted_cash - $payments_breakdown['Cash'];

    // [A2 UTC SYNC] 写入 UTC 时间
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    $sql_insert = "INSERT INTO `{$eod_table}` (
                       report_date, store_id, user_id, executed_at,
                       transactions_count, system_gross_sales, system_discounts, system_net_sales, system_tax,
                       system_cash, system_card, system_platform,
                       counted_cash, cash_discrepancy, notes
                   ) VALUES (
                       :report_date, :store_id, :user_id, :now_utc,
                       :transactions_count, :system_gross_sales, :system_discounts, :system_net_sales, :system_tax,
                       :system_cash, :system_card, :system_platform,
                       :counted_cash, :cash_discrepancy, :notes
                   )";
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        ':report_date' => $target_business_date,
        ':store_id' => $store_id,
        ':user_id' => (int)($_SESSION['pos_user_id'] ?? $json_data['user_id'] ?? 1),
        ':now_utc' => $now_utc_str, // [A2 UTC SYNC]
        ':transactions_count' => $summary['transactions_count'],
        ':system_gross_sales' => $summary['system_gross_sales'],
        ':system_discounts' => $summary['system_discounts'],
        ':system_net_sales' => $summary['system_net_sales'],
        ':system_tax' => $summary['system_tax'],
        ':system_cash' => $payments_breakdown['Cash'],
        ':system_card' => $payments_breakdown['Card'],
        ':system_platform' => $payments_breakdown['Platform'],
        ':counted_cash' => $counted_cash,
        ':cash_discrepancy' => $cash_discrepancy,
        ':notes' => $notes
    ]);

    $pdo->commit();
    json_ok(null, '日结报告已成功提交。');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_list.php                          */
/* -------------------------------------------------------------------------- */
function handle_eod_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $limit = isset($_GET['limit']) ? max(1,min(200,(int)$_GET['limit'])) : 50;

    $sql = "SELECT id, shift_id, store_id, user_id,
                 started_at, ended_at,
                 starting_float, cash_sales, cash_in, cash_out, cash_refunds,
                 expected_cash, counted_cash, cash_diff, created_at
          FROM pos_eod_records
          WHERE store_id = ?
          ORDER BY id DESC
          LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // [A3 UTC DISPLAY FIX]
    // 移除: foreach ($rows as &$row) { ... fmt_local ... }
    // 理由: 必须发送原始 UTC 字符串到前端。

    json_ok(['items'=>$rows, 'count'=>count($rows)], 'ok');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_get.php                           */
/* -------------------------------------------------------------------------- */
function handle_eod_get(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $eod_id   = isset($_GET['eod_id']) ? (int)$_GET['eod_id'] : 0;
    if ($eod_id <= 0) json_error('Missing eod_id', 400);

    $stmt = $pdo->prepare("SELECT * FROM pos_eod_records WHERE id=? AND store_id=? LIMIT 1");
    $stmt->execute([$eod_id, $store_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_error('Record not found', 404);

    // [A3 UTC DISPLAY FIX]
    // 移除: $row['started_at'] = fmt_local(...) 等
    // 理由: 必须发送原始 UTC 字符串到前端。

    json_ok(['item'=>$row], 'OK');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/check_eod_status.php                  */
/* -------------------------------------------------------------------------- */
function handle_check_eod_status(PDO $pdo, array $config, array $input_data): void {
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tzMadrid = new DateTimeZone(APP_DEFAULT_TIMEZONE);
    $store_id = (int)($_GET['store_id'] ?? $_SESSION['pos_store_id'] ?? 1);

    // 1. 确定“昨天”的营业日 (马德里本地日期)
    $yesterday_date_str = (new DateTime('yesterday', $tzMadrid))->format('Y-m-d');

    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM pos_eod_reports WHERE store_id = :store_id AND report_date = :report_date");
    $stmt_check->execute([':store_id' => $store_id, ':report_date' => $yesterday_date_str]);
    $report_exists = (int)$stmt_check->fetchColumn() > 0;

    if ($report_exists) {
        json_ok(['previous_day_unclosed' => false, 'unclosed_date' => null]);
    }

    // 2. [A2 UTC SYNC] 将昨天的本地营业日转换为 UTC 窗口
    [$yesterday_start_utc_dt, $yesterday_end_utc_dt] = to_utc_window($yesterday_date_str, null, APP_DEFAULT_TIMEZONE);
    $yesterday_start_utc_str = $yesterday_start_utc_dt->format('Y-m-d H:i:s');
    $yesterday_end_utc_str   = $yesterday_end_utc_dt->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $stmt_invoice = $pdo->prepare(
        "SELECT 1 FROM pos_invoices WHERE store_id = :store_id AND issued_at BETWEEN :start_utc AND :end_utc LIMIT 1"
    );
    $stmt_invoice->execute([
        ':store_id' => $store_id,
        ':start_utc' => $yesterday_start_utc_str,
        ':end_utc' => $yesterday_end_utc_str
    ]);
    $invoice_exists = $stmt_invoice->fetchColumn() !== false;
    
    if ($invoice_exists) {
        json_ok(['previous_day_unclosed' => true, 'unclosed_date' => $yesterday_date_str]);
    } else {
        json_ok(['previous_day_unclosed' => false, 'unclosed_date' => null]);
    }
}
