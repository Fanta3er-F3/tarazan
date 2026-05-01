<?php
// ═══════════════════════════════════════════════════════════════════════════════
// 🏢 TARZAN PARK | OWNER DASHBOARD v2.0
// ═══════════════════════════════════════════════════════════════════════════════

// 1. ДІАГНОСТИКА
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header("Location: index.php"); exit;
}

$msg = '';
$msg_type = 'success';

// ═══════════════════════════════════════════════════════════════════════════════
// 2. ОБРОБКА ДІЙ (CRUD + ІНКАСАЦІЯ)
// ═══════════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // --- ПРЯМА ІНКАСАЦІЯ ---
        if ($_POST['action'] === 'manual_encashment') {
            $amount = (float)$_POST['amount'];
            $desc = "Вилучення власником: " . trim($_POST['desc']);
            if ($amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO transactions (cashier_id, type, amount, description) VALUES (?, 'encashment', ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $amount, $desc]);
                $msg = "✅ Інкасацію на суму $amount ₴ проведено!";
            }
        }

        // --- ЗБЕРЕЖЕННЯ / РЕДАГУВАННЯ ПОСЛУГИ ---
        if ($_POST['action'] === 'save_service') {
            $active = isset($_POST['active']) ? 1 : 0;
            if (!empty($_POST['id'])) {
                $stmt = $pdo->prepare("UPDATE services SET name=?, price=?, commission_percent=?, is_active=? WHERE id=?");
                $stmt->execute([$_POST['name'], $_POST['price'], $_POST['comm'], $active, $_POST['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO services (name, price, commission_percent, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$_POST['name'], $_POST['price'], $_POST['comm']]);
            }
            $msg = "✅ Послугу збережено успішно!";
        }
        
        // --- ВИДАЛЕННЯ ПОСЛУГИ ---
        if ($_POST['action'] === 'delete_service') {
            $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([$_POST['id']]);
            $msg = "🗑 Послугу видалено!";
        }

        // --- ПЕРСОНАЛ: ДОДАТИ ---
        if ($_POST['action'] === 'add_staff') {
            $hash = password_hash($_POST['pass'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, phone, email, password, role, base_salary, status) VALUES (?, ?, ?, ?, ?, ?, 'verified')");
            $stmt->execute([$_POST['name'], $_POST['phone'], $_POST['email'], $hash, $_POST['role'], $_POST['salary']]);
            $msg = "✅ Працівника додано до системи!";
        }

        // --- ПЕРСОНАЛ: РЕДАГУВАННЯ ---
        if ($_POST['action'] === 'edit_staff') {
            $stmt = $pdo->prepare("UPDATE users SET full_name=?, base_salary=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['salary'], $_POST['staff_id']]);
            $msg = "✅ Дані працівника оновлено!";
        }

        // --- ПЕРСОНАЛ: ВИДАЛЕННЯ ---
        if ($_POST['action'] === 'delete_staff') {
            $pdo->prepare("UPDATE users SET status='disabled' WHERE id=?")->execute([$_POST['staff_id']]);
            $msg = "🗑 Працівника видалено!";
        }

        // --- НАЛАШТУВАННЯ СИСТЕМИ ---
        if ($_POST['action'] === 'update_settings') {
            foreach ($_POST['set'] as $key => $val) {
                $pdo->prepare("INSERT OR REPLACE INTO system_settings (`key`, `value`) VALUES (?, ?)")->execute([$key, $val]);
            }
            $msg = "⚙️ Налаштування оновлено!";
        }
        
        // --- КЛІЄНТ: ЗБЕРЕГТИ / ОНОВИТИ ---
        if ($_POST['action'] === 'save_client') {
            $id = (int)$_POST['client_id'];
            $status = in_array($_POST['status'] ?? '', ['verified','disabled']) ? $_POST['status'] : 'verified';
            $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=?, barcode=?, sub_adult_balance=?, sub_child_balance=?, loyalty_visits=?, status=? WHERE id=?");
            $stmt->execute([
                trim($_POST['full_name']),
                trim($_POST['email']),
                trim($_POST['phone']),
                trim($_POST['barcode']),
                (int)$_POST['sub_adult_balance'],
                (int)$_POST['sub_child_balance'],
                (int)$_POST['loyalty_visits'],
                $status,
                $id
            ]);
            $msg = "✅ Дані клієнта збережено!";
        }

        // --- РАНКОВА ПЕРЕВІРКА БЕЗПЕКИ: ДОДАТИ ---
        if ($_POST['action'] === 'add_safety_check') {
            $stmt = $pdo->prepare("INSERT INTO morning_checks (instructor_id, check_date, status) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['instructor_id'], $_POST['check_date'], $_POST['status']]);
            $msg = "✅ Ранкову перевірку безпеки додано!";
        }

        // --- ГРАФІК РОБОТИ: ДОДАТИ/ОНОВИТИ (НОВА ГНУЧКА СИСТЕМА) ---
        if ($_POST['action'] === 'save_schedule') {
            $stmt = $pdo->prepare("INSERT INTO flexible_schedule (user_id, work_date, start_time, end_time, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['user_id'], $_POST['work_date'], $_POST['start_time'], $_POST['end_time'], $_POST['notes']]);
            $msg = "✅ Графік роботи додано!";
        }

        // --- ГРАФІК РОБОТИ: ВИДАЛИТИ (НОВА ГНУЧКА СИСТЕМА) ---
        if ($_POST['action'] === 'delete_schedule') {
            $pdo->prepare("DELETE FROM flexible_schedule WHERE id = ?")->execute([$_POST['schedule_id']]);
            $msg = "🗑 Запис графіку видалено!";
        }

        // --- ПРАВИЛО КЛІЄНТА: ДОДАТИ/ОНОВИТИ ---
        if ($_POST['action'] === 'save_client_rule') {
            if (!empty($_POST['rule_id'])) {
                $stmt = $pdo->prepare("UPDATE client_rules SET rule_title=?, rule_content=?, is_active=?, display_order=? WHERE id=?");
                $stmt->execute([$_POST['rule_title'], $_POST['rule_content'], $_POST['is_active'], $_POST['display_order'], $_POST['rule_id']]);
            } else {
                // Перевірка, чи правило з таким заголовком вже існує
                $existing = $pdo->prepare("SELECT id FROM client_rules WHERE rule_title = ?");
                $existing->execute([$_POST['rule_title']]);
                if ($existing->fetch()) {
                    $msg = "⚠️ Правило з таким заголовком вже існує!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO client_rules (rule_title, rule_content, is_active, display_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_POST['rule_title'], $_POST['rule_content'], $_POST['is_active'], $_POST['display_order']]);
                    $msg = "✅ Правило збережено!";
                }
            }
            // Перенаправлення для уникнення повторного відправлення форми
            header("Location: dashboard_owner.php#tab-rules"); exit;
        }

        // --- ПРАВИЛО КЛІЄНТА: ВИДАЛИТИ ---
        if ($_POST['action'] === 'delete_client_rule') {
            $pdo->prepare("DELETE FROM client_rules WHERE id = ?")->execute([$_POST['rule_id']]);
            $msg = "🗑 Правило видалено!";
        }
    } catch (Exception $e) { 
        $msg = "❌ Помилка: " . $e->getMessage();
        $msg_type = 'danger';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. ОТРИМАННЯ ДАНИХ
// ═══════════════════════════════════════════════════════════════════════════════

$services = $pdo->query("SELECT * FROM services ORDER BY is_active DESC, name ASC")->fetchAll();
$staff = $pdo->query("SELECT * FROM users WHERE role IN ('cashier', 'instructor') AND status='verified' ORDER BY full_name ASC")->fetchAll();
$clients = $pdo->query("SELECT * FROM users WHERE role='client' AND status='verified' ORDER BY full_name ASC")->fetchAll();
// Підготовка даних клієнтів для JavaScript
$clients_for_js = [];
foreach ($clients as $c) {
    $clients_for_js[$c['id']] = [
        'id' => $c['id'],
        'full_name' => $c['full_name'],
        'email' => $c['email'],
        'phone' => $c['phone'],
        'barcode' => $c['barcode'],
        'sub_adult_balance' => $c['sub_adult_balance'],
        'sub_child_balance' => $c['sub_child_balance'],
        'loyalty_visits' => $c['loyalty_visits'],
        'status' => $c['status']
    ];
}
$clients_map_json_literal = json_encode($clients_for_js, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE);
$reports = $pdo->query("SELECT t.*, u.full_name as cashier_name FROM transactions t JOIN users u ON t.cashier_id = u.id WHERE t.type = 'report_finish' ORDER BY t.created_at DESC LIMIT 15")->fetchAll();
$encashments = $pdo->query("SELECT * FROM transactions WHERE type = 'encashment' ORDER BY created_at DESC LIMIT 15")->fetchAll();
$sys = $pdo->query("SELECT `key`, `value` FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

// --- АНАЛІТИКА ---
$today_rev = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='report_finish' AND date(created_at) = date('now')")->fetchColumn();
$total_encashed = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='encashment' AND date(created_at) >= date('now','start of month')")->fetchColumn();
$cash_in_box = (float)$pdo->query("SELECT SUM(CASE WHEN type IN ('service_sale','ticket_small','ticket_large','subscription_sale','manual_sale') THEN amount WHEN type IN ('expense','encashment','salary_payout') THEN -amount ELSE 0 END) FROM transactions WHERE type != 'report_finish'")->fetchColumn();
$total_clients = count($clients);
$total_staff = count($staff);

// --- ДАНІ ДЛЯ НОВИХ ФУНКЦІЙ ---
// Історія ранкових перевірок безпеки
$safety_checks = $pdo->query("SELECT mc.*, u.full_name as instructor_name FROM morning_checks mc JOIN users u ON mc.instructor_id = u.id ORDER BY mc.check_date DESC LIMIT 30")->fetchAll();

// Графік роботи (нова гнучка система)
$flexible_schedule = $pdo->query("SELECT fs.*, u.full_name, u.role FROM flexible_schedule fs JOIN users u ON fs.user_id = u.id WHERE u.role IN ('cashier', 'instructor') ORDER BY fs.work_date DESC, fs.start_time ASC LIMIT 50")->fetchAll();
$staff_for_schedule = $pdo->query("SELECT id, full_name, role FROM users WHERE role IN ('cashier', 'instructor') AND status='verified' ORDER BY full_name ASC")->fetchAll();

// Історія відвідувань клієнтів (останні 50)
$client_visits = $pdo->query("SELECT cv.*, c.full_name as client_name, cs.full_name as cashier_name FROM client_visits cv LEFT JOIN users c ON cv.client_id = c.id LEFT JOIN users cs ON cv.cashier_id = cs.id ORDER BY cv.visit_date DESC LIMIT 50")->fetchAll();

// Правила для клієнтського кабінету
$client_rules = $pdo->query("SELECT * FROM client_rules ORDER BY display_order ASC")->fetchAll();

// --- СТАТИСТИКА ЗА МІСЯЦЬ ---
$monthly_revenue = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='report_finish' AND date(created_at) >= date('now','start of month')")->fetchColumn();
$monthly_expenses = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='expense' AND date(created_at) >= date('now','start of month')")->fetchColumn();
$monthly_salary = (float)$pdo->query("SELECT SUM(amount) FROM transactions WHERE type='salary_payout' AND date(created_at) >= date('now','start of month')")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner CRM | Tarzan Park</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Inline SVG favicon to avoid missing /favicon.ico 404 -->
    <link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20100%20100'%3E%3Crect%20fill='%2300a86b'%20width='100'%20height='100'/%3E%3Ctext%20x='50'%20y='65'%20font-size='60'%20text-anchor='middle'%20fill='white'%20font-family='Arial'%3ET%3C/text%3E%3C/svg%3E">
    
    <style>
        :root {
            --primary: #00a86b;
            --primary-dark: #008f59;
            --secondary: #0fa472;
            --accent: #ff6b35;
            --danger: #e74c3c;
            --bg-dark: #0d1117;
            --bg-light: #f7f9fc;
            --surface: #ffffff;
            --text-dark: #1a1d23;
            --text-muted: #6c757d;
            --border-color: #e1e5eb;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            background: var(--bg-light);
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            line-height: 1.6;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           SIDEBAR
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .sidebar {
            background: linear-gradient(135deg, var(--bg-dark) 0%, #1a1f2e 100%);
            min-height: 100vh;
            padding: 2rem 1.5rem;
            position: sticky;
            top: 0;
            border-right: 1px solid rgba(255,255,255,0.1);
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            text-align: center;
            margin-bottom: 3rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--primary);
        }

        .sidebar-brand h4 {
            color: var(--primary);
            font-size: 1.8rem;
            margin: 0;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .sidebar-brand p {
            color: var(--text-muted);
            font-size: 0.75rem;
            margin-top: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .nav-link {
            color: rgba(255,255,255,0.6);
            border-radius: 12px;
            padding: 0.875rem 1.125rem;
            margin-bottom: 0.5rem;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .nav-link:hover {
            background: rgba(0, 168, 107, 0.15);
            color: var(--primary);
            transform: translateX(4px);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 168, 107, 0.3);
        }

        .nav-link i {
            font-size: 1.1rem;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-footer .nav-link {
            color: rgba(255, 107, 53, 0.7);
            margin-bottom: 0;
        }

        .sidebar-footer .nav-link:hover {
            background: rgba(255, 107, 53, 0.15);
            color: var(--accent);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           MAIN CONTENT
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .main-content {
            padding: 2rem 3rem;
            overflow-y: auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .page-header h2 {
            font-size: 2.2rem;
            color: var(--text-dark);
            margin: 0;
        }

        /* ALERT */
        .alert {
            border: none;
            border-radius: 16px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            font-weight: 500;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            border-left: 4px solid;
            animation: slideIn 0.4s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success { border-left-color: var(--primary); background: rgba(0, 168, 107, 0.08); }
        .alert-danger { border-left-color: var(--danger); background: rgba(231, 76, 60, 0.08); }

        /* ═══════════════════════════════════════════════════════════════════════════════
           STAT CARDS
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .stat-card {
            background: var(--surface);
            border-radius: 20px;
            padding: 1.75rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: var(--transition);
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }

        .stat-card.stat-green {
            border-bottom: 4px solid var(--primary);
        }

        .stat-card.stat-blue {
            border-bottom: 4px solid #2563eb;
        }

        .stat-card.stat-orange {
            border-bottom: 4px solid var(--accent);
        }

        .stat-card.stat-purple {
            border-bottom: 4px solid #9333ea;
        }

        .stat-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .stat-subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           TABS
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .nav-tabs {
            border: none;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .nav-tabs .nav-link {
            color: var(--text-muted);
            border: none;
            padding: 0.75rem 1rem;
            font-weight: 600;
            border-radius: 8px;
            transition: var(--transition);
            position: relative;
        }

        .nav-tabs .nav-link:hover {
            background: rgba(0, 168, 107, 0.1);
            color: var(--primary);
        }

        .nav-tabs .nav-link.active {
            background: transparent;
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           BUTTONS
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .btn {
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .btn-primary, .btn-success {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 168, 107, 0.3);
        }

        .btn-primary:hover, .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 168, 107, 0.4);
            color: white;
        }

        .btn-dark {
            background: var(--bg-dark);
            color: white;
        }

        .btn-dark:hover {
            background: rgba(0,0,0,0.8);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
            color: white;
        }

        .btn-light {
            background: var(--bg-light);
            color: var(--text-dark);
            border: 1px solid var(--border-color);
        }

        .btn-light:hover {
            background: white;
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-outline-danger {
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           TABLES
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .table-responsive {
            border-radius: 16px;
            overflow: hidden;
        }

        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table thead th {
            background: var(--bg-light);
            border: none;
            border-bottom: 2px solid var(--border-color);
            color: var(--text-muted);
            font-weight: 700;
            padding: 1.25rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody td {
            border: none;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem;
            vertical-align: middle;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:hover {
            background: rgba(0, 168, 107, 0.02);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           FORMS
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.875rem 1rem;
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--surface);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 168, 107, 0.1);
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           MODAL
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .modal-content {
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 2rem;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem 2rem;
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           STAFF & CLIENT CARDS
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .staff-card, .client-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .staff-card:hover, .client-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: 0 12px 32px rgba(0, 168, 107, 0.15);
        }

        .staff-avatar, .client-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 2rem;
            box-shadow: 0 4px 12px rgba(0, 168, 107, 0.3);
        }

        /* Mobile tweaks */
        @media (max-width: 600px) {
            .main-content {
                padding: 1rem;
            }
            .client-card, .staff-card {
                padding: 1.25rem;
                text-align: left;
            }
            .client-avatar, .staff-avatar {
                width: 64px;
                height: 64px;
                font-size: 1.5rem;
            }
            .client-name, .staff-name {
                font-size: 1rem;
            }
        }

        .staff-name, .client-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .staff-role, .client-status {
            display: inline-block;
            background: var(--bg-light);
            color: var(--primary);
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .staff-salary, .client-visits {
            font-size: 0.9rem;
            color: var(--text-muted);
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            margin-top: 1rem;
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           RECEIPT
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .receipt {
            background: white;
            font-family: 'Courier New', monospace;
            padding: 2rem;
            border: 1px dashed var(--border-color);
            color: var(--text-dark);
            border-radius: 16px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           RESPONSIVE
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                width: 250px;
                z-index: 999;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                padding: 1.5rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-header h2 {
                font-size: 1.5rem;
            }

            .stat-value {
                font-size: 2rem;
            }
        }

        /* ═══════════════════════════════════════════════════════════════════════════════
           BADGES
        ═══════════════════════════════════════════════════════════════════════════════ */
        
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge.bg-success {
            background: rgba(0, 168, 107, 0.15) !important;
            color: var(--primary) !important;
        }

        .badge.bg-danger {
            background: rgba(231, 76, 60, 0.15) !important;
            color: var(--danger) !important;
        }

        .service-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .service-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px rgba(0, 168, 107, 0.1);
        }

        .service-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .service-price {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin: 1rem 0;
        }

        .service-commission {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>

<div class="container-fluid h-100">
    <div class="row h-100 g-0">
        <!-- SIDEBAR -->
        <div class="col-md-2 sidebar d-none d-md-block">
            <div class="sidebar-brand">
                <h4>🦁 TARZAN</h4>
                <p>Park Management</p>
            </div>
            
            <nav class="nav flex-column">
                <a class="nav-link active" data-bs-toggle="tab" href="#main">
                    <i class="bi bi-grid-1x2-fill"></i> Огляд
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-encash">
                    <i class="bi bi-cash-stack"></i> Інкасація
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-services">
                    <i class="bi bi-tags-fill"></i> Послуги
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-staff">
                    <i class="bi bi-people-fill"></i> Персонал
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-clients">
                    <i class="bi bi-person-hearts"></i> Клієнти
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-safety">
                    <i class="bi bi-shield-check"></i> Безпека
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-schedule">
                    <i class="bi bi-calendar-week"></i> Графік
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-visits">
                    <i class="bi bi-clock-history"></i> Відвідування
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-rules">
                    <i class="bi bi-card-text"></i> Правила
                </a>
                <a class="nav-link" data-bs-toggle="tab" href="#tab-config">
                    <i class="bi bi-sliders"></i> Налаштування
                </a>
            </nav>

            <div class="sidebar-footer">
                <a class="nav-link" href="logout.php">
                    <i class="bi bi-power"></i> Вихід
                </a>
            </div>
        </div>

        <!-- MAIN CONTENT -->
        <div class="col-md-10 main-content">
            <?php if($msg): ?>
                <div class="alert alert-<?= $msg_type ?>" role="alert">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <div class="tab-content">
                
                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ГЛАВНАЯ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade show active" id="main">
                    <div class="page-header">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button class="btn btn-light d-md-none" id="sidebarToggle" aria-label="Toggle menu">☰</button>
                                    <h2 style="margin:0;">Добро пожаловать обратно! 👋</h2>
                                </div>
                                <button class="btn btn-primary shadow" data-bs-toggle="modal" data-bs-target="#modalManualEncash">
                                    <i class="bi bi-box-arrow-up me-2"></i> ЗАБРАТИ ГРОШІ
                                </button>
                    </div>
                    
                    <div class="row g-4 mb-5">
                        <div class="col-lg-3">
                            <div class="stat-card stat-green">
                                <div class="stat-label">💰 Готівка в касі</div>
                                <div class="stat-value"><?= number_format($cash_in_box, 0) ?></div>
                                <div class="stat-subtitle">₴ (зараз)</div>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="stat-card stat-blue">
                                <div class="stat-label">📊 Виручка сьогодні</div>
                                <div class="stat-value"><?= number_format($today_rev, 0) ?></div>
                                <div class="stat-subtitle">₴</div>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="stat-card stat-orange">
                                <div class="stat-label">👥 Клієнти</div>
                                <div class="stat-value"><?= $total_clients ?></div>
                                <div class="stat-subtitle">активних користувачів</div>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="stat-card stat-purple">
                                <div class="stat-label">👨‍💼 Персонал</div>
                                <div class="stat-value"><?= $total_staff ?></div>
                                <div class="stat-subtitle">працівників</div>
                            </div>
                        </div>
                    </div>

                            <!-- Modal: Клієнт (перегляд/редагування) -->
                            <div class="modal fade" id="modalClient" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold" id="clientTitle">Клієнт</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="save_client">
                                                <input type="hidden" name="client_id" id="clientId">

                                                <div class="row g-3">
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label fw-600">ПІБ</label>
                                                        <input type="text" name="full_name" id="clientFullName" class="form-control" required>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label fw-600">Email</label>
                                                        <input type="email" name="email" id="clientEmail" class="form-control">
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label fw-600">Телефон</label>
                                                        <input type="text" name="phone" id="clientPhone" class="form-control">
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label fw-600">Barcode</label>
                                                        <input type="text" name="barcode" id="clientBarcode" class="form-control">
                                                    </div>

                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label fw-600">Дорослі проходи</label>
                                                        <input type="number" name="sub_adult_balance" id="clientSubAdult" class="form-control">
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label fw-600">Дитячі проходи</label>
                                                        <input type="number" name="sub_child_balance" id="clientSubChild" class="form-control">
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label fw-600">Лояльні візити</label>
                                                        <input type="number" name="loyalty_visits" id="clientVisits" class="form-control">
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label fw-600">Статус</label>
                                                        <select name="status" id="clientStatus" class="form-select">
                                                            <option value="verified">verified</option>
                                                            <option value="disabled">disabled</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Закрити</button>
                                                <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-save me-2"></i> Зберегти</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                    <!-- Monthly Stats -->
                    <div class="row g-4 mb-5">
                        <div class="col-lg-4">
                            <div class="stat-card">
                                <div class="stat-label">📈 Виручка за місяць</div>
                                <div class="stat-value" style="color: var(--primary);"><?= number_format($monthly_revenue, 0) ?></div>
                                <div class="stat-subtitle">₴</div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="stat-card">
                                <div class="stat-label">💸 Видатки за місяць</div>
                                <div class="stat-value" style="color: var(--danger);"><?= number_format($monthly_expenses, 0) ?></div>
                                <div class="stat-subtitle">₴</div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="stat-card">
                                <div class="stat-label">💳 Зарплата за місяць</div>
                                <div class="stat-value" style="color: #9333ea;"><?= number_format($monthly_salary, 0) ?></div>
                                <div class="stat-subtitle">₴</div>
                            </div>
                        </div>
                    </div>

                    <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">Останні звіти закриття змін</h3>
                    <div class="stat-card p-0 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>📅 Дата / Час</th>
                                        <th>👤 Касир</th>
                                        <th>💵 Виручка</th>
                                        <th class="text-end">Дія</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($reports) > 0): ?>
                                        <?php foreach($reports as $r): ?>
                                        <tr>
                                            <td>
                                                <strong><?= date('d.m.Y', strtotime($r['created_at'])) ?></strong>
                                                <span class="text-muted ms-2"><?= date('H:i', strtotime($r['created_at'])) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($r['cashier_name']) ?></td>
                                            <td>
                                                <span class="badge bg-success"><?= number_format($r['amount'], 0) ?> ₴</span>
                                            </td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-light" onclick='openReceipt(<?= $r["details_json"] ?>, "<?= date("d.m H:i", strtotime($r["created_at"])) ?>", "<?= $r["cashier_name"] ?>")'>
                                                    <i class="bi bi-receipt"></i> ЧЕК
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Немає звітів</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ІНКАСАЦІЯ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-encash">
                    <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">Журнал інкасацій</h3>
                    <div class="stat-card p-0 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>📅 Дата і час</th>
                                        <th>💰 Сума</th>
                                        <th>📝 Коментар</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($encashments) > 0): ?>
                                        <?php foreach($encashments as $e): ?>
                                        <tr>
                                            <td>
                                                <strong><?= date('d.m.Y', strtotime($e['created_at'])) ?></strong>
                                                <span class="text-muted ms-2"><?= date('H:i', strtotime($e['created_at'])) ?></span>
                                            </td>
                                            <td><strong style="color: var(--danger);">- <?= number_format($e['amount'], 0) ?> ₴</strong></td>
                                            <td><?= htmlspecialchars($e['description']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">Немає інкасацій</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ПОСЛУГИ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-services">
                    <div class="page-header">
                        <h3 class="fw-bold">Послуги та ціни</h3>
                        <button class="btn btn-primary" onclick="addService()">
                            <i class="bi bi-plus-lg me-2"></i> Додати послугу
                        </button>
                    </div>

                    <div class="row g-4">
                        <?php if(count($services) > 0): ?>
                            <?php foreach($services as $s): ?>
                            <div class="col-lg-4">
                                <div class="service-card">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="service-title"><?= htmlspecialchars($s['name']) ?></h5>
                                        <span class="badge <?= $s['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $s['is_active'] ? '✓ АКТИВНА' : '✗ ВИМКНЕНА' ?>
                                        </span>
                                    </div>
                                    <div class="service-price"><?= $s['price'] ?> ₴</div>
                                    <div class="service-commission">Комісія: <strong><?= $s['commission_percent'] ?>%</strong></div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-light flex-grow-1" onclick='editService(<?= json_encode($s) ?>)'>
                                            <i class="bi bi-pencil"></i> Редагувати
                                        </button>
                                        <form method="POST" style="flex-grow: 1;" onsubmit="return confirm('Видалити послугу?')">
                                            <input type="hidden" name="action" value="delete_service">
                                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger w-100">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-center py-5">
                                <p class="text-muted mb-3">Послуги не додані</p>
                                <button class="btn btn-primary" onclick="addService()">
                                    <i class="bi bi-plus-lg me-2"></i> Додати першу послугу
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ПЕРСОНАЛ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-staff">
                    <div class="page-header">
                        <h3 class="fw-bold">Управління командою</h3>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalStaff">
                            <i class="bi bi-plus-lg me-2"></i> Додати працівника
                        </button>
                    </div>

                    <div class="row g-4">
                        <?php if(count($staff) > 0): ?>
                            <?php foreach($staff as $w): ?>
                            <div class="col-lg-3 col-md-4">
                                <div class="staff-card">
                                    <div class="staff-avatar">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                    <div class="staff-name"><?= htmlspecialchars($w['full_name']) ?></div>
                                    <span class="staff-role"><?= $w['role'] === 'cashier' ? '💰 Касир' : '🏋️ Інструктор' ?></span>
                                    
                                    <div class="staff-salary">
                                        <div class="mb-3"><strong><?= $w['base_salary'] ?> ₴</strong> за місяць</div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-light btn-sm flex-grow-1" data-bs-toggle="modal" data-bs-target="#modalEditStaff" onclick='fillStaffEdit(<?= json_encode($w) ?>)'>
                                                <i class="bi bi-pencil"></i> Змінити
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Видалити працівника?')">
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?= $w['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-center py-5">
                                <p class="text-muted mb-3">Персонал не додан</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalStaff">
                                    <i class="bi bi-plus-lg me-2"></i> Додати першого працівника
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: КЛІЄНТИ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-clients">
                    <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">
                        <i class="bi bi-person-hearts me-2"></i> Клієнти (<?= $total_clients ?>)
                    </h3>

                    <div class="row g-4">
                        <?php if(count($clients) > 0): ?>
                            <?php foreach($clients as $c): 
                                    // Prefer loyalty_visits field when available
                                    $visits = (int)($c['loyalty_visits'] ?? 0);
                                ?>
                                <div class="col-lg-3 col-md-4 col-sm-6">
                                <div class="client-card">
                                    <div class="client-avatar">
                                        <i class="bi bi-person-circle"></i>
                                    </div>
                                        <div class="client-name"><?= htmlspecialchars($c['full_name']) ?></div>
                                    <span class="client-status">🟢 Активний</span>
                                    
                                    <div class="client-visits">
                                        <div class="small text-muted mb-2"><?= htmlspecialchars($c['email']) ?></div>
                                        <div class="mb-2"><strong><?= $visits ?></strong> / <?= $sys['bonus_threshold'] ?? 5 ?> візитів</div>
                                        <div style="height: 4px; background: var(--border-color); border-radius: 2px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= min(100, ($visits / ($sys['bonus_threshold'] ?? 5)) * 100) ?>%; background: linear-gradient(90deg, var(--primary), var(--secondary));"></div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <button class="btn btn-light btn-sm w-100" onclick='openClientById(<?= (int)$c['id'] ?>)'><i class="bi bi-eye me-1"></i> Переглянути / Редагувати</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-center py-5">
                                <p class="text-muted">Клієнти поки не зареєстровані</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: БЕЗПЕКА
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-safety">
                    <div class="page-header">
                        <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">
                            <i class="bi bi-shield-check me-2"></i> Історія ранкових перевірок безпеки
                        </h3>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalSafetyCheck">
                            <i class="bi bi-plus-lg me-2"></i> Додати перевірку
                        </button>
                    </div>

                    <div class="stat-card p-0 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>📅 Дата</th>
                                        <th>👤 Інструктор</th>
                                        <th>✅ Статус</th>
                                        <th>⏰ Час</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($safety_checks) > 0): ?>
                                        <?php foreach($safety_checks as $check): ?>
                                        <tr>
                                            <td><strong><?= date('d.m.Y', strtotime($check['check_date'])) ?></strong></td>
                                            <td><?= htmlspecialchars($check['instructor_name']) ?></td>
                                            <td>
                                                <span class="badge <?= $check['status'] === 'pass' ? 'bg-success' : 'bg-danger' ?>">
                                                    <?= $check['status'] === 'pass' ? '✓ Пройдено' : '✗ Не пройдено' ?>
                                                </span>
                                            </td>
                                            <td class="text-muted"><?= date('H:i', strtotime($check['created_at'])) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Немає записів про перевірки безпеки</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ГРАФІК (НОВА ГНУЧКА СИСТЕМА)
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-schedule">
                    <div class="page-header">
                        <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">
                            <i class="bi bi-calendar-week me-2"></i> Гнучкий графік роботи персоналу
                        </h3>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalSchedule">
                            <i class="bi bi-plus-lg me-2"></i> Додати запис
                        </button>
                    </div>

                    <div class="stat-card p-0 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>📅 Дата</th>
                                        <th>👤 Працівник</th>
                                        <th>👔 Посада</th>
                                        <th>⏰ Час роботи</th>
                                        <th>📝 Нотатки</th>
                                        <th class="text-end">Дії</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($flexible_schedule) > 0): ?>
                                        <?php foreach($flexible_schedule as $item): ?>
                                        <tr>
                                            <td><strong><?= date('d.m.Y', strtotime($item['work_date'])) ?></strong></td>
                                            <td><?= htmlspecialchars($item['full_name']) ?></td>
                                            <td><?= $item['role'] === 'cashier' ? '💰 Касир' : '🏋️ Інструктор' ?></td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?= date('H:i', strtotime($item['start_time'])) ?> - <?= date('H:i', strtotime($item['end_time'])) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($item['notes'] ?? '-') ?></td>
                                            <td class="text-end">
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Видалити запис графіку?')">
                                                    <input type="hidden" name="action" value="delete_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?= $item['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">Графік порожній</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ВІДВІДУВАННЯ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-visits">
                    <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">
                        <i class="bi bi-clock-history me-2"></i> Історія відвідувань клієнтів
                    </h3>

                    <div class="stat-card p-0 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>📅 Дата та час</th>
                                        <th>👤 Клієнт</th>
                                        <th>🎟 Послуга</th>
                                        <th>💰 Касир</th>
                                        <th>📝 Нотатки</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($client_visits) > 0): ?>
                                        <?php foreach($client_visits as $visit): ?>
                                        <tr>
                                            <td>
                                                <strong><?= date('d.m.Y', strtotime($visit['visit_date'])) ?></strong>
                                                <span class="text-muted ms-2"><?= date('H:i', strtotime($visit['visit_date'])) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($visit['client_name'] ?? 'Невідомо') ?></td>
                                            <td><?= htmlspecialchars($visit['service_type'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($visit['cashier_name'] ?? 'Невідомо') ?></td>
                                            <td><?= htmlspecialchars($visit['notes'] ?? '-') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">Немає записів про відвідування</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: ПРАВИЛА
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-rules">
                    <div class="page-header">
                        <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">
                            <i class="bi bi-card-text me-2"></i> Правила для клієнтського кабінету
                        </h3>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalClientRule">
                            <i class="bi bi-plus-lg me-2"></i> Додати правило
                        </button>
                    </div>

                    <div class="row g-4">
                        <?php if(count($client_rules) > 0): ?>
                            <?php foreach($client_rules as $rule): ?>
                            <div class="col-md-6">
                                <div class="stat-card <?= $rule['is_active'] ? '' : 'bg-light' ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="fw-bold mb-0"><?= htmlspecialchars($rule['rule_title']) ?></h5>
                                        <span class="badge <?= $rule['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $rule['is_active'] ? '✓ Активне' : '✗ Вимкнене' ?>
                                        </span>
                                    </div>
                                    <div class="mb-3" style="white-space: pre-line;"><?= htmlspecialchars($rule['rule_content']) ?></div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-light btn-sm flex-grow-1" onclick='editClientRule(<?= json_encode($rule) ?>)'>
                                            <i class="bi bi-pencil"></i> Редагувати
                                        </button>
                                        <form method="POST" style="flex-grow: 1;" onsubmit="return confirm('Видалити правило?')">
                                            <input type="hidden" name="action" value="delete_client_rule">
                                            <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-center py-5">
                                <p class="text-muted mb-3">Правила не додані</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalClientRule">
                                    <i class="bi bi-plus-lg me-2"></i> Додати перше правило
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     TAB: НАЛАШТУВАННЯ
                ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="tab-pane fade" id="tab-config">
                    <h3 class="fw-bold mb-4" style="font-size: 1.5rem;">Конфігурація системи</h3>
                    
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="stat-card">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_settings">
                                    
                                    <div class="mb-4">
                                        <label class="form-label fw-600 mb-2">
                                            <i class="bi bi-telegram me-2" style="color: #0088cc;"></i> Telegram Bot Token
                                        </label>
                                        <input type="text" name="set[tg_token]" class="form-control" value="<?= $sys['tg_token'] ?? '' ?>" placeholder="Введіть токен">
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-600 mb-2">
                                            <i class="bi bi-envelope me-2" style="color: #ea4335;"></i> Google Apps Script URL
                                        </label>
                                        <input type="text" name="set[gas_url]" class="form-control" value="<?= $sys['gas_url'] ?? '' ?>" placeholder="https://script.google.com/...">
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-600 mb-2">
                                            <i class="bi bi-gift me-2" style="color: #f1ad4f;"></i> Поріг для бонусу
                                        </label>
                                        <input type="number" name="set[bonus_threshold]" class="form-control" value="<?= $sys['bonus_threshold'] ?? 5 ?>" placeholder="Кількість візитів">
                                        <small class="text-muted d-block mt-2">Після скількох візитів дається бонус?</small>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold">
                                        <i class="bi bi-check-circle me-2"></i> ЗБЕРЕГТИ НАЛАШТУВАННЯ
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="stat-card bg-light">
                                <h5 class="fw-bold mb-3">ℹ️ Інформація про систему</h5>
                                <div class="mb-3">
                                    <small class="text-muted">Версія</small>
                                    <p class="fw-bold">2.0</p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">База даних</small>
                                    <p class="fw-bold">SQLite</p>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted">Користувачів</small>
                                    <p class="fw-bold"><?= $total_clients + $total_staff + 1 ?></p>
                                </div>
                                <div>
                                    <small class="text-muted">Останнє оновлення</small>
                                    <p class="fw-bold"><?= date('d.m.Y H:i') ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     МОДАЛЬНІ ВІКНА
═══════════════════════════════════════════════════════════════════════════════ -->

<!-- Modal: Вилучення готівки -->
<div class="modal fade" id="modalManualEncash" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">💰 Вилучення готівки</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="manual_encashment">
                    <div class="mb-3">
                        <label class="form-label fw-600">Сума</label>
                        <input type="number" name="amount" class="form-control form-control-lg" placeholder="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">Коментар</label>
                        <input type="text" name="desc" class="form-control" placeholder="Причина вилучення">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-danger fw-bold">
                        <i class="bi bi-arrow-up-circle me-2"></i> ЗАБРАТИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Послуга -->
<div class="modal fade" id="modalService" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="sTitle">Послуга</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_service">
                    <input type="hidden" name="id" id="sId">
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">Назва послуги</label>
                        <input type="text" name="name" id="sName" class="form-control" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label fw-600">Ціна (₴)</label>
                            <input type="number" name="price" id="sPrice" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600">Комісія (%)</label>
                            <input type="number" name="comm" id="sComm" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-check form-switch mt-3" id="sActiveBox" style="display: none;">
                        <input class="form-check-input" type="checkbox" name="active" id="sActive">
                        <label class="form-check-label" for="sActive">Активна послуга</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-check-circle me-2"></i> ЗБЕРЕГТИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Новий працівник -->
<div class="modal fade" id="modalStaff" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">👤 Новий працівник</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_staff">
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">ПІБ</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">📞 Телефон</label>
                        <input type="text" name="phone" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">Пароль</label>
                        <input type="password" name="pass" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">Посада</label>
                        <select name="role" class="form-select" required>
                            <option value="cashier">💰 Касир</option>
                            <option value="instructor">🏋️ Інструктор</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">Ставка (₴/місяць)</label>
                        <input type="number" name="salary" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-plus-circle me-2"></i> СТВОРИТИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Редагування працівника -->
<div class="modal fade" id="modalEditStaff" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">✏️ Редагування працівника</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_staff">
                    <input type="hidden" name="staff_id" id="editStaffId">
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">ПІБ</label>
                        <input type="text" name="name" id="editStaffName" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">Ставка (₴/місяць)</label>
                        <input type="number" name="salary" id="editStaffSalary" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-check-circle me-2"></i> ЗБЕРЕГТИ ЗМІНИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Чек -->
<div class="modal fade" id="modalReceipt" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="receipt rounded-4 shadow-lg" id="receiptContent"></div>
        </div>
    </div>
</div>

<!-- Modal: Ранкова перевірка безпеки -->
<div class="modal fade" id="modalSafetyCheck" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">🛡 Ранкова перевірка безпеки</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_safety_check">
                    <div class="mb-3">
                        <label class="form-label fw-600">📅 Дата перевірки</label>
                        <input type="date" name="check_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">👤 Інструктор</label>
                        <select name="instructor_id" class="form-select" required>
                            <option value="">Оберіть інструктора</option>
                            <?php foreach($staff as $s): if($s['role'] === 'instructor'): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">✅ Статус</label>
                        <select name="status" class="form-select" required>
                            <option value="pass">✓ Пройдено</option>
                            <option value="fail">✗ Не пройдено</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-check-circle me-2"></i> ЗБЕРЕГТИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Графік роботи (НОВА ГНУЧКА СИСТЕМА) -->
<div class="modal fade" id="modalSchedule" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">📅 Гнучкий графік роботи</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_schedule">
                    <div class="mb-3">
                        <label class="form-label fw-600">👤 Працівник</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Оберіть працівника</option>
                            <?php foreach($staff_for_schedule as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?> <?= $s['role'] === 'cashier' ? '💰 Касир' : '🏋️ Інструктор' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">📅 Дата</label>
                        <input type="date" name="work_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-600">⏰ Початок роботи</label>
                            <input type="time" name="start_time" class="form-control" value="08:00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600">⏰ Кінець роботи</label>
                            <input type="time" name="end_time" class="form-control" value="16:00" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">📝 Нотатки (необов'язково)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Наприклад: Чергова зміна, відповідальний за касу тощо."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-check-circle me-2"></i> ЗБЕРЕГТИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Правило для клієнтського кабінету -->
<div class="modal fade" id="modalClientRule" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="ruleModalTitle">➕ Нове правило</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_client_rule">
                    <input type="hidden" name="rule_id" id="ruleId">
                    <div class="mb-3">
                        <label class="form-label fw-600">📌 Заголовок правила</label>
                        <input type="text" name="rule_title" id="ruleTitle" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600">📝 Вміст правила</label>
                        <textarea name="rule_content" id="ruleContent" class="form-control" rows="6" required></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-600">🔢 Порядок відображення</label>
                            <input type="number" name="display_order" id="ruleOrder" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-600">✅ Статус</label>
                            <select name="is_active" id="ruleActive" class="form-select">
                                <option value="1">✓ Активне</option>
                                <option value="0">✗ Вимкнене</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="bi bi-check-circle me-2"></i> ЗБЕРЕГТИ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Clients data map (safe JSON injected from server)
    const clientsMap = <?= $clients_map_json_literal ?? '{}' ?>;

    function openReceipt(data, date, cashier) {
        let h = `<div class="text-center mb-4">
                    <h3 class="fw-bold m-0" style="font-family: 'Playfair Display', serif;">🦁 TARZAN PARK</h3>
                    <small class="text-muted">${date}</small>
                 </div>`;
        h += `<div class="fw-bold small mb-2 text-uppercase" style="color: #666;">🎟 Послуги:</div>`;
        if (data.services && data.services.length > 0) {
            data.services.forEach(s => { 
                h += `<div class="d-flex justify-content-between small mb-1"><span>${s.name} x${s.qty}</span> <span>${s.sum} ₴</span></div>`; 
            });
        }
        h += `<hr class="my-2">`;
        if (data.finance) {
            h += `<div class="d-flex justify-content-between small mb-1"><span>Видатки:</span> <span>-${data.finance.exp || 0} ₴</span></div>`;
            h += `<div class="d-flex justify-content-between small text-danger mb-3"><span>Інкасація:</span> <span>-${data.finance.encash || 0} ₴</span></div>`;
        }
        h += `<div class="mt-4 pt-3 border-top border-dark d-flex justify-content-between fs-5 fw-bold">
                <span>В КАСІ:</span> <span style="color: #00a86b;">${data.finance?.final || 0} ₴</span>
              </div>`;
        h += `<button class="btn btn-primary w-100 mt-4 py-2 rounded-3 fw-bold small" data-bs-dismiss="modal">ЗАКРИТИ</button>`;
        document.getElementById('receiptContent').innerHTML = h;
        new bootstrap.Modal(document.getElementById('modalReceipt')).show();
    }

    function addService() {
        document.getElementById('sId').value = '';
        document.getElementById('sTitle').textContent = '➕ Нова послуга';
        document.getElementById('sName').value = '';
        document.getElementById('sPrice').value = '';
        document.getElementById('sComm').value = '10';
        document.getElementById('sActiveBox').style.display = 'none';
        new bootstrap.Modal(document.getElementById('modalService')).show();
    }

    function editService(s) {
        document.getElementById('sId').value = s.id;
        document.getElementById('sTitle').textContent = '✏️ Редагування послуги';
        document.getElementById('sName').value = s.name;
        document.getElementById('sPrice').value = s.price;
        document.getElementById('sComm').value = s.commission_percent;
        document.getElementById('sActive').checked = (s.is_active == 1);
        document.getElementById('sActiveBox').style.display = 'block';
        new bootstrap.Modal(document.getElementById('modalService')).show();
    }

    function fillStaffEdit(staff) {
        document.getElementById('editStaffId').value = staff.id;
        document.getElementById('editStaffName').value = staff.full_name;
        document.getElementById('editStaffSalary').value = staff.base_salary;
    }

    // Open client modal and fill fields from object
    function openClient(c) {
        if(!c) return;
        document.getElementById('clientId').value = c.id || '';
        document.getElementById('clientFullName').value = c.full_name || '';
        document.getElementById('clientEmail').value = c.email || '';
        document.getElementById('clientPhone').value = c.phone || '';
        document.getElementById('clientBarcode').value = c.barcode || '';
        document.getElementById('clientSubAdult').value = c.sub_adult_balance ?? 0;
        document.getElementById('clientSubChild').value = c.sub_child_balance ?? 0;
        document.getElementById('clientVisits').value = c.loyalty_visits ?? 0;
        document.getElementById('clientStatus').value = c.status || 'verified';
        new bootstrap.Modal(document.getElementById('modalClient')).show();
    }

    // Open client by id using safe clientsMap
    function openClientById(id) {
        if(typeof clientsMap === 'undefined') return;
        const c = clientsMap[id] || null;
        openClient(c);
    }

    // Edit client rule
    function editClientRule(rule) {
        document.getElementById('ruleId').value = rule.id;
        document.getElementById('ruleModalTitle').textContent = '✏️ Редагування правила';
        document.getElementById('ruleTitle').value = rule.rule_title;
        document.getElementById('ruleContent').value = rule.rule_content;
        document.getElementById('ruleOrder').value = rule.display_order;
        document.getElementById('ruleActive').value = rule.is_active;
        new bootstrap.Modal(document.getElementById('modalClientRule')).show();
    }

    // Sidebar toggle for small screens
    document.addEventListener('DOMContentLoaded', function(){
        const toggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        if(toggle && sidebar) {
            toggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
    });
</script>
</body>
</html>