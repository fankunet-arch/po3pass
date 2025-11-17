<?php
/**
 * [GEMINI FIX 2025-11-16]
 * 解决了 'Cannot redeclare ensure_active_shift_or_fail()' 的致命错误。
 * 此函数已在 pos_helper.php 中定义，此处为重复定义，必须移除。
 */

// -------------------------------------------------------------------
// START OF REMOVED DUPLICATE FUNCTION
// -------------------------------------------------------------------
/*
function ensure_active_shift_or_fail(PDO $pdo): int {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id = (int)($_SESSION['pos_user_id'] ?? 0);
    
    if ($shift_id > 0 && $store_id > 0 && $user_id > 0) {
        // 可选：可以进一步检查 $shift_id 是否真的在数据库中且未关闭
        // $stmt = $pdo->prepare("SELECT 1 FROM pos_shifts WHERE id = ? AND store_id = ? AND ended_at IS NULL");
        // $stmt->execute([$shift_id, $store_id]);
        // if ($stmt->fetchColumn()) {
        //     return $shift_id;
        // }
        return $shift_id; // 简化：信任 session
    }

    // 如果 session 中没有，触发班次保护
    json_error('No active shift found. Please start a shift.', 403, ['error_code' => 'NO_ACTIVE_SHIFT']);
    exit;
}
*/
// -------------------------------------------------------------------
// END OF REMOVED DUPLICATE FUNCTION
// -------------------------------------------------------------------

// (此文件现在为空，这是正确的，因为它的唯一内容就是那个重复的函数)