<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') { 
    header("Location: index.php"); exit; 
}

// --- АВТОМАТИЧНЕ ВІДНОВЛЕННЯ БАЗИ ДАНИХ ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        price REAL NOT NULL,
        commission_percent REAL NOT NULL DEFAULT 12.0,
        is_active INTEGER DEFAULT 1
    )");
    
    $count = $pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();
    if ($count == 0) {
        $stmt_s = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'price_small'");
        $price_s = $stmt_s ? (float)$stmt_s->fetchColumn() : 120;
        $stmt_l = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'price_large'");
        $price_l = $stmt_l ? (float)$stmt_l->fetchColumn() : 150;
        $pdo->exec("INSERT INTO services (name, price, commission_percent) VALUES ('Мала траса', $price_s, 12)");
        $pdo->exec("INSERT INTO services (name, price, commission_percent) VALUES ('Велика траса', $price_l, 12)");
    }
} catch (Exception $e) {
    die("Помилка бази даних: " . $e->getMessage());
}

$msg = '';
$msg_type = 'success';

// --- ОБРОБКА ВСІХ ДІЙ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        
        // 1. ПОСЛУГИ: Видалення (Soft Delete)
        case 'delete_service':
            $sid = (int)$_POST['service_id'];
            $pdo->prepare("UPDATE services SET is_active = 0 WHERE id = ?")->execute([$sid]);
            $msg = "🗑️ Послугу приховано з активного списку!";
            break;

        // 2. КОРИСТУВАЧІ: Видалення
        case 'delete_user':
            $uid = (int)$_POST['user_id'];
            if ($uid == $_SESSION['user_id']) {
                $msg = "❌ Ви не можете видалити самого себе!";
                $msg_type = "danger";
            } else {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
                $msg = "🗑️ Користувача видалено назавжди!";
            }
            break;

        // 3. КОРИСТУВАЧІ: Повне редагування (включаючи роль)
        case 'edit_user_full':
            $uid = (int)$_POST['user_id'];
            $name = trim($_POST['full_name']);
            $phone = trim($_POST['phone']);
            $role = $_POST['role'];
            $base = (float)$_POST['base_salary'];
            
            $sql = "UPDATE users SET full_name = ?, phone = ?, role = ?, base_salary = ? WHERE id = ?";
            $params = [$name, $phone, $role, $base, $uid];
            
            if (!empty($_POST['new_password'])) {
                $sql = "UPDATE users SET full_name = ?, phone = ?, role = ?, base_salary = ?, password = ? WHERE id = ?";
                $params = [$name, $phone, $role, $base, password_hash($_POST['new_password'], PASSWORD_DEFAULT), $uid];
            }
            $pdo->prepare($sql)->execute($params);
            $msg = "✅ Дані $name оновлено!";
            break;

        // 4. ФІНАНСИ: Інкасація
        case 'encash_owner':
            $amount = (float)$_POST['amount'];
            $desc = trim($_POST['desc']) ?: 'Інкасація власником';
            if ($amount > 0) {
                $pdo->prepare("INSERT INTO transactions (cashier_id, type, amount, description) VALUES (?, 'encashment', ?, ?)")
                    ->execute([$_SESSION['user_id'], $amount, $desc]);
                $msg = "✅ З каси вилучено $amount грн!";
            }
            break;

        // 5. КАДРИ: Виплата ЗП
        case 'payout_salary':
            $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'salary_payout', ?, ?)")
                ->execute([$_SESSION['user_id'], (int)$_POST['worker_id'], (float)$_POST['amount'], "Видача ЗП"]);
            $msg = "✅ Виплату зафіксовано!";
            break;

        // 6. СИСТЕМА: Стерти дані
        case 'reset_system':
            $pdo->exec("DELETE FROM transactions");
            $pdo->exec("DELETE FROM waivers");
            $pdo->exec("DELETE FROM morning_checks");
            $pdo->exec("UPDATE users SET loyalty_visits = 0");
            $msg = "⚠️ ВСІ ДАНІ ТЕСТУВАННЯ СТЕРТО!";
            $msg_type = 'danger';
            break;

        // 7. ПОСЛУГИ: Додати/Редагувати
        case 'add_service':
            $pdo->prepare("INSERT INTO services (name, price, commission_percent) VALUES (?, ?, ?)")
                ->execute([trim($_POST['name']), (float)$_POST['price'], (float)$_POST['commission_percent']]);
            $msg = "✅ Послугу додано!";
            break;
        case 'edit_service':
            $pdo->prepare("UPDATE services SET name = ?, price = ?, commission_percent = ? WHERE id = ?")
                ->execute([trim($_POST['name']), (float)$_POST['price'], (float)$_POST['commission_percent'], (int)$_POST['service_id']]);
            $msg = "✅ Послугу оновлено!";
            break;
    }
}

