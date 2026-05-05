<?php
session_start();
require 'db.php'; // Подключаем db.php, где создается функция для подключения

// Инициализация переменной для сообщения об ошибке
$errorMessage = '';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: register.php'); // Перенаправление на страницу авторизации
    exit();
}

$userId = $_SESSION['user_id']; // ID пользователя из сессии

// Получаем данные пользователя из таблицы users
$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id_user = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "Пользователь не найден.";
    exit();
}

// Если форма отправлена, обрабатываем данные
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Отладочная информация
    error_log("POST data: " . print_r($_POST, true));
    
    $userName = $_POST['name'];
    $newStreet = $_POST['street'];
    $newHouse = $_POST['house'];
    $newApartment = $_POST['apartment'];
    $deliveryDate = $_POST['delivery_date'];
    $deliveryTime = $_POST['delivery_time'];

    // Проверяем наличие всех необходимых данных
    if (empty($userName) || empty($newStreet) || empty($newHouse) || empty($newApartment) || 
        empty($deliveryDate) || empty($deliveryTime)) {
        $errorMessage = "Все поля должны быть заполнены";
    } else {
        try {
            $pdo = getDbConnection();
            
            // Проверяем, есть ли товары в корзине
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM cart WHERE user_id = ?');
            $stmt->execute([$userId]);
            $cartCount = $stmt->fetchColumn();
            
            if ($cartCount == 0) {
                $errorMessage = "Корзина пуста";
            } else {
                // Начинаем транзакцию
                $pdo->beginTransaction();

                // Проверка остатков ингредиентов и списание по рецепту (если рецепты заданы)
                $stmt = $pdo->prepare('
                    SELECT
                        i.ingredient_id,
                        i.name,
                        i.unit,
                        i.stock_qty,
                        SUM(pi.qty_per_product * c.quantity) AS required_qty
                    FROM cart c
                    JOIN product_ingredients pi ON pi.product_id = c.product_id
                    JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
                    WHERE c.user_id = ?
                    GROUP BY i.ingredient_id, i.name, i.unit, i.stock_qty
                ');
                $stmt->execute([$userId]);
                $requiredIngredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($requiredIngredients as $req) {
                    $need = (float)$req['required_qty'];
                    $have = (float)$req['stock_qty'];
                    if ($need > $have + 0.0000001) {
                        throw new Exception(
                            'Недостаточно ингредиента: ' . $req['name'] . ' (нужно ' . $need . ' ' . $req['unit'] . ', есть ' . $have . ' ' . $req['unit'] . ')'
                        );
                    }
                }

                // Списываем ингредиенты (атомарно в той же транзакции)
                foreach ($requiredIngredients as $req) {
                    $need = (float)$req['required_qty'];
                    if ($need <= 0) continue;
                    $stmt = $pdo->prepare('
                        UPDATE ingredients
                        SET stock_qty = stock_qty - ?
                        WHERE ingredient_id = ?
                    ');
                    $stmt->execute([$need, (int)$req['ingredient_id']]);
                }
                
                // 1. Вставляем заказ в таблицу orders
                $stmt = $pdo->prepare('
                    INSERT INTO orders (user_id, user_name, addres_street, addres_house, addres_apt, delivery_date, delivery_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $status = 'Принят';
                $stmt->execute([$userId, $userName, $newStreet, $newHouse, $newApartment, $deliveryDate, $deliveryTime, $status]);
                
                // Получаем ID созданного заказа
                $orderId = $pdo->lastInsertId();
                error_log("Created order ID: " . $orderId);
                
                // 2. Получаем товары из корзины
                $stmt = $pdo->prepare('
                    SELECT c.product_id, m.name, m.price, c.quantity
                    FROM cart c
                    JOIN menu m ON c.product_id = m.product_id
                    WHERE c.user_id = ?
                ');
                $stmt->execute([$userId]);
                $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 3. Добавляем товары в order_details
                foreach ($cartItems as $item) {
                    $stmt = $pdo->prepare('
                        INSERT INTO order_details (number_order, product_id, product_name, quantity, price)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $orderId,
                        $item['product_id'],
                        $item['name'],
                        $item['quantity'],
                        $item['price']
                    ]);
                }
                
                // 4. Очищаем корзину
                $stmt = $pdo->prepare('DELETE FROM cart WHERE user_id = ?');
                $stmt->execute([$userId]);
                
                // Подтверждаем транзакцию
                $pdo->commit();

                // Чек на email (ошибка почты заказ не отменяет)
                require_once __DIR__ . '/mailer.php';
                $mailTo = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
                if ($mailTo === false) {
                    $mailTo = $user['email'] ?? '';
                }
                $orderTotal = 0.0;
                foreach ($cartItems as $ci) {
                    $orderTotal += (float)$ci['price'] * (int)$ci['quantity'];
                }
                $timeForMail = trim((string)$deliveryTime) !== '' ? $deliveryTime : 'не указано';
                $receiptHtml = build_order_receipt_html(
                    (int)$orderId,
                    $userName,
                    $mailTo,
                    $newStreet,
                    $newHouse,
                    $newApartment,
                    $deliveryDate,
                    $timeForMail,
                    $cartItems,
                    $orderTotal
                );
                $receiptTxt = build_order_receipt_text(
                    (int)$orderId,
                    $userName,
                    $mailTo,
                    $newStreet,
                    $newHouse,
                    $newApartment,
                    $deliveryDate,
                    $timeForMail,
                    $cartItems,
                    $orderTotal
                );
                $attachName = 'chek_zakaz_' . (int)$orderId . '.txt';
                if (!send_email($mailTo, 'Чек по заказу №' . $orderId . ' — Kriter', $receiptHtml, [
                    [
                        'filename' => $attachName,
                        'content' => $receiptTxt,
                        'mime' => 'text/plain; charset=UTF-8',
                    ],
                ])) {
                    error_log('Order receipt email not sent for order ' . $orderId . ' to ' . $mailTo);
                }
                
                // Перенаправляем на страницу заказов
                header('Location: order.php');
                exit();
            }
        } catch (Exception $e) {
            if (isset($pdo)) {
                $pdo->rollBack();
            }
            $errorMessage = "Ошибка при оформлении заказа: " . $e->getMessage();
            error_log("Order error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оформление заказа</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        img {
            max-width: 100px;
            height: auto;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header_client.php'; ?>

    <h1>Оформление заказа</h1>

    <div class="content">
        <form action="order_form.php" method="POST">
            <h2>Ваши данные</h2>
            <label for="name">Имя:</label>
            <input type="text" name="name" id="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
            <br>
            <label for="email">Email:</label>
            <input type="email" name="email" id="email" value="<?= htmlspecialchars($user['email']) ?>" required>
            <br>

            <h2>Адрес доставки</h2>
            <label for="street">Улица:</label>
            <input type="text" name="street" id="street" value="<?= htmlspecialchars($user['street'] ?? '') ?>" required>
            <br>
            <label for="house">Дом:</label>
            <input type="text" name="house" id="house" value="<?= htmlspecialchars($user['house'] ?? '') ?>" required maxlength="4">
            <br>
            <label for="apartment">Квартира:</label>
            <input type="text" name="apartment" id="apartment" value="<?= htmlspecialchars($user['apartment'] ?? '') ?>" required maxlength="3">
            <br>

            <h2>Дата и время доставки</h2>
            <label for="delivery_date">Дата доставки:</label>
            <input type="date" name="delivery_date" id="delivery_date" value="<?= htmlspecialchars($deliveryDate) ?>" required>
            <br>
            <label for="delivery_time">Время доставки:</label>
            <input type="time" name="delivery_time" id="delivery_time" value="<?= htmlspecialchars($deliveryTime) ?>">
            <br>
            <label>Если время доставки не будет указано, то мы доставим ваш заказ в течение 40 минут.</label>
            <br>
            <label>Оплата только при получении.</label>
            <br>

            <?php if ($errorMessage): ?>
                <p style="color: red;"><?= $errorMessage ?></p>
            <?php endif; ?>

            <button type="submit">Оформить заказ</button>
        </form>
    </div>

    <footer>
        <p>&copy; 2023 Кондитерская "Kriter"</p>
    </footer>
</body>
</html>