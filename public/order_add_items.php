<?php
session_start();
require 'db.php';
require_once __DIR__ . '/includes/order_modify_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: register.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$orderNumber = isset($_GET['order']) ? (int)$_GET['order'] : 0;

$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE number_order = ? AND user_id = ?');
$stmt->execute([$orderNumber, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: order.php?error=denied');
    exit();
}
if (($order['status'] ?? '') !== 'Принят') {
    header('Location: order.php?error=denied');
    exit();
}
if (!order_can_modify_before_delivery($order)) {
    header('Location: order.php?error=locked');
    exit();
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_order'])) {
    $postOrder = (int)($_POST['number_order'] ?? 0);
    if ($postOrder !== $orderNumber) {
        header('Location: order.php?error=denied');
        exit();
    }

    $productId = (int)($_POST['product_id'] ?? 0);
    $addQty = (int)($_POST['quantity'] ?? 0);

    if ($productId <= 0 || $addQty <= 0) {
        $errorMessage = 'Выберите товар и укажите количество больше нуля.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT product_id, name, price, quantity FROM menu WHERE product_id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new Exception('Товар не найден.');
            }
            if ((int)$product['quantity'] < $addQty) {
                throw new Exception('Недостаточно товара на складе (остаток: ' . (int)$product['quantity'] . ').');
            }

            order_ingredients_apply_product_qty_delta($pdo, $productId, $addQty);

            $stmt = $pdo->prepare('SELECT quantity, price FROM order_details WHERE number_order = ? AND product_id = ? FOR UPDATE');
            $stmt->execute([$orderNumber, $productId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $price = (float)$product['price'];
            if ($existing) {
                $newQ = (int)$existing['quantity'] + $addQty;
                $stmt = $pdo->prepare('
                    UPDATE order_details
                    SET quantity = ?, price = ?
                    WHERE number_order = ? AND product_id = ?
                ');
                $stmt->execute([$newQ, $price, $orderNumber, $productId]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO order_details (number_order, product_id, product_name, quantity, price)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$orderNumber, $productId, $product['name'], $addQty, $price]);
            }

            $stmt = $pdo->prepare('UPDATE menu SET quantity = quantity - ? WHERE product_id = ?');
            $stmt->execute([$addQty, $productId]);

            $stmt = $pdo->prepare('SELECT SUM(price * quantity) AS t FROM order_details WHERE number_order = ?');
            $stmt->execute([$orderNumber]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('UPDATE orders SET total_price = ? WHERE number_order = ?');
            $stmt->execute([$total['t'] ?? 0, $orderNumber]);

            $pdo->commit();
            header('Location: order.php?success=items');
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $e->getMessage();
        }
    }
}

$stmt = get_menu_all();
$menu = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$byCategory = [];
foreach ($menu as $p) {
    $byCategory[(int)$p['category_id']][] = $p;
}
ksort($byCategory);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить товар к заказу №<?= (int)$orderNumber ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .wrap { max-width: 720px; margin: 20px auto; padding: 0 16px; }
        select, input[type="number"], button { padding: 8px; margin: 4px 0; }
        select { width: 100%; max-width: 100%; }
        .err { color: #c62828; margin: 12px 0; }
        .hint { color: #555; font-size: 14px; margin-bottom: 16px; }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header_client.php'; ?>

<div class="wrap content">
    <h1>Заказ №<?= (int)$orderNumber ?> — добавить товар</h1>
    <p class="hint">Время доставки изменить нельзя. Добавлять товары можно только если до доставки больше 3 часов.</p>
    <p><a href="order.php">← К моим заказам</a></p>

    <?php if ($errorMessage): ?>
        <p class="err"><?= htmlspecialchars($errorMessage) ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="add_to_order" value="1">
        <input type="hidden" name="number_order" value="<?= (int)$orderNumber ?>">
        <label for="product_id">Товар</label>
        <select name="product_id" id="product_id" required>
            <option value="">— Выберите —</option>
            <?php foreach ($byCategory as $cid => $products):
                $cat = get_category_by_id($cid);
                $catLabel = $cat ? $cat['category_name'] : ('Категория ' . $cid);
                ?>
                <optgroup label="<?= htmlspecialchars($catLabel) ?>">
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['product_id'] ?>">
                            <?= htmlspecialchars($p['name']) ?> — <?= htmlspecialchars((string)$p['price']) ?> руб. (на складе: <?= (int)$p['quantity'] ?>)
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <label for="quantity">Количество</label>
        <input type="number" name="quantity" id="quantity" min="1" value="1" required>
        <button type="submit">Добавить в заказ</button>
    </form>
</div>
</body>
</html>
