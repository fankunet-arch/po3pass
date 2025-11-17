<?php
declare(strict_types=1);
/**
 * pos_pass_helper.php
 *
 * 辅助函数 - 封装次卡售卖 (P0) 和核销 (P3) 的核心事务逻辑。
 * * Version: 1.0.3 (B1.5 - FIX Errors 1 & 2)
 * Date: 2025-11-14
 *
 * [B1.5 ERROR 1 FIX]
 * - 移除了对幻想函数 generate_compliance_data_for_pass() 的调用。
 * - 新增了 generate_compliance_data() 辅助函数 (基于 pos_registry.php 移植)。
 * - create_redeem_records() 现在正确调用 generate_compliance_data()。
 *
 * [B1.5 ERROR 2 FIX]
 * - 新增了 validate_redeem_limits() 函数的完整实现 (P3 规范)。
 *
 * [B1.4 P4]
 * - 重大修改: create_redeem_records() (P3 核心)
 * - 1. (P4) 仅在 $alloc['extra_total'] > 0 时才创建 `RECEIPT` (加价发票)。
 * - 2. (P4) 总是创建 `PASS_REDEMPTION_SLIP` (核销凭条) 打印任务。
 * - 3. (P4) 依赖 get_pass_print_details() 获取凭条所需数据。
 * - 4. (P3) 返回值变更为数组，包含 VR 和 TP 票号以及打印任务。
 *
 * [B1.3.1]
 * - 修复了 create_redeem_records() 中 KDS 内部 ID ($kds_internal_id) 的生成逻辑。
 * - 移除了重复定义的 get_pass_plan_details。
 *
 * [B1.2]
 * - 初始版本，包含 B1 (售卡) 所需的辅助函数。
 */

// 依赖 (由 pos_helper.php 确保已加载):
// - pos_repo.php (get_store_config_full, allocate_invoice_number)
// - pos_repo_ext_pass.php (allocate_vr_invoice_number)
// - pos_datetime_helper.php (utc_now, fmt_local, APP_DEFAULT_TIMEZONE)
// - compliance/*Handler.php (用于 generate_compliance_data)


if (!function_exists('get_pass_plan_details')) {
    /**
     * [B1.2] 从数据库获取次卡方案详情
     */
    function get_pass_plan_details(PDO $pdo, int $plan_id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM pass_plans WHERE pass_plan_id = ? AND is_active = 1");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        return $plan ?: null;
    }
}


