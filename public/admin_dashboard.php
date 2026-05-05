<?php
session_start();
require 'db.php'; // Подключение к базе данных

// Проверка, авторизован ли администратор
if (!isset($_SESSION['admin_user'])) {
    header('Location: login_admin.php'); // Перенаправление на страницу авторизации
    exit();
}

$db = getDbConnection();

// Обработка удаления товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    $productId = (int)$_POST['product_id'];

    // Удаление товара из базы данных
    $stmt = $db->prepare("DELETE FROM menu WHERE product_id = ?");
    $stmt->execute([$productId]);

    // Перенаправление после удаления
    header('Location: admin_dashboard.php'); 
    exit();
}

// Определение параметров сортировки
$orderBy = 'm.product_id'; // По умолчанию сортировка по ID товара
$orderDir = 'ASC'; // По умолчанию сортировка по возрастанию

if (isset($_GET['sort_by']) && isset($_GET['order'])) {
    $orderBy = match($_GET['sort_by']) {
        'price' => 'm.price',
        'quantity' => 'm.quantity',
        'category' => 'c.category_name',
        'popularity' => 'COALESCE(stats.orders_count, 0)',
        default => 'm.product_id'
    };
    $orderDir = $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
}

// Обновление данных товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $productId = (int)$_POST['product_id'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = (float)$_POST['price'];
    $quantity = (int)$_POST['quantity'];

    try {
        // Обновление товара в базе данных
        $stmt = $db->prepare("UPDATE menu SET name = ?, description = ?, price = ?, quantity = ? WHERE product_id = ?");
        $result = $stmt->execute([$name, $description, $price, $quantity, $productId]);
        
        if ($result) {
            // Успешное обновление
            header('Location: admin_dashboard.php?success=1');
        } else {
            // Ошибка обновления
            header('Location: admin_dashboard.php?error=1');
        }
        exit();
    } catch (PDOException $e) {
        // Ошибка базы данных
        header('Location: admin_dashboard.php?error=2');
        exit();
    }
}

