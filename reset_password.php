<?php
require_once 'config.php';
$email = $_GET['email'] ?? '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = $_POST['code'];
    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND reset_code = ?");
    $stmt->execute([$email, $code]);
    $user = $stmt->fetch();

    if ($user) {
        $pdo->prepare("UPDATE users SET password = ?, reset_code = NULL WHERE id = ?")->execute([$new_pass, $user['id']]);
        $msg = "✅ Пароль успішно змінено! <a href='index.php'>Увійти</a>";
    } else {
        $msg = "❌ Невірний код підтвердження.";
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новий пароль</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; display: flex; align-items: center; height: 100vh; }
        .card { border-radius: 25px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<div class="container" style="max-width: 400px;">
    <div class="card p-4">
        <h4 class="fw-bold text-center mb-4">Встановлення пароля</h4>
        <?php if($msg) echo "<div class='alert alert-info small'>$msg</div>"; ?>
        <form method="POST">
            <input type="text" name="code" class="form-control mb-2 text-center fw-bold" placeholder="Код з пошти" required>
            <input type="password" name="password" class="form-control mb-3" placeholder="Новий пароль" required>
            <button class="btn btn-dark w-100 py-2 fw-bold rounded-3">ЗМІНИТИ ПАРОЛЬ</button>
        </form>
    </div>
</div>
</body>
</html>