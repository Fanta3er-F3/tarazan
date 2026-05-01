<?php
require_once 'config.php';

try {
    // Видаляємо всіх користувачів
    $pdo->exec("DELETE FROM users");
    echo "✅ Таблиця користувачів повністю очищена.<br>";
    
    // Скидаємо лічильник ID, щоб почати з 1
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name='users'");
    echo "✅ Лічильник ID скинуто.<br>";
    
    echo "<br><b>Тепер спробуй зареєструватися знову. Перший зареєстрований стане клієнтом з ID 1.</b>";
} catch (Exception $e) {
    echo "❌ Помилка: " . $e->getMessage();
}
?>