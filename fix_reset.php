<?php
require_once 'config.php';

try {
    // Додаємо колонку reset_code в таблицю users
    $pdo->exec("ALTER TABLE users ADD COLUMN reset_code TEXT DEFAULT NULL");
    echo "✅ Успіх! Колонку 'reset_code' додано. Тепер відновлення пароля запрацює.";
} catch (Exception $e) {
    echo "❌ Помилка: " . $e->getMessage();
}
?>