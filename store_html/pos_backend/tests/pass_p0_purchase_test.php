<?php
declare(strict_types=1);
/**
 * P0 (售卡) 事务自检 (pass_p0_purchase_test.php)
 * * 目标：验证 P0 (B1) 售卡流程的核心事务 (create_pass_records)
 * 警告：此脚本会向数据库写入真实数据 (topup_orders, member_passes)！
 * 仅用于开发和预发布环境自检。
 *
 * (替换旧的 pass_b1_selftest.php)
 */

header('Content-Type: text/plain; charset=utf-8');

// --- 1. 环境加载 ---
echo "--- [P0 售卡事务自检 (B1)] ---\n";
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

// B1: 售卡 (P0) 测试参数
// (必须与数据库中的真实数据匹配)
const P0_MEMBER_ID = 1;       // 必须是 pos_members 中存在的 ID
const P0_STORE_ID = 1;        // 必须是 kds_stores 中存在的 ID
const P0_USER_ID = 1;         // 必须是 kds_users 中存在的 ID
const P0_PLAN_ID = 1;         // 必须是 pass_plans 中存在的 ID (e.g., 10次卡)
const P0_MENU_ITEM_ID = 100;  // 必须是 pos_menu_items 中代表此 plan_id 的商品 ID

// 模拟购物车商品 (来自前端)
$mock_cart_item = [
    'product_id' => P0_MENU_ITEM_ID,
    'qty' => 1,
    'unit_price_eur' => 30.00 // 假设10次卡售价30欧
];

// 模拟上下文 (来自会话)
$mock_context = [
    'store_id' => P0_STORE_ID,
    'user_id' => P0_USER_ID,
    'device_id' => 'pos_p0_test_suite',
    'member_id' => P0_MEMBER_ID
];

$test_start_time = microtime(true);

// --- 3. 执行测试 ---
try {
    echo "Connecting to DB: {$db_host}\n";
    // $pdo 在 config.php 中定义

    echo "Running P0 Self-Test...\n";
    
    $pdo->beginTransaction();
    echo "Transaction started.\n";

    // 1. (P0) 获取次卡方案详情
    echo "Fetching pass plan details (ID: " . P0_PLAN_ID . ")...\n";
    $plan_details = get_pass_plan_details($pdo, P0_PLAN_ID);
    if (!$plan_details) {
        throw new Exception("P0 FAILED: Plan (ID: " . P0_PLAN_ID . ") not found or inactive.");
    }
    echo "Plan found: " . $plan_details['name'] . " (" . $plan_details['total_uses'] . " uses, " . $plan_details['validity_days'] . " days)\n";

    // 2. (P0) 获取门店前缀 (用于VR票号)
    $store_config = get_store_config_full($pdo, P0_STORE_ID);
    if (empty($store_config['invoice_prefix'])) {
         throw new Exception('P0 FAILED：门店 (ID: ' . P0_STORE_ID . ') 缺少票号前缀 (invoice_prefix) 配置。');
    }
    $store_prefix = $store_config['invoice_prefix'];
    echo "Store prefix found: $store_prefix\n";
    
    // 3. (P0) 分配 VR 票号
    echo "Allocating VR invoice number...\n";
    [$vr_series, $vr_number] = allocate_vr_invoice_number($pdo, $store_prefix);
    $vr_info = ['series' => $vr_series, 'number' => $vr_number];
    echo "VR Number allocated: $vr_series-$vr_number\n";
    
    // 4. (P0) 执行核心事务：创建售卡记录
    echo "Executing create_pass_records() transaction...\n";
    $member_pass_id = create_pass_records(
        $pdo, 
        $mock_context, 
        $vr_info, 
        $mock_cart_item, 
        $plan_details
    );
    echo "Transaction successful. New member_pass_id: $member_pass_id\n";
    
    // 5. 验证结果
    echo "Verifying records (SELECT)...\n";
    $stmt_topup = $pdo->prepare("SELECT * FROM topup_orders WHERE topup_order_id = (SELECT topup_order_id FROM member_passes WHERE member_pass_id = ?)");
    $stmt_topup->execute([$member_pass_id]);
    $topup_order = $stmt_topup->fetch(PDO::FETCH_ASSOC);

    $stmt_pass = $pdo->prepare("SELECT * FROM member_passes WHERE member_pass_id = ?");
    $stmt_pass->execute([$member_pass_id]);
    $member_pass = $stmt_pass->fetch(PDO::FETCH_ASSOC);

    if (!$topup_order || !$member_pass) {
        throw new Exception("P0 FAILED: Verification failed. Records not found after commit.");
    }
    
    echo "\n--- [VERIFICATION PASSED] ---\n";
    echo "topup_orders (ID: {$topup_order['topup_order_id']}):\n";
    echo "  - VR Number: {$topup_order['voucher_series']}-{$topup_order['voucher_number']}\n";
    echo "  - Member ID: {$topup_order['member_id']}\n";
    echo "  - Amount: {$topup_order['amount_total']}\n";
    echo "  - Status: {$topup_order['review_status']}\n";

    echo "\nmember_passes (ID: {$member_pass['member_pass_id']}):\n";
    echo "  - Total Uses: {$member_pass['total_uses']}\n";
    echo "  - Remaining: {$member_pass['remaining_uses']}\n";
    echo "  - Status: {$member_pass['status']}\n";
    echo "  - Activated: {$member_pass['activated_at']}\n";
    echo "  - Expires: {$member_pass['expires_at']}\n";
    
    // 6. 回滚
    echo "\nRolling back transaction...\n";
    $pdo->rollBack();
    
    $duration = microtime(true) - $test_start_time;
    echo "\n--- [P0 TEST SUCCESS] (Rolled Back) ---";
    echo "\nDuration: " . number_format($duration * 1000, 2) . " ms\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        echo "\n!!! TEST FAILED: Rolling back transaction... !!!\n";
        $pdo->rollBack();
    }
    echo "\n--- [P0 TEST FAILED] ---\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line: " . $e->getLine() . ")\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}