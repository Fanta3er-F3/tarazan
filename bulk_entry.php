<?php
// 1. НАЛАШТУВАННЯ ТА БЕЗПЕКА
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['cashier', 'instructor'])) { 
    header("Location: index.php"); exit; 
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$today_date = date('Y-m-d');
$msg_profile = '';

// --- 2. ПІДРАХУНОК СКАНІВ ЗА СЬОГОДНІ (для підказки в касі) ---
// Шукаємо записи типу 'scan_paid_%', які ми робимо в process_scan_action.php
$today_start = date('Y-m-d 00:00:00');
$scan_stats_query = $pdo->prepare("
    SELECT type, COUNT(*) as qty 
    FROM transactions 
    WHERE created_at >= ? AND type LIKE 'scan_paid_%' 
    GROUP BY type
");
$scan_stats_query->execute([$today_start]);
$scan_stats = $scan_stats_query->fetchAll(PDO::FETCH_KEY_PAIR);

// Допоміжна функція для отримання кількості сканів конкретної послуги
function getScanQty($service_id, $stats) {
    $key = 'scan_paid_' . $service_id;
    return $stats[$key] ?? 0;
}

// --- 3. ОБРОБКА ДІЙ (POST) ---

// А) Оновлення профілю
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    if (!empty($_POST['new_password'])) {
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user_id]);
        $msg_profile = "✅ Пароль оновлено!";
    }
}

// Б) Запис щоденної перевірки траси
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_inspection') {
    $details = json_encode(['ropes' => 'ok', 'gear' => 'ok', 'safety' => 'ok'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO daily_inspections (inspector_id, check_date, status, details_json) VALUES (?, ?, 'ok', ?)")
        ->execute([$user_id, $today_date, $details]);
    header("Location: bulk_entry.php"); exit;
}

// В) Закриття зміни (Звіт)
$show_results = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_report') {
    $service_qtys = $_POST['service_qty'] ?? [];
    $exp_desc = trim($_POST['expense_desc'] ?? '');
    $exp_amount = (float)($_POST['expense_amount'] ?? 0);
    $encash = (float)($_POST['encashment'] ?? 0);
    $staff_ids = $_POST['staff'] ?? [];
    
    $all_services = $pdo->query("SELECT * FROM services WHERE is_active = 1")->fetchAll();
    $total_income = 0;
    $total_percent_fund = 0;
    $details = ['services' => [], 'staff' => [], 'finance' => []];

    foreach ($all_services as $srv) {
        $qty = (int)($service_qtys[$srv['id']] ?? 0);
        if ($qty > 0) {
            $sum = $qty * $srv['price'];
            $total_income += $sum;
            $total_percent_fund += $sum * ($srv['commission_percent'] / 100);
            $details['services'][] = ['name' => $srv['name'], 'qty' => $qty, 'sum' => $sum];
            
            // Ось тут ми фіксуємо реальні гроші в касі
            $pdo->prepare("INSERT INTO transactions (cashier_id, type, amount, description) VALUES (?, 'service_sale', ?, ?)")
                ->execute([$user_id, $sum, "{$srv['name']} ($qty шт)"]);
        }
    }

    if (!empty($staff_ids)) {
        $perc_one = $total_percent_fund / count($staff_ids);
        foreach ($staff_ids as $sid) {
            $st = $pdo->prepare("SELECT full_name, base_salary FROM users WHERE id = ?");
            $st->execute([$sid]);
            $w = $st->fetch();
            $zp = round($w['base_salary'] + $perc_one, 2);
            $details['staff'][] = ['name' => $w['full_name'], 'amount' => $zp];
            $pdo->prepare("INSERT INTO transactions (cashier_id, client_id, type, amount, description) VALUES (?, ?, 'salary_accrual', ?, ?)")
                ->execute([$user_id, $sid, $zp, "ЗП зміна: " . $w['full_name']]);
        }
    }

    if ($exp_amount > 0) $pdo->prepare("INSERT INTO transactions (cashier_id, type, amount, description) VALUES (?, 'expense', ?, ?)")->execute([$user_id, $exp_amount, "Витрата: $exp_desc"]);
    if ($encash > 0) $pdo->prepare("INSERT INTO transactions (cashier_id, type, amount, description) VALUES (?, 'encashment', ?, ?)")->execute([$user_id, $encash, "Інкасація"]);

    // Рахуємо фінальний баланс (тільки фінансові типи, ігноруючи скани з сумою 0)
    $stmt = $pdo->query("SELECT SUM(CASE WHEN type IN ('service_sale','ticket_small','ticket_large','subscription_sale','manual_sale') THEN amount WHEN type IN ('expense','encashment','salary_payout') THEN -amount ELSE 0 END) FROM transactions WHERE type != 'report_finish'");
    $final_bal = (float)$stmt->fetchColumn();
    
    $details['finance'] = ['income' => $total_income, 'exp' => $exp_amount, 'encash' => $encash, 'final' => $final_bal];
    $pdo->prepare("INSERT INTO transactions (cashier_id, type, amount, description, details_json) VALUES (?, 'report_finish', ?, ?, ?)")
        ->execute([$user_id, $total_income, "Звіт " . date('d.m'), json_encode($details, JSON_UNESCAPED_UNICODE)]);

    $show_results = true;
}

