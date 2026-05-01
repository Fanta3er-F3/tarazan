<?php
require_once 'config.php';

// 1. ПЕРЕВІРКА ДОСТУПУ (тільки авторизовані працівники/власник)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Сесія завершена. Увійдіть знову.']);
    exit;
}

$clientId = $_POST['client_id'] ?? null;
$type = $_POST['type'] ?? '';
$cashierId = $_SESSION['user_id'];

if (!$clientId) {
    echo json_encode(['success' => false, 'message' => 'Помилка: Клієнта не ідентифіковано.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $response = ['success' => true, 'message' => ''];

    switch ($type) {
        // --- ЗАРАХУВАННЯ ЗВИЧАЙНОГО ВІЗИТУ (+1 в лояльність) ---
        case 'loyalty_visit':
            $stmt = $pdo->prepare("UPDATE users SET loyalty_visits = loyalty_visits + 1 WHERE id = ?");
            $stmt->execute([$clientId]);
            
            // Записуємо в історію клієнта
            $stmtLog = $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'loyalty_visit', 0, 'Звичайний візит зафіксовано')");
            $stmtLog->execute([$cashierId, $clientId]);
            
            $response['message'] = "Візит зараховано! Дані в кабінеті клієнта оновлено.";
            break;

        // --- ВИКОРИСТАННЯ ДОРОСЛОГО АБОНЕМЕНТА ---
        case 'use_sub_adult':
            $stmtCheck = $pdo->prepare("SELECT sub_adult_balance FROM users WHERE id = ?");
            $stmtCheck->execute([$clientId]);
            $balance = (int)$stmtCheck->fetchColumn();

            if ($balance > 0) {
                $pdo->prepare("UPDATE users SET sub_adult_balance = sub_adult_balance - 1 WHERE id = ?")->execute([$clientId]);
                
                // Записуємо в історію клієнта
                $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'use_subscription', 0, 'Вхід по абонементу (Дорослий)')")
                    ->execute([$cashierId, $clientId]);
                
                $response['message'] = "Прохід списано! Залишок: " . ($balance - 1);
            } else {
                $response = ['success' => false, 'message' => 'На абонементі 0 проходів!'];
            }
            break;

        // --- ВИКОРИСТАННЯ ДИТЯЧОГО АБОНЕМЕНТА ---
        case 'use_sub_child':
            $stmtCheck = $pdo->prepare("SELECT sub_child_balance FROM users WHERE id = ?");
            $stmtCheck->execute([$clientId]);
            $balance = (int)$stmtCheck->fetchColumn();

            if ($balance > 0) {
                $pdo->prepare("UPDATE users SET sub_child_balance = sub_child_balance - 1 WHERE id = ?")->execute([$clientId]);
                
                // Записуємо в історію клієнта
                $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'use_subscription', 0, 'Вхід по абонементу (Дитячий)')")
                    ->execute([$cashierId, $clientId]);
                
                $response['message'] = "Прохід списано! Залишок: " . ($balance - 1);
            } else {
                $response = ['success' => false, 'message' => 'На абонементі 0 проходів!'];
            }
            break;

        // --- ПРОДАЖ ДОРОСЛОГО АБОНЕМЕНТА (+10) ---
        case 'buy_sub_adult':
            $price = 1500; // Твоя ціна
            $pdo->prepare("UPDATE users SET sub_adult_balance = sub_adult_balance + 10 WHERE id = ?")->execute([$clientId]);
            
            // Записуємо в історію і в касу
            $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'subscription_sale', ?, 'Купівля абонемента: Дорослий (10)')")
                ->execute([$cashierId, $clientId, $price]);
            
            $response['message'] = "Абонемент на 10 дорослих проходів додано!";
            break;

        // --- ПРОДАЖ ДИТЯЧОГО АБОНЕМЕНТА (+10) ---
        case 'buy_sub_child':
            $price = 1000; // Твоя ціна
            $pdo->prepare("UPDATE users SET sub_child_balance = sub_child_balance + 10 WHERE id = ?")->execute([$clientId]);
            
            // Записуємо в історію і в касу
            $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'subscription_sale', ?, 'Купівля абонемента: Дитячий (10)')")
                ->execute([$cashierId, $clientId, $price]);
            
            $response['message'] = "Абонемент на 10 дитячих проходів додано!";
            break;

        default:
            $response = ['success' => false, 'message' => 'Невідома команда.'];
            break;
    }

    $pdo->commit();
    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Помилка бази даних: ' . $e->getMessage()]);
}