<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['user_id'])) {
    $inspector_id = $_SESSION['user_id'];
    $today = date('Y-m-d');
    
    // Збираємо результати чек-боксів (якщо вони прийшли)
    $checks = [
        'ropes' => isset($_POST['check_1']) ? 'ok' : 'fail',
        'carabiners' => isset($_POST['check_2']) ? 'ok' : 'fail',
        'cleanliness' => isset($_POST['check_3']) ? 'ok' : 'fail'
    ];
    
    $details = json_encode([
        'checks' => $checks,
        'notes' => trim($_POST['notes'] ?? '')
    ], JSON_UNESCAPED_UNICODE);

    try {
        $stmt = $pdo->prepare("INSERT INTO daily_inspections (inspector_id, check_date, status, details_json) VALUES (?, ?, 'ok', ?)");
        $stmt->execute([$inspector_id, $today, $details]);
        
        // Повертаємо касира назад на головну
        header("Location: bulk_entry.php?safety=success");
    } catch (Exception $e) {
        // Якщо вже перевірено сьогодні - просто повертаємо
        header("Location: bulk_entry.php");
    }
}