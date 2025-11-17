<?php
/**
 * Toptea POS - 统一 JSON 响应助手
 * (逻辑同步自 KDS 助手，以确保一致性)
 * 职责: 统一 JSON 响应格式 (json_ok, json_error) 和输入 (get_request_data)。
 * Version: 1.0.0 (KDS Synced)
 * Date: 2025-11-08
 */

if (!function_exists('send_json_headers_once')) {
    /**
     * 确保 JSON 头只发送一次
     */
    function send_json_headers_once(): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }
}

if (!function_exists('json_ok')) {
    /**
     * 发送成功的 JSON 响应并退出
     * @param mixed $data (可选) 要发送的数据
     * @param string $message (可选) 成功的消息
     * @param int $http_code (可选) HTTP 状态码
     */
    function json_ok($data = null, string $message = '操作成功', int $http_code = 200): void {
        send_json_headers_once();
        http_response_code($http_code);
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_error')) {
    /**
     * 发送失败的 JSON 响应并退出
     * @param string $message 错误消息
     * @param int $http_code (可选) HTTP 状态码 (400-599)
     * @param mixed $data (可选) 额外的错误详情
     */
    function json_error(string $message, int $http_code = 400, $data = null): void {
        send_json_headers_once();
        http_response_code($http_code >= 400 ? $http_code : 400); // 确保是错误码
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('read_json_input')) {
    /**
     * 读取 application/json 格式的 POST/PUT body
     * @return array
     */
    function read_json_input(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            json_error('无效的 JSON 请求体', 400);
        }
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('get_request_data')) {
    /**
     * 自动检测并返回 JSON body 或 POST/GET 表单数据
     * (使用 KDS 的健壮逻辑)
     * @return array
     */
    function get_request_data(): array {
        if (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            return read_json_input();
        }
        // 优先 POST
        if (!empty($_POST)) {
            return $_POST;
        }
        // 其次 GET (用于旧的 kds/api)
        if (!empty($_GET)) {
            // 移除 res 和 act，它们是路由参数，不是业务数据
            $data = $_GET;
            unset($data['res'], $data['act']);
            return $data;
        }
        return [];
    }
}