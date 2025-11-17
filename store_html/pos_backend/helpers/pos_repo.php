<?php
/**
 * Toptea Store - POS 核心帮助库 (Repo)
 * 包含所有 POS API 处理器共用的业务逻辑函数。
 * (迁移自 /pos/api/*)
 * Version: 1.1.0 (Phase 3a: Invoice Numbering Refactor)
 * Date: 2025-11-08
 *
 * [B1.2 PASS]: Added pass-related helpers (VR invoice, validation, allocation, tags, plan details)
 *
 * [GEMINI SUPER-ENGINEER FIX (Error 2)]:
 * 1. 重写 compute_expected_cash 函数。
 * 2. 移除了对不存在的表 (pos_payments, pos_orders, pos_cash_movements) 的查询。
 * 3. 新逻辑改为调用 GetaInvoiceSummaryForPeriod() 来获取正确的现金总额。
 * 4. 因表不存在，cash_in, cash_out, cash_refunds 硬编码为 0.0。
 *
 * [GEMINI AUDIT FIX 2025-11-16]:
 * 1. 移除了所有与次卡 (pass) 相关的函数 (get_cart_item_tags, get_pass_plan_details, 
 * allocate_vr_invoice_number, calculate_pass_allocation, 
 * validate_pass_purchase_order, create_pass_records)。
 * 2. 这些函数已在 pos_repo_ext_pass.php 和 pos_pass_helper.php 中定义。
 * 3. 保留它们在此处会导致 PHP "Cannot redeclare function" 致命错误。
 * 4. [get_addons_with_tags] 新增了 get_addons_with_tags() 函数的实现，
 * 修复了 pos_registry_ext_pass.php 中的调用错误。
 *
 * [GEMINI FATAL ERROR FIX 2025-11-16]:
 * 1. 重写 get_addons_with_tags() 的实现。
 * 2. 旧实现返回的数组以 'id' 为键，但业务逻辑 (pos_pass_helper) 期望以 'addon_code' (key) 为键。
 * 3. 此修复将使次卡核销计价 (P2) 逻辑得以正确运行。
 */

/* -------------------------------------------------------------------------- */
/* 任务 2.2: 门店配置 & 购物车编码                         */
/* -------------------------------------------------------------------------- */

/**
 * * 获取完整的门店配置, 包括所有新的打印机角色字段
 */
if (!function_exists('get_store_config_full')) {
    function get_store_config_full(PDO $pdo, int $store_id): array {
        $stmt_store = $pdo->prepare("SELECT * FROM kds_stores WHERE id = :store_id LIMIT 1");
        $stmt_store->execute([':store_id' => $store_id]);
        $store_config = $stmt_store->fetch(PDO::FETCH_ASSOC);
        if (!$store_config) {
            throw new Exception("Store configuration for store_id #{$store_id} not found.");
        }
        return $store_config;
    }
}

/**
 * * 从购物车 item 中提取或查询 KDS/SOP 所需的机器码
 */
if (!function_exists('get_cart_item_codes')) {
    function get_cart_item_codes(PDO $pdo, array $item): array {
        $product_id = (int)($item['product_id'] ?? 0); // This is pos_menu_items.id
        $variant_id = (int)($item['variant_id'] ?? 0);
        
        $p_code = null;
        $cup_id = null;
        if ($variant_id > 0) {
            $stmt_pv = $pdo->prepare("
                SELECT mi.product_code, pv.cup_id
                FROM pos_item_variants pv
                JOIN pos_menu_items mi ON pv.menu_item_id = mi.id
                WHERE pv.id = ?
            ");
            $stmt_pv->execute([$variant_id]);
            $row = $stmt_pv->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $p_code = $row['product_code'];
                $cup_id = (int)$row['cup_id'];
            }
        }
        
        $cup_code = null;
        if ($cup_id > 0) {
            $stmt_cup = $pdo->prepare("SELECT cup_code FROM kds_cups WHERE id = ? AND deleted_at IS NULL");
            $stmt_cup->execute([$cup_id]);
            $cup_code = $stmt_cup->fetchColumn();
        }
        
        $ice_code = $item['ice'] ?? null;
        $sweet_code = $item['sugar'] ?? null;

        return [
            'product_code' => $p_code,
            'cup_code'     => $cup_code ? (string)$cup_code : null,
            'ice_code'     => $ice_code ? (string)$ice_code : null,
            'sweet_code'   => $sweet_code ? (string)$sweet_code : null,
        ];
    }
}


