<?php
session_start();
require 'db.php'; // Подключение к базе данных

// Проверка, авторизован ли администратор
if (!isset($_SESSION['admin_user'])) {
    header('Location: login_admin.php'); // Перенаправление на страницу авторизации
    exit();
}

$db = getDbConnection();

// Период для фильтра и отчета
$period = isset($_GET['period']) ? (string)$_GET['period'] : 'month';
$periodConfig = [
    'week' => ['interval' => '7 DAY', 'label' => 'Неделя'],
    'month' => ['interval' => '1 MONTH', 'label' => 'Месяц'],
    '3months' => ['interval' => '3 MONTH', 'label' => '3 месяца'],
    '6months' => ['interval' => '6 MONTH', 'label' => '6 месяцев'],
    'all' => ['interval' => null, 'label' => 'Все'],
];
if (!isset($periodConfig[$period])) {
    $period = 'month';
}
$periodLabel = $periodConfig[$period]['label'];

if ($period === 'all') {
    $periodOrdersCondition = '1=1';
} else {
    $periodInterval = $periodConfig[$period]['interval'];
    $periodOrdersCondition = "o.delivery_date >= DATE_SUB(CURDATE(), INTERVAL $periodInterval)";
}

// Отчет о продажах за выбранный период
if (isset($_GET['sales_report'])) {
    // 1) Детализация по каждому заказу
    $stmt = $db->prepare("
        SELECT
            o.number_order,
            o.user_name,
            o.delivery_date,
            o.delivery_time,
            o.status,
            o.total_price,
            od.product_name,
            od.quantity,
            od.price
        FROM orders o
        LEFT JOIN order_details od ON od.number_order = o.number_order
        WHERE $periodOrdersCondition
        ORDER BY o.number_order ASC, od.product_name ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ordersMap = [];
    foreach ($rows as $row) {
        $orderNumber = (int)$row['number_order'];
        if (!isset($ordersMap[$orderNumber])) {
            $ordersMap[$orderNumber] = [
                'user_name' => $row['user_name'],
                'delivery_date' => $row['delivery_date'],
                'delivery_time' => $row['delivery_time'],
                'status' => $row['status'],
                'total_price' => (float)$row['total_price'],
                'items' => [],
            ];
        }
        if (!empty($row['product_name'])) {
            $ordersMap[$orderNumber]['items'][] = [
                'product_name' => $row['product_name'],
                'quantity' => (int)$row['quantity'],
                'price' => (float)$row['price'],
            ];
        }
    }

    // 2) Сводка по товарам (как сейчас)
    $stmt = $db->prepare("
        SELECT
            od.product_id,
            od.product_name,
            SUM(od.quantity) AS sold_qty,
            SUM(od.quantity * od.price) AS revenue
        FROM order_details od
        JOIN orders o ON o.number_order = od.number_order
        WHERE $periodOrdersCondition
        GROUP BY od.product_id, od.product_name
        ORDER BY sold_qty DESC, revenue DESC
    ");
    $stmt->execute();
    $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $content = "Отчет о продажах\n";
    $content .= "Период: {$periodLabel}\n";
    $content .= "Дата формирования: " . date('Y-m-d H:i:s') . "\n\n";

    $content .= "===== Заказы (детализация) =====\n";
    if (!$ordersMap) {
        $content .= "Нет заказов за выбранный период.\n\n";
    } else {
        $ordersTotalRevenue = 0.0;
        foreach ($ordersMap as $orderNumber => $orderData) {
            $ordersTotalRevenue += (float)$orderData['total_price'];
            $content .= "Заказ #{$orderNumber} | {$orderData['delivery_date']} {$orderData['delivery_time']} | {$orderData['status']} | Клиент: {$orderData['user_name']}\n";
            if (empty($orderData['items'])) {
                $content .= "  (без позиций)\n";
            } else {
                foreach ($orderData['items'] as $item) {
                    $lineRevenue = $item['quantity'] * $item['price'];
                    $content .= "  - {$item['product_name']} | {$item['quantity']} шт. | " .
                        number_format($item['price'], 2, '.', '') . " руб. | " .
                        number_format($lineRevenue, 2, '.', '') . " руб.\n";
                }
            }
            $content .= "  Итого по заказу: " . number_format((float)$orderData['total_price'], 2, '.', '') . " руб.\n";
            $content .= str_repeat('-', 72) . "\n";
        }
        $content .= "Общая прибыль по всем заказам: " . number_format($ordersTotalRevenue, 2, '.', '') . " руб.\n\n";
    }

    $content .= "===== Сводка по товарам =====\n";
    $content .= "Товар | Кол-во | Выручка (руб.)\n";
    $content .= str_repeat('-', 72) . "\n";

    if (!$reportRows) {
        $content .= "Нет данных за выбранный период.\n";
    } else {
        $summaryRevenue = 0.0;
        foreach ($reportRows as $row) {
            $name = $row['product_name'];
            $soldQty = (int)$row['sold_qty'];
            $revenueFloat = (float)$row['revenue'];
            $summaryRevenue += $revenueFloat;
            $revenue = number_format($revenueFloat, 2, '.', '');
            $content .= "{$name} | {$soldQty} | {$revenue}\n";
        }
        $content .= str_repeat('-', 72) . "\n";
        $content .= "Итого выручка по товарам: " . number_format($summaryRevenue, 2, '.', '') . " руб.\n";
    }

    $filename = 'sales_report_' . $period . '_' . date('Ymd_His') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $content;
    exit();
}

// Получение заказа в текстовый файл
if (isset($_GET['get'])) {
    $orderNumber = (int)$_GET['get'];

    // Получение данных по конкретному заказу
    $stmt = $db->prepare("SELECT * FROM orders WHERE number_order = ?");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT * FROM order_details WHERE number_order = ?");
    $stmt->execute([$orderNumber]);
    $orderDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Проверка, существует ли заказ
    if ($order) {
        // Определяем имя файла
        $filename = 'order_' . $order['number_order'] . '.txt';

        // Открываем файл для записи
        $file = fopen($filename, 'w');

        if ($file) {
            // Записываем данные о заказе
            fwrite($file, "ID заказа: {$order['number_order']}\n");
            fwrite($file, "Имя: {$order['user_name']}\n");
            fwrite($file, "Адрес: {$order['addres_street']} {$order['addres_house']} {$order['addres_apt']}\n");
            fwrite($file, "Дата доставки: {$order['delivery_date']}\n");
            fwrite($file, "Время доставки: {$order['delivery_time']}\n");
            fwrite($file, "Статус: {$order['status']}\n");
            fwrite($file, "Общая стоимость: {$order['total_price']} руб.\n");
            fwrite($file, "Детали заказа:\n");

            foreach ($orderDetails as $detail) {
                fwrite($file, " - {$detail['product_name']} - {$detail['quantity']} шт.\n");
            }

            fclose($file); // Закрываем файл
            echo "Заказ успешно сохранен в файл $filename."; // Уведомление об успешном сохранении
        } else {
            echo "Не удалось открыть файл для записи.";
        }
    } else {
        echo "Заказ не найден.";
    }

    exit(); // Останавливаем выполнение скрипта после обработки
}

// Удаление заказа и его деталей
if (isset($_GET['delete'])) {
    $orderNumber = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM orders WHERE number_order = ?");
    $stmt->execute([$orderNumber]);

    $stmt = $db->prepare("DELETE FROM order_details WHERE number_order = ?");
    $stmt->execute([$orderNumber]);

    header('Location: admin_orders.php?period=' . urlencode($period)); // Перенаправление после удаления
    exit();
}

// Обновление данных заказа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderNumber = (int)$_POST['number_order'];
    $postPeriod = isset($_POST['current_period']) ? (string)$_POST['current_period'] : $period;

    if (isset($_POST['update_order'])) {
        $newStatus = $_POST['status'];
        $newStreet = $_POST['street'];
        $newHouse = $_POST['house'];
        $newApt = $_POST['apt'];
        $newTotalPrice = $_POST['total_price'];
        
        // Получаем дату и время отдельно
        $newDeliveryDate = $_POST['delivery_date'];
        $newDeliveryTime = $_POST['delivery_time'];

        // Обновление основного заказа
        $stmt = $db->prepare("UPDATE orders SET status = ?, addres_street = ?, addres_house = ?, addres_apt = ?, delivery_date = ?, delivery_time = ?, total_price = ? WHERE number_order = ?");
        $stmt->execute([$newStatus, $newStreet, $newHouse, $newApt, $newDeliveryDate, $newDeliveryTime, $newTotalPrice, $orderNumber]);

        // Обновление количества товаров
        if (isset($_POST['product_id'])) {
            foreach ($_POST['product_id'] as $index => $productId) {
                $quantity = (int)$_POST['quantity'][$index];
                $stmt = $db->prepare("UPDATE order_details SET quantity = ? WHERE number_order = ? AND product_id = ?");
                $stmt->execute([$quantity, $orderNumber, $productId]);
            }
        }
    }

    header('Location: admin_orders.php?period=' . urlencode($postPeriod));
    exit();
}

// Получение данных заказов и деталей
$stmt = $db->prepare(
    "SELECT o.number_order, o.user_id, o.user_name, 
     o.addres_street, o.addres_house, o.addres_apt,
     o.delivery_date, o.delivery_time, 
     o.status, o.total_price,  
     od.product_id, od.product_name, od.quantity AS order_quantity,
     od.price
     FROM orders o
     LEFT JOIN order_details od ON o.number_order = od.number_order
     WHERE $periodOrdersCondition
     ORDER BY 
        CASE 
            WHEN o.status = 'Доставлен' THEN 1 
            ELSE 0 
        END,
        o.delivery_date ASC,
        o.delivery_time ASC"
);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление заказами</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        img {
            max-width: 100px;
            height: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .edit-button, .save-button, .delete-button, .get-button {
            border: 1px solid rgba(48,33,38,.15);
            padding: 6px 12px;
            cursor: pointer;
            color: var(--color-text);
            background: rgba(255,255,255,.55);
            border-radius: 8px;
            transition: background-color .15s, border-color .15s;
        }
        .edit-button { background-color: rgba(224,202,184,.35); }
        .save-button { background-color: rgba(224,202,184,.35); }
        .delete-button { background-color: rgba(244,67,54,.10); color: #7a1f1a; border-color: rgba(244,67,54,.25); }
        .get-button { background-color: rgba(52,152,219,.10); color: #1f5f8d; border-color: rgba(52,152,219,.25); }
        .hidden { display: none; }

        /* В "просмотре" disabled поля должны выглядеть как обычный текст */
        input:disabled, select:disabled, textarea:disabled {
            background-color: transparent;
            border: none;
            padding: 0;
            margin: 0;
            box-shadow: none;
            width: auto;
        }
        textarea:disabled { resize: none; }

        /* Статус: в режиме просмотра текст, в режиме редактирования select */
        .status-text { display: inline; }
        .status-select { display: none; }
        .edit-mode .status-text { display: none; }
        .edit-mode .status-select { display: inline-block; }
    </style>
    <script>
        function toggleEdit(orderNumber) {
            const orderRow = document.getElementById(`order-row-${orderNumber}`);
            const editButton = document.getElementById(`edit-button-${orderNumber}`);
            const saveButton = document.getElementById(`save-button-${orderNumber}`);
            const inputs = orderRow.querySelectorAll('input[type="text"], input[type="date"], input[type="time"], select, input[type="number"]');

            inputs.forEach(input => {
                input.disabled = !input.disabled;
            });

            orderRow.classList.toggle('edit-mode');
            editButton.classList.toggle('hidden');
            saveButton.classList.toggle('hidden');
        }

        // Функция для пересчета общей стоимости
        function updateTotalPrice(orderNumber) {
            const orderRow = document.getElementById(`order-row-${orderNumber}`);
            const quantityInputs = orderRow.querySelectorAll('input[name="quantity[]"]');
            const priceInputs = orderRow.querySelectorAll('input[name="price[]"]');
            let totalPrice = 0;

            quantityInputs.forEach((input, index) => {
                const quantity = parseInt(input.value) || 0;
                const price = parseFloat(priceInputs[index].value) || 0;
                totalPrice += quantity * price;
            });

            // Обновляем отображение общей стоимости
            const totalPriceCell = orderRow.querySelector('.total-price');
            if (totalPriceCell) {
                totalPriceCell.textContent = totalPrice.toFixed(2) + ' руб.';
            }

            // Обновляем скрытое поле с общей стоимостью
            const totalPriceInput = orderRow.querySelector('input[name="total_price"]');
            if (totalPriceInput) {
                totalPriceInput.value = totalPrice.toFixed(2);
            }
        }

        // Добавляем обработчики событий для всех полей количества
        document.addEventListener('DOMContentLoaded', function() {
            const quantityInputs = document.querySelectorAll('input[name="quantity[]"]');
            quantityInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const orderNumber = this.closest('tr').id.split('-')[2];
                    updateTotalPrice(orderNumber);
                });
            });
        });
    </script>
