<?php
session_start();
require 'db.php'; // Подключение к базе данных
require_once __DIR__ . '/includes/order_modify_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php'); // Перенаправление на страницу авторизации
    exit();
}

$userId = $_SESSION['user_id']; // ID пользователя из сессии

$pdo = getDbConnection(); // Получаем соединение с базой данных

// Автоматическое обновление статуса заказов
$currentDateTime = new DateTime();
$stmt = $pdo->prepare('
    UPDATE orders 
    SET status = "Доставлен" 
    WHERE user_id = ? 
    AND status != "Доставлен" 
    AND CONCAT(delivery_date, " ", delivery_time) < ?
');
$stmt->execute([$userId, $currentDateTime->format('Y-m-d H:i:s')]);

// Обработка обновления заказа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $orderNumber = (int)$_POST['number_order'];

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE number_order = ? AND user_id = ?');
    $stmt->execute([$orderNumber, $userId]);
    $orderRowCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orderRowCheck || ($orderRowCheck['status'] ?? '') === 'Доставлен') {
        header('Location: order.php?error=denied');
        exit();
    }
    if (!order_can_modify_before_delivery($orderRowCheck)) {
        header('Location: order.php?error=locked');
        exit();
    }

    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Обновляем количество товаров в заказе
        if (isset($_POST['product_id']) && isset($_POST['quantity'])) {
            foreach ($_POST['product_id'] as $index => $productId) {
                $newQuantity = (int)$_POST['quantity'][$index];
                
                // Получаем текущее количество товара в заказе
                $stmt = $pdo->prepare('
                    SELECT quantity 
                    FROM order_details 
                    WHERE number_order = ? AND product_id = ?
                ');
                $stmt->execute([$orderNumber, $productId]);
                $orderItem = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($orderItem) {
                    $oldQuantity = $orderItem['quantity'];
                    
                    // Получаем цену товара из меню
                    $stmt = $pdo->prepare('
                        SELECT price 
                        FROM menu 
                        WHERE product_id = ?
                    ');
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product) {
                        $pricePerUnit = $product['price'];
                        
                        // Обновляем количество в order_details, сохраняя цену за единицу
                        $stmt = $pdo->prepare('
                            UPDATE order_details 
                            SET quantity = ?, 
                                price = ? 
                            WHERE number_order = ? AND product_id = ?
                        ');
                        $stmt->execute([$newQuantity, $pricePerUnit, $orderNumber, $productId]);

                        $deltaQty = $newQuantity - $oldQuantity;
                        if ($deltaQty !== 0) {
                            order_ingredients_apply_product_qty_delta($pdo, (int)$productId, $deltaQty);
                        }
                        
                        // Синхронизируем с таблицей menu
                        if ($newQuantity > $oldQuantity) {
                            $stmt = $pdo->prepare('
                                UPDATE menu 
                                SET quantity = quantity - ? 
                                WHERE product_id = ?
                            ');
                            $stmt->execute([$newQuantity - $oldQuantity, $productId]);
                        } elseif ($newQuantity < $oldQuantity) {
                            $stmt = $pdo->prepare('
                                UPDATE menu 
                                SET quantity = quantity + ? 
                                WHERE product_id = ?
                            ');
                            $stmt->execute([$oldQuantity - $newQuantity, $productId]);
                        }
                    }
                }
            }
        }
        
        // Адрес и дата доставки (время доставки из формы не принимаем — не меняется)
        if (isset($_POST['street'], $_POST['house'], $_POST['apt'], $_POST['delivery_date'])) {
            $stmt = $pdo->prepare('
                UPDATE orders 
                SET addres_street = ?, 
                    addres_house = ?, 
                    addres_apt = ?, 
                    delivery_date = ?
                WHERE number_order = ? AND user_id = ?
            ');
            $stmt->execute([
                $_POST['street'],
                $_POST['house'],
                $_POST['apt'],
                $_POST['delivery_date'],
                $orderNumber,
                $userId
            ]);
        }
        
        // После всех обновлений, пересчитываем общую стоимость заказа
        $stmt = $pdo->prepare('
            SELECT SUM(price * quantity) as total 
            FROM order_details 
            WHERE number_order = ?
        ');
        $stmt->execute([$orderNumber]);
        $total = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Обновляем общую стоимость в таблице orders
        $stmt = $pdo->prepare('
            UPDATE orders 
            SET total_price = ? 
            WHERE number_order = ?
        ');
        $stmt->execute([$total['total'], $orderNumber]);
        
        // Подтверждаем транзакцию
        $pdo->commit();
        
    } catch (Exception $e) {
        // В случае ошибки откатываем транзакцию
        $pdo->rollBack();
        error_log('update_order: ' . $e->getMessage());
        header('Location: order.php?error=update&message=' . rawurlencode($e->getMessage()));
        exit();
    }
    
    header('Location: order.php');
    exit();
}

// Обработка отмены заказа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $orderNumber = (int)$_POST['number_order'];
    
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE number_order = ? AND user_id = ?');
    $stmt->execute([$orderNumber, $userId]);
    $orderForCancel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($orderForCancel) {
        if (($orderForCancel['status'] ?? '') === 'Доставлен') {
            header('Location: order.php?error=denied');
            exit();
        }
        if (!order_can_modify_before_delivery($orderForCancel)) {
            header('Location: order.php?error=locked');
            exit();
        }
        
        // Начинаем транзакцию
        $pdo->beginTransaction();
        
        try {
            // Возвращаем товары в меню и ингредиенты на склад
            $stmt = $pdo->prepare('
                SELECT product_id, quantity 
                FROM order_details 
                WHERE number_order = ?
            ');
            $stmt->execute([$orderNumber]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orderItems as $item) {
                order_ingredients_apply_product_qty_delta($pdo, (int)$item['product_id'], -(int)$item['quantity']);
                $stmt = $pdo->prepare('
                    UPDATE menu 
                    SET quantity = quantity + ? 
                    WHERE product_id = ?
                ');
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // Удаляем детали заказа
            $stmt = $pdo->prepare('DELETE FROM order_details WHERE number_order = ?');
            $stmt->execute([$orderNumber]);
            
            // Удаляем заказ
            $stmt = $pdo->prepare('DELETE FROM orders WHERE number_order = ? AND user_id = ?');
            $stmt->execute([$orderNumber, $userId]);
            
            $pdo->commit();
            header('Location: order.php?success=cancel');
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: order.php?error=db');
            exit();
        }
    }
}

// Получаем все заказы пользователя, включая поле number_order
$stmt = $pdo->prepare('
    SELECT number_order, delivery_date, delivery_time, addres_street, addres_house, addres_apt, status
    FROM orders 
    WHERE user_id = ? 
    ORDER BY 
        CASE 
            WHEN status = "Принят" THEN 1
            ELSE 2
        END,
        delivery_date ASC,
        delivery_time ASC
');
$stmt->execute([$userId]);
$orderInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получение заказа в текстовый файл
if (isset($_GET['get'])) {
    $orderNumber = (int)$_GET['get'];

    // Получение данных по конкретному заказу
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE number_order = ? AND user_id = ?");
    $stmt->execute([$orderNumber, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM order_details WHERE number_order = ?");
    $stmt->execute([$orderNumber]);
    $orderDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Проверка, существует ли заказ
    if ($order) {
        // Создаем содержимое файла
        $content = "ID заказа: {$order['number_order']}\n";
        $content .= "Имя: {$order['user_name']}\n";
        $content .= "Адрес: {$order['addres_street']} {$order['addres_house']} {$order['addres_apt']}\n";
        $content .= "Дата доставки: {$order['delivery_date']}\n";
        $content .= "Время доставки: {$order['delivery_time']}\n";
        $content .= "Статус: {$order['status']}\n";
        $content .= "Общая стоимость: {$order['total_price']} руб.\n";
        $content .= "Детали заказа:\n";

        foreach ($orderDetails as $detail) {
            $content .= " - {$detail['product_name']} - {$detail['quantity']} шт.\n";
        }

        // Устанавливаем заголовки для скачивания файла
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="order_' . $order['number_order'] . '.txt"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Выводим содержимое файла
        echo $content;
        exit();
    } else {
        echo "Заказ не найден.";
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ваши заказы</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        img {
            max-width: 100px;
            height: auto;
        }
        .order-form {
            margin-bottom: 20px;
        }
        .order-details input {
            margin: 5px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .order-details input:disabled {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
        }
        .edit-button, .save-button {
            padding: 8px 16px;
            margin: 10px 0;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .edit-button {
            background-color: #4CAF50;
            color: white;
        }
        .save-button {
            background-color: #2196F3;
            color: white;
        }
        .hidden {
            display: none;
        }
        .order-details p {
            margin: 10px 0;
        }
        .cancel-button {
            background-color: #dc3545;
            color: white;
            padding: 8px 16px;
            margin: 10px 0;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .cancel-button:hover {
            background-color: #c82333;
        }
        .get-button {
            background-color: #2196F3;
            color: white;
            padding: 8px 16px;
            margin: 10px 0;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .get-button:hover {
            background-color: #1976D2;
        }
        .order-locked-msg {
            background: #fff3e0;
            border: 1px solid #ffcc80;
            padding: 10px 12px;
            border-radius: 6px;
            color: #5d4037;
        }
    </style>
</head>

<body>
<?php require __DIR__ . '/includes/header_client.php'; ?>

    <main>
    <?php if (!empty($orderInfo)): ?>
        <h2>Ваши заказы</h2>
        <?php foreach ($orderInfo as $order): ?>
            <?php
                $canModify = ($order['status'] ?? '') === 'Принят' && order_can_modify_before_delivery($order);
            ?>
            <div class="order">
            <p><strong>Номер заказа:</strong> <?= htmlspecialchars($order['number_order']) ?></p>
                <?php if (!$canModify && ($order['status'] ?? '') === 'Принят'): ?>
                    <p class="order-locked-msg">Изменения недоступны: до доставки осталось меньше 3 часов (время доставки изменить нельзя).</p>
                <?php endif; ?>
                <h3>Товары в заказе:</h3>
                <?php
                // Проверяем, есть ли номер заказа в массиве $order
                if (isset($order['number_order'])) {
                    $stmt = $pdo->prepare('
                        SELECT od.product_id, od.product_name, od.quantity, od.price 
                        FROM order_details od 
                        WHERE od.number_order = ?
                    ');
                    $stmt->execute([$order['number_order']]);
                    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $totalPrice = 0; // Общая стоимость для этого заказа

                    if ($orderItems): ?>
                        <form method="POST" action="order.php" class="order-form" data-order="<?= htmlspecialchars($order['number_order']) ?>">
                            <input type="hidden" name="number_order" value="<?= htmlspecialchars($order['number_order']) ?>">
                        <ul>
                            <?php foreach ($orderItems as $item):
                                $itemTotal = $item['price'] * $item['quantity'];
                                $totalPrice += $itemTotal;
                            ?>
                                <li>
                                        <?= htmlspecialchars($item['product_name']) ?> - 
                                        <input type="number" name="quantity[]" value="<?= $item['quantity'] ?>" min="1" disabled>
                                        <input type="hidden" name="product_id[]" value="<?= $item['product_id'] ?>">
                                    <br>
                                        Цена за единицу: <?= number_format($item['price'], 2) ?> BUN
                                    <br>
                                    Общая стоимость: <?= number_format($itemTotal, 2) ?> BUN
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <h4>Общая стоимость: <?= number_format($totalPrice, 2) ?> BUN</h4>
                            
                            <div class="order-details">
                                <p><strong>Дата доставки:</strong> 
                                    <input type="date" name="delivery_date" value="<?= htmlspecialchars($order['delivery_date']) ?>" disabled>
                                </p>
                                <p><strong>Время доставки</strong> (изменить нельзя): 
                                    <input type="time" name="delivery_time" value="<?= htmlspecialchars($order['delivery_time']) ?>" disabled title="Время доставки нельзя изменить">
                                </p>
                                <p><strong>Адрес доставки:</strong> 
                                    <input type="text" name="street" value="<?= htmlspecialchars($order['addres_street']) ?>" disabled>
                                    <input type="text" name="house" value="<?= htmlspecialchars($order['addres_house']) ?>" disabled>
                                    <input type="text" name="apt" value="<?= htmlspecialchars($order['addres_apt']) ?>" disabled>
                                </p>
                                <p><strong>Статус заказа:</strong> <?= htmlspecialchars($order['status'] ?? 'Неизвестно') ?></p>
                            </div>
                            
                            <?php if ($order['status'] !== 'Доставлен' && $canModify): ?>
                                <button type="button" class="edit-button">Изменить</button>
                                <button type="submit" name="update_order" class="save-button hidden">Сохранить</button>
                                <a class="get-button" style="display:inline-block;text-decoration:none;" href="order_add_items.php?order=<?= (int)$order['number_order'] ?>">Добавить товар в заказ</a>
                            <?php endif; ?>
                            <?php if ($order['status'] !== 'Доставлен' && $canModify): ?>
                            <button type="submit" name="cancel_order" class="cancel-button" value="<?= htmlspecialchars($order['number_order']) ?>">Отменить заказ</button>
                            <?php elseif ($order['status'] !== 'Доставлен' && !$canModify): ?>
                            <button type="button" class="cancel-button" disabled title="Отмена недоступна менее чем за 3 часа до доставки">Отменить заказ</button>
                            <?php endif; ?>
                            <button type="button" class="get-button" onclick="window.location.href='order.php?get=<?= htmlspecialchars($order['number_order']) ?>'">Скачать чек</button>
                        </form>
                    <?php else: ?>
                        <p>Товары не найдены для этого заказа.</p>
                    <?php endif;
                } else {
                    echo "<p>Не удалось найти номер заказа.</p>";
                }
                ?>
                <hr>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>У вас пока нет оформленных заказов.</p>
    <?php endif; ?>
    </main>

    <?php if (isset($_GET['error'])): ?>
        <div class="error-message" style="color: red; margin: 20px; padding: 15px; background-color: #ffebee; border-radius: 5px; border: 1px solid #ffcdd2;">
            <?php
            switch ($_GET['error']) {
                case 'time':
                case 'locked':
                    echo 'Действие недоступно: до доставки осталось меньше 3 часов. Изменить заказ, отменить его или добавить товары можно только заранее (не позднее чем за 3 часа до доставки). Время доставки изменить нельзя.';
                    break;
                case 'denied':
                    echo 'Заказ не найден или недоступен для этого действия.';
                    break;
                case 'update':
                    $m = isset($_GET['message']) ? (string)$_GET['message'] : '';
                    echo 'Не удалось сохранить изменения.' . ($m !== '' ? ' ' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') : '');
                    break;
                case 'db':
                    echo 'Произошла ошибка при обработке заказа. Пожалуйста, попробуйте позже.';
                    break;
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'cancel'): ?>
        <div class="success-message" style="color: green; margin: 20px;">
            Заказ успешно отменен.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'items'): ?>
        <div class="success-message" style="color: green; margin: 20px;">
            Товары добавлены в заказ.
        </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.order-form').forEach(function (form) {
            const inputs = form.querySelectorAll(
                'input[name="delivery_date"], input[name="street"], input[name="house"], input[name="apt"], input[name="quantity[]"]'
            );
            const editButton = form.querySelector('.edit-button');
            const saveButton = form.querySelector('.save-button');
            const cancelButton = form.querySelector('.cancel-button:not([disabled])');

            if (editButton && saveButton) {
                editButton.addEventListener('click', function () {
                    inputs.forEach(function (input) {
                        input.disabled = false;
                    });
                    editButton.classList.add('hidden');
                    saveButton.classList.remove('hidden');
                });
            }

            if (cancelButton && cancelButton.getAttribute('name') === 'cancel_order') {
                cancelButton.addEventListener('click', function (event) {
                    if (!confirm('Вы уверены, что хотите отменить заказ?')) {
                        event.preventDefault();
                    }
                });
            }
        });
    });
    </script>
</body>

</html>