// ОТРИМАННЯ ДАНИХ
$staff_for_payout = $pdo->query("SELECT * FROM users WHERE role IN ('cashier', 'instructor') ORDER BY full_name ASC")->fetchAll();
$all_users = $pdo->query("SELECT * FROM users ORDER BY role DESC, full_name ASC")->fetchAll();
$active_services = $pdo->query("SELECT * FROM services WHERE is_active = 1")->fetchAll();

// Баланс каси
$stmt = $pdo->query("SELECT SUM(CASE WHEN type IN ('service_sale', 'ticket_small', 'ticket_large', 'subscription_sale') THEN amount WHEN type IN ('expense', 'encashment', 'salary_payout') THEN -amount ELSE 0 END) FROM transactions");
$current_register_balance = (float)$stmt->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Адмін-центр - Тарзан</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .top-bar { background: #212529; color: white; padding: 15px 20px; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px; margin-bottom: 25px; }
        .modern-card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); border: none; margin-bottom: 20px; }
        .nav-pills .nav-link { border-radius: 12px; color: #495057; font-weight: bold; margin: 0 5px; }
        .nav-pills .nav-link.active { background-color: #212529; color: white; }
        .user-row { border-bottom: 1px solid #f0f0f0; padding: 12px 0; }
        .badge-role { font-size: 0.7rem; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; }
        .salary-badge { background: #e8f5e9; color: #2e7d32; padding: 5px 12px; border-radius: 10px; font-weight: bold; }
    </style>
</head>
<body>

<div class="top-bar d-flex justify-content-between align-items-center">
    <a href="dashboard_owner.php" class="btn btn-sm btn-outline-light rounded-pill px-3">🔙 Назад</a>
    <h5 class="mb-0 fw-bold">⚙️ Адмін-центр</h5>
</div>

<div class="container" style="max-width: 900px;">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-3 fw-bold shadow-sm"><?= $msg ?></div>
    <?php endif; ?>

    <!-- ТАБИ -->
    <ul class="nav nav-pills nav-fill mb-4 bg-white p-2 rounded-4 shadow-sm" id="adminTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-hr">👥 Кадри</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-users">👤 Користувачі</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-finance">💰 Фінанси</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-services">🏷️ Послуги</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-system">⚙️ Система</button></li>
    </ul>

    <div class="tab-content">
        
        <!-- 1. ТАБ: КАДРИ (ВИПЛАТИ) -->
        <div class="tab-pane fade show active" id="tab-hr">
            <div class="modern-card">
                <h5 class="fw-bold mb-4">Нарахування та виплати</h5>
                <?php foreach ($staff_for_payout as $s): 
                    $earned = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type = 'salary_accrual' AND client_id = {$s['id']}")->fetchColumn();
                    $paid = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type = 'salary_payout' AND client_id = {$s['id']}")->fetchColumn();
                    $balance = $earned - $paid;
                ?>
                    <div class="user-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold"><?= htmlspecialchars($s['full_name']) ?></div>
                            <small class="text-muted">📞 <?= $s['phone'] ?></small>
                        </div>
                        <div class="text-end d-flex align-items-center gap-3">
                            <span class="salary-badge"><?= number_format($balance, 0) ?> ₴</span>
                            <button class="btn btn-success btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#payModal<?= $s['id'] ?>">💸 Виплатити</button>
                        </div>
                    </div>

                    <div class="modal fade" id="payModal<?= $s['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered"><form method="POST" class="modal-content rounded-4"><div class="modal-body p-4">
                            <h5 class="fw-bold mb-3">Видача ЗП: <?= $s['full_name'] ?></h5>
                            <input type="hidden" name="action" value="payout_salary">
                            <input type="hidden" name="worker_id" value="<?= $s['id'] ?>">
                            <input type="number" name="amount" class="form-control form-control-lg mb-3" value="<?= $balance ?>" required>
                            <button type="submit" class="btn btn-success w-100 py-3 rounded-3 fw-bold">ПІДТВЕРДИТИ</button>
                        </div></form></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 2. ТАБ: ВСІ КОРИСТУВАЧІ -->
        <div class="tab-pane fade" id="tab-users">
            <div class="modern-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold m-0">Керування користувачами</h5>
                    <button class="btn btn-dark btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addStaffModal">+ Додати</button>
                </div>
                <?php foreach ($all_users as $u): 
                    $role_bg = ['owner' => 'bg-danger', 'cashier' => 'bg-primary', 'instructor' => 'bg-success', 'client' => 'bg-secondary'];
                ?>
                    <div class="user-row d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge badge-role <?= $role_bg[$u['role']] ?> mb-1"><?= $u['role'] ?></span>
                            <div class="fw-bold fs-5"><?= htmlspecialchars($u['full_name']) ?></div>
                            <small class="text-muted">📞 <?= $u['phone'] ?> | ID: <?= $u['id'] ?></small>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-light btn-sm rounded-circle shadow-sm" data-bs-toggle="modal" data-bs-target="#editUser<?= $u['id'] ?>">⚙️</button>
                            <form method="POST" onsubmit="return confirm('Видалити користувача НАЗАВЖДИ?')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-light btn-sm text-danger rounded-circle shadow-sm">🗑️</button>
                            </form>
                        </div>
                    </div>

                    <!-- Модалка редагування профілю -->
                    <div class="modal fade" id="editUser<?= $u['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered"><form method="POST" class="modal-content rounded-4"><div class="modal-body p-4">
                            <h5 class="fw-bold mb-4">Дані: <?= $u['full_name'] ?></h5>
                            <input type="hidden" name="action" value="edit_user_full">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <div class="mb-3"><label class="small fw-bold">ПІБ</label><input type="text" name="full_name" class="form-control" value="<?= $u['full_name'] ?>" required></div>
                            <div class="mb-3"><label class="small fw-bold">Телефон</label><input type="text" name="phone" class="form-control" value="<?= $u['phone'] ?>" required></div>
                            <div class="mb-3"><label class="small fw-bold">Роль</label>
                                <select name="role" class="form-select">
                                    <option value="client" <?= $u['role']=='client'?'selected':''?>>Клієнт</option>
                                    <option value="instructor" <?= $u['role']=='instructor'?'selected':''?>>Інструктор</option>
                                    <option value="cashier" <?= $u['role']=='cashier'?'selected':''?>>Касир</option>
                                    <option value="owner" <?= $u['role']=='owner'?'selected':''?>>Власник</option>
                                </select>
                            </div>
                            <div class="mb-3"><label class="small fw-bold">Ставка ₴</label><input type="number" name="base_salary" class="form-control" value="<?= $u['base_salary'] ?>"></div>
                            <div class="mb-3"><label class="small fw-bold">Новий пароль</label><input type="text" name="new_password" class="form-control" placeholder="Порожньо = без змін"></div>
                            <button type="submit" class="btn btn-dark w-100 py-3 rounded-3 fw-bold">ЗБЕРЕГТИ</button>
                        </div></form></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 3. ТАБ: ФІНАНСИ -->
        <div class="tab-pane fade" id="tab-finance">
            <div class="modern-card text-center border border-success border-2">
                <h6 class="text-muted fw-bold mb-1">Фактичний залишок в касі</h6>
                <div class="text-success fw-bold" style="font-size: 3rem; line-height: 1;"><?= number_format($current_register_balance, 0, '.', ' ') ?> ₴</div>
            </div>
            <div class="modern-card">
                <h5 class="fw-bold mb-4">💼 Інкасація власником</h5>
                <form method="POST">
                    <input type="hidden" name="action" value="encash_owner">
                    <div class="mb-3"><label class="small fw-bold">Сума (₴)</label><input type="number" name="amount" class="form-control form-control-lg text-primary fw-bold" required></div>
                    <div class="mb-3"><label class="small fw-bold">Коментар</label><input type="text" name="desc" class="form-control" placeholder="Наприклад: Забрав додому"></div>
                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-3">ВИЛУЧИТИ З КАСИ</button>
                </form>
            </div>
        </div>

        <!-- 4. ТАБ: ПОСЛУГИ (З ВИДАЛЕННЯМ) -->
        <div class="tab-pane fade" id="tab-services">
            <div class="modern-card">
                <h5 class="fw-bold mb-4">Налаштування прайсу</h5>
                <?php foreach ($active_services as $srv): ?>
                    <div class="user-row">
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="action" value="edit_service">
                            <input type="hidden" name="service_id" value="<?= $srv['id'] ?>">
                            <div class="col-5"><label class="small fw-bold">Назва</label><input type="text" name="name" class="form-control form-control-sm fw-bold" value="<?= $srv['name'] ?>"></div>
                            <div class="col-2"><label class="small fw-bold">Ціна</label><input type="number" name="price" class="form-control form-control-sm" value="<?= $srv['price'] ?>"></div>
                            <div class="col-2"><label class="small fw-bold">% ЗП</label><input type="number" name="commission_percent" class="form-control form-control-sm" value="<?= $srv['commission_percent'] ?>"></div>
                            <div class="col-3 d-flex gap-1">
                                <button type="submit" class="btn btn-dark btn-sm flex-grow-1">💾</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelSrv(<?= $srv['id'] ?>)">🗑️</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
                <div class="bg-light p-3 rounded-4 mt-4">
                    <h6 class="fw-bold mb-3">+ Нова послуга</h6>
                    <form method="POST" class="row g-2">
                        <input type="hidden" name="action" value="add_service">
                        <div class="col-5"><input type="text" name="name" class="form-control" placeholder="Назва" required></div>
                        <div class="col-3"><input type="number" name="price" class="form-control" placeholder="Ціна" required></div>
                        <div class="col-4"><input type="number" name="commission_percent" class="form-control" placeholder="% ЗП" required></div>
                        <button type="submit" class="btn btn-success btn-sm w-100 mt-2 fw-bold">СТВОРИТИ</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 5. ТАБ: СИСТЕМА -->
        <div class="tab-pane fade" id="tab-system">
            <div class="modern-card border border-danger">
                <h5 class="text-danger fw-bold">Скидання системи</h5>
                <form method="POST" onsubmit="return confirm('СТЕРТИ ВСЮ ІСТОРІЮ?')">
                    <input type="hidden" name="action" value="reset_system">
                    <button type="submit" class="btn btn-danger w-100 py-3 rounded-3 fw-bold">🛑 ОЧИСТИТИ ТРАНЗАКЦІЇ</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Модалка додавання користувача -->
<div class="modal fade" id="addStaffModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><form method="POST" class="modal-content rounded-4"><div class="modal-body p-4">
        <h5 class="fw-bold mb-4">Новий користувач</h5>
        <input type="hidden" name="action" value="add_staff">
        <input type="text" name="full_name" class="form-control mb-2" placeholder="ПІБ" required>
        <input type="text" name="phone" class="form-control mb-2" placeholder="Телефон" required>
        <input type="text" name="password" class="form-control mb-2" placeholder="Пароль" required>
        <select name="role" class="form-select mb-2">
            <option value="client">Клієнт</option>
            <option value="instructor">Інструктор</option>
            <option value="cashier">Касир</option>
        </select>
        <input type="number" name="base_salary" class="form-control mb-3" value="300" placeholder="Ставка">
        <button type="submit" class="btn btn-success w-100 py-3 rounded-3 fw-bold">СТВОРИТИ</button>
    </div></form></div>
</div>

<!-- Технічна форма видалення послуги -->
<form id="srvDelForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete_service">
    <input type="hidden" name="service_id" id="srv_del_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function confirmDelSrv(id) {
        if (confirm('Видалити цю послугу зі списку?')) {
            document.getElementById('srv_del_id').value = id;
            document.getElementById('srvDelForm').submit();
        }
    }
</script>
</body>
</html>