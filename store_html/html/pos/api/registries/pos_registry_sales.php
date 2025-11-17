<?php
/**
 * Toptea Store POS - Sales Handlers
 * Extracted from pos_registry.php
 *
 * [GEMINI AUDIT FIX 2025-11-16]:
 * 1. 修复了 handle_order_submit 中 json_ok() 的参数颠倒问题 (Bug: Argument #1 was string)。
 *
 * [GEMINI TYPO FIX 2025-11-16]:
 * 1. 修复了 handle_order_submit 中 L241 的打印机角色拼写错误 (STICKTER -> STICKER)。
 */
/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/submit_order.php                     */
/* -------------------------------------------------------------------------- */
function handle_order_submit(PDO $pdo, array $config, array $input_data): void {
    // 依赖: ensure_active_shift_or_fail (来自 pos_helper.php)
    ensure_active_shift_or_fail($pdo);

    $json_data = $input_data; // 网关已解析
    
    if (empty($json_data['cart']) || !is_array($json_data['cart'])) {
        json_error('Cart data is missing or empty.', 400);
    }

    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
    
    $member_id  = isset($json_data['member_id']) ? (int)$json_data['member_id'] : null;
    $points_redeemed_from_payload = (int)($json_data['points_redeemed'] ?? 0);

    $couponCode = null;
    foreach (['coupon_code','coupon','code','promo_code','discount_code'] as $k) {
        if (!empty($json_data[$k])) { $couponCode = trim((string)$json_data[$k]); break; }
    }

    $payment_payload_raw = $json_data['payment'] ?? $json_data['payments'] ?? [];
    // 依赖: extract_payment_totals (来自 pos_repo.php)
    [, , , $sumPaid, $payment_summary] = extract_payment_totals($payment_payload_raw);

    // 依赖: get_store_config_full (来自 pos_repo.php)
    $store_config = get_store_config_full($pdo, $store_id);
    $vat_rate = (float)($store_config['default_vat_rate'] ?? 21.0);

    // 依赖: PromotionEngine (来自 pos_helper.php)
    $engine = new PromotionEngine($pdo);
    $promoResult = $engine->applyPromotions($json_data['cart'], $couponCode);
    $cart = $promoResult['cart'];
    $discount_from_promo = (float)($promoResult['discount_amount'] ?? 0.0);
    $final_total_after_promo = (float)($promoResult['final_total'] ?? 0.0);

    $pdo->beginTransaction();

    // 积分抵扣
    $points_discount_final = 0.0;
    $points_to_deduct = 0;
    if ($member_id && $points_redeemed_from_payload > 0) {
        $stmt_member = $pdo->prepare("SELECT points_balance FROM pos_members WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt_member->execute([$member_id]);
        if ($m = $stmt_member->fetch(PDO::FETCH_ASSOC)) {
            $current_points = (int)$m['points_balance'];
            $max_possible_discount = $final_total_after_promo;
            $max_points_for_discount = (int)floor($max_possible_discount * 100);
            $points_to_deduct = min($points_redeemed_from_payload, $current_points, $max_points_for_discount);
            if ($points_to_deduct > 0) $points_discount_final = $points_to_deduct / 100.0;
            else $points_to_deduct = 0;
        }
    }

    $final_total = round($final_total_after_promo - $points_discount_final, 2);
    $discount_amount = round($discount_from_promo + $points_discount_final, 2);

    if ($sumPaid < $final_total - 0.01) {
        $pdo->rollBack();
        json_error('Payment breakdown does not match final total.', 422, [
          'final_total'=>$final_total,
          'sum_paid'=>$sumPaid,
        ]);
    }
    
    // 积分扣减与累计
    if ($member_id && $points_to_deduct > 0 && $points_discount_final > 0) {
        $pdo->prepare("UPDATE pos_members SET points_balance = points_balance - ? WHERE id = ?")
            ->execute([$points_to_deduct, $member_id]);
        $pdo->prepare("INSERT INTO pos_member_points_log (member_id, invoice_id, points_change, reason_code, notes, user_id)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$member_id, null, -$points_to_deduct, 'REDEEM_DISCOUNT', "兑换抵扣 {$points_discount_final} EUR", $user_id]);
    }
    if ($member_id && $final_total > 0) {
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM pos_settings WHERE setting_key = 'points_euros_per_point'");
        $settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $euros_per_point = isset($settings['points_euros_per_point']) ? (float)$settings['points_euros_per_point'] : 1.0;
        if ($euros_per_point <= 0) $euros_per_point = 1.0;
        
        $points_to_add = (int)floor($final_total / $euros_per_point);
        if ($points_to_add > 0) {
            $pdo->prepare("UPDATE pos_members SET points_balance = points_balance + ? WHERE id = ?")
                ->execute([$points_to_add, $member_id]);
            $pdo->prepare("INSERT INTO pos_member_points_log (member_id, invoice_id, points_change, reason_code, user_id)
                         VALUES (?,?,?,?,?)")
                ->execute([$member_id, null, $points_to_add, 'PURCHASE', $user_id]);
        }
    }

    // 检查是否需要开票
    // 依赖: is_invoicing_enabled (来自 pos_helper.php)
    if (!is_invoicing_enabled($store_config)) {
        $pdo->commit();
        json_ok(['invoice_id' => null, 'invoice_number' => 'NO_INVOICE', 'qr_content' => null], 'Order processed without invoice.');
    }

    // --- 开票流程 ---
    $compliance_system = $store_config['billing_system'];
    
    // --- [PHASE 3a MODIFIED] ---
    // 
    // 依赖: allocate_invoice_number (来自 pos_repo.php)
    // 传递 $store_config['invoice_prefix'] 而不是 $store_config
    if (empty($store_config['invoice_prefix'])) {
         $pdo->rollBack();
         json_error('开票失败：门店缺少票号前缀 (invoice_prefix) 配置。', 412);
    }
    [$series, $invoice_number] = allocate_invoice_number(
        $pdo, 
        $store_config['invoice_prefix'], 
        $compliance_system
    );
    // --- [PHASE 3a END MOD] ---

    // [A2 UTC SYNC] 获取当前 UTC 时间
    // 依赖: datetime_helper.php (已通过 pos_helper.php 加载)
    $now_utc = utc_now();
    $issued_at_micro_utc_str = $now_utc->format('Y-m-d H:i:s.u');
    $issued_at_utc_str = $now_utc->format('Y-m-d H:i:s');
    // [A2 UTC SYNC] END

    $compliance_data = null;
    $qr_payload = null;
    if ($compliance_system) {
        $handler_path = realpath(__DIR__ . "/../../../pos_backend/compliance/{$compliance_system}Handler.php");
        if ($handler_path && file_exists($handler_path)) {
            require_once $handler_path;
            $class = $compliance_system . 'Handler';
            if (class_exists($class)) {
                $issuer_nif = (string)$store_config['tax_id'];
                // [PHASE 3a] TICKETBAI/VERIFACTU 依赖于前一个 hash
                $stmt_prev = $pdo->prepare(
                    "SELECT compliance_data FROM pos_invoices 
                     WHERE compliance_system=:system AND series=:series AND issuer_nif=:nif 
                     ORDER BY `number` DESC LIMIT 1"
                );
                $stmt_prev->execute([':system'=>$compliance_system, ':series'=>$series, ':nif'=>$issuer_nif]);
                $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);
                $previous_hash = $prev ? (json_decode($prev['compliance_data'] ?? '[]', true)['hash'] ?? null) : null;
                
                // [A2 UTC SYNC] 使用 $issued_at_micro_utc_str
                $invoiceData = ['series'=>$series,'number'=>$invoice_number,'issued_at'=>$issued_at_micro_utc_str,'final_total'=>$final_total];
                $handler = new $class();
                $compliance_data = $handler->generateComplianceData($pdo, $invoiceData, $previous_hash);
                if (is_array($compliance_data)) $qr_payload = $compliance_data['qr_content'] ?? null;
            }
        }
    }

    $taxable_base = round($final_total / (1 + ($vat_rate / 100)), 2);
    $vat_amount   = round($final_total - $taxable_base, 2);

    $stmt_invoice = $pdo->prepare("
        INSERT INTO pos_invoices (invoice_uuid, store_id, user_id, shift_id, issuer_nif, series, `number`, issued_at, invoice_type, taxable_base, vat_amount, discount_amount, final_total, status, compliance_system, compliance_data, payment_summary) 
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt_invoice->execute([
        bin2hex(random_bytes(16)), $store_id, $user_id, $shift_id, (string)$store_config['tax_id'],
        $series, $invoice_number, $issued_at_micro_utc_str, // [A2 UTC SYNC] 使用带毫秒的 UTC 时间
        'F2', $taxable_base, $vat_amount, $discount_amount, $final_total,
        'ISSUED', $compliance_system, json_encode($compliance_data, JSON_UNESCAPED_UNICODE),
        json_encode($payment_summary, JSON_UNESCAPED_UNICODE)
    ]);
    $invoice_id = (int)$pdo->lastInsertId();

    if ($member_id) {
        $pdo->prepare("UPDATE pos_member_points_log SET invoice_id = ? WHERE user_id = ? AND invoice_id IS NULL ORDER BY id DESC LIMIT 2")
            ->execute([$invoice_id, $user_id]);
    }

    $sql_item = "INSERT INTO pos_invoice_items (
                   invoice_id, menu_item_id, variant_id, 
                   item_name, variant_name, 
                   item_name_zh, item_name_es, variant_name_zh, variant_name_es,
                   quantity, 
                   unit_price, unit_taxable_base, vat_rate, 
                   vat_amount, customizations
               ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt_item = $pdo->prepare($sql_item);

    // --- [PHASE 3b MOD] START: 打印任务 ---
    $print_jobs = [];
    $kitchen_items = []; // KDS厨房单的条目
    
    // [PHASE 3b] $series 已经是 {Prefix}Y{YY} (e.g., S1Y25)
    // $invoice_number 是序列号 (e.g., 1001)
    $full_invoice_number = $series . '-' . $invoice_number; // e.g., S1Y25-1001
    // [PHASE 3b] 使用纯数字 $invoice_number 作为取餐号
    $pickup_number_human = (string)$invoice_number; 

    $cup_index = 1; // [PHASE 3b] 杯序号计数器
    
    foreach ($cart as $i => $item) {
        $qty = max(1, (int)($item['qty'] ?? 1));
        $unit_price = (float)($item['final_price'] ?? $item['unit_price_eur'] ?? 0);
        $item_total = round($unit_price * $qty, 2);
        $item_tax_base_total = round($item_total / (1 + ($vat_rate / 100)), 2);
        $item_vat_amount = round($item_total - $item_tax_base_total, 2);
        $unit_tax_base = ($qty > 0) ? round($item_tax_base_total / $qty, 4) : 0;
        $custom = ['ice' => $item['ice'] ?? null, 'sugar' => $item['sugar'] ?? null, 'addons' => $item['addons'] ?? [], 'remark' => $item['remark'] ?? ''];
        
        $item_name_to_db = (string)($item['title'] ?? ($item['name'] ?? ''));
        $variant_name_to_db = (string)($item['variant_name'] ?? '');
        $item_name_zh_to_db = (string)($item['title_zh'] ?? '');
        $item_name_es_to_db = (string)($item['title_es'] ?? '');
        $variant_name_zh_to_db = (string)($item['variant_name_zh'] ?? '');
        $variant_name_es_to_db = (string)($item['variant_name_es'] ?? '');
        $menu_item_id_to_db = isset($item['product_id']) ? (int)$item['product_id'] : null;
        $variant_id_to_db = isset($item['variant_id']) ? (int)$item['variant_id'] : null;
        
        $stmt_item->execute([
            $invoice_id, $menu_item_id_to_db, $variant_id_to_db,
            $item_name_to_db, $variant_name_to_db,
            $item_name_zh_to_db, $item_name_es_to_db, $variant_name_zh_to_db, $variant_name_es_to_db,
            $qty, $unit_price, $unit_tax_base, $vat_rate, $item_vat_amount, 
            json_encode($custom, JSON_UNESCAPED_UNICODE)
        ]);

        // [PHASE 3b] 准备 KDS 厨房单 和 杯贴 的变量
        
        $customizations_parts = [];
        // TODO: 此处应按需从 $custom['ice'] 和 $custom['sugar'] 的 *code* 去查询翻译表
        if (!empty($custom['ice'])) $customizations_parts[] = 'Ice:' . $custom['ice']; 
        if (!empty($custom['sugar'])) $customizations_parts[] = 'Sugar:' . $custom['sugar'];
        if (!empty($custom['addons'])) $customizations_parts[] = '+' . implode(',+', $custom['addons']);
        $customization_detail_str = implode(' / ', $customizations_parts);
        
        // 厨房单条目
        $kitchen_items[] = [
            'item_name' => $item_name_zh_to_db,
            'variant_name' => $variant_name_zh_to_db,
            'customizations' => $customization_detail_str,
            'qty' => $qty,
            'remark' => (string)($custom['remark'] ?? ''),
        ];
        
        // [PHASE 3b] 依赖 pos_repo::get_cart_item_codes
        // $kds_codes = get_cart_item_codes($pdo, $item); // 依赖 cart.js (Phase 4.1)
        
        // [PHASE 4.1 修复] cart.js 尚未修改，我们先从 item 中读取已有的 kds_code
        $p_code = $item['product_code'] ?? 'NA';
        $cup_code = $item['cup_code'] ?? 'NA';
        $ice_code = $item['ice'] ?? 'NA';
        $sweet_code = $item['sugar'] ?? 'NA';

        // [PHASE 3b] 为每一杯生成一个杯贴任务
        for ($i_qty = 0; $i_qty < $qty; $i_qty++) {
            $kds_internal_id = $store_config['invoice_prefix'] . '-' . $invoice_number . '-' . $cup_index;
            
            $item_print_data = [
                // 计划书 3.1.B 定义的变量
                'pickup_number'    => $pickup_number_human, // e.g., "1001"
                'kds_id'           => $kds_internal_id, // e.g., "S1-1001-1"
                'store_prefix'     => $store_config['invoice_prefix'], // e.g., "S1"
                'invoice_sequence' => $invoice_number, // e.g., 1001
                'cup_index'        => $cup_index, // e.g., 1
                'product_code'     => $p_code,
                'cup_code'         => $cup_code,
                'ice_code'         => $ice_code,
                'sweet_code'       => $sweet_code,
                'cup_order_number' => $kds_internal_id, // 兼容旧模板
                // 附加变量
                'item_name'         => $item_name_to_db,
                'variant_name'      => $variant_name_to_db,
                'item_name_zh'      => $item_name_zh_to_db,
                'item_name_es'      => $item_name_es_to_db,
                'variant_name_zh'   => $variant_name_zh_to_db,
                'variant_name_es'   => $variant_name_es_to_db,
                'customization_detail' => $customization_detail_str,
                'remark'            => (string)($custom['remark'] ?? ''),
                'store_name'        => $store_config['store_name'] ?? ''
            ];

            $print_jobs[] = [
                'type'         => 'CUP_STICKER',
                'data'         => $item_print_data,
                'printer_role' => 'POS_STICKER' // <--- [GEMINI TYPO FIX 2025-11-16] 修正拼写
            ];
            
            $cup_index++; // 递增杯序号
        }
    }

    // [PHASE 3b] 准备厨房单
    $kitchen_data = [
        'invoice_number' => $full_invoice_number,
        'issued_at'      => $issued_at_utc_str, // [A2 UTC SYNC] 使用 UTC 字符串
        'items'          => $kitchen_items,
        // [PHASE 3b] 添加新变量
        'pickup_number' => $pickup_number_human,
        'invoice_full'  => $full_invoice_number,
    ];
    $print_jobs[] = [
        'type'         => 'KITCHEN_ORDER',
        'data'         => $kitchen_data,
        'printer_role' => 'KDS_PRINTER' // <--- 关键：标记角色
    ];

    // [PHASE 3b] 准备顾客小票
    $receipt_data = [
        'store_name'      => $store_config['store_name'] ?? '',
        'store_address'   => $store_config['store_address'] ?? '',
        'store_tax_id'    => $store_config['tax_id'] ?? '',
        'invoice_number'  => $full_invoice_number, // 旧变量 (兼容)
        'issued_at'       => $issued_at_utc_str, // [A2 UTC SYNC] 使用 UTC 字符串
        'cashier_name'    => $_SESSION['pos_display_name'] ?? 'N/A',
        'qr_code'         => $qr_payload,
        'subtotal'        => number_format((float)($promoResult['subtotal'] ?? 0.0), 2),
        'discount_amount' => number_format($discount_amount, 2),
        'final_total'     => number_format($final_total, 2),
        'taxable_base'    => number_format($taxable_base, 2),
        'vat_amount'      => number_format($vat_amount, 2),
        'payment_methods' => '...', // TODO: 格式化 $payment_summary
        'change'          => number_format((float)($payment_summary['change'] ?? 0.0), 2),
        'items'           => $cart, // 模板引擎需要自己循环 cart
        // [PHASE 3b] 添加新变量
        'pickup_number'    => $pickup_number_human,
        'invoice_full'     => $full_invoice_number,
        'invoice_series'   => $series,
        'invoice_sequence' => $invoice_number,
    ];
    $print_jobs[] = [
        'type'         => 'RECEIPT',
        'data'         => $receipt_data,
        'printer_role' => 'POS_RECEIPT' // <--- 关键：标记角色
    ];
    // --- [PHASE 3b MOD] END: 打印任务 ---

    
    $pdo->commit();

    // [GEMINI AUDIT FIX 2025-11-16] 修复 json_ok 参数颠倒
    json_ok([
        'invoice_id'=>$invoice_id,
        'invoice_number'=>$full_invoice_number, // e.g., S1Y25-1001
        'qr_content'=>$qr_payload,
        'print_jobs' => $print_jobs
    ], 'Order created.');
}


/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/calculate_promotions.php              */
/* -------------------------------------------------------------------------- */
function handle_cart_calculate(PDO $pdo, array $config, array $input_data): void {
    $json_data = $input_data;
    if (!isset($json_data['cart'])) json_error('Cart data is missing.', 400);

    $cart = $json_data['cart'];
    $couponCode = $json_data['coupon_code'] ?? null;
    $member_id = isset($json_data['member_id']) ? (int)$json_data['member_id'] : null;
    $points_to_redeem = isset($json_data['points_to_redeem']) ? (int)$json_data['points_to_redeem'] : 0;
    
    // 依赖: PromotionEngine (来自 pos_helper.php)
    $engine = new PromotionEngine($pdo);
    $promoResult = $engine->applyPromotions($cart, $couponCode);
    
    $final_total = (float)$promoResult['final_total'];
    $points_discount = 0.0;
    $points_redeemed = 0;

    if ($member_id && $points_to_redeem > 0 && $final_total > 0) {
        $stmt_member = $pdo->prepare("SELECT points_balance FROM pos_members WHERE id = ? AND is_active = 1");
        $stmt_member->execute([$member_id]);
        $member = $stmt_member->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            $current_points = (float)$member['points_balance'];
            $max_possible_discount = $final_total;
            $max_points_for_discount = floor($max_possible_discount * 100);
            $points_can_be_used = min($points_to_redeem, $current_points, $max_points_for_discount);

            if ($points_can_be_used > 0) {
                $points_redeemed = $points_can_be_used;
                $points_discount = floor($points_can_be_used) / 100.0;
                $final_total -= $points_discount;
            }
        }
    }
    
    $total_discount_amount = (float)$promoResult['discount_amount'] + $points_discount;

    $result = [
        'cart' => $promoResult['cart'],
        'subtotal' => $promoResult['subtotal'],
        'discount_amount' => number_format($total_discount_amount, 2, '.', ''),
        'final_total' => number_format($final_total, 2, '.', ''),
        'points_redemption' => [
            'points_redeemed' => $points_redeemed,
            'discount_amount' => number_format($points_discount, 2, '.', '')
        ]
    ];

    json_ok($result, 'Promotions and points calculated successfully.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_hold_handler.php                  */
/* -------------------------------------------------------------------------- */
function handle_hold_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $sort_by = $_GET['sort'] ?? 'time_desc';
    $order_clause = 'created_at DESC';
    if ($sort_by === 'amount_desc') {
        $order_clause = 'total_amount DESC';
    }
    $stmt = $pdo->prepare("SELECT id, note, created_at, total_amount FROM pos_held_orders WHERE store_id = ? ORDER BY $order_clause");
    $stmt->execute([$store_id]);
    $held_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($held_orders, 'Held orders retrieved.');
}
function handle_hold_save(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $note = trim($input_data['note'] ?? '');
    $cart_data = $input_data['cart'] ?? [];

    if (empty($note)) json_error('备注/桌号不能为空 (Note cannot be empty).', 400);
    if (empty($cart_data)) json_error('不能挂起一个空的购物车 (Cannot hold an empty cart).', 400);
    
    $total_amount = 0;
    foreach ($cart_data as $item) {
        $total_amount += ($item['unit_price_eur'] ?? 0) * ($item['qty'] ?? 1);
    }

    $stmt = $pdo->prepare("INSERT INTO pos_held_orders (store_id, user_id, note, cart_data, total_amount) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$store_id, $user_id, $note, json_encode($cart_data), $total_amount]);
    $new_id = $pdo->lastInsertId();
    json_ok(['id' => $new_id], 'Order held successfully.');
}
function handle_hold_restore(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $id = (int)($_GET['id'] ?? $input_data['id'] ?? 0);
    if (!$id) json_error('Invalid hold ID.', 400);

    $pdo->beginTransaction();
    $stmt_get = $pdo->prepare("SELECT cart_data FROM pos_held_orders WHERE id = ? AND store_id = ? FOR UPDATE");
    $stmt_get->execute([$id, $store_id]);
    $cart_json = $stmt_get->fetchColumn();
    
    if ($cart_json === false || empty($cart_json)) {
        $pdo->rollBack();
        json_error('Held order not found or is empty.', 404);
    }
    
    $cart_data = json_decode($cart_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $pdo->rollBack();
        json_error('Failed to parse held cart data.', 500);
    }

    $stmt_delete = $pdo->prepare("DELETE FROM pos_held_orders WHERE id = ?");
    $stmt_delete->execute([$id]);
    $pdo->commit();
    
    json_ok($cart_data, 'Order restored.');
}


/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_transaction_handler.php           */
/* -------------------------------------------------------------------------- */
function handle_txn_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    
    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tz = APP_DEFAULT_TIMEZONE;
    
    $sql = "SELECT id, series, number, issued_at, final_total, status FROM pos_invoices WHERE store_id = :store_id";
    $params = [':store_id' => $store_id];

    if ($start_date && $end_date) {
        // [A2 UTC SYNC] 使用 to_utc_window 转换查询范围
        [$utc_start, $utc_end] = to_utc_window($start_date, $end_date, $tz);

        $sql .= " AND issued_at BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $utc_start->format('Y-m-d H:i:s');
        $params[':end_date'] = $utc_end->format('Y-m-d H:i:s');
    }
    $sql .= " ORDER BY issued_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // [A3 UTC DISPLAY FIX]
    // 移除: foreach ($transactions as &$txn) { ... fmt_local ... }
    // 理由: 必须发送原始 UTC 字符串到前端，由 JS 负责转换。

    json_ok($transactions, 'Transactions retrieved.');
}
function handle_txn_get_details(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Invalid Invoice ID.', 400);

    $stmt_invoice = $pdo->prepare("
        SELECT pi.*, ku.display_name AS cashier_name
        FROM pos_invoices pi
        LEFT JOIN kds_users ku ON pi.user_id = ku.id
        WHERE pi.id = ? AND pi.store_id = ?
    ");
    $stmt_invoice->execute([$id, $store_id]);
    $invoice = $stmt_invoice->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) json_error('Invoice not found.', 404);

    // [A3 UTC DISPLAY FIX]
    // 移除: $invoice['issued_at'] = fmt_local(...)
    // 理由: 必须发送原始 UTC 字符串到前端。

    $stmt_items = $pdo->prepare("SELECT * FROM pos_invoice_items WHERE invoice_id = ?");
    $stmt_items->execute([$id]);
    $invoice['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $invoice['payment_summary_decoded'] = json_decode($invoice['payment_summary'] ?? '[]', true);
    $invoice['compliance_data_decoded'] = json_decode($invoice['compliance_data'] ?? '[]', true);

    json_ok($invoice, 'Invoice details retrieved.');
}