// Получение данных о товарах и их категориях с учетом сортировки
$stmt = $db->prepare("
    SELECT
        m.product_id,
        m.name,
        m.description,
        m.price,
        m.quantity,
        m.image_url,
        c.category_name,
        m.category_id,
        COALESCE(stats.orders_count, 0) AS orders_count,
        COALESCE(recipe.composition_list, '') AS composition_list
    FROM menu m
    LEFT JOIN categories c ON m.category_id = c.category_id
    LEFT JOIN (
        SELECT
            product_id,
            COUNT(DISTINCT number_order) AS orders_count
        FROM order_details
        GROUP BY product_id
    ) AS stats ON stats.product_id = m.product_id
    LEFT JOIN (
        SELECT
            pi.product_id,
            GROUP_CONCAT(
                CONCAT(i.name, ' (', (pi.qty_per_product + 0), ' ', i.unit, ')')
                ORDER BY i.name SEPARATOR ', '
            ) AS composition_list
        FROM product_ingredients pi
        JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
        GROUP BY pi.product_id
    ) AS recipe ON recipe.product_id = m.product_id
    ORDER BY $orderBy $orderDir
");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Группировка товаров по категориям
$groupedProducts = [];
foreach ($products as $product) {
    $categoryName = $product['category_name'];
    if (!isset($groupedProducts[$categoryName])) {
        $groupedProducts[$categoryName] = [];
    }
    $groupedProducts[$categoryName][] = $product;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ Панель - Управление Товарами</title>
    <link rel="stylesheet" href="styles.css">
    <style>
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
        
        td:nth-child(6) input[type="number"],
        td:nth-child(7) input[type="number"] {
            width: 70px;
            max-width: 70px;
            box-sizing: border-box;
        }
        /* Сужаем столбцы "Цена" и "Количество" */
        th:nth-child(6), td:nth-child(6),
        th:nth-child(7), td:nth-child(7) {
            width: 90px;
        }
        td:nth-child(6) input[type="number"],
        td:nth-child(7) input[type="number"] {
            width: 70px;
            max-width: 70px;
            box-sizing: border-box;
        }
        img {
            max-width: 100px;
            height: auto;
        }
        .sort-buttons {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .sort-buttons span {
            margin-right: 10px;
            font-weight: bold;
        }
        .sort-buttons a {
            padding: 8px 12px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .sort-buttons a:hover {
            background-color: #0056b3;
        }
        .sort-buttons a.active {
            background-color: #0056b3;
            font-weight: bold;
        }
        .reset-button {
            background-color: #dc3545 !important;
            margin-left: auto;
        }
        .reset-button:hover {
            background-color: #c82333 !important;
        }
        .edit-button, .save-button, .delete-button {
            padding: 6px 12px;
            cursor: pointer;
            color: var(--color-text);
            border: 1px solid rgba(48,33,38,.15);
            background: rgba(255,255,255,.55);
            border-radius: 8px;
            transition: background-color .15s, border-color .15s;
        }
        .edit-button { background-color: rgba(224,202,184,.35); }
        .save-button { background-color: rgba(224,202,184,.35); }
        .delete-button { background-color: rgba(244,67,54,.10); color: #7a1f1a; border-color: rgba(244,67,54,.25); }
        .hidden { display: none; }
        input[type="text"], input[type="number"], textarea {
            width: 100%;
            padding: 5px;
            margin: 5px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .add-product-button {
    padding: 10px 15px;
    background-color: #28a745; /* Green color */
    color: white;
    text-decoration: none;
    border-radius: 4px;
    margin-bottom: 20px;
    display: inline-block;
}

.add-product-button:hover {
    background-color: #218838; /* Darker green on hover */
}
        .hidden {
            display: none;
        }
        .edit-button, .save-button {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 2px;
        }
        .edit-button {
            background-color: rgba(224,202,184,.35);
            color: var(--color-text);
        }
        .save-button {
            background-color: rgba(224,202,184,.35);
            color: var(--color-text);
        }
        .delete-button {
            background-color: rgba(244,67,54,.10);
            color: #7a1f1a;
            border: 1px solid rgba(244,67,54,.25);
            border-radius: 8px;
            padding: 6px 12px;
            cursor: pointer;
        }
        /* В режиме "просмотра" disabled-поля должны выглядеть как текст, а не как input */
        input:disabled, textarea:disabled {
            background-color: transparent;
            border: none;
            padding: 0;
            margin: 0;
            box-shadow: none;
            width: auto;
        }
        textarea:disabled {
            resize: none;
        }
        textarea[name="description"] {
            overflow: hidden;
        }
        .composition-view {
            width: auto;
            min-height: auto;
            resize: none;
        }
        .scroll-to-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #4CAF50;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            cursor: pointer;
            display: none;
            transition: background-color 0.3s;
            z-index: 1000;
        }
        .scroll-to-top:hover {
            background-color: #45a049;
        }
        .scroll-to-top::before {
            content: "↑";
            font-size: 24px;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header_admin.php'; ?>

<div class="content">
    <?php if (isset($_GET['success'])): ?>
        <div class="success-message" style="color: green; margin-bottom: 20px;">
            Товар успешно обновлен!
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="error-message" style="color: red; margin-bottom: 20px;">
            <?php
            switch ($_GET['error']) {
                case '1':
                    echo 'Ошибка при обновлении товара.';
                    break;
                case '2':
                    echo 'Ошибка базы данных.';
                    break;
                default:
                    echo 'Произошла ошибка.';
            }
            ?>
        </div>
    <?php endif; ?>
    <h2>Список Товаров</h2>
    <a href="add_product.php" class="add-product-button">Добавить Товар</a>
    <div class="sort-buttons">
        <span>Сортировать по:</span>
        <a href="?sort_by=category&order=asc" <?= ($orderBy === 'c.category_name' && $orderDir === 'ASC') ? 'class="active"' : '' ?>>Категории (A-Z)</a>
        <a href="?sort_by=category&order=desc" <?= ($orderBy === 'c.category_name' && $orderDir === 'DESC') ? 'class="active"' : '' ?>>Категории (Z-A)</a>
        <a href="?sort_by=price&order=asc" <?= ($orderBy === 'm.price' && $orderDir === 'ASC') ? 'class="active"' : '' ?>>Цене (от меньшего)</a>
        <a href="?sort_by=price&order=desc" <?= ($orderBy === 'm.price' && $orderDir === 'DESC') ? 'class="active"' : '' ?>>Цене (от большего)</a>
        <a href="?sort_by=quantity&order=asc" <?= ($orderBy === 'm.quantity' && $orderDir === 'ASC') ? 'class="active"' : '' ?>>Количество (от меньшего)</a>
        <a href="?sort_by=quantity&order=desc" <?= ($orderBy === 'm.quantity' && $orderDir === 'DESC') ? 'class="active"' : '' ?>>Количество (от большего)</a>
        <a href="?sort_by=popularity&order=asc" <?= ($orderBy === 'COALESCE(stats.orders_count, 0)' && $orderDir === 'ASC') ? 'class="active"' : '' ?>>Популярности (от меньшего)</a>
        <a href="?sort_by=popularity&order=desc" <?= ($orderBy === 'COALESCE(stats.orders_count, 0)' && $orderDir === 'DESC') ? 'class="active"' : '' ?>>Популярности (от большего)</a>
        <a href="admin_dashboard.php" class="reset-button">Сбросить</a>
    </div>
    
    <table>
        <thead>
            <tr>
                <th></th>
                <th>ID товара</th>
                <th>Название</th>
                <th>Описание</th>
                <th>Состав</th>
                <th>Цена</th>
                <th>Количество</th>
                <th>Продаж</th>
                <th>Изображение</th>
                <th>Категория</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groupedProducts as $categoryName => $categoryProducts): ?>
                <tr class="category-header">
                    <td colspan="11" style="background-color: #f2f2f2; font-weight: bold; text-align: center;">
                        <?= htmlspecialchars($categoryName) ?>
                    </td>
                </tr>
                <?php foreach ($categoryProducts as $product): ?>
                    <tr>
                        <form action="admin_dashboard.php" method="post">
                            <td>
                                <button type="submit" name="delete_product" class="delete-button" onclick="return confirm('Вы уверены, что хотите удалить этот товар?');">✖</button>
                            </td>
                            <td>
                                <input type="hidden" name="product_id" value="<?= htmlspecialchars($product['product_id']) ?>">
                                <?= htmlspecialchars($product['product_id']) ?>
                            </td>
                            <td>
                                <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required disabled>
                            </td>
                            <td>
                                <textarea name="description" required disabled><?= htmlspecialchars($product['description']) ?></textarea>
                            </td>
                            <td>
                                <textarea class="composition-view" disabled readonly><?= htmlspecialchars($product['composition_list'] ?? '') ?></textarea>
                                <div class="recipe-link hidden" style="margin-top:6px;">
                                    <a href="product_ingredients_admin.php?product_id=<?= (int)$product['product_id'] ?>">Редактировать</a>
                                </div>
                            </td>
                            <td>
                                <input type="number" name="price" value="<?= htmlspecialchars($product['price']) ?>" required disabled>
                            </td>
                            <td>
                                <input type="number" name="quantity" value="<?= htmlspecialchars($product['quantity']) ?>" required disabled>
                            </td>
                            <td><?= (int)($product['orders_count'] ?? 0) ?></td>
                            <td>
                                <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" style="max-width: 100px; height: auto;">
                            </td>
                            <td><?= htmlspecialchars($product['category_name']) ?></td>
                            <td>
                                <button type="button" class="edit-button" onclick="toggleEdit(this)">Изменить</button>
                                <button type="submit" name="update_product" class="save-button hidden">Готово</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<button class="scroll-to-top" onclick="scrollToTop()"></button>

<footer>
    <p>&copy; 2024 Кондитерская "Kriter"</p>
</footer>

<script>
    function autosizeTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    function toggleEdit(button) {
        const row = button.closest('tr');
        const inputs = row.querySelectorAll(
            'input[name="name"], textarea[name="description"], input[name="price"], input[name="quantity"]'
        );
        const saveButton = row.querySelector('.save-button');
        const editButton = row.querySelector('.edit-button');
        const recipeLink = row.querySelector('.recipe-link');
        
        inputs.forEach(input => {
            input.disabled = !input.disabled;
            if (input.tagName === 'TEXTAREA') {
                autosizeTextarea(input);
            }
        });
        
        saveButton.classList.toggle('hidden');
        editButton.classList.toggle('hidden');

        if (recipeLink) {
            recipeLink.classList.toggle('hidden');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('textarea[name="description"]').forEach(function (t) {
            autosizeTextarea(t);
        });

        document.addEventListener('input', function (e) {
            const t = e.target;
            if (t && t.tagName === 'TEXTAREA' && t.name === 'description') {
                autosizeTextarea(t);
            }
        });
    });

    // Показываем/скрываем кнопку при прокрутке
    window.onscroll = function() {
        const scrollButton = document.querySelector('.scroll-to-top');
        if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
            scrollButton.style.display = "block";
        } else {
            scrollButton.style.display = "none";
        }
    };

    // Функция плавной прокрутки вверх
    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }
</script>

</body>
</html>