if (!function_exists('create_pass_records')) {
    /**
     * [B1.2] P0 售卡：创建售卡记录 (topup_orders)
     * (注意：B1 阶段简化，暂不处理支付详情，假设已支付)
     */
    function create_pass_records(PDO $pdo, array $context, array $vr_info, array $cart_item, array $plan_details): int {
        
        // 1. 获取上下文
        $store_id  = $context['store_id'];
        $user_id   = $context['user_id'];
        $device_id = $context['device_id'];
        $member_id = $context['member_id'];
        
        // [A2 UTC SYNC] 依赖 datetime_helper.php
        $now_utc = utc_now();
        $now_utc_str = $now_utc->format('Y-m-d H:i:s'); // B1 阶段的表 (topup_orders, member_passes) 均使用 0 精度
        $validity_days = (int)$plan_details['validity_days'];
        
        // 2. 写入 售卡订单 (VR)
        $sql_topup = "
            INSERT INTO topup_orders 
                (pass_plan_id, member_id, quantity, amount_total, store_id, device_id, 
                 sale_user_id, sale_time, voucher_series, voucher_number, review_status)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ";
        $stmt_topup = $pdo->prepare($sql_topup);
        $stmt_topup->execute([
            $plan_details['pass_plan_id'],
            $member_id,
            (int)$cart_item['qty'],
            (float)$cart_item['unit_price_eur'], // B1 阶段, 总价 = 单价 * 1
            $store_id,
            $device_id,
            $user_id,
            $now_utc_str,
            $vr_info['series'],
            $vr_info['number']
        ]);
        $topup_order_id = (int)$pdo->lastInsertId();
        
        // 3. (B1 阶段) 写入/激活 会员持卡
        // B1 阶段简化：售卡立即激活，不走审核
        // [B1.2] B1 阶段的实现：售卡订单只允许包含一个次卡商品，且数量为1
        $total_uses_to_add = (int)$plan_details['total_uses'] * (int)$cart_item['qty'];
        $purchase_amount = (float)$cart_item['unit_price_eur'];
        $unit_allocated_base = ($total_uses_to_add > 0) ? ($purchase_amount / $total_uses_to_add) : 0;
        
        // [A2 UTC SYNC] 计算 UTC 过期时间
        $expires_at_utc_str = $now_utc->modify("+{$validity_days} days")->format('Y-m-d H:i:s');

        $sql_pass = "
            INSERT INTO member_passes 
                (member_id, pass_plan_id, topup_order_id, total_uses, remaining_uses, 
                 purchase_amount, unit_allocated_base, status, store_id, 
                 activated_at, expires_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
        ";
        $stmt_pass = $pdo->prepare($sql_pass);
        $stmt_pass->execute([
            $member_id,
            $plan_details['pass_plan_id'],
            $topup_order_id,
            $total_uses_to_add,
            $total_uses_to_add,
            $purchase_amount,
            $unit_allocated_base,
            $store_id,
            $now_utc_str, // activated_at
            $expires_at_utc_str // expires_at
        ]);
        
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('calculate_redeem_allocation')) {
    /**
     * [B1.4 P2] P2 核销计价：计算覆盖金额和加价金额
     */
    function calculate_redeem_allocation(PDO $pdo, array $cart, array $tags_map, array $addon_defs, int $global_free_addon_limit): array {
        $covered_total = 0.0;
        $extra_total = 0.0;
        
        foreach ($cart as $item) {
            $item_tags = $tags_map[(int)($item['product_id'] ?? 0)] ?? [];
            $is_eligible_drink = in_array('pass_eligible_beverage', $item_tags, true);
            $item_qty = (int)($item['qty'] ?? 1);
            
            $item_extra_charge_per_unit = 0.0;
            
            if ($is_eligible_drink) {
                // 是可核销饮品，基础价被覆盖
                $covered_total += (float)$item['base_price_eur'] * $item_qty;
                
                // 计算加料费
                $free_addons_this_item = 0;
                foreach ($item['addons'] ?? [] as $addon) {
                    $addon_key = $addon['key'] ?? '';
                    $addon_def = $addon_defs[$addon_key] ?? null;
                    if (!$addon_def) continue;

                    $addon_tags = $addon_def['tags'] ?? [];
                    
                    if (in_array('paid_addon', $addon_tags, true)) {
                        // 1. 明确是“收费加料”
                        $item_extra_charge_per_unit += (float)$addon['price'];
                    } else if (in_array('free_addon', $addon_tags, true)) {
                        // 2. 是“免费加料”，检查上限
                        if ($global_free_addon_limit === 0 || $free_addons_this_item < $global_free_addon_limit) {
                            $free_addons_this_item++;
                            // 价格为 0
                        } else {
                            // 超出上限，计入加价
                            $item_extra_charge_per_unit += (float)$addon['price'];
                        }
                    } else {
                        // 3. 其他加料（未标记），在核销模式下视为收费
                        $item_extra_charge_per_unit += (float)$addon['price'];
                    }
                }
            } else {
                // 不是可核销饮品（例如：蛋糕），全额计入加价
                $item_extra_charge_per_unit = (float)$item['unit_price_eur'];
            }
            
            // 累加总加价
            $extra_total += $item_extra_charge_per_unit * $item_qty;
        }

        return [
            'covered_total' => $covered_total, // 被次卡覆盖的（饮品）金额
            'extra_total'   => $extra_total    // 额外支付的（加料/非饮品）金额
        ];
    }
}

// [B1.5 ERROR 1 FIX] 新增：合规数据生成器 (移植自 pos_registry.php)
if (!function_exists('generate_compliance_data')) {
    /**
     * 移植自 pos_registry.php (handle_order_submit)，用于生成合规数据 (TBAI/VF)
     */
    function generate_compliance_data(PDO $pdo, array $store_config, string $series, int $invoice_number, string $issued_at_micro_utc_str, float $final_total): ?array {
        $compliance_system = $store_config['billing_system'];
        if (!$compliance_system) return null;

        $handler_path = realpath(__DIR__ . "/../compliance/{$compliance_system}Handler.php");
        if (!$handler_path || !file_exists($handler_path)) {
            // Log or error? For now, fail silently.
            return null;
        }
        
        require_once $handler_path;
        $class = $compliance_system . 'Handler';
        if (!class_exists($class)) return null;

        $issuer_nif = (string)$store_config['tax_id'];
        
        // TICKETBAI/VERIFACTU 依赖于前一个 hash
        $stmt_prev = $pdo->prepare(
            "SELECT compliance_data FROM pos_invoices 
             WHERE compliance_system=:system AND series=:series AND issuer_nif=:nif 
             ORDER BY `number` DESC LIMIT 1"
        );
        $stmt_prev->execute([':system'=>$compliance_system, ':series'=>$series, ':nif'=>$issuer_nif]);
        $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);
        $previous_hash = $prev ? (json_decode($prev['compliance_data'] ?? '[]', true)['hash'] ?? null) : null;
        
        $invoiceData = [
            'series' => $series,
            'number' => $invoice_number,
            'issued_at' => $issued_at_micro_utc_str,
            'final_total' => $final_total
        ];
        
        $handler = new $class();
        return $handler->generateComplianceData($pdo, $invoiceData, $previous_hash);
    }
}


if (!function_exists('create_redeem_records')) {
    /**
     * [B1.4 P3/P4] P3 核销：创建核销记录（事务）
     * [B1.5 ERROR 1 FIX] 修复了合规数据调用
     */
    function create_redeem_records(PDO $pdo, array $context, array $alloc, array $cart, array $tags_map, array $addon_defs, array $payment_summary): array {
        
        // 1. 获取上下文
        $store_id  = $context['store_id'];
        $user_id   = $context['user_id'];
        $device_id = $context['device_id'];
        $member_id = $context['member_id'];
        $pass_id   = $context['pass_id']; // 正在使用的 member_pass_id
        $idempotency_key = $context['idempotency_key']; // 幂等键
        
        $store_config = $context['store_config'];
        $vat_rate = $context['vat_rate'];
        
        // 2. 获取时间
        // [A2 UTC SYNC] 依赖 datetime_helper.php
        $now_utc = utc_now();
        $now_utc_str_6 = $now_utc->format('Y-m-d H:i:s.u'); // 用于 pos_invoices.issued_at(6)
        $now_utc_str_0 = $now_utc->format('Y-m-d H:i:s');   // 用于其他所有 0 精度 TS
        
        // [A2 UTC SYNC] 依赖 datetime_helper.php
        $tz = new DateTimeZone(APP_DEFAULT_TIMEZONE);
        $today_local_date = (new DateTime('now', $tz))->format('Y-m-d');
        $today_utc_date = (new DateTime($today_local_date . ' 00:00:00', $tz))
                          ->setTimezone(new DateTimeZone('UTC'))
                          ->format('Y-m-d');

        // 3. 初始化 P3/P4 变量
        $items_redeemed_count = 0; // P3: 核销总次数
        $invoice_id_tp = null;       // P4: 加价发票 (TP) ID
        $invoice_number_tp = null;   // P4: 加价发票 (TP) 号
        $invoice_number_vr = null;   // P4: 核销凭条 (VR) 号
        $qr_payload_tp = null;       // P4: 加价发票 (TP) 二维码
        $print_jobs = [];          // P4: 打印任务
        
        // 4. [B1.4 P4] 仅在有加价时才开具发票
        if ($alloc['extra_total'] > 0) {
            
            // 4a. (P3) 分配 TP 票号 (依赖: pos_repo.php)
            if (empty($store_config['invoice_prefix'])) {
                 throw new Exception('核销失败：门店缺少票号前缀 (invoice_prefix) 配置。', 412);
            }
            $compliance_system = $store_config['billing_system'];
            [$series, $invoice_number] = allocate_invoice_number(
                $pdo, 
                $store_config['invoice_prefix'], 
                $compliance_system
            );
            $invoice_number_tp = $series . '-' . $invoice_number;
            $pickup_number_human = (string)$invoice_number; // 使用 TP 票号作为取餐号

            // 4b. (P3) [B1.5 ERROR 1 FIX] 生成合规数据 (依赖: pos_pass_helper.php)
            $compliance_data = null;
            if (is_invoicing_enabled($store_config)) {
                $compliance_data = generate_compliance_data(
                    $pdo, $store_config, $series, $invoice_number, $now_utc_str_6, $alloc['extra_total']
                );
                if (is_array($compliance_data)) $qr_payload_tp = $compliance_data['qr_content'] ?? null;
            }

            // 4c. (P3) 计算 TP 税额
            $final_total     = $alloc['extra_total'];
            $taxable_base    = round($final_total / (1 + ($vat_rate / 100)), 2);
            $vat_amount      = round($final_total - $taxable_base, 2);

            // 4d. (P3) 写入 pos_invoices (加价发票)
            $stmt_invoice = $pdo->prepare("
                INSERT INTO pos_invoices 
                    (invoice_uuid, store_id, user_id, shift_id, issuer_nif, series, `number`, 
                     issued_at, invoice_type, taxable_base, vat_amount, discount_amount, final_total, 
                     status, compliance_system, compliance_data, payment_summary) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt_invoice->execute([
                $idempotency_key, // P3 幂等键
                $store_id, $user_id, (int)($_SESSION['pos_shift_id'] ?? 0), (string)$store_config['tax_id'],
                $series, $invoice_number, $now_utc_str_6,
                'F2', $taxable_base, $vat_amount, 0.0, $final_total, // P3: 折扣为0, 总额=加价
                'ISSUED', $compliance_system, json_encode($compliance_data, JSON_UNESCAPED_UNICODE),
                json_encode($payment_summary, JSON_UNESCAPED_UNICODE)
            ]);
            $invoice_id_tp = (int)$pdo->lastInsertId(); // P3: 保存 TP ID
            
        } else {
            // [B1.4 P4] 0元加价，使用 VR 凭条号 (依赖: pos_repo_ext_pass.php)
            [$vr_series, $vr_number] = allocate_vr_invoice_number($pdo, $store_config['invoice_prefix']);
            $invoice_number_vr = $vr_series . '-' . $vr_number;
            $pickup_number_human = (string)$vr_number; // 使用 VR 凭条号作为取餐号
        }
        
        // 5. (P3) 写入核销批次 (pass_redemption_batches)
        $sql_batch = "
            INSERT INTO pass_redemption_batches 
                (member_pass_id, order_id, redeemed_uses, extra_charge_total, store_id, cashier_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt_batch = $pdo->prepare($sql_batch);
        // 注意：redeemed_uses 稍后计算
        $stmt_batch->execute([
            $pass_id,
            $invoice_id_tp, // P3: 关联 TP 发票 ID (如果为0元，则为 NULL)
            0, // P3: 临时为 0
            $alloc['extra_total'],
            $store_id,
            $user_id,
            $now_utc_str_0 // P3: 使用 0 精度 UTC
        ]);
        $batch_id = (int)$pdo->lastInsertId();

        // 6. (P3) 写入核销明细 (pass_redemptions) 和 发票明细 (pos_invoice_items)
        $sql_item_tp = "INSERT INTO pos_invoice_items (invoice_id, menu_item_id, variant_id, item_name, variant_name, item_name_zh, item_name_es, variant_name_zh, variant_name_es, quantity, unit_price, unit_taxable_base, vat_rate, vat_amount, customizations) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt_item_tp = $pdo->prepare($sql_item_tp);
        
        $sql_item_pass = "INSERT INTO pass_redemptions (batch_id, member_pass_id, order_id, order_item_id, sku_id, invoice_series, invoice_number, covered_amount, extra_charge, redeemed_at, store_id, device_id, cashier_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt_item_pass = $pdo->prepare($sql_item_pass);

        $cup_index = 1;
        $kitchen_items = []; // P4: 厨房单
        
        foreach ($cart as $item) {
            $item_tags = $tags_map[(int)($item['product_id'] ?? 0)] ?? [];
            $is_eligible_drink = in_array('pass_eligible_beverage', $item_tags, true);
            $item_qty = (int)($item['qty'] ?? 1);
            
            // P3: 计算单价
            $item_extra_charge_per_unit = 0.0;
            $item_covered_per_unit = 0.0;
            
            if ($is_eligible_drink) {
                $items_redeemed_count += $item_qty; // P3: 累加核销次数
                $item_covered_per_unit = (float)$item['base_price_eur'];
                
                // (P2 逻辑复用)
                $free_addons_this_item = 0;
                $freeLimit = $store_config['global_free_addon_limit'] ?? 0;
                foreach ($item['addons'] ?? [] as $addon) {
                    $addon_def = $addon_defs[$addon['key'] ?? ''] ?? null;
                    if (!$addon_def) continue;
                    $addon_tags = $addon_def['tags'] ?? [];
                    if (in_array('paid_addon', $addon_tags, true)) {
                        $item_extra_charge_per_unit += (float)$addon['price'];
                    } else if (in_array('free_addon', $addon_tags, true)) {
                        if ($freeLimit === 0 || $free_addons_this_item < $freeLimit) $free_addons_this_item++;
                        else $item_extra_charge_per_unit += (float)$addon['price'];
                    } else {
                        $item_extra_charge_per_unit += (float)$addon['price'];
                    }
                }
            } else {
                $item_extra_charge_per_unit = (float)$item['unit_price_eur'];
            }
            
            $item_total_extra_charge = $item_extra_charge_per_unit * $item_qty;

            // 7. (P3) 写入 TP 发票明细 (仅当有加价发票时)
            $order_item_id = null;
            if ($invoice_id_tp) {
                // [P3] 写入 pos_invoice_items
                $unit_price = $item_extra_charge_per_unit;
                $item_total = $item_total_extra_charge;
                $item_tax_base_total = round($item_total / (1 + ($vat_rate / 100)), 2);
                $item_vat_amount = round($item_total - $item_tax_base_total, 2);
                $unit_tax_base = ($item_qty > 0) ? round($item_tax_base_total / $item_qty, 4) : 0;
                
                $stmt_item_tp->execute([
                    $invoice_id_tp, (int)($item['product_id'] ?? 0), (int)($item['variant_id'] ?? 0),
                    $item['title'], $item['variant_name'],
                    $item['title_zh'], $item['title_es'], $item['variant_name_zh'], $item['variant_name_es'],
                    $item_qty, $unit_price, $unit_tax_base, $vat_rate, $item_vat_amount, 
                    json_encode(['ice' => $item['ice'] ?? null, 'sugar' => $item['sugar'] ?? null, 'addons' => $item['addons'] ?? [], 'remark' => $item['remark'] ?? ''], JSON_UNESCAPED_UNICODE)
                ]);
                $order_item_id = (int)$pdo->lastInsertId();
            }
            
            // 8. (P3) 写入核销明细 (逐杯)
            for ($i = 0; $i < $item_qty; $i++) {
                $stmt_item_pass->execute([
                    $batch_id, $pass_id, $invoice_id_tp, $order_item_id,
                    $item['product_code'] ?? null, // sku_id
                    $invoice_id_tp ? $series : $vr_series, // P4: 票号
                    $invoice_id_tp ? $invoice_number : $vr_number, // P4: 序号
                    $item_covered_per_unit, // 覆盖金额
                    $item_extra_charge_per_unit, // 加价
                    $now_utc_str_0, // redeemed_at
                    $store_id, $device_id, $user_id
                ]);
            }
            
            // 9. (P4) 准备打印数据 (厨房单 + 杯贴)
            $customizations_parts = [];
            if (!empty($item['ice'])) $customizations_parts[] = 'Ice:' . $item['ice']; 
            if (!empty($item['sugar'])) $customizations_parts[] = 'Sugar:' . $item['sugar'];
            if (!empty($item['addons'])) {
                 $addons_str = implode(',+', array_map(fn($a) => $a['key'], $item['addons']));
                 if ($addons_str) $customizations_parts[] = '+' . $addons_str;
            }
            $customization_detail_str = implode(' / ', $customizations_parts);
            
            $kitchen_items[] = [
                'item_name' => $item['title_zh'],
                'variant_name' => $item['variant_name_zh'],
                'customizations' => $customization_detail_str,
                'qty' => $item_qty,
                'remark' => (string)($item['remark'] ?? ''),
            ];
            
            for ($i_qty = 0; $i_qty < $item_qty; $i_qty++) {
                $kds_internal_id = $store_config['invoice_prefix'] . '-' . $pickup_number_human . '-' . $cup_index;
                $print_jobs[] = [
                    'type' => 'CUP_STICKER',
                    'data' => [
                        'pickup_number' => $pickup_number_human,
                        'kds_id' => $kds_internal_id,
                        'store_prefix' => $store_config['invoice_prefix'],
                        'invoice_sequence' => $invoice_id_tp ? $invoice_number : $vr_number,
                        'cup_index' => $cup_index,
                        'product_code' => $item['product_code'] ?? 'NA',
                        'cup_code' => $item['cup_code'] ?? 'NA',
                        'ice_code' => $item['ice'] ?? 'NA',
                        'sweet_code' => $item['sugar'] ?? 'NA',
                        'cup_order_number' => $kds_internal_id,
                        'item_name' => $item['title'], 'variant_name' => $item['variant_name'],
                        'item_name_zh' => $item['title_zh'], 'item_name_es' => $item['title_es'],
                        'variant_name_zh' => $item['variant_name_zh'], 'variant_name_es' => $item['variant_name_es'],
                        'customization_detail' => $customization_detail_str,
                        'remark' => (string)($item['remark'] ?? ''),
                        'store_name' => $store_config['store_name'] ?? ''
                    ],
                    'printer_role' => 'POS_STICKER'
                ];
                $cup_index++;
            }
        } // end foreach cart

        // 10. (P3) 回填核销总次数
        $pdo->prepare("UPDATE pass_redemption_batches SET redeemed_uses = ? WHERE batch_id = ?")
            ->execute([$items_redeemed_count, $batch_id]);

        // 11. (P3) 更新日限额
        $sql_daily = "
            INSERT INTO pass_daily_usage (member_pass_id, usage_date, uses_count)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE uses_count = uses_count + VALUES(uses_count)
        ";
        $pdo->prepare($sql_daily)->execute([$pass_id, $today_utc_date, $items_redeemed_count]);
        
        // 12. (P3) 扣减总次数
        $sql_pass_update = "UPDATE member_passes SET remaining_uses = remaining_uses - ? WHERE member_pass_id = ?";
        $pdo->prepare($sql_pass_update)->execute([$items_redeemed_count, $pass_id]);
        
        // 13. (P4) 准备打印任务 (厨房单)
        $kitchen_data = [
            'invoice_number' => $invoice_id_tp ? $invoice_number_tp : $invoice_number_vr,
            'issued_at'      => $now_utc_str_0,
            'items'          => $kitchen_items,
            'pickup_number'  => $pickup_number_human,
            'invoice_full'   => $invoice_id_tp ? $invoice_number_tp : $invoice_number_vr,
        ];
        $print_jobs[] = [ 'type' => 'KITCHEN_ORDER', 'data' => $kitchen_data, 'printer_role' => 'KDS_PRINTER' ];

        // 14. (P4) 准备打印任务 (加价发票 - RECEIPT)
        if ($invoice_id_tp) {
            $receipt_data_tp = [
                'store_name'      => $store_config['store_name'] ?? '',
                'store_address'   => $store_config['store_address'] ?? '',
                'store_tax_id'    => $store_config['tax_id'] ?? '',
                'issued_at'       => $now_utc_str_0,
                'cashier_name'    => $_SESSION['pos_display_name'] ?? 'N/A',
                'qr_code'         => $qr_payload_tp,
                'subtotal'        => number_format($alloc['extra_total'], 2), // P4: subtotal = extra_total
                'discount_amount' => number_format(0.0, 2),
                'final_total'     => number_format($alloc['extra_total'], 2),
                'taxable_base'    => number_format($taxable_base, 2),
                'vat_amount'      => number_format($vat_amount, 2),
                'payment_methods' => '...', // TODO: 格式化 $payment_summary
                'change'          => number_format((float)($payment_summary['change'] ?? 0.0), 2),
                'items'           => $cart, // P4: 模板引擎需要循环 cart
                'pickup_number'    => $pickup_number_human,
                'invoice_full'     => $invoice_number_tp,
                'invoice_series'   => $series,
                'invoice_sequence' => $invoice_number,
            ];
            $print_jobs[] = [ 'type' => 'RECEIPT', 'data' => $receipt_data_tp, 'printer_role' => 'POS_RECEIPT' ];
        }
        
        // 15. (P4) 准备打印任务 (核销凭条 - PASS_REDEMPTION_SLIP)
        // [B1.4 P4] 依赖 get_pass_print_details
        $pass_details = get_pass_print_details($pdo, $pass_id);
        
        $slip_data = [
            'pass_card_last4'      => $pass_details ? substr($pass_details['phone_number'], -4) : '****',
            'redeemed_uses_total'  => $items_redeemed_count,
            'extra_charge_total'   => number_format($alloc['extra_total'], 2),
            'remaining_uses'       => $pass_details ? (int)$pass_details['remaining_uses'] : 0, // 剩余次数
            'redeemed_at_local'    => fmt_local($now_utc_str_0, 'Y-m-d H:i', APP_DEFAULT_TIMEZONE), // 本地时间
            'store_name'           => $store_config['store_name'] ?? '',
            'cashier_name'         => $_SESSION['pos_display_name'] ?? 'N/A',
            'pickup_number'        => $pickup_number_human,
            'items'                => $cart // 模板循环
        ];
        $print_jobs[] = [ 'type' => 'PASS_REDEMPTION_SLIP', 'data' => $slip_data, 'printer_role' => 'POS_RECEIPT' ];

        // 16. (P3) 返回结果
        return [
            'invoice_id_tp'     => $invoice_id_tp,       // 加价发票 ID (int|null)
            'invoice_number_tp' => $invoice_number_tp,   // 加价发票号 (string|null)
            'invoice_number_vr' => $invoice_number_vr,   // 0元凭条号 (string|null)
            'qr_content_tp'     => $qr_payload_tp,       // 加价发票二维码 (string|null)
            'print_jobs'        => $print_jobs           // 打印任务
        ];
    }
}

// [B1.5 ERROR 2 FIX] 新增：P3 核销限制验证器
if (!function_exists('validate_redeem_limits')) {
    /**
     * P3 服务端验证：检查核销是否违反限制
     */
    function validate_redeem_limits(PDO $pdo, array $cart, array $tags_map, array $pass_check): void {
        
        // 1. 计算本次核销的总次数
        $items_redeemed_count = 0;
        foreach ($cart as $item) {
            $item_tags = $tags_map[(int)($item['product_id'] ?? 0)] ?? [];
            if (in_array('pass_eligible_beverage', $item_tags, true)) {
                $items_redeemed_count += (int)($item['qty'] ?? 1);
            }
        }

        if ($items_redeemed_count === 0) {
            throw new Exception('购物车中没有可用于核销的饮品 (No eligible items in cart)。', 400);
        }

        // 2. 检查总剩余次数
        if ($items_redeemed_count > (int)$pass_check['remaining_uses']) {
            throw new Exception("次卡剩余次数不足 (剩余 {$pass_check['remaining_uses']}, 本次需 {$items_redeemed_count})", 400);
        }

        // 3. 检查单笔订单上限
        if ((int)$pass_check['max_uses_per_order'] > 0 && $items_redeemed_count > (int)$pass_check['max_uses_per_order']) {
            throw new Exception("单笔订单核销上限为 {$pass_check['max_uses_per_order']} 次 (本次 {$items_redeemed_count} 次)", 400);
        }

        // 4. 检查当日剩余上限
        // $pass_check['daily_uses_remaining'] 由 get_member_pass_for_update() 提供
        if ($pass_check['daily_uses_remaining'] !== null && $items_redeemed_count > (int)$pass_check['daily_uses_remaining']) {
            throw new Exception("今日剩余核销上限为 {$pass_check['daily_uses_remaining']} 次 (本次 {$items_redeemed_count} 次)", 400);
        }
    }
}