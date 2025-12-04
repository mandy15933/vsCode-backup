<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo "NOT_LOGGED_IN";
    exit;
}

echo "OK";
