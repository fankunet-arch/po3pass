<?php
/**
 * TopTea POS - Invoicing Guard
 *
 * Prevents invoicing operations for stores where billing is disabled ('NONE').
 * Engineer: Gemini
 * Date: 2025-10-30
 */

if (!function_exists('assert_invoicing_enabled')) {
    /**
     * Checks if the store's billing system allows invoicing.
     * If not, it terminates the script with a 403 Forbidden error.
     *
     * @param array|null $store_config The store's configuration array from the database.
     * @return void
     */
    function assert_invoicing_enabled(?array $store_config): void
    {
        if (empty($store_config) || !isset($store_config['billing_system']) || $store_config['billing_system'] === 'NONE') {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invoicing is disabled for this store.',
                'data' => [
                    'billing_system' => $store_config['billing_system'] ?? 'NOT_CONFIGURED'
                ]
            ]);
            exit;
        }
    }
}

if (!function_exists('is_invoicing_enabled')) {
    /**
     * Checks if the store's billing system allows invoicing.
     *
     * @param array|null $store_config The store's configuration array from the database.
     * @return bool True if invoicing is enabled, false otherwise.
     */
    function is_invoicing_enabled(?array $store_config): bool
    {
        return !empty($store_config) && isset($store_config['billing_system']) && $store_config['billing_system'] !== 'NONE';
    }
}