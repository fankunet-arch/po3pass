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
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_repo_ext_pass.php');
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
    //   pos_repo.php (get_member_by_id, get_store_config_full, get_cart_item_tags)
    //   pos_repo_ext_pass.php (get_pass_plan_by_sku, validate_pass_purchase_order, allocate_vr_invoice_number, create_pass_records)
    //   pos_json_helper.php (json_ok, json_error, json_error_localized)

    // 1. 检查依赖
    $deps = [
        'ensure_active_shift_or_fail', 'gen_uuid_v4',
        'get_member_by_id', 'get_store_config_full', 'get_cart_item_tags',
        'get_pass_plan_by_sku', 'validate_pass_purchase_order', 'allocate_vr_invoice_number', 'create_pass_records',
        'json_ok', 'json_error', 'json_error_localized'
    ];
    foreach ($deps as $dep) {
        if (!function_exists($dep)) json_error("Missing dependency: $dep", 500);
    }

    // 1. 校验班次
    ensure_active_shift_or_fail($pdo);

    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $device_id = (int)($_SESSION['pos_device_id'] ?? 0);

    // 2. 校验输入
    $cart = $input_data['cart'] ?? [];
    $member_id = (int)($input_data['member_id'] ?? 0);
    $secondary_phone = trim($input_data['secondary_phone_input'] ?? '');
    $payment_method = trim($input_data['payment_method'] ?? '');
    $idempotency_key = trim($input_data['idempotency_key'] ?? '');
    if (empty($idempotency_key)) {
        json_error('Idempotency key is required.', 400);
    }

    // 2.1. Pre-purchase validation
    $tags_map = get_cart_item_tags($pdo, $cart);
    validate_pass_purchase_order($pdo, $cart, $tags_map, $input_data['promo_result'] ?? null);


    if (!$member_id) {
        json_error('Member ID is required.', 400);
    }


    // 2.2. Member requirement and secondary phone verification
    $member = get_member_by_id($pdo, $member_id);
    if (!$member) {
        json_error('Member not found.', 404);
    }
    if ($secondary_phone !== trim($member['phone_number'])) {
        json_error_localized(
            '二次输入的手机号与当前登录会员不一致。如需更换会员，请先退出当前会员，再用正确手机号登录后重新购买。',
            'El número de teléfono introducido en la segunda verificación no coincide con el miembro actualmente conectado. Si desea cambiar de cliente, primero cierre la sesión del miembro actual y vuelva a iniciar sesión con el número correcto antes de realizar la compra.'
        );
    }

    // 2.3. Payment method validation
    if (!in_array($payment_method, ['cash', 'card'])) {
        json_error_localized(
            '购买优惠卡仅支持现金或银行卡支付，请更改支付方式。',
            'La compra de tarjetas promocionales solo admite efectivo o tarjeta bancaria. Por favor, cambie el método de pago.'
        );
    }

    try {
        // 3. 启动事务
        $pdo->beginTransaction();

        // 5. 检查次卡定义 (依赖: pos_repo_ext_pass.php)
        $pass_item = $cart[0];
        $plan_details = get_pass_plan_by_sku($pdo, $pass_item['product_code']);
        if (!$plan_details) {
            json_error('Pass plan not found for sku: ' . $pass_item['product_code'], 404);
        }

        // 7. 分配 VR 票号
        $store_config = get_store_config_full($pdo, $store_id);
        if (empty($store_config['invoice_prefix'])) {
            json_error('Store invoice_prefix (VR Series) is not configured.', 500);
        }
        [$vr_series, $vr_number] = allocate_vr_invoice_number($pdo, $store_config['invoice_prefix']);
        $vr_info = ['series' => $vr_series, 'number' => $vr_number];

        // 8. 写入数据库 (topup_orders, member_passes)
        $context = [
            'store_id' => $store_id,
            'user_id' => $user_id,
            'device_id' => $device_id,
            'member_id' => $member_id,
            'payment_method' => $payment_method,
            'idempotency_key' => $idempotency_key
        ];
        $member_pass_id = create_pass_records($pdo, $context, $vr_info, $pass_item, $plan_details);

        // 10. 提交事务
        $pdo->commit();

        // 11. 准备响应
        json_ok([
            'success' => true,
            'message' => 'PASS_PURCHASE_SUCCESS',
            'actions' => [
                'LOGOUT_MEMBER',
                'CLEAR_ORDER',
                'RESET_TO_HOME',
                'SHOW_PASS_SUCCESS_PAGE'
            ],
            'data' => [
                'pass_id' => $member_pass_id,
                'member_id' => $member_id,
                'phone_masked' => '********' . substr($member['phone_number'], -4)
            ]
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        json_error('Database error during pass purchase: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        $pdo->rollBack();
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
                notes,
                important_notice_zh,
                important_notice_es
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