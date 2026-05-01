<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    // Шукаємо користувача
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $code = (string)rand(1000, 9999);
        
        try {
            // Записуємо код у базу
            $stmt = $pdo->prepare("UPDATE users SET reset_code = ? WHERE id = ?");
            $stmt->execute([$code, $user['id']]);

            // Відправка листа (з ігноруванням помилок з'єднання, щоб не було білого екрана)
            $payload = json_encode(['email' => $email, 'code' => $code, 'key' => GAS_KEY]);
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 10 // Чекаємо не більше 10 секунд
                ]
            ];
            $context = stream_context_create($opts);
            @file_get_contents(GAS_URL, false, $context);

            // Перенаправлення
            header("Location: reset_password.php?email=" . urlencode($email));
            exit;
            
        } catch (PDOException $e) {
            $msg = "❌ Помилка бази даних: " . $e->getMessage() . ". Перевірте, чи додана колонка reset_code.";
        }
    } else {
        $msg = "❌ Користувача з такою поштою не знайдено.";
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Відновлення пароля - Тарзан Парк</title>
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

        .reset-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 450px;
            padding: 20px;
        }

        .reset-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 50px 40px;
            border-radius: 25px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.2);
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

        .reset-header {
            text-align: center;
            margin-bottom: 40px;
            animation: slideDown 0.8s ease-out;
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

        .reset-icon {
            font-size: 3.5rem;
            margin-bottom: 15px;
            display: inline-block;
            animation: bounce 2.5s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .reset-title {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 10px 0;
        }

        .reset-subtitle {
            font-size: 0.9rem;
            color: #94a3b8;
            margin: 0;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 20px;
            animation: fadeIn 0.6s ease-out;
            animation-fill-mode: both;
        }

        .form-group:nth-child(1) { animation-delay: 0.3s; }
        .form-group:nth-child(2) { animation-delay: 0.4s; }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

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
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
            transform: translateY(-2px);
        }

        .form-label {
            color: #64748b;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-send {
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
            animation: fadeIn 0.6s ease-out 0.5s both;
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.35);
        }

        .btn-send::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .btn-send:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(59, 130, 246, 0.45);
        }

        .btn-send:hover::before {
            left: 100%;
        }

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

        .back-link {
            text-align: center;
            margin-top: 24px;
            animation: fadeIn 0.6s ease-out 0.6s both;
        }

        .back-link a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link a:hover {
            color: #8b5cf6;
            transform: translateX(-4px);
        }

        @media (max-width: 500px) {
            .reset-card {
                padding: 35px 25px;
            }

            .reset-title {
                font-size: 1.5rem;
            }

            .reset-icon {
                font-size: 3rem;
            }
        }
    </style>
</head>
<body>
    <div class="reset-wrapper">
        <div class="reset-card">
            <!-- Заголовок -->
            <div class="reset-header">
                <div class="reset-icon">🔐</div>
                <h1 class="reset-title">Відновіть доступ</h1>
                <p class="reset-subtitle">Введіть email для отримання коду скидання</p>
            </div>

            <!-- Помилка -->
            <?php if($msg): ?>
                <div class="alert-danger">
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <!-- Форма -->
            <form method="POST">
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-envelope me-2"></i>Email адреса</label>
                    <input type="email" name="email" class="form-control" placeholder="your@email.com" required>
                </div>

                <button type="submit" class="btn-send">
                    <i class="fas fa-paper-plane me-2"></i>НАДІСЛАТИ КОД
                </button>
            </form>

            <!-- Назад -->
            <div class="back-link">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i>Назад до входу
                </a>
            </div>
        </div>
    </div>
</body>
</html>