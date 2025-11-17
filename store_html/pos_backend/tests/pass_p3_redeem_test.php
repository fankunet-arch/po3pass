<?php
declare(strict_types=1);
/**
 * P3 (核销) 事务自检 (pass_p3_redeem_test.php)
 * * 目标：验证 P3 (B1.4) 核销流程的核心事务 (create_redeem_records)，
 * 包含 P2 (计价) 和 P4 (打印) 逻辑。
 *
 * 警告：此脚本会向数据库写入真实数据 (pos_invoices, pass_redemptions, 等)！
 * 仅用于开发和预发布环境自检。
 */

header('Content-Type: text/plain; charset=utf-8');

// --- 1. 环境加载 ---
echo "--- [P3 核销事务自检 (B1.4)] ---\n";
echo "Bootstrapping environment...\n";
try {
    require_once realpath(__DIR__ . '/../core/config.php');
    require_once realpath(__DIR__ . '/../helpers/pos_helper.php');
} catch (Throwable $e) {
    echo "FATAL: Failed to load core helpers or config.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

// --- 2. 模拟参数定义 ---
echo "Defining mock parameters...\n";

// (必须与数据库中的真实数据匹配)
const P3_MEMBER_ID = 1;      // 必须是 pos_members 中存在的 ID
const P3_STORE_ID = 1;       // 必须是 kds_stores 中存在的 ID
const P3_USER_ID = 1;        // 必须是 kds_users 中存在的 ID

// 关键：必须是 P0 测试创建的 (或手动创建的) member_passes.id
// 并且该卡必须是 { member_id: 1, status: 'active', remaining_uses: > 0 }
const P3_MEMBER_PASS_ID = 1; // 假设 ID=1 的次卡存在且有效

// 模拟购物车 (来自前端 P1/P2)
$mock_cart = [
    [
        'id' => 'cart1',
        'product_id' => 1, // 假设商品 #1 是 'pass_eligible_beverage'
        'variant_id' => 1,
        'title_zh' => '可核销饮品A',
        'variant_name_zh' => '大杯',
        'product_code' => 'A1', // KDS P-Code
        'cup_code' => '1',
        'qty' => 2,
        'base_price_eur' => 5.00, // P2: 基础价
        'unit_price_eur' => 5.70, // P2: 最终价 (含加料)
        'ice' => '1',
        'sugar' => '1',
        'addons' => [
            ['key' => 'free_1', 'price' => 0.00], // 假设 free_1 是 'free_addon'
            ['key' => 'paid_1', 'price' => 0.70]  // 假设 paid_1 是 'paid_addon'
        ]
    ],
    [
        'id' => 'cart2',
        'product_id' => 10, // 假设商品 #10 是 'pass_eligible_beverage'
        'variant_id' => 10,
        'title_zh' => '可核销饮品B',
        'variant_name_zh' => '大杯',
        'product_code' => 'A10',
        'cup_code' => '1',
        'qty' => 1,
        'base_price_eur' => 6.00,
        'unit_price_eur' => 6.00, // P2: 无加料
        'ice' => '1',
        'sugar' => '1',
        'addons' => []
    ],
    [
        'id' => 'cart3',
        'product_id' => 20, // 假设商品 #20 不是 'pass_eligible_beverage' (例如蛋糕)
        'variant_id' => 20,
        'title_zh' => '不可核销(蛋糕)',
        'variant_name_zh' => '份',
        'product_code' => 'C1',
        'cup_code' => null,
        'qty' => 1,
        'base_price_eur' => 3.50,
        'unit_price_eur' => 3.50, // P2: 按原价
        'ice' => null,
        'sugar' => null,
        'addons' => []
    ]
];

// 模拟 P2 计算结果 (前端)
$mock_extra_charge_total_frontend = (0.70 * 2) + // 饮品A (0元基础 + 0.7付费) * 2
                                    (0.00 * 1) + // 饮品B (0元基础 + 0付费) * 1
                                    (3.50 * 1);  // 蛋糕 (3.50基础) * 1
                                    // = 1.40 + 0 + 3.50 = 4.90
$mock_redeem_count_frontend = 2 + 1; // 饮品A(2) + 饮品B(1) = 3次

// 模拟支付
$mock_payment_summary = [
    'total' => $mock_extra_charge_total_frontend,
    'paid' => $mock_extra_charge_total_frontend,
    'change' => 0.0,
    'summary' => [
        ['method' => 'Cash', 'amount' => $mock_extra_charge_total_frontend]
    ]
];

$test_start_time = microtime(true);

// --- 3. 执行测试 ---
try {
    echo "Connecting to DB: {$db_host}\n";
    // $pdo 在 config.php 中定义

    echo "Running P3 Self-Test...\n";
    
    $pdo->beginTransaction();
    echo "Transaction started.\n";

    // 1. (P3) 加载依赖 (P2 计价所需)
    echo "Loading dependencies (Store Config, Tags, Addons)...\n";
    $store_config = get_store_config_full($pdo, P3_STORE_ID);
    $vat_rate = (float)($store_config['default_vat_rate'] ?? 21.0);
    $global_free_addon_limit = (int)($store_config['global_free_addon_limit'] ?? 0);
    $tags_map = get_cart_item_tags($pdo, [1, 10, 20]);
    $addon_defs = get_addons_with_tags($pdo); 
    
    // 模拟前端 addon_defs (JS只用了 key 和 tags)
    $addon_defs_sim = [
        'free_1' => ['tags' => ['free_addon'], 'price_eur' => 0.50], // 假设免费加料原价 0.5
        'paid_1' => ['tags' => ['paid_addon'], 'price_eur' => 0.70]
    ];
    
    // 假设 P2 (JS) 使用的全局上限为 1
    $store_config['global_free_addon_limit'] = 1;
    echo "Simulating P2 Frontend Calculation (Limit: 1)...\n";
    
    // 模拟 P2 前端计价 (cart.js -> calculatePassRedemptionTotals)
    // 饮品A(qty 2): 
    //   杯1: free_1 (免费), paid_1 (0.7) -> extra = 0.7
    //   杯2: free_1 (免费), paid_1 (0.7) -> extra = 0.7
    //   (注：P2 计价逻辑是 *每杯* 享有 N 次免费，所以 free_1 始终免费)
    //   (修正 P2 计价逻辑：free_1 在 杯1 是免费, 在 杯2 也是免费)
    //   (修正 P3 计价逻辑：)
    //    Item 1 (qty 2):
    //      Addon free_1 (free_addon): limit=1. 1st one free. 2nd one costs 0.50? NO.
    //      P2 计价逻辑 (cart.js L:139) 是 *per-item* (per cart line), 
    //      P2 计价逻辑 (cart.js L:78) 是 *per-unit* (inside addToCart)
    //      P3 计价逻辑 (pos_pass_helper L:95) 是 *per-item* (inside loop)
    
    // 让我们遵循 pos_pass_helper.php (P3) L:95 的服务端逻辑：
    // Item 1 (qty 2): free_1 (free), paid_1 (0.7).
    //   free_addons_this_item = 0;
    //   Loop addon 'free_1': tags=['free_addon']. limit=1. free_addons_this_item (0) < 1. free_addons_this_item becomes 1. extra = 0.
    //   Loop addon 'paid_1': tags=['paid_addon']. extra += 0.70.
    //   item_extra_charge_per_unit = 0.70.
    //   extra_total += 0.70 * 2 = 1.40
    // Item 2 (qty 1): no addons.
    //   extra_total += 0 * 1 = 1.40
    // Item 3 (qty 1): not eligible.
    //   item_extra_charge_per_unit = 3.50
    //   extra_total += 3.50 * 1 = 4.90
    
    echo "P2 Server-Side Recalc (calculate_redeem_allocation)...\n";
    $alloc = calculate_redeem_allocation($pdo, $mock_cart, $tags_map, $addon_defs, $store_config['global_free_addon_limit']);
    
    if (abs($alloc['extra_total'] - $mock_extra_charge_total_frontend) > 0.01) {
        throw new Exception("P2 FAILED: Server calc ({$alloc['extra_total']}) != Client calc ({$mock_extra_charge_total_frontend})");
    }
    echo "P2 PASSED: Server calculation matches client (Extra Charge: {$alloc['extra_total']})\n";

    // 2. (P3) 检查卡片状态 (FOR UPDATE)
    echo "Checking pass (ID: ".P3_MEMBER_PASS_ID.") status (FOR UPDATE)...\n";
    $pass_check = get_member_pass_for_update($pdo, P3_MEMBER_PASS_ID, P3_MEMBER_ID);
    if (!$pass_check) {
        throw new Exception("P3 FAILED: Pass (ID: " . P3_MEMBER_PASS_ID . ") is invalid, expired, or has 0 uses.");
    }
    echo "Pass OK: Remaining Uses: {$pass_check['remaining_uses']}, Daily Remaining: {$pass_check['daily_uses_remaining']}\n";
    
    // 3. (P3) 检查业务限制
    echo "Validating limits (Need: $mock_redeem_count_frontend uses)...\n";
    validate_redeem_limits($pdo, $mock_cart, $tags_map, $pass_check);
    echo "Limits OK.\n";
    
    // 4. (P3/P4) 执行核心事务
    echo "Executing create_redeem_records() transaction...\n";
    $context = [
        'store_id' => P3_STORE_ID, 'user_id' => P3_USER_ID, 'device_id' => 'pos_p3_test_suite',
        'member_id' => P3_MEMBER_ID, 'pass_id' => P3_MEMBER_PASS_ID,
        'idempotency_key' => 'test-key-' . bin2hex(random_bytes(16)),
        'store_config' => $store_config, 'vat_rate' => $vat_rate
    ];
    
    $result = create_redeem_records(
        $pdo, $context, $alloc, $mock_cart, $tags_map, $addon_defs, $mock_payment_summary
    );
    echo "Transaction successful.\n";
    
    // 5. (P3/P4) 验证结果
    echo "\n--- [VERIFICATION PASSED] ---\n";
    
    if (empty($result['invoice_id_tp']) || empty($result['invoice_number_tp'])) {
         throw new Exception("P3 FAILED: (TP) Invoice ID or Number was not generated for extra charge.");
    }
    if ($result['invoice_number_vr'] !== null) {
         throw new Exception("P3 FAILED: (VR) Number was generated, but (TP) should have been.");
    }
    echo "P3 PASSED: (TP) Invoice {$result['invoice_number_tp']} (ID: {$result['invoice_id_tp']}) created for extra charge.\n";
    echo "P3 PASSED: (TP) QR Payload: " . ($result['qr_content_tp'] ? 'OK' : 'MISSING') . "\n";

    $job_types = array_column($result['print_jobs'], 'type');
    $has_receipt = in_array('RECEIPT', $job_types, true);
    $has_slip = in_array('PASS_REDEMPTION_SLIP', $job_types, true);

    if (!$has_receipt) {
        throw new Exception("P4 FAILED: 'RECEIPT' (TP) print job was NOT generated for extra charge.");
    }
    echo "P4 PASSED: 'RECEIPT' (TP) print job generated.\n";
    
    if (!$has_slip) {
        throw new Exception("P4 FAILED: 'PASS_REDEMPTION_SLIP' print job was NOT generated.");
    }
    echo "P4 PASSED: 'PASS_REDEMPTION_SLIP' print job generated.\n";

    // 验证 P4 凭条数据
    $slip_job = array_values(array_filter($result['print_jobs'], fn($j) => $j['type'] === 'PASS_REDEMPTION_SLIP'))[0];
    $slip_data = $slip_job['data'];
    
    if (empty($slip_data['redeemed_at_local'])) throw new Exception("P4 FAILED: Slip data 'redeemed_at_local' is missing.");
    if ($slip_data['remaining_uses'] !== ((int)$pass_check['remaining_uses'] - $mock_redeem_count_frontend)) {
         throw new Exception("P4 FAILED: Slip data 'remaining_uses' calculation is incorrect.");
    }
    echo "P4 PASSED: Slip data 'remaining_uses' ({$slip_data['remaining_uses']}) is correct.\n";

    // 6. 回滚
    echo "\nRolling back transaction...\n";
    $pdo->rollBack();
    
    $duration = microtime(true) - $test_start_time;
    echo "\n--- [P3 TEST SUCCESS] (Rolled Back) ---";
    echo "\nDuration: " . number_format($duration * 1000, 2) . " ms\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        echo "\n!!! TEST FAILED: Rolling back transaction... !!!\n";
        $pdo->rollBack();
    }
    echo "\n--- [P3 TEST FAILED] ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line: " . $e->getLine() . ")\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}