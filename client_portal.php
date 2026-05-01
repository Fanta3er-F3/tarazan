и <?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') { 
    header("Location: index.php"); exit; 
}

$uid = $_SESSION['user_id'];
$msg = '';

// Обробка налаштувань
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $news = isset($_POST['newsletter']) ? 1 : 0;
    $pdo->prepare("UPDATE users SET full_name = ?, email = ?, newsletter = ? WHERE id = ?")->execute([$name, $email, $news, $uid]);
    $_SESSION['full_name'] = $name;
    $msg = "✅ Профіль оновлено!";
}

// Обробка зміни паролю
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Перевірка поточного паролю
    $user_data = $pdo->query("SELECT password FROM users WHERE id = $uid")->fetch();
    if (password_verify($current_password, $user_data['password'])) {
        // Перевірка співпадіння нових паролів
        if ($new_password === $confirm_password) {
            // Перевірка міцності паролю
            if (strlen($new_password) >= 6) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$new_password_hash, $uid]);
                $msg = "✅ Пароль успішно змінено!";
            } else {
                $msg = "❌ Пароль повинен містити щонайменше 6 символів!";
            }
        } else {
            $msg = "❌ Нові паролі не співпадають!";
        }
    } else {
        $msg = "❌ Поточний пароль введений неправильно!";
    }
}

// Отримання даних
$user = $pdo->query("SELECT * FROM users WHERE id = $uid")->fetch();
// Отримання історії з двох таблиць: transactions і client_visits
$history_transactions = $pdo->query("SELECT * FROM transactions WHERE client_id = $uid AND type IN ('service_sale', 'ticket_small', 'ticket_large') ORDER BY created_at DESC LIMIT 10")->fetchAll();
$history_visits = $pdo->query("SELECT * FROM client_visits WHERE client_id = $uid ORDER BY visit_date DESC LIMIT 10")->fetchAll();
// Об'єднуємо історію з двох джерел
$history = array_merge($history_transactions, $history_visits);
// Сортуємо за датою (спочатку новіші)
usort($history, function($a, $b) {
    $dateA = isset($a['created_at']) ? strtotime($a['created_at']) : strtotime($a['visit_date']);
    $dateB = isset($b['created_at']) ? strtotime($b['created_at']) : strtotime($b['visit_date']);
    return $dateB - $dateA;
});
// Отримання активних правил для клієнтського кабінету (тільки унікальні)
$client_rules_raw = $pdo->query("SELECT * FROM client_rules WHERE is_active = 1 ORDER BY display_order ASC")->fetchAll();
// Видаляємо дублікати правил
$client_rules = [];
$rule_titles = [];
foreach ($client_rules_raw as $rule) {
    if (!in_array($rule['rule_title'], $rule_titles)) {
        $client_rules[] = $rule;
        $rule_titles[] = $rule['rule_title'];
    }
}

// Логіка звань
$v = (int)$user['loyalty_visits'];
if ($v < 5) { $rank = "Новачок 🍃"; $next = "Мауглі"; $target = 5; }
elseif ($v < 15) { $rank = "Мауглі 🐒"; $next = "Тарзан"; $target = 15; }
elseif ($v < 30) { $rank = "Тарзан 🦍"; $next = "Король Джунглів"; $target = 30; }
else { $rank = "Король Джунглів 👑"; $next = "Максимум"; $target = $v; }

