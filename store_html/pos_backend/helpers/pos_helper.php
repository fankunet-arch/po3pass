<?php
// pos_helper.php
// 核心助手函数

/**
 * 确保当前有一个活动的班次，否则抛出错误。
 * 这是所有销售和资金操作的保护锁。
 */
function ensure_active_shift_or_fail(PDO $pdo): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id = (int)($_SESSION['pos_user_id'] ?? 0);
    
    if ($shift_id > 0 && $store_id > 0 && $user_id > 0) {
        // 可选：可以进一步检查 $shift_id 是否真的在数据库中且未关闭
        // $stmt = $pdo->prepare("SELECT 1 FROM pos_shifts WHERE id = ? AND store_id = ? AND ended_at IS NULL");
        // $stmt->execute([$shift_id, $store_id]);
        // if ($stmt->fetchColumn()) {
        //     return $shift_id;
        // }
        return $shift_id; // 简化：信任 session
    }

    // 如果 session 中没有，触发班次保护
    json_error('No active shift found. Please start a shift.', 403, ['error_code' => 'NO_ACTIVE_SHIFT']);
    exit;
}

/**
 * 计算EOD（日结）或班次交接的总额。
 *
 * @param PDO $pdo 数据库连接
 * @param int $store_id 门店ID
 * @param string $start_time (UTC) 开始时间 (Y-m-d H:i:s)
 * @param string $end_time (UTC) 结束时间 (Y-m-d H:i:s)
 * @return array 包含总额的数组
 */
function calculate_eod_totals(PDO $pdo, int $store_id, string $start_time, string $end_time): array {

    $totals = [
        'total_sales' => 0.0,
        'total_refunds' => 0.0,
        'net_sales' => 0.0,
        'total_discount' => 0.0,
        'total_tax' => 0.0,
        'item_count' => 0,
        'order_count' => 0,
        'payment_methods' => [],
    ];

    // 1. 获取所有在时间范围内的已完成和已退款的发票
    $sql_invoices = "
        SELECT 
            id, 
            final_total, 
            subtotal,
            discount_total, 
            tax_total, 
            status, 
            (SELECT SUM(quantity) FROM pos_invoice_items WHERE invoice_id = pos_invoices.id) as items
        FROM pos_invoices
        WHERE store_id = :store_id
          AND issued_at >= :start_time
          AND issued_at <= :end_time
          AND status IN ('completed', 'refunded')
    ";
    
    $stmt_invoices = $pdo->prepare($sql_invoices);
    $stmt_invoices->execute([
        ':store_id' => $store_id,
        ':start_time' => $start_time,
        ':end_time' => $end_time
    ]);

    $invoices = $stmt_invoices->fetchAll(PDO::FETCH_ASSOC);
    $invoice_ids = [];

    foreach ($invoices as $invoice) {
        $invoice_ids[] = (int)$invoice['id'];
        
        if ($invoice['status'] === 'completed') {
            $totals['total_sales'] += (float)$invoice['final_total'];
            $totals['total_discount'] += (float)$invoice['discount_total'];
            $totals['total_tax'] += (float)$invoice['tax_total'];
            $totals['item_count'] += (int)$invoice['items'];
            $totals['order_count']++;
        } elseif ($invoice['status'] === 'refunded') {
            // 退款计为负销售
            $totals['total_refunds'] += (float)$invoice['final_total']; // final_total 已经是负数
            $totals['total_discount'] += (float)$invoice['discount_total']; // 折扣也可能是负数
            $totals['total_tax'] += (float)$invoice['tax_total']; // 税金也是负数
            $totals['item_count'] += (int)$invoice['items']; // 计入操作
            $totals['order_count']++;
        }
    }

    $totals['net_sales'] = $totals['total_sales'] + $totals['total_refunds'];

    if (empty($invoice_ids)) {
        return $totals; // 如果没有订单，提前返回
    }

    // 2. 按支付方式汇总
    $placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));
    $sql_payments = "
        SELECT 
            payment_method, 
            SUM(amount) as total_amount
        FROM pos_invoice_payments
        WHERE invoice_id IN ($placeholders)
        GROUP BY payment_method
    ";
    
    $stmt_payments = $pdo->prepare($sql_payments);
    $stmt_payments->execute($invoice_ids);
    
    $payments = $stmt_payments->fetchAll(PDO::FETCH_ASSOC);
    
    $payment_map = [];
    foreach ($payments as $payment) {
        $method = $payment['payment_method'] ?? 'Unknown';
        $amount = (float)$payment['total_amount'];
        $payment_map[$method] = $amount;
    }
    
    // 确保包含所有主要类型，即使它们是0
    $known_methods = ['Cash', 'Card', 'Platform', 'Points', 'Voucher'];
    foreach ($known_methods as $method) {
        if (!isset($payment_map[$method])) {
            $payment_map[$method] = 0.0;
        }
    }
    
    $totals['payment_methods'] = $payment_map;

    return $totals;
}


/**
 * [GEMINI FIX 2025-11-16]
 * 添加 pos_registry_member_pass.php 所需的缺失函数 gen_uuid_v4。
 * 这是导致“创建会员”失败的根本原因。
 *
 * 生成一个符合 RFC 4122 标准的 Version 4 UUID。
 *
 * @return string 格式为 "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx" 的 UUID。
 * @throws Exception 如果无法收集到足够的加密随机数据。
 */
function gen_uuid_v4(): string {
    // 1. 生成 16 字节 (128 位) 的随机数据
    $data = random_bytes(16);

    // 2. 设置 UUID 版本 (Version 4)
    // 字节 6 (索引 6) 的高 4 位必须是 0100 (binary)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

    // 3. 设置 UUID 变体 (Variant 10xx)
    // 字节 8 (索引 8) 的高 2 位必须是 10 (binary)
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // 4. 将 16 字节的二进制数据格式化为 36 个字符的十六进制字符串
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}