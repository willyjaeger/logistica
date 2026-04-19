<?php
require_once __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