</head>
<body>
<?php require __DIR__ . '/includes/header_admin.php'; ?>

<div class="content">
    <h2>Управление заказами</h2>

    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
        <form method="get" action="admin_orders.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label for="period"><strong>Период списка:</strong></label>
            <select id="period" name="period">
                <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Неделя</option>
                <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Месяц</option>
                <option value="3months" <?= $period === '3months' ? 'selected' : '' ?>>3 месяца</option>
                <option value="6months" <?= $period === '6months' ? 'selected' : '' ?>>6 месяцев</option>
                <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Все</option>
            </select>
            <button type="submit" class="edit-button">Применить</button>
        </form>

        <a href="admin_orders.php?sales_report=1&period=<?= urlencode($period) ?>" class="get-button" style="text-decoration:none;">
            Отчет о продажах
        </a>
    </div>

    <table>
        <thead>
            <tr>
                <th></th>
                <th>Номер заказа</th>
                <th>Пользователь</th>
                <th>Адрес</th>
                <th>Дата доставки</th>
                <th>Время доставки</th>
                <th>Статус</th>
                <th>Общая стоимость</th>
                <th>Детали заказа</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $orderNumber => $orderDetails): ?>
                <?php $first = $orderDetails[0]; ?>
                
                <tr id="order-row-<?= htmlspecialchars($orderNumber) ?>">
                    <form action="admin_orders.php" method="post">
                        <input type="hidden" name="current_period" value="<?= htmlspecialchars($period) ?>">
                        <td>
                            <a href="?delete=<?= htmlspecialchars($orderNumber) ?>&period=<?= urlencode($period) ?>" onclick="return confirm('Вы уверены, что хотите удалить этот заказ?');">
                                <button type="button" class="delete-button">✖</button>
                            </a>
                        </td>
                        <td rowspan="<?= count($orderDetails) ?>"><?= htmlspecialchars($orderNumber) ?></td>
                        <td rowspan="<?= count($orderDetails) ?>">ID: <?= htmlspecialchars($first['user_id']) ?><br>Имя: <?= htmlspecialchars($first['user_name']) ?></td>
                        <td rowspan="<?= count($orderDetails) ?>" class="address-cell">
                            <input type="text" name="street" value="<?= htmlspecialchars($first['addres_street']) ?>" required disabled>
                            <input type="text" name="house" value="<?= htmlspecialchars($first['addres_house']) ?>" required disabled>
                            <input type="text" name="apt" value="<?= htmlspecialchars($first['addres_apt']) ?>" required disabled>
                        </td>
                        <td rowspan="<?= count($orderDetails) ?>" class="delivery-cell">
                            <input type="date" name="delivery_date" value="<?= htmlspecialchars($first['delivery_date']) ?>" required disabled>
                        </td>
                        <td rowspan="<?= count($orderDetails) ?>" class="delivery-cell">
                            <input type="time" name="delivery_time" value="<?= htmlspecialchars($first['delivery_time']) ?>" required disabled>
                        </td>
                        <td rowspan="<?= count($orderDetails) ?>">
                            <span class="status-text"><?= htmlspecialchars($first['status']) ?></span>
                            <select name="status" disabled class="status-select">
                                <option value="Принят" <?= $first['status'] === 'Принят' ? 'selected' : '' ?>>Принят</option>
                                <option value="Доставлен" <?= $first['status'] === 'Доставлен' ? 'selected' : '' ?>>Доставлен</option>
                            </select>
                            <input type="hidden" name="number_order" value="<?= htmlspecialchars($orderNumber) ?>">
                        </td>
                        <td rowspan="<?= count($orderDetails) ?>" class="total-price"><?= htmlspecialchars($first['total_price']) ?> руб.</td>
                        <input type="hidden" name="total_price" value="<?= htmlspecialchars($first['total_price']) ?>">
                        <td>
                            <?php foreach ($orderDetails as $index => $detail): ?>
                                <div>
                                    <?= htmlspecialchars($detail['product_name']) ?> - 
                                    <input type="number" name="quantity[]" value="<?= htmlspecialchars($detail['order_quantity']) ?>" min="1" required disabled>
                                    <input type="hidden" name="product_id[]" value="<?= htmlspecialchars($detail['product_id']) ?>">
                                    <input type="hidden" name="price[]" value="<?= htmlspecialchars($detail['price']) ?>">
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td rowspan="<?= count($orderDetails) ?>">
                            <button type="button" class="edit-button" id="edit-button-<?= htmlspecialchars($orderNumber) ?>" onclick="toggleEdit(<?= htmlspecialchars($orderNumber) ?>)">Изменить</button>
                            <button type="submit" name="update_order" class="save-button hidden" id="save-button-<?= htmlspecialchars($orderNumber) ?>">Готово</button>
                            <button type="submit" name="get_order" class="get-button" formaction="admin_orders.php?get=<?= htmlspecialchars($orderNumber) ?>">Получить</button>
                        </td>
                    </form>
                </tr>
                
                <?php // Добавляем отдельные строки для деталей заказа ?>
                <?php foreach ($orderDetails as $index => $detail): ?>
                    <?php if ($index > 0): // Пропускаем первую строку, так как она уже создана ?>
                        <tr>
                            <td colspan="6"></td> <!-- Пустая ячейка для выравнивания -->
                            <td><?= htmlspecialchars($detail['product_name']) ?> - <?= htmlspecialchars($detail['order_quantity']) ?> шт.</td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<footer>
    <p>&copy; 2024 Кондитерская "Kriter"</p>
</footer>
</body>
</html>