<?php
declare(strict_types=1);
/**
 * pos_repo_ext_pass.php
 *
 * 仓库扩展 - 专门用于处理次卡 (Seasons Pass) 相关的数据库查询。
 * * Version: 1.0.2 (B1.4 P4)
 * Date: 2025-11-14
 *
 * [B1.4 P4]
 * - 新增 get_pass_print_details()，用于在核销后获取打印凭条所需的数据。
 *
 * [B1.3.1]
 * - 修正了 handle_pass_redeem (已移至 registry) 依赖的 FOR UPDATE 查询。
 * - 移除了重复定义的 get_pass_plan_details。
 *
 * [B1.3]
 * - 新增 get_member_active_passes()，供 handle_member_find 调用。
 *
 * [B1.2]
 * - 初始版本，包含 B1 (售卡) 所需的辅助函数。
 */

function get_pass_plan_by_sku(PDO $pdo, string $sku): ?array {
    $stmt = $pdo->prepare("SELECT * FROM pass_plans WHERE sale_sku = ? AND is_active = 1");
    $stmt->execute([$sku]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    return $plan ?: null;
}

function get_pass_plan_by_id(PDO $pdo, int $pass_plan_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM pass_plans WHERE pass_plan_id = ? AND is_active = 1");
    $stmt->execute([$pass_plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    return $plan ?: null;
}

function create_pass_records(PDO $pdo, array $context, array $vr_info, ?array $cart_item, array $plan_details): int {
    // Idempotency check
    $stmt = $pdo->prepare("SELECT topup_order_id FROM topup_orders WHERE idempotency_key = ?");
    $stmt->execute([$context['idempotency_key']]);
    $existing_order = $stmt->fetch();
    if ($existing_order) {
        // If the order already exists, we need to find the member_pass_id to return.
        $stmt = $pdo->prepare("SELECT member_pass_id FROM member_passes WHERE topup_order_id = ?");
        $stmt->execute([$existing_order['topup_order_id']]);
        $member_pass = $stmt->fetch();
        return $member_pass ? (int)$member_pass['member_pass_id'] : 0;
    }

    // 1. Create topup_orders record
    $sql_topup = "
        INSERT INTO topup_orders (
            store_id, user_id, device_id, member_id, pass_plan_id,
            vr_invoice_series, vr_invoice_number, payment_method, amount, status, idempotency_key
        ) VALUES (
            :store_id, :user_id, :device_id, :member_id, :pass_plan_id,
            :vr_series, :vr_number, :payment_method, :amount, 'completed', :idempotency_key
        )
    ";
    $stmt_topup = $pdo->prepare($sql_topup);
    $stmt_topup->execute([
        ':store_id' => $context['store_id'],
        ':user_id' => $context['user_id'],
        ':device_id' => $context['device_id'],
        ':member_id' => $context['member_id'],
        ':pass_plan_id' => $plan_details['pass_plan_id'],
        ':vr_series' => $vr_info['series'],
        ':vr_number' => $vr_info['number'],
        ':payment_method' => $context['payment_method'],
        ':amount' => $plan_details['sale_price'],
        ':idempotency_key' => $context['idempotency_key']
    ]);
    $topup_order_id = (int)$pdo->lastInsertId();

    // 2. Create or update member_passes record
    // For simplicity, this example creates a new pass each time.
    // A more robust implementation might check for existing, unused passes.
    $validity_days = (int)$plan_details['validity_days'];
    $expires_at = $validity_days > 0 ? (new DateTime())->modify("+$validity_days days")->format('Y-m-d H:i:s') : null;

    $sql_member_pass = "
        INSERT INTO member_passes (
            member_id, pass_plan_id, topup_order_id,
            total_uses, remaining_uses, expires_at, status
        ) VALUES (
            :member_id, :pass_plan_id, :topup_order_id,
            :total_uses, :remaining_uses, :expires_at, 'active'
        )
    ";
    $stmt_member_pass = $pdo->prepare($sql_member_pass);
    $stmt_member_pass->execute([
        ':member_id' => $context['member_id'],
        ':pass_plan_id' => $plan_details['pass_plan_id'],
        ':topup_order_id' => $topup_order_id,
        ':total_uses' => $plan_details['total_uses'],
        ':remaining_uses' => $plan_details['total_uses'],
        ':expires_at' => $expires_at
    ]);

    return (int)$pdo->lastInsertId();
}


// [B1.2] 验证次卡售卖订单
// (依赖: pos_repo.php -> get_cart_item_tags)
if (!function_exists('validate_pass_purchase_order')) {
    function validate_pass_purchase_order(PDO $pdo, array $cart, array $tags_map, ?array $promo_result): void {
        // 1. 检查是否为纯次卡商品
        $has_pass_product = false;
        $has_other_product = false;

        foreach ($cart as $item) {
            $menu_item_id = (int)($item['product_id'] ?? 0);
            $item_tags = $tags_map[$menu_item_id] ?? [];

            if (in_array('pass_product', $item_tags, true)) {
                $has_pass_product = true;
            } else {
                $has_other_product = true;
            }
        }

        if (!$has_pass_product) {
            json_error_localized('购物车中不包含次卡商品', 'Cart does not contain pass products.');
        }
        if ($has_other_product) {
            json_error_localized('购买优惠卡时，订单中不能包含其他商品，请先完成或清空当前订单。', 'Para comprar una tarjeta promocional, el pedido no puede contener otros productos. Por favor, finalice o vacíe el pedido actual.');
        }

        // 2. 检查是否应用了任何折扣
        $discount_amount = $promo_result['discount_amount'] ?? 0.0;
        if ((float)$discount_amount > 0) {
            json_error_localized('购买优惠卡时不允许使用优惠券、折扣或积分，请先取消订单中的所有优惠。', 'No se permiten cupones, descuentos ni puntos al comprar una tarjeta promocional. Por favor, elimine todas las promociones del pedido.');
        }

        // 3. 检查是否使用了积分
        $points_redeemed = $promo_result['points_redemption']['points_redeemed'] ?? 0;
        if ((int)$points_redeemed > 0) {
            json_error_localized('购买优惠卡时不允许使用优惠券、折扣或积分，请先取消订单中的所有优惠。', 'No se permiten cupones, descuentos ni puntos al comprar una tarjeta promocional. Por favor, elimine todas las promociones del pedido.');
        }
    }
}

// [B1.2] 获取次卡方案详情
// [B1.3.1] 修复：移除了此处的重复定义，此函数已在 pos_pass_helper.php 中定义。
/*
if (!function_exists('get_pass_plan_details')) {
    ...
}
*/

// [B1.2] 分配 VR (售卡) 票号
if (!function_exists('allocate_vr_invoice_number')) {
    function allocate_vr_invoice_number(PDO $pdo, string $store_prefix): array {

        // 1. [A2 UTC SYNC] 依赖 datetime_helper.php
        $tz = new DateTimeZone(APP_DEFAULT_TIMEZONE); // e.g., 'Europe/Madrid'
        $year_short = (new DateTime('now', $tz))->format('y'); // e.g., "25"

        $vr_prefix = $store_prefix . '-VR'; // e.g., S1-VR
        $series = $vr_prefix . 'Y' . $year_short; // e.g., S1-VRY25

        // 2. 原子化更新计数器
        $sql = "
            INSERT INTO pos_vr_counters (vr_prefix, series, current_number)
            VALUES (:vr_prefix, :series, 1)
            ON DUPLICATE KEY UPDATE
                current_number = LAST_INSERT_ID(current_number + 1);
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':vr_prefix' => $vr_prefix,
            ':series' => $series
        ]);

        $new_number = (int)$pdo->lastInsertId();

        return [$series, $new_number];
    }
}

// [B1.3.1] 获取用于核销的次卡 (带行锁)
if (!function_exists('get_member_pass_for_update')) {
    function get_member_pass_for_update(PDO $pdo, int $pass_id, int $member_id): ?array {

        // [A2 UTC SYNC] 依赖 datetime_helper.php
        $now_utc_str = utc_now()->format('Y-m-d H:i:s');

        $sql = "
            SELECT
                mp.member_pass_id AS pass_id,
                mp.remaining_uses,
                pp.max_uses_per_order,
                pp.max_uses_per_day
            FROM member_passes mp
            JOIN pass_plans pp ON mp.pass_plan_id = pp.pass_plan_id
            WHERE mp.member_pass_id = ?
              AND mp.member_id = ?
              AND mp.status = 'active'
              AND mp.remaining_uses > 0
              AND (mp.expires_at IS NULL OR mp.expires_at > ?)
            FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pass_id, $member_id, $now_utc_str]);
        $pass = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pass) {
            return null; // 卡无效或不满足条件
        }

        // 检查当日限额
        if ($pass['max_uses_per_day'] > 0) {
            // [A2 UTC SYNC] 依赖 datetime_helper.php
            $tz = new DateTimeZone(APP_DEFAULT_TIMEZONE);
            $today_local_date = (new DateTime('now', $tz))->format('Y-m-d');
            // 将本地日期转为 UTC 零点 (用于比较数据库中的 UTC 日期)
            $today_utc_date = (new DateTime($today_local_date . ' 00:00:00', $tz))
                              ->setTimezone(new DateTimeZone('UTC'))
                              ->format('Y-m-d');

            $sql_usage = "SELECT uses_count FROM pass_daily_usage WHERE member_pass_id = ? AND usage_date = ?";
            $stmt_usage = $pdo->prepare($sql_usage);
            $stmt_usage->execute([$pass_id, $today_utc_date]);
            $today_uses = (int)$stmt_usage->fetchColumn();

            $pass['daily_uses_remaining'] = max(0, (int)$pass['max_uses_per_day'] - $today_uses);
        } else {
            $pass['daily_uses_remaining'] = null; // null 表示不限制
        }

        return $pass;
    }
}