/**
 * [GEMINI FATAL ERROR FIX 2025-11-16]
 * 修复：get_addons_with_tags() 函数实现。
 * 此函数被 pos_registry_ext_pass.php (核销流程) 调用。
 * 逻辑从 pos_registry_ops.php (handle_data_load) 迁移而来，并
 * 修正了返回的数组，使其以 addon_code (key) 为键。
 */
if (!function_exists('get_addons_with_tags')) {
    function get_addons_with_tags(PDO $pdo): array {
        try {
            // 1. 获取所有激活的加料
            $addons_sql = "
                SELECT a.id, a.addon_code AS `key`, a.name_zh AS label_zh, a.name_es AS label_es, a.price_eur 
                FROM pos_addons a
                WHERE a.is_active = 1 AND a.deleted_at IS NULL 
                ORDER BY a.sort_order ASC
            ";
            $all_addons_list = $pdo->query($addons_sql)->fetchAll(PDO::FETCH_ASSOC);

            if (empty($all_addons_list)) return [];

            // 2. 抓取所有 addon 标签
            $addon_ids = array_map('intval', array_column($all_addons_list, 'id'));
            $addon_tags_map = [];
            
            if (!empty($addon_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($addon_ids), '?'));
                $sql_addon_tags = "SELECT map.addon_id, t.tag_code 
                                   FROM pos_addon_tag_map map
                                   JOIN pos_tags t ON map.tag_id = t.tag_id
                                   WHERE map.addon_id IN ($in_placeholders)";
                $stmt_addon_tags = $pdo->prepare($sql_addon_tags);
                $stmt_addon_tags->execute($addon_ids);
                while ($row = $stmt_addon_tags->fetch(PDO::FETCH_ASSOC)) {
                    $addon_tags_map[(int)$row['addon_id']][] = $row['tag_code'];
                }
            }
            
            // 3. 构建以 addon_code (key) 为键的最终数组
            $addons_by_key = [];
            foreach ($all_addons_list as $addon) {
                $addon_id = (int)$addon['id'];
                $addon_key = $addon['key'];
                
                $addon['tags'] = $addon_tags_map[$addon_id] ?? [];
                
                $addons_by_key[$addon_key] = $addon;
            }
            
            return $addons_by_key;

        } catch (PDOException $e) { 
            error_log("get_addons_with_tags failed: " . $e->getMessage());
            return []; 
        }
    }
}


/* -------------------------------------------------------------------------- */
/* [B1.2] 次卡 (PASS) 核心业务逻辑                                      */
/* -------------------------------------------------------------------------- */

/**
 * [B1.2] 获取购物车中所有 menu_item_id 关联的 tags
 * @param PDO $pdo
 * @param array $cart_menu_item_ids (来自 $item['product_id'])
 * @return array [ menu_item_id => [tag_code1, tag_code2] ]
 */
if (!function_exists('get_cart_item_tags')) {
    function get_cart_item_tags(PDO $pdo, array $cart_menu_item_ids): array {
        if (empty($cart_menu_item_ids)) {
            return [];
        }
        $unique_ids = array_unique(array_filter(array_map('intval', $cart_menu_item_ids)));
        if (empty($unique_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($unique_ids), '?'));
        
        $sql = "
            SELECT map.product_id, t.tag_code
            FROM pos_product_tag_map map
            JOIN pos_tags t ON map.tag_id = t.tag_id
            WHERE map.product_id IN ($placeholders)
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($unique_ids);
        
        $tags_by_item = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tags_by_item[(int)$row['product_id']][] = $row['tag_code'];
        }
        
        return $tags_by_item;
    }
}

/* * [GEMINI AUDIT FIX 2025-11-16]
 * 移除了以下所有函数，因为它们在 pos_pass_helper.php 或 pos_repo_ext_pass.php 
 * 中被重新定义，保留它们会导致 PHP 致命错误。
 *
 * if (!function_exists('get_pass_plan_details')) { ... }
 * if (!function_exists('allocate_vr_invoice_number')) { ... }
 * if (!function_exists('calculate_pass_allocation')) { ... }
 * if (!function_exists('validate_pass_purchase_order')) { ... }
 * if (!function_exists('create_pass_records')) { ... }
 */


