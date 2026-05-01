<?php
require_once 'config.php';

try {
    // Створюємо відсутню таблицю для дітей
    $pdo->exec("CREATE TABLE IF NOT EXISTS family_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NOT NULL,
        child_name TEXT NOT NULL,
        barcode TEXT UNIQUE,
        FOREIGN KEY (parent_id) REFERENCES users(id)
    )");
    
    echo "<h3>✅ Помилку виправлено!</h3>";
    echo "<p>Таблицю для сімейних акаунтів успішно створено.</p>";
    echo "<a href='client.php'>Перейти в кабінет клієнта</a>";
    
} catch (PDOException $e) {
    echo "❌ Помилка: " . $e->getMessage();
}
?>