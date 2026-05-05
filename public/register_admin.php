<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

const ADMIN_PENDING_KEY = 'pending_admin_registration';
const ADMIN_CODE_LIFETIME_SECONDS = 600;
const ADMIN_CODE_TARGET_EMAIL = 'krispetrukhin@gmail.com';

function adminRenderPage(string $title, string $bodyHtml): void {
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<link rel="stylesheet" href="styles.css">';
    echo '<style>
        .content{max-width:480px;margin:20px auto;padding:20px;border:1px solid #ddd;border-radius:10px;background:#f9f9f9;}
        form{display:flex;flex-direction:column;gap:10px;}
        input{padding:10px;border:1px solid #ddd;border-radius:5px;}
        button{padding:10px;background:#e74c3c;color:#fff;border:none;border-radius:5px;cursor:pointer;}
        button.secondary{background:#7f8c8d;}
        .msg{padding:10px;border-radius:6px;margin-bottom:12px;}
        .msg.err{background:#ffebee;color:#b71c1c;}
        .msg.ok{background:#e8f5e9;color:#1b5e20;}
    </style></head><body>';
    require __DIR__ . '/includes/header_client.php';
    echo '<div class="content">' . $bodyHtml . '</div></body></html>';
}

function adminGenerateCode6(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendAdminPersonalCode(string $code): bool {
    $subject = 'Личный код сотрудника (регистрация администратора)';
    $body = '<p>Новый личный код сотрудника: <strong style="font-size:22px;letter-spacing:3px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . '<p>Код действует 10 минут.</p>';
    return send_email(ADMIN_CODE_TARGET_EMAIL, $subject, $body);
}

function adminFail(string $message): void {
    $body = '<h2>Ошибка регистрации администратора</h2>'
        . '<div class="msg err">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<a href="register.php?form_type=admin_register">Вернуться к регистрации администратора</a>';
    adminRenderPage('Ошибка регистрации администратора', $body);
    exit();
}

function backToAdminRegisterForm(array $old, array $errors): void {
    $_SESSION['admin_register_old'] = $old;
    $_SESSION['admin_register_errors'] = $errors;
    header('Location: register.php?form_type=admin_register');
    exit();
}

function adminRenderVerifyForm(string $message = '', bool $isError = false): void {
    $msg = '';
    if ($message !== '') {
        $msg = '<div class="msg ' . ($isError ? 'err' : 'ok') . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $body = '<h2>Подтверждение регистрации администратора</h2>'
        . '<p>Личный код отправлен на общую почту: <strong>' . htmlspecialchars(ADMIN_CODE_TARGET_EMAIL, ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . $msg
        . '<form method="post" action="register_admin.php">'
        . '<input type="hidden" name="action" value="verify_admin_code">'
        . '<label for="personal_code">Личный код сотрудника (6 цифр):</label>'
        . '<input id="personal_code" type="text" name="personal_code" pattern="\d{6}" maxlength="6" required autofocus>'
        . '<button type="submit">Подтвердить регистрацию</button>'
        . '</form>'
        . '<form method="post" action="register_admin.php" style="margin-top:8px;">'
        . '<input type="hidden" name="action" value="resend_admin_code">'
        . '<button type="submit" class="secondary">Запросить код снова</button>'
        . '</form>';
    adminRenderPage('Подтверждение регистрации администратора', $body);
}

$action = $_POST['action'] ?? 'start_admin_registration';

if ($action === 'start_admin_registration') {
    $login = htmlspecialchars(trim($_POST['login'] ?? ''), ENT_QUOTES, 'UTF-8');
    $pass = trim($_POST['pass'] ?? '');
    $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');

    $old = [
        'login' => $login,
        'pass' => htmlspecialchars($pass, ENT_QUOTES, 'UTF-8'),
        'phone' => $phone,
    ];
    $errors = [];

    if (mb_strlen($login) < 5 || mb_strlen($login) > 50) {
        $errors['login'] = 'Недопустимая длина логина.';
    }
    if (mb_strlen($pass) < 8 || mb_strlen($pass) > 72) {
        $errors['pass'] = 'Пароль должен быть от 8 до 72 символов.';
    }
    if (!preg_match('/^\+?\d{10,15}$/', $phone)) {
        $errors['phone'] = 'Некорректный номер телефона.';
    }

    if (!empty($errors)) {
        foreach (array_keys($errors) as $field) {
            $old[$field] = '';
        }
        backToAdminRegisterForm($old, $errors);
    }

    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM `admin_users` WHERE `login` = :login OR `phone` = :phone');
    $stmt->execute([':login' => $login, ':phone' => $phone]);
    if ($stmt->fetchColumn()) {
        $old['login'] = '';
        $old['phone'] = '';
        $errors['common'] = 'Администратор с таким логином или номером телефона уже существует.';
        backToAdminRegisterForm($old, $errors);
    }

    $personalCode = adminGenerateCode6();
    if (!sendAdminPersonalCode($personalCode)) {
        $errors['common'] = 'Не удалось отправить личный код на общую почту. Проверьте настройки почты.';
        backToAdminRegisterForm($old, $errors);
    }

    $_SESSION[ADMIN_PENDING_KEY] = [
        'login' => $login,
        'pass_hash' => password_hash($pass, PASSWORD_BCRYPT),
        'phone' => $phone,
        'personal_code_hash' => password_hash($personalCode, PASSWORD_BCRYPT),
        'expires_at' => time() + ADMIN_CODE_LIFETIME_SECONDS,
    ];

    adminRenderVerifyForm('Код отправлен.');
    exit();
}

if ($action === 'resend_admin_code') {
    $pending = $_SESSION[ADMIN_PENDING_KEY] ?? null;
    if (!$pending) {
        adminFail('Сессия регистрации не найдена. Заполните форму заново.');
    }
    $personalCode = adminGenerateCode6();
    if (!sendAdminPersonalCode($personalCode)) {
        adminRenderVerifyForm('Не удалось отправить код повторно.', true);
        exit();
    }
    $pending['personal_code_hash'] = password_hash($personalCode, PASSWORD_BCRYPT);
    $pending['expires_at'] = time() + ADMIN_CODE_LIFETIME_SECONDS;
    $_SESSION[ADMIN_PENDING_KEY] = $pending;
    adminRenderVerifyForm('Новый код отправлен.');
    exit();
}

if ($action === 'verify_admin_code') {
    $pending = $_SESSION[ADMIN_PENDING_KEY] ?? null;
    if (!$pending) {
        adminFail('Сессия регистрации не найдена. Заполните форму заново.');
    }

    $inputCode = trim((string)($_POST['personal_code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $inputCode)) {
        adminRenderVerifyForm('Введите корректный 6-значный код.', true);
        exit();
    }
    if (time() > (int)$pending['expires_at']) {
        adminRenderVerifyForm('Срок действия кода истек. Нажмите "Запросить код снова".', true);
        exit();
    }
    if (!password_verify($inputCode, (string)$pending['personal_code_hash'])) {
        adminRenderVerifyForm('Неверный код. Попробуйте еще раз.', true);
        exit();
    }

    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM `admin_users` WHERE `login` = :login OR `phone` = :phone');
    $stmt->execute([':login' => $pending['login'], ':phone' => $pending['phone']]);
    if ($stmt->fetchColumn()) {
        unset($_SESSION[ADMIN_PENDING_KEY]);
        adminFail('Администратор с таким логином или номером телефона уже существует.');
    }

    $sql = "INSERT INTO `admin_users` (`login`, `pass_hash`, `personal_code_hash`, `phone`, `created_at`)
            VALUES (:login, :pass, :personal_code, :phone, :created_at)";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':login' => $pending['login'],
        ':pass' => $pending['pass_hash'],
        ':personal_code' => $pending['personal_code_hash'],
        ':phone' => $pending['phone'],
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    unset($_SESSION[ADMIN_PENDING_KEY]);
    header('Location: index.php');
    exit();
}

adminFail('Неизвестное действие.');