/* -------------------------------------------------------------------------- */
/* 迁移自: pos_shift_handler.php                                              */
/* -------------------------------------------------------------------------- */

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}
if (!function_exists('col_exists')) {
    function col_exists(PDO $pdo, string $table, string $col): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $col]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('compute_expected_cash')) {
    /**
     * [GEMINI SUPER-ENGINEER FIX (Error 2)]
     * 重写此函数以使用 getInvoiceSummaryForPeriod，因为它查询的是真实存在的 pos_invoices 表。
     * 删除了对 pos_payments, pos_orders, pos_cash_movements 的无效查询。
     */
    function compute_expected_cash(PDO $pdo, int $store_id, string $start_iso, string $end_iso, float $starting_float): array {
        
        // 1. [FIX] 调用 getInvoiceSummaryForPeriod 来获取基于 pos_invoices 的正确支付汇总
        // 该函数已正确处理 payment_summary JSON 解析和找零。
        $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $start_iso, $end_iso);
        
        $cash_sales   = (float)($full_summary['payments']['Cash'] ?? 0.0);
        
        // 2. [FIX] 由于 pos_cash_movements 表在 .sql 中不存在，必须将 cash_in/out 硬编码为 0
        $cash_in      = 0.0;
        $cash_out     = 0.0;
        
        // 3. [FIX] 由于没有退款表或清晰的退款支付逻辑，cash_refunds 也必须为 0
        $cash_refunds = 0.0;

        $expected_cash = (float)$starting_float + (float)$cash_sales + (float)$cash_in - (float)$cash_out - (float)$cash_refunds;

        return [
            'starting_float' => round((float)$starting_float, 2),
            'cash_sales'     => round((float)$cash_sales, 2),
            'cash_in'        => round((float)$cash_in, 2),
            'cash_out'       => round((float)$cash_out, 2),
            'cash_refunds'   => round((float)$cash_refunds, 2),
            'expected_cash'  => round((float)$expected_cash, 2),
        ];
    }
}


/* -------------------------------------------------------------------------- */
/* 迁移自: submit_order.php                                                   */
/* -------------------------------------------------------------------------- */

if (!function_exists('to_float')) {
    function to_float($v): float {
      if (is_int($v) || is_float($v)) return (float)$v;
      if (!is_string($v)) return 0.0;
      $s = trim($v);
      $s = preg_replace('/[^\d\.,\-]/u', '', $s);
      if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
      } else {
        $s = str_replace(',', '', $s);
      }
      if ($s === '' || $s === '-' || $s === '.') return 0.0;
      return is_numeric($s) ? (float)$s : 0.0;
    }
}

if (!function_exists('extract_payment_totals')) {
    function extract_payment_totals($payment): array {
      $cash = 0.0; $card = 0.0; $platform = 0.0;
    
      if (!is_array($payment)) {
        $tmp = to_float($payment);
        if ($tmp > 0) $cash += $tmp;
        $payment = [];
      }
    
      // 1) summary = 对象 {cash, card, platform...}
      if (isset($payment['summary']) && is_array($payment['summary']) && array_values($payment['summary']) !== $payment['summary']) {
        $s = $payment['summary'];
        $cash     += to_float($s['cash'] ?? 0);
        $card     += to_float($s['card'] ?? 0);
        $platform += to_float($s['platform'] ?? 0)
                   + to_float($s['bizum'] ?? 0) + to_float($s['qr'] ?? 0)
                   + to_float($s['wechat'] ?? 0) + to_float($s['alipay'] ?? 0)
                   + to_float($s['online'] ?? 0) + to_float($s['stripe'] ?? 0)
                   + to_float($s['paypal'] ?? 0);
      }
    
      // 2) summary = 数组 [{method:'Cash', amount:4.5}, ...]
      if (isset($payment['summary']) && is_array($payment['summary']) && array_values($payment['summary']) === $payment['summary']) {
        foreach ($payment['summary'] as $line) {
          if (!is_array($line)) continue;
          $amount = to_float($line['amount'] ?? $line['value'] ?? 0);
          $m = strtolower((string)($line['method'] ?? $line['type'] ?? $line['channel'] ?? $line['name'] ?? ''));
          if (in_array($m, ['cash','efectivo'])) $cash += $amount;
          elseif (in_array($m, ['card','tarjeta'])) $card += $amount;
          else $platform += $amount;
        }
      }
    
      // 3) 顶层字段
      $cash     += to_float($payment['cash'] ?? 0);
      $card     += to_float($payment['card'] ?? 0);
      $platform += to_float($payment['platform'] ?? 0)
                 + to_float($payment['bizum'] ?? 0) + to_float($payment['qr'] ?? 0)
                 + to_float($payment['wechat'] ?? 0) + to_float($payment['alipay'] ?? 0)
                 + to_float($payment['online'] ?? 0) + to_float($payment['stripe'] ?? 0)
                 + to_float($payment['paypal'] ?? 0);
    
      // 4) methods/tenders/lines
      foreach (['methods','tenders','lines'] as $k) {
        if (!empty($payment[$k]) && is_array($payment[$k])) {
          foreach ($payment[$k] as $m) {
            if (!is_array($m)) continue;
            $amount = to_float($m['amount'] ?? $m['value'] ?? 0);
            $t = strtolower((string)($m['type'] ?? $m['method'] ?? $m['channel'] ?? $m['name'] ?? ''));
            if (in_array($t, ['cash','efectivo'])) $cash += $amount;
            elseif (in_array($t, ['card','tarjeta'])) $card += $amount;
            else $platform += $amount;
          }
        }
      }
    
      // 5) paid / breakdown
      foreach (['paid','breakdown'] as $obj) {
        if (!empty($payment[$obj]) && is_array($payment[$obj])) {
          $o = $payment[$obj];
          $cash     += to_float($o['cash'] ?? 0);
          $card     += to_float($o['card'] ?? 0);
          $platform += to_float($o['platform'] ?? 0)
                     + to_float($o['bizum'] ?? 0) + to_float($o['qr'] ?? 0)
                     + to_float($o['wechat'] ?? 0) + to_float($o['alipay'] ?? 0)
                     + to_float($o['online'] ?? 0) + to_float($o['stripe'] ?? 0)
                     + to_float($o['paypal'] ?? 0);
        }
      }
    
      // 6) 兜底
      if ($cash == 0.0 && $card == 0.0 && $platform == 0.0) {
        $total  = to_float($payment['total']  ?? 0);
        $paid   = to_float($payment['paid']   ?? 0);
        $change = to_float($payment['change'] ?? 0);
        $candidate = 0.0;
        if ($paid > 0 || $change > 0) $candidate = max(0.0, $paid - $change);
        elseif ($total > 0) $candidate = $total;
        if ($candidate > 0) $cash = round($candidate, 2);
      }
    
      $cash = round($cash, 2); $card = round($card, 2); $platform = round($platform, 2);
      $sumPaid = round($cash + $card + $platform, 2);
    
      if (!isset($payment['summary']) || !is_array($payment['summary']) || array_values($payment['summary']) === $payment['summary']) {
        $payment['summary'] = ['cash'=>$cash,'card'=>$card,'platform'=>$platform,'total'=>$sumPaid];
      } else {
        $payment['summary']['cash'] = $cash;
        $payment['summary']['card'] = $card;
        $payment['summary']['platform'] = $platform;
        $payment['summary']['total'] = $sumPaid;
      }
      return [$cash, $card, $platform, $sumPaid, $payment];
    }
}

