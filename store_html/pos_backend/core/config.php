<?php
/**
 * Toptea POS - Core Configuration File
 * Engineer: Gemini | Date: 2025-10-24
 * Revision: 3.0 (Sync with HQ Error Logging)
 */

// --- [SECURITY FIX V2.0] ---
ini_set('display_errors', '0'); // Turn off displaying errors in production
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1'); // Enable logging errors
ini_set('error_log', __DIR__ . '/php_errors_pos.log'); // Log errors to this file
// --- [END FIX] ---

error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

// --- Database Configuration (Copied from HQ) ---
$db_host = 'mhdlmskv3gjbpqv3.mysql.db';
$db_name = 'mhdlmskv3gjbpqv3';
$db_user = 'mhdlmskv3gjbpqv3';
$db_pass = 'zqVdVfAWYYaa4gTAuHWX7CngpRDqR';
$db_char = 'utf8mb4';

// --- Application Settings ---
define('POS_BASE_URL', '/pos/'); // Relative base URL for the POS app

// --- Directory Paths ---
define('POS_ROOT_PATH', dirname(__DIR__));
define('POS_APP_PATH', POS_ROOT_PATH . '/app');
define('POS_CORE_PATH', POS_ROOT_PATH . '/core');
define('POS_PUBLIC_PATH', POS_ROOT_PATH . '/html');

// --- Database Connection (PDO) ---
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_char";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

    // [A1 UTC SYNC] Set connection timezone to UTC
    $pdo->exec("SET time_zone='+00:00'");
    
} catch (\PDOException $e) {
    error_log("POS Database connection failed: " . $e->getMessage());
    // For POS, we must die cleanly in a way the frontend can parse
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503); // Service Unavailable
    echo json_encode([
        'status' => 'error',
        'message' => 'DB Connection Error (POS)',
        'data' => null
    ]);
    exit;
}