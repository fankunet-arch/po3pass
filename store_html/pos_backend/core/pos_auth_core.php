<?php
/**
 * Toptea Store - POS
 * Core Authentication & Session Check for POS
 * Engineer: Gemini | Date: 2025-10-29
 */
@session_start();

// If the session variable is not set or is not true, redirect to login page.
if (!isset($_SESSION['pos_logged_in']) || $_SESSION['pos_logged_in'] !== true) {
    session_destroy();
    header('Location: login.php');
    exit;
}