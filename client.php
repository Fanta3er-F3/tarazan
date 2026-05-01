<?php
require_once 'config.php';

$error = '';
$success = '';

// --- 1. ОБРОБКА РЕЄСТРАЦІЇ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'register') {
    $phone = trim($_POST['phone']);
    $name = trim($_POST['full_name']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $barcode = 'TZ-' . mt_rand(100000, 999999);

    $check = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
    $check->execute([$phone]);
    if ($check->fetch()) {
        $error = "❌ Користувач з таким номером вже існує! Спробуйте увійти.";
    } else {
        // Додано колонки абонементів у дефолтні значення (0)
        $stmt = $pdo->prepare("INSERT INTO users (role, phone, password, full_name, barcode, loyalty_visits, sub_adult_balance, sub_child_balance) VALUES ('client', ?, ?, ?, ?, 0, 0, 0)");
        $stmt->execute([$phone, $password, $name, $barcode]);
        
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['role'] = 'client';
        $_SESSION['full_name'] = $name;
        header("Location: client.php");
        exit;
    }
}

// --- 2. ОБРОБКА ВХОДУ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'login') {
    $phone = trim($_POST['phone']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? AND role = 'client'");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        header("Location: client.php");
        exit;
    } else {
        $error = "❌ Невірний номер або пароль!";
    }
}

