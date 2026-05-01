<?php
require_once 'config.php';

// --- НАЛАШТУВАННЯ ГУГЛ-ПОШТИ ---
define('GAS_URL', 'https://script.google.com/macros/s/AKfycbx5PboV-k9Cedw2JRptrmnCgoX8FWEu6IALoSDGNYmyZ0R5iatBJyzZM81sclKwFzMSaw/exec');
define('GAS_KEY', 'tarzan777'); // Має бути таким же, як у скрипті Google

// Якщо залогінений - перекидаємо за роллю
if (isset($_SESSION['user_id'])) {
    $r = $_SESSION['role'];
    header("Location: " . ($r == 'owner' ? "dashboard_owner.php" : ($r == 'client' ? "client_portal.php" : "bulk_entry.php")));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $phone = preg_replace('/\D/', '', $_POST['phone']);
    $password = $_POST['password'];

    // --- ЛОГІКА ВХОДУ ---
    if ($action == 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] == 'unverified') {
                $_SESSION['temp_phone'] = $phone;
                header("Location: verify.php"); exit;
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            header("Location: index.php"); exit;
        } else {
            $error = "Невірний номер або пароль";
        }
    }

    // --- ЛОГІКА РЕЄСТРАЦІЇ ---
    if ($action == 'register') {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $code = rand(1000, 9999);

        $check = $pdo->prepare("SELECT id FROM users WHERE phone = ? OR email = ?");
        $check->execute([$phone, $email]);
        
        if ($check->fetch()) {
            $error = "Такий номер або Email вже є в системі!";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, phone, email, password, role, status, verify_code) VALUES (?, ?, ?, ?, 'client', 'unverified', ?)");
            $stmt->execute([$full_name, $phone, $email, $hashed, $code]);

            // Відправка листа через Google Script
            $payload = json_encode(['email' => $email, 'code' => $code, 'key' => GAS_KEY]);
            $opts = ['http' => ['method' => 'POST', 'header' => "Content-type: application/json\r\n", 'content' => $payload]];
            @file_get_contents(GAS_URL, false, stream_context_create($opts));

            $_SESSION['temp_phone'] = $phone;
            header("Location: verify.php"); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тарзан Парк - Авторизація</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="modern-style.css?v=2026.1.0" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1a2e5e 50%, #0f3a5f 100%);
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden;
        }

        /* Анімаційні елементи фону */
        body::before, body::after {
            content: '';
            position: fixed;
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            top: -200px;
            left: -100px;
            animation: float 8s ease-in-out infinite;
        }

        body::after {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(168, 85, 247, 0.1) 0%, transparent 70%);
            bottom: -150px;
            right: -100px;
            animation: float 10s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, -30px); }
        }

        .auth-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 450px;
            padding: 20px;
        }

        .auth-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 50px 40px;
            border-radius: 25px;
            box-shadow: 
                0 8px 32px rgba(31, 38, 135, 0.2),
                0 0 1px rgba(255, 255, 255, 0.5) inset;
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideUp 0.8s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .brand-header {
            text-align: center;
            margin-bottom: 40px;
            animation: slideDown 0.8s ease-out;
        }

        .brand-icon {
            font-size: 4rem;
            margin-bottom: 15px;
            display: inline-block;
            animation: bounce 2.5s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .brand-title {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }

        .brand-subtitle {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 5px;
            font-weight: 500;
        }

        /* Таби */
        .tab-switcher {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            padding: 6px;
            border-radius: 16px;
            animation: fadeIn 0.6s ease-out 0.2s both;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .tab-btn {
            flex: 1;
            padding: 12px 16px;
            border: none;
            background: transparent;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            color: #64748b;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .tab-btn.active {
            background: white;
            color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
            transform: translateY(-2px);
        }

        .tab-btn:hover:not(.active) {
            color: #475569;
            transform: translateY(-1px);
        }

        /* Помилки */
        .alert-danger {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            border: none;
            border-radius: 14px;
            color: #7f1d1d;
            font-weight: 600;
            padding: 14px 16px;
            margin-bottom: 24px;
            animation: shake 0.5s ease-out;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }

        .alert-danger::before {
            content: '⚠️';
        }

        /* Форми */
        .form-group {
            margin-bottom: 18px;
            animation: fadeIn 0.6s ease-out;
            animation-fill-mode: both;
        }

        .form-group:nth-child(1) { animation-delay: 0.3s; }
        .form-group:nth-child(2) { animation-delay: 0.4s; }
        .form-group:nth-child(3) { animation-delay: 0.5s; }
        .form-group:nth-child(4) { animation-delay: 0.6s; }

        .form-control {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 18px;
            font-weight: 500;
            font-size: 0.95rem;
            color: #1e293b;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-control::placeholder {
            color: #94a3b8;
            font-weight: 500;
        }

        .form-control:focus {
            background: white;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1), inset 0 2px 4px rgba(59, 130, 246, 0.05);
            outline: none;
            transform: translateY(-2px);
        }

        /* Кнопка */
        .btn-auth {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            border: none;
            border-radius: 14px;
            padding: 14px 24px;
            font-weight: 800;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: white;
            width: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            margin-top: 8px;
            animation: fadeIn 0.6s ease-out 0.7s both;
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.35);
        }

        .btn-auth::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .btn-auth:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(59, 130, 246, 0.45);
        }

        .btn-auth:hover::before {
            left: 100%;
        }

        .btn-auth:active {
            transform: translateY(-1px);
        }

        /* Допоміжні посилання */
        .help-links {
            text-align: center;
            margin-top: 20px;
            animation: fadeIn 0.6s ease-out 0.8s both;
        }

        .help-links a {
            color: #3b82f6;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .help-links a:hover {
            color: #8b5cf6;
            text-decoration: underline;
        }

        /* Адаптивність */
        @media (max-width: 500px) {
            .auth-card {
                padding: 35px 25px;
            }

            .brand-title {
                font-size: 1.5rem;
            }

            .brand-icon {
                font-size: 3rem;
            }

            .tab-btn {
                font-size: 0.85rem;
                padding: 10px 12px;
            }
        }

        /* Завантаження */
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .spinner {
            border: 3px solid rgba(59, 130, 246, 0.1);
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- Заголовок бренду -->
        <div class="brand-header">
            <div class="brand-icon">🌳</div>
            <h1 class="brand-title">TARZAN PARK</h1>
            <p class="brand-subtitle">Парк незабутніх пригод</p>
        </div>

        <!-- Таби для перемикання -->
        <div class="tab-switcher">
            <button type="button" class="tab-btn active" data-tab="login" onclick="switchTab('login')">
                <i class="fas fa-sign-in-alt"></i> ВХІД
            </button>
            <button type="button" class="tab-btn" data-tab="register" onclick="switchTab('register')">
                <i class="fas fa-user-plus"></i> РЕЄСТР.
            </button>
        </div>

        <!-- Повідомлення про помилку -->
        <?php if($error): ?>
            <div class="alert-danger">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- ФОРМА ВХОДУ -->
        <form method="POST" id="form-login" class="auth-form active">
            <input type="hidden" name="action" value="login">
            
            <div class="form-group">
                <input type="text" name="phone" class="form-control phone-input" placeholder="📱 Номер телефону" required>
            </div>

            <div class="form-group">
                <input type="password" name="password" class="form-control" placeholder="🔒 Пароль" required>
            </div>

            <button type="submit" class="btn-auth">
                <span class="btn-text">УВІЙТИ</span>
            </button>

            <div class="help-links">
                <a href="forgot_password.php"><i class="fas fa-key"></i> Забули пароль?</a>
            </div>
        </form>

        <!-- ФОРМА РЕЄСТРАЦІЇ -->
        <form method="POST" id="form-register" class="auth-form" style="display: none;">
            <input type="hidden" name="action" value="register">
            
            <div class="form-group">
                <input type="text" name="full_name" class="form-control" placeholder="👤 Ваше ім'я" required>
            </div>

            <div class="form-group">
                <input type="email" name="email" class="form-control" placeholder="✉️ Email" required>
            </div>

            <div class="form-group">
                <input type="text" name="phone" class="form-control phone-input" placeholder="📱 Номер телефону" required>
            </div>

            <div class="form-group">
                <input type="password" name="password" class="form-control" placeholder="🔒 Пароль" required>
            </div>

            <button type="submit" class="btn-auth">
                <span class="btn-text">ЗАРЕЄСТРУВАТИСЯ</span>
            </button>

            <div class="help-links">
                <p style="color: #94a3b8; font-size: 0.8rem; margin-top: 12px;">
                    Реєструючись, ви приймаєте <a href="#">умови користування</a>
                </p>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
    // Перемикання табів
    function switchTab(tabName) {
        const forms = document.querySelectorAll('.auth-form');
        const btns = document.querySelectorAll('.tab-btn');

        forms.forEach(form => form.style.display = 'none');
        btns.forEach(btn => btn.classList.remove('active'));

        const activeForm = document.getElementById('form-' + tabName);
        const activeBtn = document.querySelector(`[data-tab="${tabName}"]`);

        if (activeForm) activeForm.style.display = 'block';
        if (activeBtn) activeBtn.classList.add('active');

        // Анімація появи
        if (activeForm) {
            activeForm.style.animation = 'fadeIn 0.3s ease-out';
        }
    }

    // Форматування номера телефону
    document.querySelectorAll('.phone-input').forEach(input => {
        input.addEventListener('focus', function() {
            if (!this.value) this.value = '+380';
        });

        input.addEventListener('input', function() {
            let val = this.value.replace(/\D/g, '');
            if (!val.startsWith('380')) val = '380' + val;
            val = val.substring(0, 12);
            this.value = val.length > 0 ? '+' + val : '';
        });

        input.addEventListener('blur', function() {
            if (this.value === '+') this.value = '';
        });
    });

    // Плавна поява при завантаженні
    document.addEventListener('DOMContentLoaded', function() {
        // Додаємо анімацію затримки для елементів
        const formGroups = document.querySelectorAll('.form-group');
        formGroups.forEach((group, index) => {
            group.style.animationDelay = (0.3 + index * 0.1) + 's';
        });
    });

    // Ефект фокусу для форми
    const form = document.querySelector('.auth-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const btn = this.querySelector('.btn-auth');
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<div class="spinner" style="border: 3px solid rgba(59, 130, 246, 0.1); border-top: 3px solid white; display: inline-block; width: 20px; height: 20px; border-radius: 50%; margin: 0 auto;"></div>';
        });
    }

    // Плавна прокрутка при помилці
    const alertDiv = document.querySelector('.alert-danger');
    if (alertDiv) {
        alertDiv.style.animation = 'slideDown 0.4s ease-out';
    }
</script>

</body>
</html>