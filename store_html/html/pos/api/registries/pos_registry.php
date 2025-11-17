<?php
/**
 * Toptea Store - POS 统一 API 注册表
 * 迁移所有 store/html/pos/api/ 的逻辑
 * Version: 1.2.5 (B1.4 P2 - Load Addon Tags & Global Limit)
 * Date: 2025-11-14
 *
 * [GEMINI FATAL ERROR FIX 2025-11-16]
 * 1. 添加了 `require_once pos_repo.php`。
 * 2. `pos_registry_ops.php` 中的 `handle_data_load` 函数依赖 `get_store_config_full()`。
 * 3. `get_store_config_full()` 定义在 `pos_repo.php` 中。
 * 4. 此文件（主注册表）忘记加载 `pos_repo.php`，导致了 'Call to undefined function' 致命错误。
 *
 * [B1.4 P2]:
 * - handle_data_load() 现在查询 'global_free_addon_limit' 并将其附加到 store_config。
 * - handle_data_load() 现在查询 'pos_addon_tag_map' 并将标签列表附加到每个 addon 对象中。
 *
 * [B1.3 PASS]:
 * - handle_member_find() 现在会调用 get_member_active_passes() (来自 pos_repo_ext_pass.php)，
 * 并将次卡列表作为 `passes` 字段附加到会员数据中返回。
 * - handle_data_load() 现在会额外加载所有商品的标签映射 (pos_product_tag_map)
 * 并将其作为 `tags_map` [menu_item_id => [tag_code,...]] 返回给前端。
 *
 * - 移除了 handle_txn_list, handle_txn_get_details, handle_eod_list, handle_eod_get 中的 fmt_local() 调用。
 *
 * [A2 UTC SYNC]: Modified handle_order_submit to use utc_now() for timestamps.
 * [B1.2 PASS]: Added 'pass' resource (purchase/redeem).
 * [B1.2.2 REFACTOR]: Merged pos_pass_handler.php functions (handle_pass_purchase, handle_pass_redeem) into this file
 * and removed the require_once for the handler file.
 * [B1.3.1 REFACTOR]: Removed handle_pass_redeem implementation. It is now loaded via pos_registry_ext_pass.php
 */

// 1. 加载所有 POS 业务逻辑函数 (来自 pos_repo.php)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_helper.php');
require_once realpath(__DIR__ . '/../../../../pos_backend/core/invoicing_guard.php');

// [GEMINI FATAL ERROR FIX 2025-11-16] 
// 必须在此处加载 pos_repo.php，因为 pos_registry_ops.php 中的 handle_data_load 依赖它
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_repo.php');


// [B1.2 PASS] 2. 加载次卡处理器
// [B1.2.2 REFACTOR] Removed: require_once realpath(__DIR__ . '/../handlers/pos_pass_handler.php');


// 2. 定义门店端角色常量 (必须与 pos_api_core.php 一致)
if (!defined('ROLE_STORE_MANAGER')) {
    define('ROLE_STORE_MANAGER', 'manager');
}
if (!defined('ROLE_STORE_USER')) {
    define('ROLE_STORE_USER', 'staff');
}

// [REPO SPLIT] Handlers moved to dedicated files.
require_once __DIR__ . '/pos_registry_sales.php';
require_once __DIR__ . '/pos_registry_ops.php';
require_once __DIR__ . '/pos_registry_member_pass.php';

/* -------------------------------------------------------------------------- */
/* 注册表                                                   */
/* -------------------------------------------------------------------------- */
return [
    
    // [B1.2 PASS] 新增次卡资源
    'pass' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list'     => 'handle_pass_list',     // 优惠卡列表
            'purchase' => 'handle_pass_purchase', // 售卡
            'redeem'   => 'handle_pass_redeem',   // [B1.3.1] 核销 (实现在 ext 文件)
        ],
    ],

    // POS: Order
    'order' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'submit' => 'handle_order_submit',
        ],
    ],
    
    // POS: Cart
    'cart' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'calculate' => 'handle_cart_calculate',
        ],
    ],

    // POS: Shift
    'shift' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'status' => 'handle_shift_status',
            'start' => 'handle_shift_start',
            'end' => 'handle_shift_end',
            'force_start' => 'handle_shift_force_start',
        ],
    ],
    
    // POS: Data Loader
    'data' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'load' => 'handle_data_load',
        ],
    ],
    
    // POS: Member
    'member' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'find' => 'handle_member_find',
            'create' => 'handle_member_create',
        ],
    ],
    
    // POS: Hold
    'hold' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list' => 'handle_hold_list',
            'save' => 'handle_hold_save',
            'restore' => 'handle_hold_restore',
        ],
    ],
    
    // POS: Transaction
    'transaction' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list' => 'handle_txn_list',
            'get_details' => 'handle_txn_get_details',
        ],
    ],
    
    // POS: Print
    'print' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_templates' => 'handle_print_get_templates',
            'get_eod_data' => 'handle_print_get_eod_data',
        ],
    ],
    
    // POS: Availability (估清)
    'availability' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_all' => 'handle_avail_get_all',
            'toggle' => 'handle_avail_toggle',
            'reset_all' => 'handle_avail_reset_all',
        ],
    ],
    
    // POS: EOD (日结)
    'eod' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_preview' => 'handle_eod_get_preview',
            'submit_report' => 'handle_eod_submit_report',
            'list' => 'handle_eod_list',
            'get' => 'handle_eod_get',
            'check_status' => 'handle_check_eod_status',
        ],
    ],
];