$progress_rank = ($v / $target) * 100;
$to_free = 5 - ($v % 5);
if ($to_free == 5 && $v > 0) $to_free = 0;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tarzan Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="modern-style.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        :root { --tarzan-green: #198754; --jungle-dark: #121416; }
        body { background: #f8f9fa; font-family: 'Inter', system-ui, sans-serif; color: #333; padding-bottom: 80px; }
        
        /* Header */
        .header-bg { background: var(--tarzan-green); height: 180px; border-bottom-left-radius: 40px; border-bottom-right-radius: 40px; position: absolute; width: 100%; top: 0; z-index: -1; }
        .top-nav { padding: 20px; color: white; }

        /* Картки */
        .glass-card { background: white; border-radius: 30px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .loyalty-card { background: linear-gradient(135deg, #198754 0%, #146c43 100%); color: white; position: relative; overflow: hidden; }
        .loyalty-card::after { content: '🌳'; position: absolute; right: -20px; bottom: -20px; font-size: 8rem; opacity: 0.1; }

        /* QR */
        #qrcode { background: white; padding: 12px; border-radius: 20px; display: inline-block; }

        /* Ранги */
        .rank-badge { background: rgba(255,255,255,0.2); backdrop-filter: blur(5px); padding: 5px 15px; border-radius: 50px; font-size: 0.8rem; font-weight: bold; }
        
        /* Навігація знизу */
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: white; display: flex; justify-content: space-around; padding: 15px; border-top-left-radius: 25px; border-top-right-radius: 25px; box-shadow: 0 -5px 20px rgba(0,0,0,0.05); z-index: 1000; }
        .nav-item-btn { border: none; background: none; color: #adb5bd; transition: 0.3s; display: flex; flex-direction: column; align-items: center; font-size: 0.7rem; font-weight: bold; }
        .nav-item-btn.active { color: var(--tarzan-green); }
        .nav-item-btn i { font-size: 1.4rem; margin-bottom: 2px; }

        .history-item { border-left: 4px solid var(--tarzan-green); padding: 10px 15px; background: #fff; border-radius: 10px; margin-bottom: 10px; }
    </style>
</head>
<body>

<div class="header-bg"></div>

<div class="container">
    <div class="top-nav d-flex justify-content-between align-items-center items-center">
        <div>
            <h5 class="mb-0 fw-bold text-white">Привіт, <?= explode(' ', $user['full_name'])[0] ?>! 👋</h5>
            <span class="rank-badge"><?= $rank ?></span>
        </div>
        <div class="fs-4 text-white"><i class="bi bi-bell"></i></div>
    </div>

    <!-- Вкладка: ГОЛОВНА -->
    <div id="section-home" class="tab-section">
        <div class="card-modern loyalty-card p-4 mt-2 slide-up">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h6 class="text-white opacity-75 mb-1">Клубна картка</h6>
                    <h4 class="fw-bold text-white text-gradient">TARZAN PASS</h4>
                </div>
                <div class="text-end">
                    <span class="small opacity-75 text-white">ID Клієнта</span>
                    <div class="fw-bold text-white">#<?= str_pad($user['id'], 5, '0', STR_PAD_LEFT) ?></div>
                </div>
            </div>
            
            <div class="text-center mb-4">
                <div id="qrcode" class="mx-auto"></div>
                <div class="mt-3 fw-bold small text-white">Скан-код для проходу</div>
            </div>

            <div class="row text-center g-2">
                <div class="col-6">
                    <div class="bg-white bg-opacity-10 p-2 rounded-3">
                        <small class="opacity-75 d-block text-white">Візитів</small>
                        <span class="fs-4 fw-bold text-white"><?= $v ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="bg-white bg-opacity-10 p-2 rounded-3">
                        <small class="opacity-75 d-block text-white">До подарунка</small>
                        <span class="fs-4 fw-bold text-white"><?= $to_free ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-modern glass-card p-4 slide-up" style="animation-delay: 0.2s;">
            <h6 class="fw-bold mb-3 text-gradient"><i class="bi bi-star-fill text-warning me-2"></i>Шлях до звання <?= $next ?></h6>
            <div class="progress-modern mb-2">
                <div class="progress-bar-modern" style="width: <?= $progress_rank ?>%"></div>
            </div>
            <small class="text-muted">Залишилось відвідати парк <b><?= $target - $v ?></b> разів до нового рівня.</small>
        </div>
    </div>

    <!-- Вкладка: ІСТОРІЯ -->
    <div id="section-history" class="tab-section" style="display: none;">
        <h5 class="fw-bold mb-4 mt-3 text-gradient">📜 Журнал пригод</h5>
        <?php if(empty($history)): ?>
            <div class="text-center py-5 slide-up">
                <i class="bi bi-calendar-x opacity-25" style="font-size: 4rem;"></i>
                <p class="text-muted mt-3">Історія поки порожня</p>
            </div>
        <?php else: ?>
            <?php foreach($history as $index => $h): ?>
                <div class="card-modern history-item shadow-sm slide-up" style="animation-delay: <?= $index * 0.1 ?>s;">
                    <div class="d-flex justify-content-between items-center">
                        <span class="fw-bold">🎟 <?= isset($h['type']) ? 'Покупка послуги' : 'Візит у парк' ?></span>
                        <span class="text-success fw-bold">+1 візит</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-2">
                        <span><i class="bi bi-calendar me-1"></i> <?= date('d.m.Y H:i', strtotime(isset($h['created_at']) ? $h['created_at'] : $h['visit_date'])) ?></span>
                        <span><i class="bi bi-cash me-1"></i> <?= isset($h['amount']) ? $h['amount'] . ' ₴' : 'Безкоштовно' ?></span>
                    </div>
                    <?php if(isset($h['service_type'])): ?>
                        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i> Послуга: <?= htmlspecialchars($h['service_type']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Вкладка: НАЛАШТУВАННЯ -->
    <div id="section-settings" class="tab-section" style="display: none;">
        <div class="card-modern glass-card p-4 mt-3 slide-up">
            <h5 class="fw-bold mb-4 text-gradient">⚙️ Налаштування профілю</h5>
            <?php if($msg) echo "<div class='alert-modern alert-success small py-2'>$msg</div>"; ?>
            <form method="POST">
                <input type="hidden" name="update_profile" value="1">
                <div class="form-group">
                    <label class="form-label">👤 Повне ім'я</label>
                    <input type="text" name="full_name" class="form-control input-modern" value="<?= htmlspecialchars($user['full_name']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">✉️ Email для новин</label>
                    <input type="email" name="email" class="form-control input-modern" value="<?= htmlspecialchars($user['email']) ?>">
                </div>
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" name="newsletter" id="nws" <?= $user['newsletter']?'checked':'' ?>>
                    <label class="form-check-label small" for="nws">Отримувати спецпропозиції</label>
                </div>
                <button type="submit" class="btn-modern btn-success w-100 py-3 fw-bold">💾 ЗБЕРЕГТИ ЗМІНИ</button>
            </form>

            <hr class="my-4">

            <!-- Зміна паролю -->
            <div class="mt-4">
                <h6 class="fw-bold mb-3 text-gradient">🔒 Зміна паролю</h6>
                <form method="POST" class="change-password-form">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-group">
                        <label class="form-label">🔑 Поточний пароль</label>
                        <input type="password" name="current_password" class="form-control input-modern" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🔑 Новий пароль</label>
                        <input type="password" name="new_password" class="form-control input-modern" id="newPassword" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🔑 Підтвердіть новий пароль</label>
                        <input type="password" name="confirm_password" class="form-control input-modern" id="confirmPassword" required>
                        <div id="passwordMatch" class="small mt-1"></div>
                    </div>
                    <button type="submit" class="btn-modern btn-primary w-100 py-3 fw-bold mt-3">🔄 ЗМІНИТИ ПАРОЛЬ</button>
                </form>
            </div>

            <hr class="my-4">
            <a href="logout.php" class="btn-modern btn-ghost w-100 fw-bold">🚪 ВИЙТИ З АККАУНТУ</a>
        </div>
    </div>

    <!-- Вкладка: ПРАВИЛА -->
    <div id="section-rules" class="tab-section" style="display: none;">
        <div class="card-modern glass-card p-4 mt-3 slide-up">
            <h5 class="fw-bold mb-4 text-gradient">📜 Правила безпеки</h5>
            <?php if(count($client_rules) > 0): ?>
                <?php foreach($client_rules as $index => $rule): ?>
                    <div class="card-modern mb-3 slide-up" style="animation-delay: <?= $index * 0.1 ?>s;">
                        <div class="d-flex align-items-start gap-3 mb-2">
                            <span class="badge-modern badge-success"><?= $index + 1 ?></span>
                            <h6 class="fw-bold mb-0 text-gradient"><?= htmlspecialchars($rule['rule_title']) ?></h6>
                        </div>
                        <div class="small text-muted" style="white-space: pre-line;"><?= htmlspecialchars($rule['rule_content']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5 slide-up">
                    <i class="bi bi-shield-exclamation text-warning" style="font-size: 4rem;"></i>
                    <p class="text-muted mt-3">Правила безпеки поки не додані</p>
                </div>
            <?php endif; ?>
            <div class="alert-modern alert-warning mt-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Пам'ятайте, безпека починається з вас! Гарного відпочинку.
            </div>
        </div>
    </div>

</div>

<!-- НИЖНЯ НАВІГАЦІЯ -->
<nav class="bottom-nav shadow-lg">
    <button class="nav-item-btn active" onclick="showTab('home', this)">
        <i class="bi bi-house-door-fill"></i><span>Головна</span>
    </button>
    <button class="nav-item-btn" onclick="showTab('history', this)">
        <i class="bi bi-clock-history"></i><span>Історія</span>
    </button>
    <button class="nav-item-btn" onclick="showTab('rules', this)">
        <i class="bi bi-shield-check"></i><span>Правила</span>
    </button>
    <button class="nav-item-btn" onclick="showTab('settings', this)">
        <i class="bi bi-person-fill"></i><span>Профіль</span>
    </button>
</nav>

<script>
    function showTab(id, btn) {
        document.querySelectorAll('.tab-section').forEach(s => s.style.display = 'none');
        document.querySelectorAll('.nav-item-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('section-' + id).style.display = 'block';
        btn.classList.add('active');
        window.scrollTo(0,0);
    }

    new QRCode(document.getElementById("qrcode"), {
        text: "<?= $user['id'] ?>",
        width: 150,
        height: 150,
        colorDark : "#198754",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });

    // Перевірка співпадіння паролів
    document.addEventListener('DOMContentLoaded', function() {
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        const passwordMatch = document.getElementById('passwordMatch');

        if (newPassword && confirmPassword && passwordMatch) {
            function checkPasswordMatch() {
                if (newPassword.value === confirmPassword.value && newPassword.value !== '') {
                    passwordMatch.textContent = '✅ Паролі співпадають';
                    passwordMatch.style.color = '#28a745';
                } else if (confirmPassword.value !== '' && newPassword.value !== confirmPassword.value) {
                    passwordMatch.textContent = '❌ Паролі не співпадають';
                    passwordMatch.style.color = '#dc3545';
                } else {
                    passwordMatch.textContent = '';
                }
            }

            newPassword.addEventListener('keyup', checkPasswordMatch);
            confirmPassword.addEventListener('keyup', checkPasswordMatch);
        }
    });
</script>

</body>
</html>