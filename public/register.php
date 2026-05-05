<?php
session_start();

// Определяем тип формы для отображения
$formType = $_POST['form_type'] ?? ($_GET['form_type'] ?? 'user');

$registerOld = $_SESSION['register_old'] ?? [];
$registerErrors = $_SESSION['register_errors'] ?? [];
unset($_SESSION['register_old'], $_SESSION['register_errors']);

$adminRegisterOld = $_SESSION['admin_register_old'] ?? [];
$adminRegisterErrors = $_SESSION['admin_register_errors'] ?? [];
unset($_SESSION['admin_register_old'], $_SESSION['admin_register_errors']);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация - Кондитерская "Kriter"</title>
    <link rel="stylesheet" href="styles.css">
    </head>

    <style>
        img {
            max-width: 100px;
            height: auto;
        }
        .content {
            max-width: 400px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            background-color: #f9f9f9;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        label {
            margin-bottom: 5px;
        }
        input {
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        button {
            padding: 10px;
            background-color: #e74c3c;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background-color: #c0392b;
        }
        .toggle-form {
            text-align: center;
            margin-top: 20px;
        }
        .hidden {
            display: none;
        }
        .field-error {
            margin-top: -10px;
            margin-bottom: 10px;
            color: #b71c1c;
            font-size: 13px;
        }
        .role-switch {
            display: flex;
            justify-content: flex-end;
            margin: 10px 20px 0;
        }
        .role-switch form {
            display: inline;
        }
        .role-switch button {
            background-color: #3498db;
            padding: 8px 14px;
            font-size: 14px;
        }
        .role-switch button:hover {
            background-color: #2980b9;
        }
    </style>

<body>

<?php require __DIR__ . '/includes/header_client.php'; ?>

<div class="role-switch">
    <form method="post">
        <?php if (in_array($formType, ['admin', 'admin_register'], true)): ?>
            <input type="hidden" name="form_type" value="user">
            <button type="submit">Пользователь</button>
        <?php else: ?>
            <input type="hidden" name="form_type" value="admin">
            <button type="submit">Администратор</button>
        <?php endif; ?>
    </form>
</div>

<div class="content">
    <?php if ($formType === 'user'): ?>
        <h2>Авторизация пользователя</h2>
<form action="login_user.php" method="post">
    <label for="username">Имя или логин:</label>
    <input type="text" name="login" placeholder="Введите имя или логин" required>

    <label for="password">Пароль:</label>
    <input type="password" name="pass" placeholder="Пароль" required>

    <button type="submit">Войти</button>
</form>



<div class="toggle-form">
    <form method="post" style="display: inline;">
        <input type="hidden" name="form_type" value="register">
        <button type="submit" style="background: none; border: none; color: blue; text-decoration: underline; cursor: pointer;">Нет аккаунта? Зарегистрироваться</button>
    </form>
</div>
    <?php elseif ($formType === 'admin'): ?>
        <h2>Авторизация администратора</h2>
        <form action="login_admin.php" method="post">
    <label for="login">Имя пользователя:</label>
    <input type="text" id="login" name="login" placeholder="Логин" required>

    <label for="pass">Пароль:</label>
    <input type="password" id="pass" name="pass" placeholder="Пароль" required>

    <label for="personal_code">Личный код:</label>
    <input type="text" id="personal_code" name="personal_code" placeholder="Личный код" maxlength="6" pattern="\d{6}" required>

    <label for="phone">Номер телефона:</label>
    <input type="text" id="phone" name="phone"  placeholder="Номер телефона"required>

    <button type="submit">Войти</button>
</form>

        <div class="toggle-form">
            <form method="post" style="display: inline;">
                <input type="hidden" name="form_type" value="admin_register">
                <button type="submit" style="background: none; border: none; color: blue; text-decoration: underline; cursor: pointer;">Регистрация администратора</button>
            </form>
        </div>
        <?php elseif ($formType === 'admin_register'): ?>
        <h2>Регистрация администратора</h2>
        <?php if (!empty($adminRegisterErrors['common'])): ?>
            <div class="field-error"><?= htmlspecialchars($adminRegisterErrors['common']) ?></div>
        <?php endif; ?>
        <form action="register_admin.php" method="post">
            <label for="login">Имя пользователя:</label>
            <input type="text" id="login" name="login" placeholder="Логин" value="<?= htmlspecialchars($adminRegisterOld['login'] ?? '') ?>" required>
            <?php if (!empty($adminRegisterErrors['login'])): ?><div class="field-error"><?= htmlspecialchars($adminRegisterErrors['login']) ?></div><?php endif; ?>

            <label for="pass">Пароль:</label>
            <input type="password" id="pass" name="pass" placeholder="Пароль" value="<?= htmlspecialchars($adminRegisterOld['pass'] ?? '') ?>" required>
            <?php if (!empty($adminRegisterErrors['pass'])): ?><div class="field-error"><?= htmlspecialchars($adminRegisterErrors['pass']) ?></div><?php endif; ?>

            <label for="phone">Номер телефона:</label>
            <input type="text" id="phone" name="phone" placeholder="Номер телефона" value="<?= htmlspecialchars($adminRegisterOld['phone'] ?? '') ?>" required>
            <?php if (!empty($adminRegisterErrors['phone'])): ?><div class="field-error"><?= htmlspecialchars($adminRegisterErrors['phone']) ?></div><?php endif; ?>

            <button type="submit">Зарегистрироваться</button>
        </form>
        <div class="toggle-form">
            <form method="post" style="display: inline;">
                <input type="hidden" name="form_type" value="admin">
                <button type="submit" style="background: none; border: none; color: blue; text-decoration: underline; cursor: pointer;">Авторизоваться администратора</button>
            </form>
        </div>
    

    <?php elseif ($formType === 'register'): ?>
        <h2>Регистрация пользователя</h2>
        <?php if (!empty($registerErrors['common'])): ?>
            <div class="field-error"><?= htmlspecialchars($registerErrors['common']) ?></div>
        <?php endif; ?>
        <form action="register_user.php" method="post">
            <label for="username">Никнейм:</label>
            <input type="text" name="name_user" placeholder="Никнейм" value="<?= htmlspecialchars($registerOld['name_user'] ?? '') ?>" required>
            <?php if (!empty($registerErrors['name_user'])): ?><div class="field-error"><?= htmlspecialchars($registerErrors['name_user']) ?></div><?php endif; ?>
            <label for="login">Имя пользователя:</label>
            <input type="text" name="login" placeholder="Логин" value="<?= htmlspecialchars($registerOld['login'] ?? '') ?>" required>
            <?php if (!empty($registerErrors['login'])): ?><div class="field-error"><?= htmlspecialchars($registerErrors['login']) ?></div><?php endif; ?>
            <label for="email">Email:</label>
            <input type="email" name="email" placeholder="Email" value="<?= htmlspecialchars($registerOld['email'] ?? '') ?>" required>
            <?php if (!empty($registerErrors['email'])): ?><div class="field-error"><?= htmlspecialchars($registerErrors['email']) ?></div><?php endif; ?>
            <label for="phone">Номер телефона:</label>
        <input type="text" name="phone" placeholder="Номер телефона" value="<?= htmlspecialchars($registerOld['phone'] ?? '') ?>" required>
            <?php if (!empty($registerErrors['phone'])): ?><div class="field-error"><?= htmlspecialchars($registerErrors['phone']) ?></div><?php endif; ?>
            <label for="password">Пароль:</label>
            <input type="password" name="pass" placeholder="Пароль" value="<?= htmlspecialchars($registerOld['pass'] ?? '') ?>" required>
            <?php if (!empty($registerErrors['pass'])): ?><div class="field-error"><?= htmlspecialchars($registerErrors['pass']) ?></div><?php endif; ?>
            <label for="confirm-password">Подтвердите пароль:</label>
            <input type="password" name="repeatpass" placeholder="Повторите пароль" value="<?= htmlspecialchars($registerOld['repeatpass'] ?? '') ?>" required>
            <?php if (!empty($registerErrors['repeatpass'])): ?><div class="field-error"><?= htmlspecialchars($registerErrors['repeatpass']) ?></div><?php endif; ?>
            <button type="submit">Зарегистрироваться</button>
        </form>
        <div class="toggle-form">
            <form method="post" style="display: inline;">
                <input type="hidden" name="form_type" value="user">
                <button type="submit" style="background: none; border: none; color: blue; text-decoration: underline; cursor: pointer;">Уже есть аккаунт? Авторизоваться</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
