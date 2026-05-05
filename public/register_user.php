<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

const REG_PENDING_KEY = 'pending_user_registration';
const CODE_LIFETIME_SECONDS = 600; // 10 минут

function renderPage(string $title, string $bodyHtml): void {
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

function generateCode6(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendRegistrationCode(string $email, string $code): bool {
    $subject = 'Код подтверждения регистрации';
    $body = '<p>Ваш код подтверждения: <strong style="font-size:22px;letter-spacing:3px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . '<p>Код действует 10 минут.</p>';
    return send_email($email, $subject, $body);
}

function renderVerifyForm(string $email, string $message = '', bool $isError = false): void {
    $msg = '';
    if ($message !== '') {
        $msg = '<div class="msg ' . ($isError ? 'err' : 'ok') . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $body = '<h2>Подтверждение регистрации</h2>'
        . '<p>Код отправлен на: <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . $msg
        . '<form method="post" action="register_user.php">'
        . '<input type="hidden" name="action" value="verify_code">'
        . '<label for="verification_code">Код из письма (6 цифр):</label>'
        . '<input id="verification_code" type="text" name="verification_code" pattern="\d{6}" maxlength="6" required autofocus>'
        . '<button type="submit">Подтвердить</button>'
        . '</form>'
        . '<form method="post" action="register_user.php" style="margin-top:8px;">'
        . '<input type="hidden" name="action" value="resend_code">'
        . '<button type="submit" class="secondary">Запросить код снова</button>'
        . '</form>';
    renderPage('Подтверждение регистрации', $body);
}

function failToRegister(string $message): void {
    $body = '<h2>Ошибка регистрации</h2>'
        . '<div class="msg err">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<a href="register.php?form_type=register">Вернуться к регистрации</a>';
    renderPage('Ошибка регистрации', $body);
    exit();
}

function backToRegisterForm(array $old, array $errors): void {
    $_SESSION['register_old'] = $old;
    $_SESSION['register_errors'] = $errors;
    header('Location: register.php?form_type=register');
    exit();
}

$action = $_POST['action'] ?? 'start_registration';

if ($action === 'start_registration') {
    $name = htmlspecialchars(trim($_POST['name_user'] ?? ''), ENT_QUOTES, 'UTF-8');
    $login = htmlspecialchars(trim($_POST['login'] ?? ''), ENT_QUOTES, 'UTF-8');
    $emailRaw = trim($_POST['email'] ?? '');
    $email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
    $pass = htmlspecialchars(trim($_POST['pass'] ?? ''), ENT_QUOTES, 'UTF-8');
    $repeatpass = htmlspecialchars(trim($_POST['repeatpass'] ?? ''), ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');

    $old = [
        'name_user' => $name,
        'login' => $login,
        'email' => $emailRaw,
        'phone' => $phone,
        'pass' => $pass,
        'repeatpass' => $repeatpass,
    ];
    $errors = [];

    if ($name === '') {
        $errors['name_user'] = 'Никнейм обязателен.';
    }
    if (mb_strlen($login) < 5 || mb_strlen($login) > 50) {
        $errors['login'] = 'Недопустимая длина логина.';
    }
    if ($email === false || mb_strlen(explode('@', $emailRaw)[0]) < 8) {
        $errors['email'] = 'Некорректный email. Локальная часть должна содержать минимум 8 символов.';
    }
    if (empty($phone)) {
        $errors['phone'] = 'Номер телефона обязателен.';
    }
    $phonePattern = '/^(?:\+375(44|29|33|25)|80(44|29|33|25))\d{7}$/';
    if (!empty($phone) && !preg_match($phonePattern, $phone)) {
        $errors['phone'] = 'Некорректный номер телефона. Формат: +375/80 и код (44,29,33,25) + 7 цифр.';
    }
    if (mb_strlen($pass) < 8 || mb_strlen($pass) > 50) {
        $errors['pass'] = 'Пароль должен быть от 8 до 50 символов.';
    }
    if ($pass !== $repeatpass) {
        $errors['repeatpass'] = 'Пароли не совпадают.';
    }

    if (!empty($errors)) {
        foreach (array_keys($errors) as $field) {
            $old[$field] = '';
        }
        backToRegisterForm($old, $errors);
    }

    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM `users` WHERE `login` = :login OR `email` = :email');
    $stmt->execute([':login' => $login, ':email' => $email]);
    if ($stmt->fetchColumn()) {
        $old['login'] = '';
        $old['email'] = '';
        $errors['common'] = 'Пользователь с таким логином или email уже существует.';
        backToRegisterForm($old, $errors);
    }

    $code = generateCode6();
    if (!sendRegistrationCode($email, $code)) {
        $errors['common'] = 'Не удалось отправить код подтверждения. Проверьте настройки почты и попробуйте позже.';
        backToRegisterForm($old, $errors);
    }

    $_SESSION[REG_PENDING_KEY] = [
        'name_user' => $name,
        'login' => $login,
        'email' => $email,
        'phone' => $phone,
        'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + CODE_LIFETIME_SECONDS,
    ];

    renderVerifyForm($email, 'Код отправлен.');
    exit();
}

if ($action === 'resend_code') {
    $pending = $_SESSION[REG_PENDING_KEY] ?? null;
    if (!$pending || empty($pending['email'])) {
        failToRegister('Сессия регистрации не найдена. Заполните форму заново.');
    }
    $code = generateCode6();
    if (!sendRegistrationCode((string)$pending['email'], $code)) {
        renderVerifyForm((string)$pending['email'], 'Не удалось отправить код повторно.', true);
        exit();
    }
    $pending['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $pending['expires_at'] = time() + CODE_LIFETIME_SECONDS;
    $_SESSION[REG_PENDING_KEY] = $pending;
    renderVerifyForm((string)$pending['email'], 'Новый код отправлен.');
    exit();
}

if ($action === 'verify_code') {
    $pending = $_SESSION[REG_PENDING_KEY] ?? null;
    if (!$pending) {
        failToRegister('Сессия регистрации не найдена. Заполните форму заново.');
    }
    $inputCode = trim((string)($_POST['verification_code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $inputCode)) {
        renderVerifyForm((string)$pending['email'], 'Введите корректный 6-значный код.', true);
        exit();
    }
    if (time() > (int)$pending['expires_at']) {
        renderVerifyForm((string)$pending['email'], 'Срок действия кода истек. Нажмите "Запросить код снова".', true);
        exit();
    }
    if (!password_verify($inputCode, (string)$pending['code_hash'])) {
        // Поле кода не заполняем обратно => оно очистится
        renderVerifyForm((string)$pending['email'], 'Неверный код. Попробуйте еще раз.', true);
        exit();
    }

    $db = getDbConnection();
    $stmt = $db->prepare('SELECT 1 FROM `users` WHERE `login` = :login OR `email` = :email');
    $stmt->execute([':login' => $pending['login'], ':email' => $pending['email']]);
    if ($stmt->fetchColumn()) {
        unset($_SESSION[REG_PENDING_KEY]);
        failToRegister('Пользователь с таким логином или email уже существует.');
    }

    $sql = 'INSERT INTO `users` (`name_user`, `login`, `pass`, `email`, `phone`) VALUES (:name_user, :login, :pass, :email, :phone)';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':name_user' => $pending['name_user'],
        ':login' => $pending['login'],
        ':pass' => $pending['pass_hash'],
        ':email' => $pending['email'],
        ':phone' => $pending['phone'],
    ]);

    unset($_SESSION[REG_PENDING_KEY]);
    header('Location: index.php');
    exit();
}

failToRegister('Неизвестное действие.');