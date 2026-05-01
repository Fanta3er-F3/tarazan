<?php
require_once 'config.php';

try {
    // Робимо всіх існуючих користувачів підтвердженими
    $pdo->exec("UPDATE users SET status = 'verified'");
    echo "✅ Всі користувачі успішно підтверджені! Тепер спробуйте увійти знову.";
} catch (Exception $e) {
    echo "❌ Помилка: " . $e->getMessage();
}
?>