<!DOCTYPE html>
<html lang="ru">
<head>
<?php 
session_start(); // Начинаем сессию
require 'db.php';

// Получаем количество товаров в корзине пользователя
$cartItems = [];
if (isset($_SESSION['user_id'])) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT product_id, quantity FROM cart WHERE user_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cartItems[(int)$row['product_id']] = (int)$row['quantity'];
    }
}
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Меню - Кондитерская "Kriter"</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        img {
            max-width: 100px;
            height: auto;
        }
        .product-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center; /* Центрирование элементов */
            gap: 20px; /* Промежуток между продуктами */
        }
        .product-item {
            flex: 0 1 calc(33.333% - 20px); /* Каждому элементу по 1/3 ширины с учетом отступа */
            box-sizing: border-box; /* Учитываем отступы и границы */
            text-align: center; /* Центрируем текст */
            padding: 15px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .product-item img {
            width: 200px;
            height: 200px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .go-to-cart {
            display: block;
            margin-top: 20px;
            text-align: center;
        }
        .category {
            font-size: 28px; /* Увеличьте размер шрифта по вашему выбору */
            font-weight: bold;
            border-bottom: 3px solid #ff5733; /* Цвет линии и её толщина */
            padding-bottom: 10px; /* Отступ под заголовком */
            margin-bottom: 15px; /* Отступ ниже заголовка */
        }
        h2 {
            font-size: 36px; /* Увеличьте размер шрифта по вашему выбору */
            font-weight: bold; /* Делаем текст жирным */
            text-align: center; /* Центрируем текст */
            margin-bottom: 20px; /* Отступ снизу */
        }
        .category-navigation {
            text-align: center;
            margin-bottom: 20px;
        }
        .category-button {
            display: inline-block;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
            margin: 0 5px;
            transition: background-color 0.3s;
        }
        .search-container {
            text-align: center;
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }
        .search-container input {
            padding: 10px;
            width: 200px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .search-container input:focus {
            outline: none;
            border-color: #3498db;
        }
        .search-container input::placeholder {
            color: #999;
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

        .product-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 9998;
            display: none;
        }
        .product-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: min(720px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
            z-index: 9999;
            display: none;
            padding: 18px 18px 14px;
        }
        .product-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .product-modal-title {
            margin: 0;
            font-size: 22px;
            line-height: 1.25;
        }
        .product-modal-close {
            border: none;
            background: rgba(0,0,0,0.06);
            width: 36px;
            height: 36px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            line-height: 1;
            padding: 0;
        }
        .product-modal-body {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 14px;
        }
        .product-modal-img {
            max-width: none; /* перебиваем глобальное img { max-width: 100px } */
            width: 220px;
            aspect-ratio: 1 / 1;
            height: auto;
            object-fit: cover;
            border-radius: 10px;
            background: #f2f2f2;
        }
        .product-modal-meta p {
            margin: 0 0 10px 0;
        }
        .product-modal-section-title {
            margin: 12px 0 6px;
            font-weight: 700;
        }
        .cart-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }
        .qty-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: #3498db;
            color: #fff;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .qty-btn.minus {
            background: #95a5a6;
        }
        .qty-value {
            min-width: 28px;
            text-align: center;
            font-weight: 700;
        }
        .hidden {
            display: none !important;
        }
        @media (max-width: 640px) {
            .product-modal-body {
                grid-template-columns: 1fr;
            }
            .product-modal-img {
                max-width: none;
                width: 100%;
                aspect-ratio: 1 / 1;
                height: auto;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/header_client.php'; ?>

<div class="content">
    <h2>Наше меню</h2>

    <!-- Поле поиска -->
    <form class="search-container" method="get" action="menu.php" id="searchForm">
        <input
            type="text"
            id="searchInput"
            name="search"
            placeholder="Поиск по имени товара"
            maxlength="20"
            value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>"
        >
        <button type="submit" id="searchButton">Поиск</button>
        <button type="button" id="resetButton" class="reset-button">Сбросить</button>
    </form>

    <!-- Навигация по категориям -->
    <div class="category-navigation">
        <a href="?category_id=1" class="category-button">Торты</a>
        <a href="?category_id=2" class="category-button">Даниш</a>
        <a href="?category_id=3" class="category-button">Эклеры</a>
        <a href="?category_id=4" class="category-button">Слойки</a>
        <a href="?category_id=5" class="category-button">Макароны</a>
        <a href="?category_id=6" class="category-button">Пирожные</a>
    </div>

    <div class="product-grid">
        <?php
        // Получаем меню из базы данных
        $stmt = get_menu_all(); // Предполагается, что это возвращает PDOStatement
        $menu = $stmt->fetchAll(PDO::FETCH_ASSOC); // Получаем все результаты в виде массива

        // Проверяем наличие параметра поиска
        $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

        // Фильтруем продукты по имени (без учёта регистра, с поддержкой кириллицы)
        if ($searchTerm !== '') {
            $searchLower = mb_strtolower($searchTerm, 'UTF-8');
            $menu = array_filter($menu, function($product) use ($searchLower) {
                $nameLower = mb_strtolower($product['name'], 'UTF-8');
                return mb_strpos($nameLower, $searchLower, 0, 'UTF-8') !== false;
            });
        }

        // Подтягиваем "состав" из таблиц ингредиентов (если настроено)
        $compositionByProductId = [];
        if (!empty($menu)) {
            $pdo = getDbConnection();
            $productIds = array_values(array_unique(array_map(function($p) { return (int)$p['product_id']; }, $menu)));
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    pi.product_id,
                    GROUP_CONCAT(
                        CONCAT(i.name, ' (', (pi.qty_per_product + 0), ' ', i.unit, ')')
                        ORDER BY i.name SEPARATOR ', '
                    ) AS composition_list
                FROM product_ingredients pi
                JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
                WHERE pi.product_id IN ($placeholders)
                GROUP BY pi.product_id
            ");
            $stmt->execute($productIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $compositionByProductId[(int)$row['product_id']] = $row['composition_list'];
            }

            foreach ($menu as &$productRef) {
                $pid = (int)$productRef['product_id'];
                $productRef['composition'] = $compositionByProductId[$pid] ?? ($productRef['composition'] ?? '');
            }
            unset($productRef);
        }

        // Массив для хранения продуктов по категориям
        $categories = [];

        // Группируем продукты по категориям
        foreach ($menu as $product) {
            $categoryId = $product["category_id"];
            $categories[$categoryId][] = $product;
        }

        // Получаем идентификатор категории из параметров URL
        $selectedCategoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;

        // Выводим продукты по категориям
        foreach ($categories as $categoryId => $products):
            // Если выбрана категория и она не совпадает, пропускаем
            if ($selectedCategoryId && $categoryId !== $selectedCategoryId) {
                continue;
            }
        ?>
            <div class="product-category" id="category_<?php echo $categoryId; ?>">
                <h3 class="category"><?php echo get_category_by_id($categoryId)["category_name"]; ?></h3>
                <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-item">
                            <p><img src="<?php echo $product["image_url"]; ?>" alt="Торт"></p>
                            <p><?php echo $product["name"]; ?> - <strong><?php echo $product["price"]; ?> руб.</strong></p>
                            <p><?php echo $product["description"]; ?></p>
                            <?php if (isset($_SESSION['user_id'])): ?>
                                <form method="post" action="add_to_cart.php" class="add-to-cart-form" data-product-id="<?= $product['product_id'] ?>">
                                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                    <button type="submit" class="add-to-cart-btn" data-product-id="<?= $product['product_id'] ?>">В корзину</button>
                                    <div class="cart-controls hidden" data-product-id="<?= $product['product_id'] ?>">
                                        <button type="button" class="qty-btn minus" data-action="dec">-</button>
                                        <span class="qty-value">0</span>
                                        <button type="button" class="qty-btn plus" data-action="inc">+</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <a href="register.php" class="btn">Войти, чтобы добавить</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="go-to-cart">
        <a href="cart.php" class="btn">Перейти в корзину</a>
    </div>
    
    <button class="scroll-to-top" type="button"></button>
</div>

<footer>
    <p>&copy; 2023 Кондитерская "Kriter"</p>
</footer>

<div class="product-modal-overlay" id="productModalOverlay" aria-hidden="true"></div>
<div class="product-modal" id="productModal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
    <div class="product-modal-header">
        <h3 class="product-modal-title" id="productModalTitle"></h3>
        <button type="button" class="product-modal-close" id="productModalClose" aria-label="Закрыть">✕</button>
    </div>
    <div class="product-modal-body">
        <div>
            <img class="product-modal-img" id="productModalImg" alt="">
        </div>
        <div class="product-modal-meta">
            <p><strong id="productModalPrice"></strong></p>
            <div>
                <div class="product-modal-section-title">Описание</div>
                <p id="productModalDescription"></p>
            </div>
            <div>
                <div class="product-modal-section-title">Состав</div>
                <p id="productModalComposition"></p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cartItems = <?php echo json_encode($cartItems, JSON_UNESCAPED_UNICODE); ?>;
    const products = <?php echo json_encode(array_values($menu ?? []), JSON_UNESCAPED_UNICODE); ?>;
    const productsById = new Map(products.map(p => [parseInt(p.product_id, 10), p]));

    const overlay = document.getElementById('productModalOverlay');
    const modal = document.getElementById('productModal');
    const closeBtn = document.getElementById('productModalClose');
    const elTitle = document.getElementById('productModalTitle');
    const elImg = document.getElementById('productModalImg');
    const elPrice = document.getElementById('productModalPrice');
    const elDesc = document.getElementById('productModalDescription');
    const elComp = document.getElementById('productModalComposition');

    function setProductQuantityUI(productId, qty) {
        const form = document.querySelector(`.add-to-cart-form[data-product-id="${productId}"]`);
        if (!form) return;
        const addBtn = form.querySelector('.add-to-cart-btn');
        const controls = form.querySelector('.cart-controls');
        const qtyValue = form.querySelector('.qty-value');
        if (!addBtn || !controls || !qtyValue) return;

        qtyValue.textContent = String(qty);
        if (qty > 0) {
            addBtn.classList.add('hidden');
            controls.classList.remove('hidden');
        } else {
            controls.classList.add('hidden');
            addBtn.classList.remove('hidden');
        }
    }

    function changeCartQuantity(productId, action) {
        const body = new URLSearchParams();
        body.set('product_id', String(productId));
        body.set('action', action);
        return fetch('cart_quantity_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (res) {
            return res.json();
        });
    }

    function openProductModal(productId) {
        const p = productsById.get(productId);
        if (!p) return;

        elTitle.textContent = p.name || '';
        elImg.src = p.image_url || '';
        elImg.alt = p.name || '';
        elPrice.textContent = (p.price ? `${p.price} руб.` : '');
        elDesc.textContent = p.description || '—';
        elComp.textContent = (p.composition && String(p.composition).trim() !== '') ? p.composition : '—';

        overlay.style.display = 'block';
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeProductModal() {
        overlay.style.display = 'none';
        modal.style.display = 'none';
        document.body.style.overflow = '';
        overlay.setAttribute('aria-hidden', 'true');
    }

    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
    const resetButton = document.getElementById('resetButton');

    function sanitizeSearch(value) {
        value = value.replace(/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/g, '');
        value = value.replace(/\s+/g, ' ');
        value = value.replace(/^\s+/, '');
        return value.substring(0, 20);
    }

    if (searchInput && searchForm) {
        searchInput.addEventListener('input', function () {
            this.value = sanitizeSearch(this.value);
        });

        searchForm.addEventListener('submit', function () {
            searchInput.value = sanitizeSearch(searchInput.value);
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            window.location.href = 'menu.php';
        });
    }

    document.querySelectorAll('.add-to-cart-form').forEach(function (form) {
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;

        button.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        const productId = parseInt(button.getAttribute('data-product-id'), 10);
        const initialQty = Number(cartItems[String(productId)] || 0);
        setProductQuantityUI(productId, initialQty);
        if (initialQty > 0) {
            button.textContent = 'В корзине';
        }

        if (!window.fetch) {
            return; // без JS‑улучшений форма всё равно работает
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            changeCartQuantity(productId, 'inc').then(function (data) {
                if (!data || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'Ошибка');
                }
                setProductQuantityUI(productId, Number(data.quantity || 0));
                button.textContent = 'В корзине';
            }).catch(function () {
                form.submit(); // запасной вариант
            });
        });

        const controls = form.querySelector('.cart-controls');
        if (controls) {
            controls.addEventListener('click', function (event) {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;
                const action = target.getAttribute('data-action');
                if (!action) return;
                event.stopPropagation();
                changeCartQuantity(productId, action).then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error(data && data.error ? data.error : 'Ошибка');
                    }
                    setProductQuantityUI(productId, Number(data.quantity || 0));
                }).catch(function () {
                    // если API не сработал, оставляем текущее состояние и дадим пользователю перейти в корзину
                });
            });
        }
    });

    document.querySelectorAll('.product-item').forEach(function (item) {
        const hiddenProductIdInput = item.querySelector('input[name="product_id"]');
        if (!hiddenProductIdInput) return;
        const productId = parseInt(hiddenProductIdInput.value, 10);
        if (!Number.isFinite(productId)) return;

        item.style.cursor = 'pointer';
        item.addEventListener('click', function () {
            openProductModal(productId);
        });
    });

    if (overlay) {
        overlay.addEventListener('click', closeProductModal);
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', closeProductModal);
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeProductModal();
    });

    const scrollButton = document.querySelector('.scroll-to-top');
    if (scrollButton) {
        window.addEventListener('scroll', function () {
            if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
                scrollButton.style.display = 'block';
            } else {
                scrollButton.style.display = 'none';
            }
        });

        scrollButton.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
});
</script>
</body>
</html>