<?php
require_once 'config.php';

// Перевірка доступу (Касир або Власник)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['cashier', 'owner'])) {
    header("Location: index.php"); 
    exit;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сканер QR - Tarzan Park</title>
    
    <!-- Стилі: Bootstrap та іконки -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Бібліотека сканера -->
    <script src="https://unpkg.com/html5-qrcode"></script>

    <style>
        :root { --bg: #121416; --card-bg: #ffffff; --primary: #198754; }
        body { background: var(--bg); color: white; font-family: 'Inter', sans-serif; }
        
        /* Контейнер сканера */
        #reader { 
            width: 100%; 
            border-radius: 20px; 
            overflow: hidden; 
            border: none !important;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .scanner-wrapper { max-width: 500px; margin: 0 auto; padding: 20px; }
        
        /* Картка результату (вилітає знизу або після скану) */
        .result-card { 
            background: var(--card-bg); 
            color: #121416; 
            border-radius: 25px; 
            padding: 25px; 
            margin-top: 20px; 
            display: none; 
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .btn-action { 
            border-radius: 15px; 
            padding: 14px; 
            font-weight: 800; 
            font-size: 0.9rem;
            text-transform: uppercase; 
            transition: 0.2s;
        }

        .btn-action:active { transform: scale(0.95); }

        .sub-box { 
            background: #f8f9fa; 
            border-radius: 18px; 
            padding: 12px; 
            border: 2px solid transparent; 
        }

        .balance-num { font-size: 2.2rem; font-weight: 900; line-height: 1; margin-bottom: 2px; }
        
        .header-ui { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .back-link { color: white; text-decoration: none; font-weight: 600; opacity: 0.8; }
    </style>
</head>
<body>

<div class="scanner-wrapper">
    <div class="header-ui">
        <a href="dashboard_owner.php" class="back-link">
            <i class="bi bi-chevron-left me-1"></i> Назад
        </a>
        <h5 class="fw-bold m-0 text-success">TARZAN SCAN</h5>
        <div style="width: 50px;"></div> <!-- для центровки -->
    </div>

    <!-- Область камери -->
    <div id="reader"></div>

    <!-- Блок з інформацією про клієнта -->
    <div id="result" class="result-card shadow-lg">
        <!-- Сюди дані підтягне JavaScript -->
    </div>

    <div class="text-center mt-4 opacity-50">
        <small><i class="bi bi-camera me-1"></i> Наведіть камеру на QR клієнта</small>
    </div>
</div>

<script>
    // Ініціалізація сканера
    const html5QrcodeScanner = new Html5QrcodeScanner("reader", { 
        fps: 15, 
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
    });

    html5QrcodeScanner.render(onScanSuccess);

    function onScanSuccess(decodedText) {
        // Коли код зчитано, робимо запит до бази
        fetch('get_client_info.php?barcode=' + decodedText)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderClientUI(data, decodedText);
                } else {
                    alert("⚠️ Клієнта не знайдено: " + data.message);
                }
            })
            .catch(err => console.error("Помилка запиту:", err));
    }

    function renderClientUI(data, barcode) {
        const resultDiv = document.getElementById('result');
        resultDiv.style.display = 'block';

        // Формуємо вміст картки
        resultDiv.innerHTML = `
            <div class="text-center mb-4">
                <h3 class="fw-bold mb-1">${data.name}</h3>
                <span class="badge bg-light text-dark border">${barcode}</span>
            </div>

            <!-- БАЛАНС АБОНЕМЕНТІВ -->
            <div class="row g-2 mb-4">
                <div class="col-6">
                    <div class="sub-box text-center border-primary-subtle">
                        <small class="fw-bold text-primary text-uppercase" style="font-size:0.65rem">Дорослий</small>
                        <div class="balance-num text-primary">${data.sub_adult}</div>
                        <small class="text-muted">проходів</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sub-box text-center border-info-subtle">
                        <small class="fw-bold text-info text-uppercase" style="font-size:0.65rem">Дитячий</small>
                        <div class="balance-num text-info">${data.sub_child}</div>
                        <small class="text-muted">проходів</small>
                    </div>
                </div>
            </div>

            <!-- КНОПКИ ДІЙ -->
            <div class="d-grid gap-2">
                <input type="hidden" id="active-client-id" value="${data.id}">
                
                <p class="small fw-bold text-muted mb-1 text-center text-uppercase">Використати прохід</p>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-action flex-grow-1" onclick="processAction('use_sub_adult')">
                        ДОРОСЛИЙ
                    </button>
                    <button class="btn btn-info btn-action flex-grow-1 text-white" onclick="processAction('use_sub_child')">
                        ДИТЯЧИЙ
                    </button>
                </div>

                <p class="small fw-bold text-muted mb-1 mt-3 text-center text-uppercase">Продаж (10 візитів)</p>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-success btn-action flex-grow-1" onclick="processAction('buy_sub_adult')">
                        +10 ДОРОСЛИЙ
                    </button>
                    <button class="btn btn-outline-success btn-action flex-grow-1" onclick="processAction('buy_sub_child')">
                        +10 ДИТЯЧИЙ
                    </button>
                </div>

                <hr class="my-3">
                
                <button class="btn btn-warning btn-action py-3" onclick="processAction('loyalty_visit')">
                    <i class="bi bi-plus-lg me-2"></i> ЗАРАХУВАТИ ЗВИЧАЙНИЙ ВІЗИТ
                </button>

                <button class="btn btn-light mt-3 py-2 rounded-pill small fw-bold" onclick="location.reload()">
                    <i class="bi bi-arrow-repeat me-1"></i> Очистити / Наступний
                </button>
            </div>
        `;
    }

    function processAction(actionType) {
        const clientId = document.getElementById('active-client-id').value;

        // Підтвердження для важливих дій
        if (actionType.includes('buy') && !confirm('Клієнт оплатив абонемент?')) return;
        if (actionType.includes('use') && !confirm('Списати прохід?')) return;

        fetch('process_scan_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `client_id=${clientId}&type=${actionType}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert("✅ " + data.message);
                location.reload(); // Оновлюємо, щоб скинути сканер
            } else {
                alert("❌ Помилка: " + data.message);
            }
        })
        .catch(err => alert("Сталася помилка на сервері"));
    }
</script>

</body>
</html>