// [B1.3] 获取会员的所有有效次卡 (用于前端展示)
if (!function_exists('get_member_active_passes')) {
    function get_member_active_passes(PDO $pdo, int $member_id): array {

        // [A2 UTC SYNC] 依赖 datetime_helper.php
        $now_utc_str = utc_now()->format('Y-m-d H:i:s');

        // [B1.3] 增加了 pp.name 和简单的双语支持 (如果未来 pass_plans 增加翻译)
        $sql = "
            SELECT
                mp.member_pass_id AS pass_id,
                mp.remaining_uses,
                mp.expires_at,
                SUBSTR(mp.member_pass_id, -4) AS pass_last4, /* 临时用 ID 后四位 */
                pp.name,
                pp.max_uses_per_order,
                pp.max_uses_per_day
                /* TODO: 增加 pp.name_zh, pp.name_es */
            FROM member_passes mp
            JOIN pass_plans pp ON mp.pass_plan_id = pp.pass_plan_id
            WHERE mp.member_id = ?
              AND mp.status = 'active'
              AND mp.remaining_uses > 0
              AND (mp.expires_at IS NULL OR mp.expires_at > ?)
            ORDER BY mp.expires_at ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$member_id, $now_utc_str]);
        $passes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($passes)) {
            return [];
        }

        // [B1.3.1] 检查当日限额
        // [A2 UTC SYNC] 依赖 datetime_helper.php
        $tz = new DateTimeZone(APP_DEFAULT_TIMEZONE);
        $today_local_date = (new DateTime('now', $tz))->format('Y-m-d');
        $today_utc_date = (new DateTime($today_local_date . ' 00:00:00', $tz))
                          ->setTimezone(new DateTimeZone('UTC'))
                          ->format('Y-m-d');

        $sql_usage = "SELECT member_pass_id, uses_count FROM pass_daily_usage WHERE member_id = ? AND usage_date = ?";
        $stmt_usage = $pdo->prepare($sql_usage);
        $stmt_usage->execute([$member_id, $today_utc_date]);
        $today_usages = $stmt_usage->fetchAll(PDO::FETCH_KEY_PAIR); // [pass_id => uses_count]

        foreach ($passes as &$pass) {
            if ($pass['max_uses_per_day'] > 0) {
                $today_uses = $today_usages[$pass['pass_id']] ?? 0;
                $pass['daily_uses_remaining'] = max(0, (int)$pass['max_uses_per_day'] - (int)$today_uses);
            } else {
                $pass['daily_uses_remaining'] = null; //不限
            }

            // [B1.3] 临时双语处理
            $pass['name_translation'] = [
                'zh' => $pass['name'],
                'es' => $pass['name']
            ];
        }

        return $passes;
    }
}

// [B1.4 P4] 新增：获取打印凭条所需的数据
if (!function_exists('get_pass_print_details')) {
    function get_pass_print_details(PDO $pdo, int $member_pass_id): ?array {
        $sql = "
            SELECT
                m.phone_number,
                mp.remaining_uses,
                mp.expires_at,
                pp.name AS pass_name
            FROM member_passes mp
            JOIN pos_members m ON mp.member_id = m.id
            JOIN pass_plans pp ON mp.pass_plan_id = pp.pass_plan_id
            WHERE mp.member_pass_id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$member_pass_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
