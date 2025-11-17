<?php
/**
 * Toptea Store - POS 统一 API 网关
 * Version: 1.0.2
 * Date: 2025-11-09
 * 说明：加载核心 + 主注册表 + 扩展（次卡/诊断），按顺序合并。
 *
 * [GEMINI SUPER-ENGINEER FIX (Error 1/3)]
 * 1. 修复了 "Fatal error: strict_types declaration must be the very first statement" 错误。
 * 2. 将 'declare(strict_types=1);' 语句从第 12 行（require_once 之后）移动到第 9 行（require_once 之前）。
 */

declare(strict_types=1);

// 1) 核心
require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');
require_once realpath(__DIR__ . '/../../../pos_backend/helpers/pos_json_helper.php');
require_once realpath(__DIR__ . '/../../../pos_backend/core/pos_api_core.php');

// 2) 注册表
$registry_main      = require __DIR__ . '/registries/pos_registry.php';
$registry_ext_pass  = file_exists(__DIR__ . '/registries/pos_registry_ext_pass.php')
    ? require __DIR__ . '/registries/pos_registry_ext_pass.php'
    : [];
$registry_diag      = file_exists(__DIR__ . '/registries/pos_registry_diag.php')
    ? require __DIR__ . '/registries/pos_registry_diag.php'
    : [];

// 3) 合并（扩展覆盖主表，同名 action 以扩展为准）
$full_registry = array_merge($registry_main, $registry_ext_pass, $registry_diag);

// 4) 跑引擎
run_api($full_registry, $pdo);