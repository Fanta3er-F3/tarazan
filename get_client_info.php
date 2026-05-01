<?php
require_once 'config.php';

// Отримуємо код і очищаємо його від зайвих символів, наприклад # або пробілів
$barcodeRaw = $_GET['barcode'] ?? '';
$barcode = trim(str_replace('#', '', $barcodeRaw));

if (!$barcode) {
    echo json_encode(['success' => false, 'message' => 'Код порожній']);
    exit;
}

try {
    // 1. Шукаємо в таблиці users (за barcode або за системним ID)
    // Використовуємо CAST, щоб знайти навіть якщо в базі '00004', а прийшло '4'
    $stmt = $pdo->prepare("
        SELECT id, full_name, sub_adult_balance, sub_child_balance, loyalty_visits, barcode 
        FROM users 
        WHERE barcode = ? OR id = ? OR barcode = ?
    ");
    // Перевіряємо різні варіанти: чистий код, як число, і як він прийшов
    $stmt->execute([$barcode, (int)$barcode, $barcodeRaw]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode([
            'success' => true,
            'id' => $user['id'],
            'name' => $user['full_name'],
            'sub_adult' => (int)($user['sub_adult_balance'] ?? 0),
            'sub_child' => (int)($user['sub_child_balance'] ?? 0),
            'loyalty' => (int)($user['loyalty_visits'] ?? 0)
        ]);
        exit;
    }

    // 2. Якщо не знайшли дорослого, шукаємо серед дітей
    $stmt_child = $pdo->prepare("
        SELECT f.child_name, u.id as parent_id, u.full_name as parent_name, 
               u.sub_adult_balance, u.sub_child_balance, u.loyalty_visits 
        FROM family_members f 
        JOIN users u ON f.parent_id = u.id 
        WHERE f.barcode = ? OR f.barcode = ?
    ");
    $stmt_child->execute([$barcode, $barcodeRaw]);
    $child = $stmt_child->fetch();

    if ($child) {
        echo json_encode([
            'success' => true,
            'id' => $child['parent_id'],
            'name' => $child['child_name'] . ' (Дитина ' . $child['parent_name'] . ')',
            'sub_adult' => (int)$child['sub_adult_balance'],
            'sub_child' => (int)$child['sub_child_balance'],
            'loyalty' => (int)$child['loyalty_visits']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Клієнта з кодом ' . $barcodeRaw . ' не знайдено']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Помилка бази: ' . $e->getMessage()]);
}