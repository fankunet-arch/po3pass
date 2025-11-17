<?php
/**
 * Toptea Store POS - Ops (Shift/EOD/Availability/Print/Data) Handlers
 * Extracted from pos_registry.php
 */

// [OPS SPLIT] 班次 / 日结处理拆分到独立文件，避免本文件过大
require_once __DIR__ . '/pos_registry_ops_shift.php';
require_once __DIR__ . '/pos_registry_ops_eod.php';

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_data_loader.php                   */
/* -------------------------------------------------------------------------- */
function handle_data_load(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);

    // [PHASE 4.1.A] 获取门店配置 (用于打印机)
    $store_config = get_store_config_full($pdo, $store_id);

    // [B1.4 P2] START: 加载全局免费加料上限
    $stmt_addon_limit = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'global_free_addon_limit'");
    $stmt_addon_limit->execute();
    $limit = $stmt_addon_limit->fetchColumn();
    // 确保 store_config 中包含此值
    $store_config['global_free_addon_limit'] = ($limit === false || $limit === null) ? 0 : (int)$limit;
    // [B1.4 P2] END

    $categories_sql = "SELECT category_code AS `key`, name_zh AS label_zh, name_es AS label_es FROM pos_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC";
    $categories = $pdo->query($categories_sql)->fetchAll(PDO::FETCH_ASSOC);

    $gating_data = [ 'ice' => [], 'sweetness' => [] ];
    $ice_rules = $pdo->query("SELECT product_id, ice_option_id FROM kds_product_ice_options WHERE ice_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ice_rules as $rule) { $gating_data['ice'][(int)$rule['product_id']][] = (int)$rule['ice_option_id']; }
    $sweet_rules = $pdo->query("SELECT product_id, sweetness_option_id FROM kds_product_sweetness_options WHERE sweetness_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sweet_rules as $rule) { $gating_data['sweetness'][(int)$rule['product_id']][] = (int)$rule['sweetness_option_id']; }
    $managed_ice_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_ice_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_sweet_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_sweetness_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_ice_set = array_flip($managed_ice_products);
    $managed_sweet_set = array_flip($managed_sweet_products);

    // [PHASE 4.1.A] 修改 SQL, 增加 kc.cup_code
    $menu_sql = "
        SELECT 
            mi.id, mi.name_zh, mi.name_es, mi.image_url, pc.category_code,
            pv.id as variant_id, pv.variant_name_zh, pv.variant_name_es, pv.price_eur, pv.is_default,
            kp.product_code AS product_sku, kp.id AS kds_product_id,
            kc.cup_code AS cup_code,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        JOIN pos_item_variants pv ON mi.id = pv.menu_item_id
        JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN kds_products kp ON mi.product_code = kp.product_code AND kp.deleted_at IS NULL
        LEFT JOIN kds_cups kc ON pv.cup_id = kc.id AND kc.deleted_at IS NULL
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL 
          AND mi.is_active = 1
          AND pv.deleted_at IS NULL
          AND pc.deleted_at IS NULL
        ORDER BY pc.sort_order, mi.sort_order, mi.id, pv.sort_order
    ";
    
    $stmt_menu = $pdo->prepare($menu_sql);
    $stmt_menu->execute([':store_id' => $store_id]);
    $results = $stmt_menu->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($results as $row) {
        $itemId = (int)$row['id'];
        if (!isset($products[$itemId])) {
            $kds_pid = $row['kds_product_id'] ? (int)$row['kds_product_id'] : null;
            $allowed_ice_ids = null;
            $allowed_sweetness_ids = null;
            if ($kds_pid) {
                if (isset($managed_ice_set[$kds_pid])) { $allowed_ice_ids = $gating_data['ice'][$kds_pid] ?? []; }
                if (isset($managed_sweet_set[$kds_pid])) { $allowed_sweetness_ids = $gating_data['sweetness'][$kds_pid] ?? []; }
            }
            $products[$itemId] = [
                'id' => $itemId, 'title_zh' => $row['name_zh'], 'title_es' => $row['name_es'],
                'image_url' => $row['image_url'], 'category_key' => $row['category_code'],
                'allowed_ice_ids' => $allowed_ice_ids, 'allowed_sweetness_ids' => $allowed_sweetness_ids,
                'is_sold_out' => (int)$row['is_sold_out'],
                'variants' => []
            ];
        }
        $products[$itemId]['variants'][] = [
            'id' => (int)$row['variant_id'], 
            'recipe_sku' => $row['product_sku'], // This is product_code
            'cup_code' => $row['cup_code'],   // [PHASE 4.1.A] 新增
            'name_zh' => $row['variant_name_zh'], 
            'name_es' => $row['variant_name_es'],
            'price_eur' => (float)$row['price_eur'], 
            'is_default' => (bool)$row['is_default']
        ];
    }
    
    try {
        // [B1.4 P2] START: 修改SQL以包含 addon ID
        $addons_sql = "
            SELECT a.id, a.addon_code AS `key`, a.name_zh AS label_zh, a.name_es AS label_es, a.price_eur 
            FROM pos_addons a
            WHERE a.is_active = 1 AND a.deleted_at IS NULL 
            ORDER BY a.sort_order ASC
        ";
        $addons = $pdo->query($addons_sql)->fetchAll(PDO::FETCH_ASSOC);
        
        // [B1.4 P2] 抓取所有 addon 标签
        $addon_ids = array_column($addons, 'id');
        $addon_tags_map = [];
        if (!empty($addon_ids)) {
            // 必须用 array_values 重置索引才能正确绑定
            $in_placeholders = implode(',', array_fill(0, count($addon_ids), '?'));
            $sql_addon_tags = "SELECT map.addon_id, t.tag_code 
                               FROM pos_addon_tag_map map
                               JOIN pos_tags t ON map.tag_id = t.tag_id
                               WHERE map.addon_id IN ($in_placeholders)";
            $stmt_addon_tags = $pdo->prepare($sql_addon_tags);
            $stmt_addon_tags->execute(array_values($addon_ids)); // 传入索引数组
            while ($row = $stmt_addon_tags->fetch(PDO::FETCH_ASSOC)) {
                $addon_tags_map[(int)$row['addon_id']][] = $row['tag_code'];
            }
        }
        
        // [B1.4 P2] 将标签合并到 $addons 数组中
        foreach ($addons as &$addon) {
            $addon['tags'] = $addon_tags_map[$addon['id']] ?? [];
        }
        unset($addon);
        // [B1.4 P2] END
        
    } catch (PDOException $e) { $addons = []; }
    
    $ice_options_sql = "
        SELECT i.id, i.ice_code, it_zh.ice_option_name AS name_zh, it_es.ice_option_name AS name_es
        FROM kds_ice_options i
        LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN'
        LEFT JOIN kds_ice_option_translations it_es ON i.id = it_es.ice_option_id AND it_es.language_code = 'es-ES'
        WHERE i.deleted_at IS NULL ORDER BY i.ice_code ASC
    ";
    $ice_options = $pdo->query($ice_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    $sweetness_options_sql = "
        SELECT s.id, s.sweetness_code, st_zh.sweetness_option_name AS name_zh, st_es.sweetness_option_name AS name_es
        FROM kds_sweetness_options s
        LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN'
        LEFT JOIN kds_sweetness_option_translations st_es ON s.id = st_es.sweetness_option_id AND st_es.language_code = 'es-ES'
        WHERE s.deleted_at IS NULL ORDER BY s.sweetness_code ASC
    ";
    $sweetness_options = $pdo->query($sweetness_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    $redemption_rules = [];
    try {
        $rules_sql = "
            SELECT id, rule_name_zh, rule_name_es, points_required, reward_type, reward_value_decimal, reward_promo_id
            FROM pos_point_redemption_rules
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY points_required ASC
        ";
        $redemption_rules = $pdo->query($rules_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $redemption_rules = []; }

    $sif_declaration = '';
    try {
        $stmt_sif = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'sif_declaracion_responsable'");
        $stmt_sif->execute();
        $sif_declaration = $stmt_sif->fetchColumn();
        if ($sif_declaration === false) $sif_declaration = ''; 
    } catch (PDOException $e) { $sif_declaration = 'Error: No se pudo cargar la declaración.'; }

    // [B1.3] START: 加载商品标签映射
    $tags_map = [];
    try {
        $sql_tags = "
            SELECT map.product_id, t.tag_code
            FROM pos_product_tag_map map
            JOIN pos_tags t ON map.tag_id = t.tag_id
        ";
        $stmt_tags = $pdo->query($sql_tags);
        while ($row = $stmt_tags->fetch(PDO::FETCH_ASSOC)) {
            $tags_map[(int)$row['product_id']][] = $row['tag_code'];
        }
    } catch (PDOException $e) { $tags_map = []; }
    // [B1.3] END: 加载商品标签映射

    $data_payload = [
        'store_config' => $store_config, // [PHASE 4.1.A] 新增
        'products' => array_values($products),
        'addons' => $addons,
        'categories' => $categories,
        'redemption_rules' => $redemption_rules,
        'ice_options' => $ice_options,
        'sweetness_options' => $sweetness_options,
        'sif_declaration' => $sif_declaration,
        'tags_map' => $tags_map, // [B1.3] 新增
    ];

    json_ok($data_payload);
}


/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_print_handler.php                */
/* -------------------------------------------------------------------------- */
function handle_print_get_templates(PDO $pdo, array $config, array $input_data): void {
    // [MODIFIED 2c] 只使用 POS store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0) json_error('无法确定门店ID。', 401);

    $stmt = $pdo->prepare(
        "SELECT template_type, template_content, physical_size
         FROM pos_print_templates 
         WHERE (store_id = :store_id OR store_id IS NULL) AND is_active = 1
         ORDER BY store_id DESC"
    );
    $stmt->execute([':store_id' => $store_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $templates = [];
    foreach ($results as $row) {
        if (!isset($templates[$row['template_type']])) {
            $templates[$row['template_type']] = [
                'content' => json_decode($row['template_content'], true),
                'size' => $row['physical_size']
            ];
        }
    }
    json_ok($templates, 'Templates loaded.');
}
function handle_print_get_eod_data(PDO $pdo, array $config, array $input_data): void {
    // [MODIFIED 2c] 只使用 POS store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0) json_error('无法确定门店ID。', 401);
    
    $report_id = (int)($_GET['report_id'] ?? 0);
    if (!$report_id) json_error('无效的报告ID。', 400);

    $stmt = $pdo->prepare(
        "SELECT r.*, s.store_name, u.display_name as user_name
         FROM pos_eod_reports r
         LEFT JOIN kds_stores s ON r.store_id = s.id
         LEFT JOIN cpsys_users u ON r.user_id = u.id
         WHERE r.id = ? AND r.store_id = ?"
    );
    $stmt->execute([$report_id, $store_id]);
    $report_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report_data) json_error('未找到指定的日结报告。', 404);

    // [A2 UTC SYNC] 依赖 datetime_helper.php
    $tz = APP_DEFAULT_TIMEZONE;
    // report_date 已经是 Y-m-d 格式，不需要转
    // executed_at 是 UTC，需要转
    $report_data['executed_at'] = fmt_local($report_data['executed_at'], 'Y-m-d H:i:s', $tz);
    // [A2 UTC SYNC] print_time 在本地生成，使用本地时区
    $report_data['print_time'] = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d H:i:s');
    
    foreach(['system_gross_sales', 'system_discounts', 'system_net_sales', 'system_tax', 'system_cash', 'system_card', 'system_platform', 'counted_cash', 'cash_discrepancy'] as $key) {
        if (isset($report_data[$key])) {
            $report_data[$key] = number_format((float)$report_data[$key], 2, '.', '');
        }
    }
    json_ok($report_data, 'EOD report data for printing retrieved.');
}


/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_availability_handler.php          */
/* -------------------------------------------------------------------------- */
function handle_avail_get_all(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $sql = "
        SELECT 
            mi.id AS menu_item_id, mi.name_zh, mi.name_es, mi.product_code,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL AND mi.is_active = 1
        ORDER BY mi.pos_category_id, mi.sort_order
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':store_id' => $store_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($items, 'Status loaded.');
}
function handle_avail_toggle(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $menu_item_id = (int)($input_data['menu_item_id'] ?? 0);
    $is_sold_out = isset($input_data['is_sold_out']) ? (int)$input_data['is_sold_out'] : 0;
    if ($menu_item_id <= 0) json_error('Invalid menu_item_id.', 400);

    // [A2 UTC SYNC] 使用 UTC 时间
    $now_utc_str = utc_now()->format('Y-m-d H:i:s');

    $sql = "
        INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at)
        VALUES (:store_id, :menu_item_id, :is_sold_out, :now)
        ON DUPLICATE KEY UPDATE
            is_sold_out = VALUES(is_sold_out),
            updated_at = VALUES(updated_at)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':store_id' => $store_id,
        ':menu_item_id' => $menu_item_id,
        ':is_sold_out' => $is_sold_out,
        ':now' => $now_utc_str // [A2 UTC SYNC]
    ]);
    json_ok(null, 'Status updated.');
}
function handle_avail_reset_all(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $stmt = $pdo->prepare("DELETE FROM pos_product_availability WHERE store_id = :store_id");
    $stmt->execute([':store_id' => $store_id]);
    json_ok(null, 'All items restocked.');
}


// [B1.3.1 REFACTOR] 移除 handle_pass_redeem 的占位实现。
// 它现在由 pos_registry_ext_pass.php 提供。