// --- 3. ДАНІ АВТОРИЗОВАНОГО КЛІЄНТА ---
if (isset($_SESSION['user_id']) && $_SESSION['role'] == 'client') {
    // Додавання дитини
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_child') {
        $child_name = trim($_POST['child_name']);
        $child_barcode = 'CH-' . mt_rand(100000, 999999);
        
        $stmt = $pdo->prepare("INSERT INTO family_members (parent_id, child_name, barcode) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $child_name, $child_barcode]);
        $success = "✅ Дитину успішно додано!";
    }

    // Отримуємо всі дані клієнта (включаючи баланс абонементів)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $client_data = $stmt->fetch();

    // Отримуємо дітей
    $stmt = $pdo->prepare("SELECT * FROM family_members WHERE parent_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $children = $stmt->fetchAll();

    // Поріг бонусів
    $stmt = $pdo->query("SELECT value FROM system_settings WHERE key = 'bonus_threshold'");
    $target = (int)$stmt->fetchColumn() ?: 5;

    $current_visits = (int)$client_data['loyalty_visits'];
    $progress_percent = ($current_visits / $target) * 100;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мій Кабінет - Тарзан Парк</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="modern-style.css?v=2026.1.0" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1a2e5e 50%, #0f3a5f 100%);
            min-height: 100vh;
            position: relative;
        }

        /* Анімаційні елементи фону */
        body::before {
            content: '';
            position: fixed;
            top: -200px;
            left: -100px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            animation: float 8s ease-in-out infinite;
            z-index: 0;
            pointer-events: none;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, -30px); }
        }

        .container { position: relative; z-index: 1; }

        /* Шапка */
        .header-banner {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            color: white;
            padding: 30px 20px;
            border-radius: 0;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(59, 130, 246, 0.2);
            position: relative;
            overflow: hidden;
        }

        .header-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        .header-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-welcome h4 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0;
        }

        .header-welcome .barcode {
            font-size: 0.85rem;
            opacity: 0.9;
            font-weight: 500;
        }

        .btn-logout {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.4);
            color: white;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 700;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.85rem;
        }

        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-2px);
        }

        /* Карточки */
        .modern-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideUp 0.6s ease-out;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modern-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(31, 38, 135, 0.2);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* QR Картка */
        .qr-box {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 20px;
            border: 2px dashed #e2e8f0;
            transition: all 0.3s;
        }

        .qr-box:hover {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .qr-code {
            width: 180px;
            height: 180px;
            margin: 0 auto;
            border-radius: 16px;
            padding: 8px;
            background: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
        }

        .barcode-id {
            font-size: 1.3rem;
            font-weight: 800;
            color: #1e293b;
            margin-top: 15px;
            letter-spacing: 1px;
        }

        .barcode-hint {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 8px;
        }

        /* Абонементи */
        .subscription-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 0;
        }

        .subscription-card {
            background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 100%);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 2px solid #c7d2fe;
            transition: all 0.3s;
        }

        .subscription-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        .sub-label {
            font-size: 0.8rem;
            color: #6366f1;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 10px;
        }

        .sub-badge {
            font-size: 2.5rem;
            font-weight: 800;
            color: #3b82f6;
            margin: 8px 0;
        }

        .sub-hint {
            font-size: 0.75rem;
            color: #6b7280;
        }

        /* Бонусна програма */
        .bonus-section h5 {
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bonus-badge {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
            border-radius: 8px;
            padding: 4px 12px;
            font-weight: 700;
            font-size: 0.85rem;
            margin-left: auto;
        }

        .progress-bar-custom {
            height: 14px;
            border-radius: 8px;
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .progress-bg {
            background: #f1f5f9;
            border-radius: 8px;
            overflow: hidden;
        }

        .bonus-hint {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 12px;
            line-height: 1.5;
        }

        /* Діти */
        .children-header {
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .child-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 2px solid #fcd34d;
            transition: all 0.3s;
        }

        .child-card:hover {
            transform: translateX(4px);
            box-shadow: 0 6px 16px rgba(250, 204, 21, 0.25);
        }

        .child-info .child-name {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .child-barcode {
            font-size: 0.75rem;
            color: #92400e;
            font-family: 'Courier New', monospace;
        }

        .child-qr-btn {
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            border: none;
            color: white;
            border-radius: 10px;
            padding: 8px 14px;
            font-weight: 700;
            font-size: 0.75rem;
            transition: all 0.3s;
        }

        .child-qr-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3);
        }

        /* Форма додавання */
        .add-child-form {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            animation: slideUp 0.6s ease-out 0.3s both;
        }

        .form-control {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 500;
            transition: all 0.3s;
            flex: 1;
        }

        .form-control:focus {
            background: white;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }

        .btn-add {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            border: none;
            color: white;
            border-radius: 12px;
            padding: 12px 20px;
            font-weight: 700;
            transition: all 0.3s;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        /* Таби */
        .tab-content {
            margin-top: 20px;
        }

        .nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: #94a3b8;
            font-weight: 700;
            padding: 12px 16px;
            transition: all 0.3s;
            border-radius: 0;
        }

        .nav-tabs .nav-link.active {
            border-bottom-color: #3b82f6;
            color: #3b82f6;
            background: transparent;
        }

        /* Адаптивність */
        @media (max-width: 600px) {
            .header-banner {
                margin-bottom: 20px;
                padding: 20px;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .modern-card {
                margin-bottom: 15px;
                padding: 20px;
            }

            .subscription-row {
                grid-template-columns: 1fr;
            }

            .qr-code {
                width: 140px;
                height: 140px;
            }
        }

        /* Контейнер */
        .wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            padding-bottom: 40px;
        }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['user_id'])): ?>
    <!-- ФОРМИ ВХОДУ/РЕЄСТРАЦІЇ -->
    <div class="wrapper">
        <div class="text-center mb-4" style="animation: slideDown 0.6s ease-out;">
            <div style="font-size: 4rem; margin-bottom: 15px;">🌳</div>
            <h1 style="font-size: 2rem; font-weight: 800; background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">TARZAN PARK</h1>
            <p style="color: #94a3b8; font-weight: 500;">Парк незабутніх пригод</p>
        </div>

        <div class="modern-card">
            <ul class="nav nav-tabs nav-fill mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-pane" type="button" role="tab">
                        <i class="fas fa-sign-in-alt me-2"></i>ВХІД
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-pane" type="button" role="tab">
                        <i class="fas fa-user-plus me-2"></i>РЕЄСТР.
                    </button>
                </li>
            </ul>

            <?php if($error): ?>
                <div style="background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%); border-radius: 14px; color: #7f1d1d; font-weight: 600; padding: 14px 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; animation: shake 0.5s ease-out;">
                    <span>⚠️</span><?= $error ?>
                </div>
            <?php endif; ?>

            <div class="tab-content">
                <!-- ФОРМА ВХОДУ -->
                <div class="tab-pane fade show active" id="login-pane" role="tabpanel">
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group" style="animation: fadeIn 0.6s ease-out 0.2s both;">
                            <input type="text" name="phone" class="form-control" placeholder="📱 Номер телефону" required>
                        </div>
                        <div class="form-group" style="animation: fadeIn 0.6s ease-out 0.3s both;">
                            <input type="password" name="password" class="form-control" placeholder="🔒 Пароль" required>
                        </div>
                        <button type="submit" class="btn-add w-100" style="animation: fadeIn 0.6s ease-out 0.4s both;">
                            <i class="fas fa-sign-in-alt me-2"></i>УВІЙТИ
                        </button>
                    </form>
                </div>

                <!-- ФОРМА РЕЄСТРАЦІЇ -->
                <div class="tab-pane fade" id="register-pane" role="tabpanel">
                    <form method="POST">
                        <input type="hidden" name="action" value="register">
                        <div class="form-group" style="animation: fadeIn 0.6s ease-out 0.2s both;">
                            <input type="text" name="full_name" class="form-control" placeholder="👤 Ваше ПІБ" required>
                        </div>
                        <div class="form-group" style="animation: fadeIn 0.6s ease-out 0.3s both;">
                            <input type="text" name="phone" class="form-control" placeholder="📱 Номер телефону" required>
                        </div>
                        <div class="form-group" style="animation: fadeIn 0.6s ease-out 0.4s both;">
                            <input type="password" name="password" class="form-control" placeholder="🔒 Придумайте пароль" required>
                        </div>
                        <button type="submit" class="btn-add w-100" style="animation: fadeIn 0.6s ease-out 0.5s both;">
                            <i class="fas fa-check me-2"></i>СТВОРИТИ КАРТКУ
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ОСОБИСТИЙ КАБІНЕТ -->
    <div class="header-banner">
        <div class="header-content">
            <div class="header-welcome">
                <h4>Вітаємо, <?= htmlspecialchars(explode(' ', $client_data['full_name'])[0]) ?>! 👋</h4>
                <div class="barcode">ID: <?= $client_data['barcode'] ?></div>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-2"></i>Вийти</a>
        </div>
    </div>

    <div class="wrapper">
        <!-- QR КАРТКА -->
        <div class="modern-card" style="animation-delay: 0.1s;">
            <div class="qr-box">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?= urlencode($client_data['barcode']) ?>" class="qr-code" alt="QR код">
                <div class="barcode-id"><?= $client_data['barcode'] ?></div>
                <p class="barcode-hint"><i class="fas fa-camera me-1"></i>Скануйте на касі при оплаті</p>
            </div>
        </div>

        <!-- АБОНЕМЕНТИ -->
        <div class="modern-card" style="animation-delay: 0.2s;">
            <h5 class="mb-4"><i class="fas fa-ticket-alt me-2" style="color: #3b82f6;"></i><strong>Ваші абонементи</strong></h5>
            <div class="subscription-row">
                <div class="subscription-card">
                    <div class="sub-label">👨 Дорослий</div>
                    <div class="sub-badge"><?= $client_data['sub_adult_balance'] ?></div>
                    <div class="sub-hint">проходів</div>
                </div>
                <div class="subscription-card">
                    <div class="sub-label">👧 Дитячий</div>
                    <div class="sub-badge"><?= $client_data['sub_child_balance'] ?></div>
                    <div class="sub-hint">проходів</div>
                </div>
            </div>
            <?php if($client_data['sub_adult_balance'] == 0 && $client_data['sub_child_balance'] == 0): ?>
                <div style="text-align: center; margin-top: 15px; color: #94a3b8; font-size: 0.85rem; padding: 12px; background: #f8fafc; border-radius: 12px;">
                    ℹ️ У вас немає активних абонементів.<br>Придбайте 10 проходів на касі зі знижкою!
                </div>
            <?php endif; ?>
        </div>

        <!-- БОНУСНА ПРОГРАМА -->
        <div class="modern-card bonus-section" style="animation-delay: 0.3s;">
            <div style="display: flex; align-items: center; margin-bottom: 16px;">
                <h5 class="mb-0"><i class="fas fa-gift me-2" style="color: #10b981;"></i><strong>Бонусний прогрес</strong></h5>
                <span class="bonus-badge"><?= $current_visits ?> / <?= $target ?></span>
            </div>
            <div class="progress-bg">
                <div class="progress-bar-custom" style="width: <?= $progress_percent ?>%;"></div>
            </div>
            <p class="bonus-hint">
                <i class="fas fa-star me-2" style="color: #f59e0b;"></i>Кожне <strong><?= $target ?>-те</strong> проходження траси — <strong style="color: #10b981;">БЕЗКОШТОВНЕ!</strong>
            </p>
        </div>

        <!-- ДІТИ -->
        <div class="modern-card" style="animation-delay: 0.4s;">
            <div class="children-header">
                <span><i class="fas fa-child me-2"></i><strong>Коди для дітей</strong></span>
            </div>

            <?php if(count($children) > 0): ?>
                <?php foreach ($children as $child): ?>
                    <div class="child-card">
                        <div class="child-info">
                            <div class="child-name"><?= htmlspecialchars($child['child_name']) ?></div>
                            <div class="child-barcode"><?= $child['barcode'] ?></div>
                        </div>
                        <a href="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($child['barcode']) ?>" target="_blank" class="child-qr-btn">
                            <i class="fas fa-qrcode me-1"></i>QR
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="POST" class="add-child-form">
                <input type="hidden" name="action" value="add_child">
                <input type="text" name="child_name" class="form-control" placeholder="Ім'я дитини" required>
                <button type="submit" class="btn-add"><i class="fas fa-plus me-1"></i>Додати</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>