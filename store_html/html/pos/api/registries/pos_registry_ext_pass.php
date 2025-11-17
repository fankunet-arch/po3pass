<?php
declare(strict_types=1);
/**
 * pos_registry_ext_pass.php
 *
 * 注册表扩展 - 次卡 (Seasons Pass)
 * 包含次卡核销 (P3) 这一复杂事务，独立于主 registry。
 * * Version: 1.0.3 (B1.5 - FIX Error 2 Dependency & AUDIT)
 * Date: 2025-11-16
 *
 * [GEMINI AUDIT FIX 2025-11-16]:
 * 1. 修复了 handle_pass_redeem 中 json_ok() 的参数颠倒问题 (Bug: Argument #1 was string)。
 * 2. 修复了 L70 对 get_addons_with_tags() 的调用。该函数现已在 pos_repo.php 中实现。
 *
 * [B1.5 ERROR 2 FIX]
 * - 致命错误修复：P3 事务依赖 pos_pass_helper.php (用于 validate_redeem_limits 和
 * create_redeem_records) 以及 pos_repo_ext_pass.php (用于 get_member_pass_for_update)。
 * - pos_helper.php (被 B1.3.1 修复) 并未加载这些。
 * - 此处必须显式 require_once 这两个 P3 依赖项。
 *
 * [B1.4 P4]
 * - handle_pass_redeem() 现在会接收并返回 create_redeem_records() 提供的
 * P3/P4 完整数据结构 (包含 TP/VR 票号和打印任务)。
 *
 * [B1.3.1]
 * - 修正了 pos_helper.php 的 realpath 路径 (从 5 级返回到 4 级)。
 * - 实现了 handle_pass_redeem() (P3 核心事务)。
 */

// 1. 加载核心助手 (json_error 等)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_json_helper.php');

// 2. 加载核心助手 (pos_helper.php 加载了 pos_repo, datetime, promotion_engine 等)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_helper.php');

// 3. 加载班次守卫
require_once realpath(__DIR__ . '/../../../../pos_backend/core/shift_guard.php');

// 4. [B1.5 ERROR 2 FIX] 加载 P3 (核销) 事务的专属依赖
// (pos_repo_ext_pass.php 提供了 get_member_pass_for_update)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_repo_ext_pass.php');
// (pos_pass_helper.php 提供了 validate_redeem_limits 和 create_redeem_records)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_pass_helper.php');


if (!function_exists('handle_pass_redeem')) {
    /**
     * [B1.3.1] P3 核心：处理次卡核销 (res=pass&act=redeem)
     */
    function handle_pass_redeem(PDO $pdo, array $config, array $input_data): void {
        
        // 1. 验证班次
        ensure_active_shift_or_fail($pdo);

        // 2. 获取上下文
        $store_id  = (int)($_SESSION['pos_store_id'] ?? 0);
        $user_id   = (int)($_SESSION['pos_user_id']  ?? 0);
        $device_id = (string)($input_data['device_id'] ?? null);

        // 3. 解析载荷
        $cart        = $input_data['cart'] ?? [];
        $payment_raw = $input_data['payment'] ?? [];
        $member_id   = (int)($input_data['member_id'] ?? 0);
        $pass_id     = (int)($input_data['pass_id'] ?? 0); // 目标 member_pass_id
        $idempotency_key = (string)($input_data['idempotency_key'] ?? '');
        
        if (empty($cart)) json_error('购物车不能为空。', 400);
        if ($member_id <= 0) json_error('必须绑定会员。', 400);
        if ($pass_id <= 0) json_error('必须选择要核销的次卡。', 400);
        if (empty($idempotency_key)) json_error('缺少幂等键 (Idempotency Key)。', 400);

        // 4. 加载 P2 (计价) 所需的依赖
        $store_config = get_store_config_full($pdo, $store_id);
        $vat_rate = (float)($store_config['default_vat_rate'] ?? 21.0);
        
        // [B1.5] 修复：确保 global_free_addon_limit 已被加载
        if (!isset($store_config['global_free_addon_limit'])) {
            $stmt_addon_limit = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'global_free_addon_limit'");
            $stmt_addon_limit->execute();
            $limit = $stmt_addon_limit->fetchColumn();
            $store_config['global_free_addon_limit'] = ($limit === false || $limit === null) ? 0 : (int)$limit;
        }
        $global_free_addon_limit = (int)($store_config['global_free_addon_limit'] ?? 0);
        
        $tags_map = get_cart_item_tags($pdo, array_map(fn($item) => (int)$item['product_id'], $cart));
        
        // [GEMINI AUDIT FIX 2025-11-16]
        // 修复：调用在 pos_repo.php 中新实现的 get_addons_with_tags()
        $addon_defs = get_addons_with_tags($pdo); 
        
        // 5. [B1.4 P2] P2 服务端计价 (依赖: pos_pass_helper.php)
        $alloc = calculate_redeem_allocation($pdo, $cart, $tags_map, $addon_defs, $global_free_addon_limit);
        
        // 6. 校验支付金额
        // 依赖: pos_repo.php
        [, , , $sumPaid, $payment_summary] = extract_payment_totals($payment_raw);
        if ($sumPaid < $alloc['extra_total'] - 0.01) {
            json_error('支付金额不足 (Payment amount mismatch)', 422, [
              'required_extra' => $alloc['extra_total'],
              'sum_paid' => $sumPaid,
            ]);
        }

        $pdo->beginTransaction();
        try {
            // 7. (P3) 检查卡片状态 (FOR UPDATE) (依赖: pos_repo_ext_pass.php)
            $pass_check = get_member_pass_for_update($pdo, $pass_id, $member_id);
            if (!$pass_check) {
                $pdo->rollBack();
                json_error('次卡无效或已过期 (Pass invalid or expired)。', 404);
            }
            
            // 8. (P3) 检查业务限制 (依赖: pos_pass_helper.php)
            validate_redeem_limits($pdo, $cart, $tags_map, $pass_check);
            
            // 9. (P3/P4) 执行事务：写入所有记录并生成打印任务
            $context = [
                'store_id' => $store_id, 'user_id' => $user_id, 'device_id' => $device_id,
                'member_id' => $member_id, 'pass_id' => $pass_id,
                'idempotency_key' => $idempotency_key,
                'store_config' => $store_config, 'vat_rate' => $vat_rate
            ];
            $result_data = create_redeem_records(
                $pdo, $context, $alloc, $cart, $tags_map, $addon_defs, $payment_summary
            );

            // 10. 提交事务
            $pdo->commit();
            
            // 11. [B1.4 P4] 返回 P3/P4 完整数据
            // [GEMINI AUDIT FIX 2025-11-16] 修复 json_ok 参数颠倒
            json_ok($result_data, '核销成功 (Redemption successful)');

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 幂等键冲突
            if ($e instanceof PDOException && $e->getCode() == '23000' && str_contains($e->getMessage(), 'invoice_uuid')) {
                json_error('重复的请求 (Idempotency conflict)。', 409, ['debug' => $e->getMessage()]);
            }
            json_error('核销失败: ' . $e->getMessage(), 500, ['debug' => $e->getTraceAsString()]);
        }
    }
}


return [
    'pass' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            // 'purchase' => 'handle_pass_purchase', // P0 (在主 registry)
            'redeem'   => 'handle_pass_redeem',      // P3 (在此实现)
        ],
    ],
];