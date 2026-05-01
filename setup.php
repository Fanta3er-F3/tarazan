<?php
require_once 'config.php';

// Створюємо структуру таблиць для SQLite
$queries = [
    "CREATE TABLE IF NOT EXISTS system_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        description TEXT
    )",
    
    "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role TEXT NOT NULL,
        phone TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        full_name TEXT NOT NULL,
        base_salary REAL DEFAULT 0.00,
        commission_percent REAL DEFAULT 0.00,
        barcode TEXT UNIQUE,
        loyalty_visits INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        client_id INTEGER,
        type TEXT NOT NULL,
        amount REAL NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cashier_id) REFERENCES users(id)
    )",

    "CREATE TABLE IF NOT EXISTS morning_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instructor_id INTEGER NOT NULL,
        check_date DATE NOT NULL,
        status TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (instructor_id) REFERENCES users(id)
    )",

    "CREATE TABLE IF NOT EXISTS waivers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_name TEXT NOT NULL,
        client_phone TEXT NOT NULL,
        children_names TEXT,
        signature_data TEXT NOT NULL,
        agreed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"
];

foreach ($queries as $query) {
    $pdo->exec($query);
}

// Очищаємо перед створенням (щоб не було дублів при перезапуску)
$pdo->exec("DELETE FROM users");
$pdo->exec("DELETE FROM system_settings");

// Додаємо базові налаштування
$settings = [
    ['price_small', '120', 'Ціна Малої траси'],
    ['price_large', '150', 'Ціна Великої траси'],
    ['loyalty_target', '10', 'Яке проходження безкоштовне']
];
$stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
foreach ($settings as $s) { $stmt->execute($s); }

// Створюємо користувачів
$owner_pass = password_hash('123456', PASSWORD_DEFAULT);
$staff_pass = password_hash('1111', PASSWORD_DEFAULT);

$users = [
    ['owner', '380990000000', $owner_pass, 'Власник', 0, 0],
    ['cashier', '380991111111', $staff_pass, 'Артем Студилко', 400, 12],
    ['instructor', '380992222222', $staff_pass, 'Кіріл', 300, 12]
];
$stmt = $pdo->prepare("INSERT INTO users (role, phone, password, full_name, base_salary, commission_percent) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($users as $u) { $stmt->execute($u); }

echo "<h3>✅ Локальну базу SQLite створено успішно!</h3>";
echo "<p>Файл <b>tarzan.db</b> автоматично з'явився на хостингу.</p>";
echo "<b>Власник:</b> 380990000000 | Пароль: 123456 <br>";
echo "<b>Касир:</b> 380991111111 | Пароль: 1111 <br>";
?>