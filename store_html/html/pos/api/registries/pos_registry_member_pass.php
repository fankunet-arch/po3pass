<?php
/**
 * Toptea Store POS - Member & Pass Handlers
 * Extracted from pos_registry.php
 *
 * [GEMINI FIX 2025-11-16] (应用修复)
 * 1. 修复 handle_member_create 的数据结构解析
 * 前端 (member.js) 发送: { data: { phone_number: '...' } }
 * 本文件 (v1) 期望: { phone_number: '...' }
 * -> 已修复为优先读取 'data' 键，并回退到扁平结构，以实现最大兼容。
 * 2. 修复 `handle_member_create` 中 INSERT 语句使用 `NOW()` 而不是 `UTC_TIMESTAMP()` 的问题。
 */

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_member_handler.php                */
/* -------------------------------------------------------------------------- */
function handle_member_find(PDO $pdo, array $config, array $input_data): void {
    // 1. 读取手机号（兼容 JSON 和 GET）
    $phone = trim($input_data['phone'] ?? $_GET['phone'] ?? '');
    if (empty($phone)) {
        json_error('Phone number is required.', 400);
    }

    // 2. 查询会员
    // [RCA FIX] 这里不要再用 m.deleted_at 了，基准库里没有这个字段 / 或者数据不规范，
    // 改为：
    //   - 用 TRIM(phone_number) 做等值匹配
    //   - 只要 is_active = 1 就视为有效会员
    $stmt = $pdo->prepare("
        SELECT m.*, ml.level_name_zh, ml.level_name_es
        FROM pos_members m
        LEFT JOIN pos_member_levels ml ON m.member_level_id = ml.id
        WHERE TRIM(m.phone_number) = ?
          AND m.is_active = 1
    ");
    $stmt->execute([$phone]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        // 3. 附加该会员的有效次卡列表（如果 helper 存在）
        if (function_exists('get_member_active_passes')) {
            $member['passes'] = get_member_active_passes($pdo, (int)$member['id']);
        } else {
            $member['passes'] = [];
        }

        // 4. 清理可能是无效日期字符串的字段（防止后续版本对日期做处理时踩雷）
        $date_fields_to_clean = ['birthdate', 'created_at', 'updated_at'];
        foreach ($date_fields_to_clean as $field) {
            if (isset($member[$field])) {
                $date_str = (string)$member[$field];
                if ($date_str === '0000-00-00' || $date_str === '0000-00-00 00:00:00' || $date_str === '') {
                    $member[$field] = null;
                }
            }
        }

        json_ok($member, 'Member found.');
    } else {
        json_error('Member not found.', 404);
    }
}


function handle_member_create(PDO $pdo, array $config, array $input_data): void {
    
    // [GEMINI FIX 2025-11-16] 修复前端 (member.js) 与后端的数据结构不匹配
    // member.js 发送 { data: { phone_number: ... } }
    // 此处优先检查 'data' 键，如果不存在，则回退到根 $input_data
    $data = $input_data['data'] ?? $input_data;

    $first_name = trim($data['first_name'] ?? '');
    $last_name = trim($data['last_name'] ?? '');
    $phone = trim($data['phone_number'] ?? $data['phone'] ?? '');
    $email = trim($data['email'] ?? '');
    $birthdate = trim($data['birthdate'] ?? '');
    
    if (empty($phone)) json_error('Phone number is required.', 400);
    
    // 依赖: gen_uuid_v4 (来自 pos_helper.php)
    if (!function_exists('gen_uuid_v4')) json_error('Missing dependency: gen_uuid_v4', 500);
    $member_uuid = gen_uuid_v4();

    try {
        $pdo->beginTransaction();

        $sql = "
            INSERT INTO pos_members (
                member_uuid, 
                first_name, 
                last_name, 
                phone_number, 
                email, 
                birthdate,
                member_level_id, 
                points_balance,
                created_at, 
                updated_at
            ) VALUES (
                :uuid, 
                :first_name, 
                :last_name, 
                :phone, 
                :email, 
                :birthdate,
                1, 
                0,
                UTC_TIMESTAMP(), 
                UTC_TIMESTAMP()
            )
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uuid' => $member_uuid,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':phone' => $phone,
            ':email' => $email,
            ':birthdate' => empty($birthdate) ? null : $birthdate, // 允许生日为空
        ]);
        
        $member_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        // 成功后，按 "find" 接口的格式返回完整数据
        $stmt_find = $pdo->prepare("
            SELECT m.*, ml.level_name_zh, ml.level_name_es
            FROM pos_members m
            LEFT JOIN pos_member_levels ml ON m.member_level_id = ml.id
            WHERE m.id = ?
        ");
        $stmt_find->execute([$member_id]);
        $new_member = $stmt_find->fetch(PDO::FETCH_ASSOC);

        if ($new_member) {
            $new_member['passes'] = []; // 新会员没有次卡
            json_ok($new_member, 'Member created successfully.');
        } else {
            // 正常情况不会发生
            json_error('Failed to retrieve created member.', 500);
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        // 检查是否为唯一约束冲突 (1062)
        if ($e->errorInfo[1] == 1062) {
            json_error('Phone number already exists.', 409); // 409 Conflict
        }
        // 记录日志 (如果配置了)
        // error_log($e->getMessage());
        json_error('Failed to create member: DB Error.', 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('Failed to create member: Server Error.', 500);
    }
}

/* -------------------------------------------------------------------------- */
/* Handlers: B1 次卡售卖 (Pass Purchase)                             */
/* -------------------------------------------------------------------------- */
/**
 * B1: 次卡售卖
 * * 逻辑:
 * 1. (A) 校验权限/班次 (网关已做/Helper已做)
 * 2. (A) 校验输入 (cart_item, member_id)
 * 3. (B) 启动事务
 * 4. (B) [REPO] 检查会员是否存在 (get_member_by_id)
 * 5. (B) [REPO] 检查次卡定义是否存在 (get_pass_plan_by_sku)
 * 6. (A) [HELPER] 检查购买限制 (check_pass_purchase_limits)
 * 7. (B) [HELPER] 分配 VR (VeriFactu) 票号 (allocate_vr_invoice_number)
 * 8. (B) [HELPER] 写入数据库 (topup_orders, member_passes) (create_pass_records)
 * 9. (B) (B3 阶段) 记录支付详情到 topup_orders
 * 10.(B) 提交事务
 * 11.(A) 准备打印数据 (build_pass_vr_receipt)
 * 12.(A) 返回 json_ok
 */
function handle_pass_purchase(PDO $pdo, array $config, array $input_data): void {
    // 依赖: 
    //   pos_helper.php (ensure_active_shift_or_fail, gen_uuid_v4)
    //   pos_repo.php (get_member_by_id, get_store_config_full)
    //   pos_repo_ext_pass.php (get_pass_plan_by_sku)
    //   pos_pass_helper.php (check_pass_purchase_limits, allocate_vr_invoice_number, create_pass_records)
    //   pos_json_helper.php (json_ok, json_error)
    
    // 1. 检查依赖
    $deps = [
        'ensure_active_shift_or_fail', 'gen_uuid_v4', 
        'get_member_by_id', 'get_store_config_full', 
        'get_pass_plan_by_sku', 
        'check_pass_purchase_limits', 'allocate_vr_invoice_number', 'create_pass_records',
        'json_ok', 'json_error'
    ];
    foreach ($deps as $dep) {
        if (!function_exists($dep)) json_error("Missing dependency: $dep", 500);
    }

    // 1. 校验班次
    ensure_active_shift_or_fail($pdo);
    
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $device_id = (int)($_SESSION['pos_device_id'] ?? 0); // 修复可能的拼写错误

    // 2. 校验输入
    $cart_item = $input_data['cart_item'] ?? null;
    $member_id = (int)($input_data['member_id'] ?? 0);

    if (!$cart_item || !is_array($cart_item) || !$member_id) {
        json_error('Invalid input: cart_item and member_id are required.', 400);
    }
    if (empty($cart_item['sku'])) {
        json_error('Invalid input: cart_item must have a sku.', 400);
    }

    try {
        // 3. 启动事务
        $pdo->beginTransaction();

        // 4. 检查会员
        $member = get_member_by_id($pdo, $member_id);
        if (!$member) {
            json_error('Member not found.', 404);
        }

        // 5. 检查次卡定义 (依赖: pos_repo_ext_pass.php)
        $plan_details = get_pass_plan_by_sku($pdo, $cart_item['sku']);
        if (!$plan_details) {
            json_error('Pass plan not found for sku: ' . $cart_item['sku'], 404);
        }

        // 6. 检查购买限制 (依赖: pos_pass_helper.php)
        // B1 阶段简化: 暂不实现复杂的限制
        // check_pass_purchase_limits($pdo, $member_id, $plan_details);
        
        // 7. 分配 VR 票号
        // 7a. 获取门店配置 (依赖: pos_repo.php)
        $store_config = get_store_config_full($pdo, $store_id);
        if (empty($store_config['invoice_prefix'])) {
            json_error('Store invoice_prefix (VR Series) is not configured.', 500);
        }
        // 7b. 分配 (依赖: pos_pass_helper.php)
        [$vr_series, $vr_number] = allocate_vr_invoice_number($pdo, $store_config['invoice_prefix']);
        $vr_info = ['series' => $vr_series, 'number' => $vr_number];

        // 8. 写入数据库 (topup_orders, member_passes) (依赖: pos_pass_helper.php)
        $context = [
            'store_id' => $store_id,
            'user_id' => $user_id,
            'device_id' => $device_id,
            'member_id' => $member_id
        ];
        $create_result = create_pass_records($pdo, $context, $vr_info, $cart_item, $plan_details);
        $member_pass_id = (int)$create_result['member_pass_id'];
        $topup_order_id = (int)$create_result['topup_order_id'];
        
        // 9. (B1 阶段) 支付信息暂不处理，假设前端已收款
        // TODO (B3): 记录支付详情到 topup_orders
        
        // 10. 提交事务
        $pdo->commit();
        
        // 11. 准备打印数据 (B1 阶段可选, B2 必须)
        $print_jobs = [
            // [TODO B2] 在此构建 VR 售卡小票
        ];

        // [GEMINI LOGIC BUG FIX 2025-11-16] 修复 json_ok 参数颠倒
        json_ok([
            'topup_order_id' => $topup_order_id,
            'vr_invoice_number' => $vr_info['series'] . '-' . $vr_info['number'],
            'member_pass_id' => $member_pass_id,
            'print_jobs' => $print_jobs
        ], 'Top-up successful.');

    } catch (PDOException $e) {
        $pdo->rollBack();
        json_error('Database error during pass purchase: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        // 捕获 check_pass_purchase_limits 等助手函数抛出的特定错误
        json_error('Error during pass purchase: ' . $e->getMessage(), $e->getCode() > 400 ? $e->getCode() : 500);
    }
}

/* -------------------------------------------------------------------------- */
/* Handlers: 优惠卡列表 (Discount Card List)                             */
/* -------------------------------------------------------------------------- */
/**
 * 获取可售优惠卡列表
 */
function handle_pass_list(PDO $pdo, array $config, array $input_data): void {
    try {
        $sql = "
            SELECT
                pass_plan_id,
                name,
                name_zh,
                name_es,
                total_uses,
                validity_days,
                max_uses_per_order,
                max_uses_per_day,
                sale_sku,
                sale_price,
                notes
            FROM pass_plans
            WHERE is_active = 1
            ORDER BY sale_price ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_ok($cards, 'Pass plans retrieved successfully.');

    } catch (PDOException $e) {
        json_error('Database error: ' . $e->getMessage(), 500);
    }
}