<?php
/**
 * Toptea POS - 通用 API 核心引擎
 * 职责: 提供 run_api() 驱动基于注册表的 CRUD/自定义动作。
 * Version: 1.0.1
 * Date: 2025-11-09
 */

require_once realpath(__DIR__ . '/../helpers/pos_json_helper.php');
require_once realpath(__DIR__ . '/../helpers/pos_datetime_helper.php');
require_once realpath(__DIR__ . '/../services/PromotionEngine.php');

if (!defined('ROLE_STORE_USER'))    define('ROLE_STORE_USER', 'staff');
if (!defined('ROLE_STORE_MANAGER')) define('ROLE_STORE_MANAGER', 'manager');
if (!defined('ROLE_SUPER_ADMIN'))   define('ROLE_SUPER_ADMIN', 9);

/**
 * 运行注册表 API
 * @param array $registry ['resource' => ['auth_role'=>..., 'custom_actions'=>['act' => handlerName]]]
 * @param PDO   $pdo
 */
function run_api(array $registry, PDO $pdo): void {
    @session_start();

    $resource_name = $_GET['res'] ?? null;
    $action_name   = $_GET['act'] ?? null;

    if (!$resource_name || !$action_name) {
        json_error('无效的 API 请求：缺少 res 或 act 参数。', 400);
    }

    $config = $registry[$resource_name] ?? null;
    if ($config === null) {
        json_error("资源 '{$resource_name}' 未注册。", 404);
    }

    // 权限校验（门店侧只校验是否登录）
    $required_role = $config['auth_role'] ?? ROLE_STORE_USER;
    $logged_in     = isset($_SESSION['pos_user_id']);
    if (!$logged_in) json_error('未登录或会话失效。', 401);

    // 找处理器
    $handler_name = $config['custom_actions'][$action_name] ?? null;
    if (!$handler_name || !function_exists($handler_name)) {
        json_error("动作 '{$action_name}' 未定义。", 404);
    }

    // 解析请求体
    $input_data = get_request_data();

    // 执行
    try {
        call_user_func($handler_name, $pdo, $config, $input_data);
    } catch (Throwable $e) {
        json_error('服务器内部错误: ' . $e->getMessage(), 500);
    }
}
