<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin_user'])) {
    header('Location: login_admin.php');
    exit();
}

$db = getDbConnection();
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if ($productId <= 0) {
    header('Location: admin_dashboard.php');
    exit();
}

$stmt = $db->prepare("SELECT product_id, name FROM menu WHERE product_id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    header('Location: admin_dashboard.php');
    exit();
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_row'])) {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            $qty = (float)($_POST['qty_per_product'] ?? 0);

            if ($ingredientId <= 0 || $qty <= 0) {
                $errorMessage = 'Выберите ингредиент и укажите количество > 0.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO product_ingredients (product_id, ingredient_id, qty_per_product)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE qty_per_product = VALUES(qty_per_product)
                ");
                $stmt->execute([$productId, $ingredientId, $qty]);
                header("Location: product_ingredients_admin.php?product_id=$productId&success=1");
                exit();
            }
        }

        if (isset($_POST['update_row'])) {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            $qty = (float)($_POST['qty_per_product'] ?? 0);
            if ($ingredientId <= 0 || $qty <= 0) {
                $errorMessage = 'Количество должно быть > 0.';
            } else {
                $stmt = $db->prepare("
                    UPDATE product_ingredients
                    SET qty_per_product = ?
                    WHERE product_id = ? AND ingredient_id = ?
                ");
                $stmt->execute([$qty, $productId, $ingredientId]);
                header("Location: product_ingredients_admin.php?product_id=$productId&success=1");
                exit();
            }
        }

        if (isset($_POST['delete_row'])) {
            $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
            if ($ingredientId > 0) {
                $stmt = $db->prepare("DELETE FROM product_ingredients WHERE product_id = ? AND ingredient_id = ?");
                $stmt->execute([$productId, $ingredientId]);
                header("Location: product_ingredients_admin.php?product_id=$productId&success=1");
                exit();
            }
        }
    } catch (PDOException $e) {
        $errorMessage = "Ошибка БД: " . $e->getMessage();
    }
}

$ingredients = $db->query("SELECT ingredient_id, name, unit FROM ingredients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT pi.ingredient_id, i.name, i.unit, pi.qty_per_product
    FROM product_ingredients pi
    JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
    WHERE pi.product_id = ?
    ORDER BY i.name ASC
");
$stmt->execute([$productId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ингредиенты товара</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        img {
            max-width: 100px;
            height: auto;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .row { display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; align-items: end; }
        select, input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; }
        button { padding: 8px 12px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-add { background-color: #28a745; color: #fff; }
        .btn-del { background-color: rgba(244,67,54,.10); color: #7a1f1a; border: 1px solid rgba(244,67,54,.25); }
        .btn-edit { background-color: rgba(224,202,184,.35); border: 1px solid rgba(48,33,38,.15); }
        .btn-save { background-color: rgba(224,202,184,.35); border: 1px solid rgba(48,33,38,.15); }
        .hidden { display: none; }
        .msg { margin: 12px 0; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap: 12px; }
        .topbar a { text-decoration:none; }
        /* disabled inputs should look like text */
        input:disabled {
            background-color: transparent;
            border: none;
            padding: 0;
            margin: 0;
            box-shadow: none;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header_admin.php'; ?>

<div class="content">
    <div class="topbar">
        <h2>Рецепт: <?= htmlspecialchars($product['name']) ?></h2>
        <a href="admin_dashboard.php" class="add-product-button" style="background:#007bff;">← Назад</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="msg" style="color: green;">Готово.</div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="msg" style="color: red;"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post" class="row" style="margin-bottom: 12px;">
        <div>
            <label>Ингредиент</label>
            <select name="ingredient_id" required>
                <option value="">Выберите</option>
                <?php foreach ($ingredients as $ing): ?>
                    <option value="<?= (int)$ing['ingredient_id'] ?>">
                        <?= htmlspecialchars($ing['name']) ?> (<?= htmlspecialchars($ing['unit']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Колличество</label>
            <input type="number" step="0.001" name="qty_per_product" placeholder="Напр. 0.120" required>
        </div>
        <div style="padding-bottom: 2px;">
            <button type="submit" name="add_row" class="btn-add">Добавить</button>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>Ингредиент</th>
                <th>Кол-во на 1 шт.</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['unit']) ?>)</td>
                <td>
                    <form method="post" class="recipe-row-form" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="ingredient_id" value="<?= (int)$r['ingredient_id'] ?>">
                        <input type="number" step="0.001" name="qty_per_product" value="<?= htmlspecialchars($r['qty_per_product']) ?>" required disabled style="max-width: 160px;">
                        
                    </form>
                </td>
                <td style="white-space: nowrap;">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="ingredient_id" value="<?= (int)$r['ingredient_id'] ?>">
                        <button type="button" class="btn-edit" onclick="toggleRowEdit(this)">Изменить</button>
                        <button type="submit" name="update_row" class="btn-save hidden">Сохранить</button>
                        <button type="submit" name="delete_row" class="btn-del" onclick="return confirm('Удалить из рецепта?');">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function toggleRowEdit(button) {
    const form = button.closest('form');
    if (!form) return;
    const input = form.querySelector('input[name="qty_per_product"]');
    const saveBtn = form.querySelector('button[name="update_row"]');
    if (!input || !saveBtn) return;

    input.disabled = false;
    input.focus();
    button.classList.add('hidden');
    saveBtn.classList.remove('hidden');
}
</script>

</body>
</html>