// --- 4. ЗАПИТИ ДЛЯ ВІДОБРАЖЕННЯ ТАБІВ ---
$inspection = $pdo->query("SELECT * FROM daily_inspections WHERE check_date = '$today_date'")->fetch();
$services = $pdo->query("SELECT * FROM services WHERE is_active = 1")->fetchAll();
$staff_list = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('cashier', 'instructor')")->fetchAll();
$reports_history = $pdo->query("SELECT t.*, u.full_name as sender FROM transactions t JOIN users u ON t.cashier_id = u.id WHERE t.type = 'report_finish' ORDER BY t.created_at DESC LIMIT 20")->fetchAll();

$earned = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='salary_accrual' AND client_id=$user_id")->fetchColumn();
$taken = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='salary_payout' AND client_id=$user_id")->fetchColumn();
$salary_log = $pdo->query("SELECT * FROM transactions WHERE client_id=$user_id AND type IN ('salary_accrual','salary_payout') ORDER BY created_at DESC LIMIT 10")->fetchAll();
$schedule = $pdo->query("SELECT * FROM work_schedule WHERE user_id=$user_id AND work_date >= CURRENT_DATE ORDER BY work_date ASC LIMIT 5")->fetchAll();
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Тарзан Staff Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --tarzan: #198754; --dark: #121416; }
        body { background: #f0f2f5; padding-bottom: 100px; font-family: 'Segoe UI', sans-serif; }
        .header-section { background: var(--tarzan); color: white; padding: 30px 20px 60px; border-bottom-left-radius: 40px; border-bottom-right-radius: 40px; }
        .main-container { margin-top: -40px; z-index: 10; position: relative; }
        .modern-card { background: white; border-radius: 25px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: none; margin-bottom: 20px; }
        .section-title { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #adb5bd; margin-bottom: 15px; letter-spacing: 1px; }
        .service-row { background: #f8f9fa; border-radius: 15px; padding: 12px; margin-bottom: 10px; border: 1px solid #eee; }
        .service-row input { width: 70px; border: none; background: transparent; font-weight: 800; font-size: 1.2rem; color: var(--tarzan); text-align: right; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: white; display: flex; justify-content: space-around; padding: 12px 0; border-top-left-radius: 30px; border-top-right-radius: 30px; box-shadow: 0 -10px 30px rgba(0,0,0,0.05); z-index: 1000; }
        .nav-btn { border: none; background: none; color: #adb5bd; display: flex; flex-direction: column; align-items: center; font-size: 0.7rem; font-weight: 800; width: 25%; transition: 0.3s; }
        .nav-btn.active { color: var(--tarzan); }
        .nav-btn i { font-size: 1.5rem; }
        .salary-accrual { color: #198754; font-weight: bold; }
        .salary-payout { color: #dc3545; font-weight: bold; }
        .scan-hint { font-size: 0.75rem; color: #198754; font-weight: bold; display: block; }
    </style>
</head>
<body>

<div class="header-section text-center">
    <h5 class="fw-bold mb-0">🌲 Тарзан: <?= htmlspecialchars($full_name) ?></h5>
    <small class="opacity-75"><?= date('d.m.Y') ?></small>
</div>

<div class="container main-container" style="max-width: 550px;">

    <!-- ТАБ 1: КАСА -->
    <div id="tab-kasa" class="tab-content">
        <?php if($show_results): ?>
            <div class="modern-card text-center py-5">
                <div class="display-1 text-success mb-3">✅</div>
                <h3 class="fw-bold">Звіт надіслано!</h3>
                <a href="bulk_entry.php" class="btn btn-success rounded-pill px-5 py-3 fw-bold shadow mt-3">НОВА ЗМІНА</a>
            </div>
        <?php elseif(!$inspection): ?>
            <div class="modern-card">
                <div class="section-title"><i class="bi bi-shield-check"></i> Ранкова перевірка безпеки</div>
                <p class="small text-muted mb-4">Будь ласка, перевірте трасу перед початком роботи.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="submit_inspection">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" required id="c1">
                        <label class="form-check-label fw-bold" for="c1">Троси та затискачі перевірені</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" required id="c2">
                        <label class="form-check-label fw-bold" for="c2">Спорядження (ролики, системи) ціле</label>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" required id="c3">
                        <label class="form-check-label fw-bold" for="c3">Траса вільна від гілок</label>
                    </div>
                    <button type="submit" class="btn btn-danger w-100 py-3 rounded-4 fw-bold shadow">ВІДКРИТИ ТРАСУ</button>
                </form>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="send_report">
                
                <div class="modern-card">
                    <div class="section-title">🎟 Продажі (Авто-підрахунок сканів)</div>
                    <?php foreach($services as $srv): 
                        $sQty = getScanQty($srv['id'], $scan_stats);
                    ?>
                    <div class="service-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold"><?= $srv['name'] ?></div>
                            <span class="scan-hint">📲 Відскановано: <?= $sQty ?></span>
                        </div>
                        <input type="number" name="service_qty[<?= $srv['id'] ?>]" value="<?= $sQty ?>" min="0">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="modern-card">
                    <div class="section-title">👷 Персонал на зміні</div>
                    <?php foreach($staff_list as $s): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="staff[]" value="<?= $s['id'] ?>" id="st<?= $s['id'] ?>">
                        <label class="form-check-label fw-bold" for="st<?= $s['id'] ?>"><?= $s['full_name'] ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="modern-card">
                    <div class="section-title">💸 Додатково</div>
                    <input type="text" name="expense_desc" class="form-control mb-2" placeholder="Опис витрат">
                    <input type="number" name="expense_amount" class="form-control mb-3" placeholder="Сума витрат">
                    <input type="number" name="encashment" class="form-control text-success fw-bold border-success" placeholder="Інкасація власнику">
                </div>

                <button type="submit" class="btn btn-success w-100 py-3 rounded-4 fw-bold shadow-lg mb-5">ЗАКРИТИ ЗМІНУ ТА ЗВІТ</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ТАБ 2: ЗВІТИ -->
    <div id="tab-history" class="tab-content" style="display:none;">
        <h6 class="fw-bold mb-3 px-2 uppercase">Архів звітів</h6>
        <?php foreach($reports_history as $rep): ?>
            <div class="modern-card p-3 mb-2" onclick="showReportDetails(<?= htmlspecialchars($rep['details_json']) ?>, '<?= date('d.m.Y H:i', strtotime($rep['created_at'])) ?>', '<?= $rep['sender'] ?>')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold fs-5"><?= number_format($rep['amount'], 0) ?> ₴</div>
                        <small class="text-muted"><?= $rep['sender'] ?></small>
                    </div>
                    <div class="text-end small">
                        <div class="fw-bold text-success"><?= date('d.m.Y', strtotime($rep['created_at'])) ?></div>
                        <div class="text-muted"><?= date('H:i', strtotime($rep['created_at'])) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ТАБ 3: ПРОФІЛЬ -->
    <div id="tab-profile" class="tab-content" style="display:none;">
        <div class="modern-card text-center">
            <div class="salary-circle bg-light border p-4 rounded-4 mb-4 text-center">
                <small class="text-muted fw-bold">БАЛАНС ДО ВИПЛАТИ</small>
                <div class="display-4 fw-bold text-success"><?= number_format($earned - $taken, 0) ?> ₴</div>
            </div>
            
            <div class="text-start mb-4">
                <div class="section-title">📅 Мій графік</div>
                <?php if(empty($schedule)): ?><p class="small text-muted">Графік порожній</p>
                <?php else: foreach($schedule as $sc): ?>
                    <div class="d-flex justify-content-between border-bottom py-2 small fw-bold">
                        <span><?= date('d.m (D)', strtotime($sc['work_date'])) ?></span>
                        <span class="text-primary"><?= $sc['shift_type'] ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="text-start mb-4">
                <div class="section-title">🕒 Історія ЗП</div>
                <?php foreach($salary_log as $sl): ?>
                    <div class="d-flex justify-content-between small py-2 border-bottom">
                        <div>
                            <div class="fw-bold"><?= $sl['description'] ?></div>
                            <small class="text-muted"><?= date('d.m H:i', strtotime($sl['created_at'])) ?></small>
                        </div>
                        <div class="<?= $sl['type'] == 'salary_accrual' ? 'salary-accrual' : 'salary-payout' ?>">
                            <?= $sl['type'] == 'salary_accrual' ? '+' : '-' ?><?= number_format($sl['amount'], 0) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <input type="password" name="new_password" class="form-control mb-2" placeholder="Новий пароль">
                <button class="btn btn-dark w-100 rounded-3">ЗМІНИТИ ПАРОЛЬ</button>
            </form>
            <a href="logout.php" class="btn btn-link text-danger mt-3">Вийти</a>
        </div>
    </div>

</div>

<!-- МОДАЛКА ДЕТАЛЕЙ -->
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-0"><h5 class="fw-bold">Деталі звіту</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="reportModalContent"></div>
    </div></div>
</div>

<nav class="bottom-nav">
    <button class="nav-btn active" onclick="switchTab('tab-kasa', this)"><i class="bi bi-wallet2"></i><span>Каса</span></button>
    <button class="nav-btn" onclick="window.location.href='scanner.php'"><i class="bi bi-qr-code-scan text-success" style="font-size: 2rem;"></i><span>Сканер</span></button>
    <button class="nav-btn" onclick="switchTab('tab-history', this)"><i class="bi bi-journal-text"></i><span>Звіти</span></button>
    <button class="nav-btn" onclick="switchTab('tab-profile', this)"><i class="bi bi-person-circle"></i><span>Профіль</span></button>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function switchTab(id, btn) {
        document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(id).style.display = 'block';
        btn.classList.add('active');
        window.scrollTo(0,0);
    }
    function showReportDetails(data, date, sender) {
        let html = `<div class="p-3 bg-light rounded-3 small mb-3"><b>Від:</b> ${sender}<br><b>Час:</b> ${date}</div>`;
        html += `<div class="fw-bold text-success mb-2 small text-uppercase">🎟 Послуги:</div>`;
        data.services.forEach(s => { html += `<div class="d-flex justify-content-between small border-bottom py-1"><span>${s.name} (x${s.qty})</span><b>${s.sum} ₴</b></div>`; });
        html += `<div class="fw-bold text-primary mt-3 mb-2 small text-uppercase">👷 Персонал (ЗП):</div>`;
        data.staff.forEach(s => { html += `<div class="d-flex justify-content-between small py-1"><span>${s.name}</span><b>${s.amount} ₴</b></div>`; });
        html += `<div class="mt-4 border-top pt-3 d-flex justify-content-between fs-4 fw-bold text-success"><span>В КАСІ:</span><b>${data.finance.final} ₴</b></div>`;
        document.getElementById('reportModalContent').innerHTML = html;
        new bootstrap.Modal(document.getElementById('reportModal')).show();
    }
</script>
</body>
</html>
