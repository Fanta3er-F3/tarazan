<?php
require_once 'config.php';

try {
    // Створюємо таблицю для послуг (Траси, Батути тощо)
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        price REAL NOT NULL,
        commission_percent REAL NOT NULL DEFAULT 12.0,
        is_active INTEGER DEFAULT 1
    )");

    // Якщо таблиця порожня, переносимо старі ціни
    $count = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
    if ($count == 0) {
        $price_s = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'price_small'")->fetchColumn() ?: 120;
        $price_l = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'price_large'")->fetchColumn() ?: 150;
        
        $pdo->exec("INSERT INTO services (name, price, commission_percent) VALUES ('Мала траса', $price_s, 12)");
        $pdo->exec("INSERT INTO services (name, price, commission_percent) VALUES ('Велика траса', $price_l, 12)");
    }

    echo "<h3>✅ Базу даних успішно оновлено!</h3>";
    echo "<p>Створено підтримку динамічних послуг та відсотків.</p>";
    echo "<a href='settings.php'>Перейти в оновлену Адмінку</a>";

} catch (Exception $e) {
    echo "❌ Помилка: " . $e->getMessage();
}
?>