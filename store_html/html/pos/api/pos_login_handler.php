<?php
/**
 * Toptea Store - POS
 * Backend Login Handler for POS
 * Engineer: Gemini | Date: 2025-10-30
 * Revision: 1.1 (Add user role to session)
 *
 * [GEMINI FIX 2025-11-10] Removed 'is_active = 1' from kds_stores and kds_users queries to fix SQLSTATE 42S22 error.
 */

@session_start();
require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$store_code = trim($_POST['store_code'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($store_code) || empty($username) || empty($password)) {
    header('Location: ../login.php?error=1');
    exit;
}

try {
    // 1. Find the store
    // [FIX] Removed 'is_active = 1'
    $stmt_store = $pdo->prepare("SELECT id, store_name FROM kds_stores WHERE store_code = ? AND deleted_at IS NULL");
    $stmt_store->execute([$store_code]);
    $store = $stmt_store->fetch();

    if ($store) {
        // 2. Find the user within that store
        // [FIX] Removed 'is_active = 1'
        $stmt_user = $pdo->prepare("SELECT id, username, password_hash, display_name, role FROM kds_users WHERE username = ? AND store_id = ? AND deleted_at IS NULL");
        $stmt_user->execute([$username, $store['id']]);
        $user = $stmt_user->fetch();

        // 3. Verify password
        if ($user && hash_equals($user['password_hash'], hash('sha256', $password))) {
            // --- Login Successful ---
            session_regenerate_id(true);
            $_SESSION['pos_logged_in'] = true;
            $_SESSION['pos_user_id'] = $user['id'];
            $_SESSION['pos_display_name'] = $user['display_name'];
            $_SESSION['pos_store_id'] = $store['id'];
            $_SESSION['pos_store_name'] = $store['store_name'];
            $_SESSION['pos_user_role'] = $user['role']; // Add user role to session
            
            // Update last login timestamp
            $pdo->prepare("UPDATE kds_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user['id']]);


			// --- [估清 需求2] 每日自动重置 ---
            try {
                // 1. 获取门店的 EOD 截止时间 (e.g., 3)
                $stmt_cutoff = $pdo->prepare("SELECT eod_cutoff_hour FROM kds_stores WHERE id = ?");
                $stmt_cutoff->execute([$store['id']]);
                $cutoff_hour = (int)($stmt_cutoff->fetchColumn() ?: 3);

                // 2. 计算当前的“营业日”
                // (如果现在是凌晨2点，cutoff=3，则营业日还是昨天)
                $tz = new DateTimeZone('Europe/Madrid');
                $current_business_date = (new DateTime('now', $tz))
                                         ->modify("-{$cutoff_hour} hours")
                                         ->format('Y-m-d');

                // 3. 检查 `pos_daily_tracking` 表
                $stmt_track = $pdo->prepare("SELECT last_daily_reset_business_date FROM pos_daily_tracking WHERE store_id = ?");
                $stmt_track->execute([$store['id']]);
                $last_reset_date = $stmt_track->fetchColumn();

                if ($last_reset_date !== $current_business_date) {
                    // 4. 这是今天第一次登录，执行重置
                    
                    // 4a. [需求2] 重置所有估清状态
                    $pdo->prepare("DELETE FROM pos_product_availability WHERE store_id = ?")
                        ->execute([$store['id']]);
                    
                    // 4b. [需求3] 清除上一日的交接班快照
                    $sql_update_tracking = "
                        INSERT INTO pos_daily_tracking (store_id, last_daily_reset_business_date, sold_out_state_snapshot, snapshot_taken_at)
                        VALUES (?, ?, NULL, NULL)
                        ON DUPLICATE KEY UPDATE
                            last_daily_reset_business_date = VALUES(last_daily_reset_business_date),
                            sold_out_state_snapshot = VALUES(sold_out_state_snapshot),
                            snapshot_taken_at = VALUES(snapshot_taken_at)
                    ";
                    $pdo->prepare($sql_update_tracking)
                        ->execute([$store['id'], $current_business_date]);
                }

            } catch (Throwable $e) {
                // 即使重置失败，也不应阻止登录
                error_log("CRITICAL: Daily reset for store {$store['id']} failed: " . $e->getMessage());
            }
            // --- [估清 需求2] 结束 ---

            // Redirect to POS main page
            header('Location: ../index.php');


            // Redirect to POS main page
            header('Location: ../index.php');
            exit;
        }
    }

    // If any step fails, redirect back with an error
    header('Location: ../login.php?error=1');
    exit;

} catch (PDOException $e) {
    error_log("POS Login Error: " . $e->getMessage());
    header('Location: ../login.php?error=1');
    exit;
}