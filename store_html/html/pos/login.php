<?php
@session_start();
// If the user is already logged in, redirect them to the main POS page.
if (isset($_SESSION['pos_logged_in']) && $_SESSION['pos_logged_in'] === true) {
    header('Location: index.php');
    exit;
}
// Load the view file for the login page.
require_once realpath(__DIR__ . '/../../pos_backend/views/login_view.php');