<?php
/**
 * Toptea POS - 诊断注册表（B1 自测脚本）
 * 提供只读自检：函数存在性 / 关键文件存在性 / 关键表可访问性
 * Version: 1.0.0
 * Date: 2025-11-09
 *
 * [GEMINI TYPO FIX 2025-11-16]:
 * 1. 修复了 L35 检查的函数名 (validate_pass_redeem_order -> validate_redeem_limits)。
 */

declare(strict_types=1);

require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_helper.php');
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_repo_ext_pass.php');
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_pass_helper.php');

if (!defined('ROLE_STORE_MANAGER')) define('ROLE_STORE_MANAGER', 'manager');

/** GET / POST: 无参数 */
function handle_diag_selfcheck(PDO $pdo, array $config, array $input): void {
    $checks = [];

    // 1) 函数存在性
    $checks['functions'] = [
        'json_ok'                    => function_exists('json_ok'),
        'json_error'                 => function_exists('json_error'),
        'get_request_data'           => function_exists('get_request_data'),
        'utc_now'                    => function_exists('utc_now'),
        'ensure_active_shift_or_fail'=> function_exists('ensure_active_shift_or_fail'),
        'get_store_config_full'      => function_exists('get_store_config_full'),
        'get_member_pass_for_update' => function_exists('get_member_pass_for_update'),
        'validate_redeem_limits'     => function_exists('validate_redeem_limits'), // [GEMINI TYPO FIX]
        'calculate_redeem_allocation'=> function_exists('calculate_redeem_allocation'),
        'create_redeem_records'      => function_exists('create_redeem_records'),
        'allocate_invoice_number'    => function_exists('allocate_invoice_number'),
    ];

    // 2) 关键文件存在性（路径是否匹配我们本次交付）
    $base = realpath(__DIR__ . '/../../../../pos_backend/helpers');
    $checks['files'] = [
        'pos_repo_ext_pass.php' => file_exists($base . '/pos_repo_ext_pass.php'),
        'pos_pass_helper.php'   => file_exists($base . '/pos_pass_helper.php'),
    ];

    // 3) 关键表可访问性（只读探测）
    $db = ['pos_invoice_counters','pos_invoices','pos_invoice_items','member_passes','pass_redemption_batches','pass_redemptions','pass_daily_usage'];
    $tables_ok = [];
    foreach ($db as $t) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM {$t} LIMIT 1");
            $tables_ok[$t] = $stmt !== false;
        } catch (Throwable $e) {
            $tables_ok[$t] = false;
        }
    }
    $checks['tables'] = $tables_ok;

    json_ok($checks, 'diag_ok');
}

// 导出注册表
return [
    'diag' => [
        'table' => null,
        'pk' => null,
        'soft_delete_col' => null,
        'auth_role' => ROLE_STORE_MANAGER, // 只允许店长或以上自测
        'custom_actions' => [
            'selfcheck' => 'handle_diag_selfcheck'
        ],
    ],
];