if (!function_exists('allocate_invoice_number')) {
    /**
     * [PHASE 3a MODIFIED] 
     * 新的发票号逻辑 (原子计数器)
     *
     * @param PDO $pdo
     * @param string $invoice_prefix 门店前缀 (e.g., "S1")
     * @param string $compliance_system (e.g., "TICKETBAI")
     * @return array [string $full_prefix, int $next_number]
     * @throws Exception
     */
    function allocate_invoice_number(PDO $pdo, string $invoice_prefix, ?string $compliance_system): array {
        if (empty($invoice_prefix)) {
            throw new Exception('Invoice prefix cannot be empty.');
        }

        // 1. 确定系列 (Series)
        // 格式: {Prefix}Y{YY} (e.g., S1Y25 for 2025)
        $year_short = date('y'); // "25"
        $series = $invoice_prefix . 'Y' . $year_short;
        $compliance_system_key = $compliance_system ?: 'NONE';

        // 2. 尝试原子更新 (INSERT ... ON DUPLICATE KEY UPDATE)
        try {
            // 确保该系列存在，如果不存在，则从 0 开始创建
            $sql_init = "
                INSERT INTO pos_invoice_counters 
                    (invoice_prefix, series, compliance_system, current_number)
                VALUES 
                    (:prefix, :series, :system, 0)
                ON DUPLICATE KEY UPDATE 
                    current_number = current_number;
            ";
            $stmt_init = $pdo->prepare($sql_init);
            $stmt_init->execute([
                ':prefix' => $invoice_prefix,
                ':series' => $series,
                ':system' => $compliance_system_key
            ]);

            // 原子更新并获取新ID
            $sql_bump = "
                UPDATE pos_invoice_counters
                SET current_number = LAST_INSERT_ID(current_number + 1)
                WHERE series = :series AND compliance_system = :system;
            ";
            $stmt_bump = $pdo->prepare($sql_bump);
            $stmt_bump->execute([
                ':series' => $series,
                ':system' => $compliance_system_key
            ]);
            
            // 获取 LAST_INSERT_ID()
            $next_number = (int)$pdo->lastInsertId();

            if ($next_number > 0) {
                // 成功！返回前缀和新号码
                return [$series, $next_number];
            } else {
                // 如果 LAST_INSERT_ID() 返回 0 (例如，在某些复制或特定MySQL版本下)
                // 我们必须再次查询以获取当前值
                $stmt_get = $pdo->prepare("SELECT current_number FROM pos_invoice_counters WHERE series = :series AND compliance_system = :system");
                $stmt_get->execute([':series' => $series, ':system' => $compliance_system_key]);
                $next_number = (int)$stmt_get->fetchColumn();
                if ($next_number > 0) {
                     return [$series, $next_number];
                }
                
                throw new Exception("Failed to bump invoice counter, LAST_INSERT_ID and subsequent SELECT were 0.");
            }

        } catch (Throwable $e) {
            // 3. 回退 (Fallback) - 如果 pos_invoice_counters 表不存在或失败
            error_log("CRITICAL: Invoice counter failed, falling back to MAX(number). Error: " . $e->getMessage());

            $stmt_max = $pdo->prepare(
                "SELECT MAX(`number`) FROM pos_invoices WHERE series = :series"
            );
            $stmt_max->execute([':series' => $series]);
            $max = (int)$stmt_max->fetchColumn();
            
            $next_number = $max + 1;
            
            return [$series, $next_number];
        }
    }
}


