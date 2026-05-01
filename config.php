<?php
session_start();

// Вказуємо шлях до локального файлу бази даних
define('DB_FILE', __DIR__ . '/tarzan.db');

// Налаштування Telegram
define('TG_BOT_TOKEN', 'ТВІЙ_ТОКЕН');
define('TG_CHAT_ID', 'ТВІЙ_ID');

// --- НАЛАШТУВАННЯ ГУГЛ-ПОШТИ (Додано сюди) ---
define('GAS_URL', 'https://script.google.com/macros/s/AKfycbx5PboV-k9Cedw2JRptrmnCgoX8FWEu6IALoSDGNYmyZ0R5iatBJyzZM81sclKwFzMSaw/exec');
define('GAS_KEY', 'tarzan777');
try {
    // Підключення до локального файлу SQLite
    $pdo = new PDO("sqlite:" . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Помилка підключення до локальної бази: " . $e->getMessage());
}
?>