/* -------------------------------------------------------------------------- */
/* 迁移自: eod_summary_handler.php                                            */
/* -------------------------------------------------------------------------- */

if (!function_exists('getInvoiceSummaryForPeriod')) {
    function getInvoiceSummaryForPeriod(PDO $pdo, int $store_id, string $start_utc, string $end_utc): array {
        $invoices_table = 'pos_invoices';

        // 1. 计算交易总览
        $sqlInv = "SELECT 
                       COUNT(*) AS transactions_count,
                       COALESCE(SUM(taxable_base + vat_amount), 0) AS system_gross_sales,
                       COALESCE(SUM(discount_amount), 0) AS system_discounts,
                       COALESCE(SUM(final_total), 0) AS system_net_sales,
                       COALESCE(SUM(vat_amount), 0) AS system_tax
                   FROM `{$invoices_table}`
                   WHERE store_id=:sid AND issued_at BETWEEN :s AND :e AND status = 'ISSUED'";
        
        $st = $pdo->prepare($sqlInv);
        $st->execute([':sid' => $store_id, ':s' => $start_utc, ':e' => $end_utc]);
        $summary = $st->fetch(PDO::FETCH_ASSOC);

        // 2. 计算支付方式分类汇总
        $sqlPay = "SELECT payment_summary FROM `{$invoices_table}` WHERE store_id=:sid AND issued_at BETWEEN :s AND :e AND status = 'ISSUED'";
        $stmtPay = $pdo->prepare($sqlPay);
        $stmtPay->execute([':sid' => $store_id, ':s' => $start_utc, ':e' => $end_utc]);
        
        $breakdown = ['Cash' => 0.0, 'Card' => 0.0, 'Platform' => 0.0];
        
        while ($row = $stmtPay->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['payment_summary'])) continue;
            $payment_data = json_decode($row['payment_summary'], true);
            if (!is_array($payment_data)) continue;
            
            if (isset($payment_data['summary']) && is_array($payment_data['summary'])) {
                $summary_part = $payment_data['summary'];
                if (isset($summary_part[0]) || empty($summary_part)) {
                    foreach ($summary_part as $part) {
                        if (isset($part['method'], $part['amount']) && isset($breakdown[$part['method']])) {
                            $breakdown[$part['method']] += (float)$part['amount'];
                        }
                    }
                } 
                else {
                     if (isset($summary_part['cash'])) $breakdown['Cash'] += (float)$summary_part['cash'];
                     if (isset($summary_part['card'])) $breakdown['Card'] += (float)$summary_part['card'];
                     if (isset($summary_part['platform'])) $breakdown['Platform'] += (float)$summary_part['platform'];
                }
            } 
            else {
                if (isset($payment_data['cash'])) $breakdown['Cash'] += (float)$payment_data['cash'];
                if (isset($payment_data['card'])) $breakdown['Card'] += (float)$payment_data['card'];
                if (isset($payment_data['platform'])) $breakdown['Platform'] += (float)$payment_data['platform'];
            }
            
            if (isset($payment_data['change']) && (float)$payment_data['change'] > 0) {
                $breakdown['Cash'] -= (float)$payment_data['change'];
            }
        }
        
        foreach($breakdown as &$value) {
            $value = max(0, round($value, 2));
        }

        // 3. 组合最终结果
        return [
            'summary' => $summary,
            'payments' => $breakdown
        ];
    }
}