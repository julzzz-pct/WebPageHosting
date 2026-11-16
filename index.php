<?php
declare(strict_types=0);

session_start();

/*
 * GameVault - Games Inventory
 */

const DB_HOST = 'sql107.infinityfree.com';
const DB_NAME = 'if0_43413171_product_inventory';
const DB_USER = 'if0_43413171';
const DB_PASS = 'MOMZ4CI1VmUM8t1';

$games = [];
$categories = [];
$dbError = '';
$successMessage = '';
$formError = '';
$formOpen = false;

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso(mixed $value): string {
    return '₱' . number_format((float)$value, 2);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {

    // DATABASE CONNECTION
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // UPDATE GAME
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_game'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            $formError = 'Invalid request. Please refresh the page and try again.';
        } else {
            $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
            $gameName = trim($_POST['game_name'] ?? '');
            $gameDescription = trim($_POST['game_description'] ?? '');
            $gameCategory = trim($_POST['game_category'] ?? '');
            $price = trim((string)($_POST['price'] ?? ''));

            if (!$gameId || $gameName === '' || $gameCategory === '' || $price === '') {
                $formError = 'Please complete all required fields.';
            } elseif (
                mb_strlen($gameName) > 100 ||
                mb_strlen($gameDescription) > 100 ||
                mb_strlen($gameCategory) > 100
            ) {
                $formError = 'Game name, description, and category must each be 100 characters or fewer.';
            } elseif (!ctype_digit($price) || (float)$price > 2147483647) {
                $formError = 'Price must be a valid whole number.';
            } else {
                $currentStmt = $pdo->prepare('SELECT image_path FROM games WHERE game_id = ?');
                $currentStmt->execute([$gameId]);
                $currentGame = $currentStmt->fetch();

                if (!$currentGame) {
                    $formError = 'The game you are trying to update no longer exists.';
                } else {
                    $newImagePath = $currentGame['image_path'];
                    $uploadedNewImage = false;

                    if (isset($_FILES['game_image']) && $_FILES['game_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $upload = $_FILES['game_image'];
                        $maxFileSize = 5 * 1024 * 1024;

                        if ($upload['error'] !== UPLOAD_ERR_OK) {
                            $formError = 'The image upload failed. Please choose the image again.';
                        } elseif ($upload['size'] > $maxFileSize) {
                            $formError = 'The image must be 5 MB or smaller.';
                        } elseif (!is_uploaded_file($upload['tmp_name'])) {
                            $formError = 'Invalid image upload.';
                        } else {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mimeType = $finfo->file($upload['tmp_name']);
                            $allowedTypes = [
                                'image/jpeg' => 'jpg',
                                'image/png' => 'png',
                                'image/webp' => 'webp',
                                'image/gif' => 'gif',
                            ];

                            if (!isset($allowedTypes[$mimeType])) {
                                $formError = 'Only JPG, PNG, WEBP, and GIF images are allowed.';
                            } else {
                                $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

                                if (!is_dir($uploadDirectory) &&
                                    !mkdir($uploadDirectory, 0755, true) &&
                                    !is_dir($uploadDirectory)) {
                                    $formError = 'Could not create the uploads folder.';
                                } elseif (!is_writable($uploadDirectory)) {
                                    $formError = 'The uploads folder is not writable.';
                                } else {
                                    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
                                    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

                                    if (move_uploaded_file($upload['tmp_name'], $destination)) {
                                        $newImagePath = 'uploads/' . $fileName;
                                        $uploadedNewImage = true;
                                    } else {
                                        $formError = 'Could not save the uploaded image.';
                                    }
                                }
                            }
                        }
                    }

                    if ($formError === '') {
                        try {
                            $update = $pdo->prepare(
                                'UPDATE games
                                 SET game_name = :name,
                                     game_description = :description,
                                     game_category = :category,
                                     price = :price,
                                     image_path = :image_path
                                 WHERE game_id = :id'
                            );
                            $update->execute([
                                ':name' => $gameName,
                                ':description' => $gameDescription,
                                ':category' => $gameCategory,
                                ':price' => (int)$price,
                                ':image_path' => $newImagePath,
                                ':id' => $gameId,
                            ]);

                            if ($uploadedNewImage && !empty($currentGame['image_path'])) {
                                $oldImage = __DIR__ . DIRECTORY_SEPARATOR . $currentGame['image_path'];
                                if (is_file($oldImage)) {
                                    unlink($oldImage);
                                }
                            }

                            header('Location: ' . $_SERVER['PHP_SELF'] . '?updated=1');
                            exit;
                        } catch (PDOException $updateError) {
                            if ($uploadedNewImage && $newImagePath !== null) {
                                $newImage = __DIR__ . DIRECTORY_SEPARATOR . $newImagePath;
                                if (is_file($newImage)) {
                                    unlink($newImage);
                                }
                            }
                            throw $updateError;
                        }
                    }
                }
            }
        }
    }

    // DELETE GAME
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_game'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            $formError = 'Invalid request. Please refresh the page and try again.';
        } else {
            $gameId = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);

            if (!$gameId) {
                $formError = 'Invalid game ID.';
            } else {
                $deleteStmt = $pdo->prepare('SELECT image_path FROM games WHERE game_id = ?');
                $deleteStmt->execute([$gameId]);
                $gameToDelete = $deleteStmt->fetch();

                if (!$gameToDelete) {
                    $formError = 'The game was not found.';
                } else {
                    $delete = $pdo->prepare('DELETE FROM games WHERE game_id = ?');
                    $delete->execute([$gameId]);

                    if (!empty($gameToDelete['image_path'])) {
                        $imageToDelete = __DIR__ . DIRECTORY_SEPARATOR . $gameToDelete['image_path'];
                        if (is_file($imageToDelete)) {
                            unlink($imageToDelete);
                        }
                    }

                    header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1');
                    exit;
                }
            }
        }
    }

    // ADD GAME
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_game'])) {

        $formOpen = true;

        if (!hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token'] ?? ''
        )) {
            $formError = 'Invalid request. Please refresh the page and try again.';

        } else {

            $gameName = trim($_POST['game_name'] ?? '');
            $gameDescription = trim($_POST['game_description'] ?? '');
            $gameCategory = trim($_POST['game_category'] ?? '');
            $price = trim((string)($_POST['price'] ?? ''));
            $imagePath = null;

            if ($gameName === '' || $gameCategory === '' || $price === '') {
                $formError = 'Please fill in the game name, category, and price.';
            } elseif (
                mb_strlen($gameName) > 100 ||
                mb_strlen($gameDescription) > 100 ||
                mb_strlen($gameCategory) > 100
            ) {
                $formError = 'Game name, description, and category must each be 100 characters or fewer.';
            } elseif (!ctype_digit($price) || (float)$price > 2147483647) {
                $formError = 'Price must be a valid whole number because your database price column is INT.';
            } else {
                // Validate and store an optional game cover image.
                if (isset($_FILES['game_image']) && $_FILES['game_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = $_FILES['game_image'];
                    $maxFileSize = 5 * 1024 * 1024; // 5 MB

                    if ($upload['error'] !== UPLOAD_ERR_OK) {
                        $formError = 'The image upload failed. Please choose the image again.';
                    } elseif ($upload['size'] > $maxFileSize) {
                        $formError = 'The image must be 5 MB or smaller.';
                    } elseif (!is_uploaded_file($upload['tmp_name'])) {
                        $formError = 'Invalid image upload.';
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->file($upload['tmp_name']);

                        $allowedTypes = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/webp' => 'webp',
                            'image/gif'  => 'gif',
                        ];

                        if (!isset($allowedTypes[$mimeType])) {
                            $formError = 'Only JPG, PNG, WEBP, and GIF images are allowed.';
                        } else {
                            $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

                            if (!is_dir($uploadDirectory) &&
                                !mkdir($uploadDirectory, 0755, true) &&
                                !is_dir($uploadDirectory)) {
                                $formError = 'Could not create the uploads folder. Check folder permissions.';
                            } elseif (!is_writable($uploadDirectory)) {
                                $formError = 'The uploads folder is not writable. Check folder permissions.';
                            } else {
                                $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
                                $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

                                if (move_uploaded_file($upload['tmp_name'], $destination)) {
                                    $imagePath = 'uploads/' . $fileName;
                                } else {
                                    $formError = 'Could not save the uploaded image.';
                                }
                            }
                        }
                    }
                }

                if ($formError === '') {
                    try {
                        $insert = $pdo->prepare(
                            'INSERT INTO games
                            (game_name, game_description, game_category, price, image_path)
                            VALUES (:name, :description, :category, :price, :image_path)'
                        );

                        $insert->execute([
                            ':name' => $gameName,
                            ':description' => $gameDescription,
                            ':category' => $gameCategory,
                            ':price' => (int)$price,
                            ':image_path' => $imagePath
                        ]);

                        // Prevent duplicate submission on refresh.
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?added=1');
                        exit;
                    } catch (PDOException $insertError) {
                        // Remove the uploaded file if the database insert fails.
                        if ($imagePath !== null && is_file(__DIR__ . DIRECTORY_SEPARATOR . $imagePath)) {
                            unlink(__DIR__ . DIRECTORY_SEPARATOR . $imagePath);
                        }
                        throw $insertError;
                    }
                }
            }
        }
    }

    // SUCCESS MESSAGE
    if (isset($_GET['added'])) {
        $successMessage = 'Game successfully added to your inventory!';
    } elseif (isset($_GET['updated'])) {
        $successMessage = 'Game successfully updated!';
    } elseif (isset($_GET['deleted'])) {
        $successMessage = 'Game successfully deleted!';
    }

    // FETCH GAMES
    $stmt = $pdo->query(
        'SELECT game_id, game_name, game_description, game_category, price, image_path
         FROM games
         ORDER BY game_id DESC'
    );

    $games = $stmt->fetchAll();

    // FETCH CATEGORIES
    $categories = $pdo->query(
        'SELECT DISTINCT game_category
         FROM games
         ORDER BY game_category'
    )->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $error) {

    error_log($error->getMessage());

    $dbError = 'Unable to connect to the database or process your request. Check your XAMPP MySQL service, database name, table name, and column names.';

}

// DASHBOARD STATISTICS
$totalGames = count($games);

$totalValue = array_sum(
    array_map(
        static fn($game) => (float)$game['price'],
        $games
    )
);

$totalCategories = count($categories);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>GameVault – Games Inventory</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            darkMode: 'class'
        };
    </script>
</head>

<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100 min-h-screen transition-colors">

<main class="max-w-7xl mx-auto p-6">

    <!-- HEADER -->
    <header class="flex flex-wrap items-center justify-between gap-4 mb-8">

        <div class="flex items-center gap-3">

            <div class="w-12 h-12 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-2xl">
                🎮
            </div>

            <div>
                <h1 class="text-3xl font-bold">
                    GameVault Inventory
                </h1>

                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    <?= $dbError ? 'Database connection error' : 'Games loaded from your database' ?>
                </p>
            </div>

        </div>

        <!-- HEADER BUTTONS -->
        <div class="flex flex-wrap gap-2">

            <button
                type="button"
                onclick="openAddGameModal()"
                class="px-5 py-2.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors shadow">

                + Add Game

            </button>

            <button
                id="theme-toggle"
                type="button"
                class="px-3 py-2 text-sm font-medium rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors">

                Toggle dark mode

            </button>

        </div>

    </header>


    <!-- DATABASE ERROR -->
    <?php if ($dbError): ?>

        <div class="mb-6 rounded-xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-5 text-red-700 dark:text-red-300">

            <h2 class="font-bold text-lg mb-1">
                Database Connection Failed
            </h2>

            <p class="text-sm">
                <?= e($dbError) ?>
            </p>

            <p class="text-sm mt-2">
                Expected database: <strong>product_inventory</strong>
            </p>

            <p class="text-sm mt-1">
                Expected table: <strong>games</strong>
            </p>

            <p class="text-sm mt-1">
                Expected columns: game_id, game_name, game_description, game_category, price.
            </p>

        </div>

    <?php else: ?>


        <!-- SUCCESS MESSAGE -->
        <?php if ($successMessage): ?>

            <div class="mb-6 rounded-xl border border-green-300 bg-green-50 dark:bg-green-900/20 dark:border-green-800 p-4 text-green-700 dark:text-green-300">

                ✓ <?= e($successMessage) ?>

            </div>

        <?php endif; ?>


        <!-- ERROR MESSAGE -->
        <?php if ($formError): ?>

            <div class="mb-6 rounded-xl border border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 text-red-700 dark:text-red-300">

                <?= e($formError) ?>

            </div>

        <?php endif; ?>


        <!-- DASHBOARD SUMMARY -->
        <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">

            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">

                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Total Games
                </p>

                <p class="text-3xl font-bold mt-2">
                    <?= $totalGames ?>
                </p>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Records in your inventory
                </p>

            </div>


            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">

                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Game Categories
                </p>

                <p class="text-3xl font-bold mt-2">
                    <?= $totalCategories ?>
                </p>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Different game genres
                </p>

            </div>


            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-5">

                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Total Game Value
                </p>

                <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mt-2">
                    <?= e(peso($totalValue)) ?>
                </p>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Sum of all game prices
                </p>

            </div>

        </section>


        <!-- SEARCH AND FILTER -->
        <section class="mb-6">

            <div class="flex flex-col md:flex-row gap-3">

                <input
                    type="text"
                    id="search-input"
                    placeholder="Search games by name, description, or category..."
                    class="w-full px-4 py-3 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >

                <select
                    id="category-filter"
                    class="md:w-64 px-4 py-3 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500">

                    <option value="">
                        All categories
                    </option>

                    <?php foreach ($categories as $category): ?>

                        <option value="<?= e($category) ?>">
                            <?= e($category) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        </section>


        <!-- GAMES SECTION HEADER -->
        <section class="mb-4 flex items-center justify-between gap-3">

            <div>

                <h2 class="text-xl font-bold">
                    Your Games
                </h2>

                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Game information retrieved from MySQL.
                </p>

            </div>

            <span id="result-count" class="text-sm text-slate-500 dark:text-slate-400">
                <?= $totalGames ?> games
            </span>

        </section>


        <!-- GAME CARDS -->
        <div id="games-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <?php if (count($games) > 0): ?>

                <?php foreach ($games as $game): ?>

                    <article
                        class="game-card bg-white dark:bg-slate-800 rounded-xl shadow-md p-6 border border-slate-100 dark:border-slate-700 hover:shadow-lg transition-shadow"

                        data-name="<?= e(strtolower($game['game_name'])) ?>"

                        data-description="<?= e(strtolower($game['game_description'] ?? '')) ?>"

                        data-category="<?= e(strtolower($game['game_category'])) ?>">

                        <?php if (!empty($game['image_path'])): ?>
                            <img
                                src="<?= e($game['image_path']) ?>"
                                alt="<?= e($game['game_name']) ?>"
                                class="w-full h-44 object-cover rounded-xl mb-4"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div class="w-full h-44 bg-gradient-to-br from-indigo-600 to-purple-700 text-white rounded-xl flex items-center justify-center text-6xl mb-4">
                                🎮
                            </div>
                        <?php endif; ?>

                        <div class="flex items-start justify-between gap-3 mb-4">

                            <div class="w-12 h-12 bg-indigo-600 text-white rounded-xl flex items-center justify-center text-xl">
                                🎮
                            </div>

                            <span class="text-xs text-slate-400">
                                ID #<?= e($game['game_id']) ?>
                            </span>

                        </div>


                        <h3 class="text-xl font-bold mb-2">
                            <?= e($game['game_name']) ?>
                        </h3>


                        <span class="inline-block mb-3 px-3 py-1 text-xs font-semibold bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-300 rounded-full">

                            <?= e($game['game_category']) ?>

                        </span>


                        <p class="text-sm text-slate-600 dark:text-slate-300 min-h-16">

                            <?= e($game['game_description'] ?? '') ?: 'No description provided.' ?>

                        </p>


                        <div class="mt-5 pt-4 border-t border-slate-100 dark:border-slate-700 flex items-center justify-between">

                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                Game Price
                            </span>

                            <span class="text-lg font-bold text-indigo-600 dark:text-indigo-400">

                                <?= e(peso($game['price'])) ?>

                            </span>

                        </div>

                        <div class="mt-4 flex gap-2">
                            <button
                                type="button"
                                class="edit-game-btn flex-1 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold"
                                data-id="<?= e($game['game_id']) ?>"
                                data-name="<?= e($game['game_name']) ?>"
                                data-description="<?= e($game['game_description'] ?? '') ?>"
                                data-category="<?= e($game['game_category']) ?>"
                                data-price="<?= e($game['price']) ?>"
                                data-image="<?= e($game['image_path'] ?? '') ?>">
                                ✏️ Update
                            </button>

                            <form method="POST" action="" class="flex-1"
                                  onsubmit="return confirm('Are you sure you want to delete this game? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="game_id" value="<?= e($game['game_id']) ?>">
                                <button
                                    type="submit"
                                    name="delete_game"
                                    class="w-full px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold">
                                    🗑 Delete
                                </button>
                            </form>
                        </div>

                    </article>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="col-span-full bg-white dark:bg-slate-800 rounded-xl p-10 text-center border border-slate-200 dark:border-slate-700">

                    <div class="text-4xl mb-3">
                        🎮
                    </div>

                    <h3 class="font-bold text-lg">
                        No games found
                    </h3>

                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Click the Add Game button to add your first game!
                    </p>

                </div>

            <?php endif; ?>

        </div>


        <!-- EMPTY SEARCH MESSAGE -->
        <p id="empty-search" class="hidden text-center text-slate-500 dark:text-slate-400 py-10">
            No games match your search.
        </p>


        <!-- CATEGORY SUMMARY -->
        <section class="mt-10">

            <h2 class="text-xl font-bold mb-4">
                Game Categories
            </h2>

            <div class="flex flex-wrap gap-2">

                <?php if ($categories): ?>

                    <?php foreach ($categories as $category): ?>

                        <button
                            type="button"
                            class="category-chip px-4 py-2 rounded-full text-sm bg-slate-200 dark:bg-slate-700 hover:bg-indigo-600 hover:text-white transition-colors"

                            data-category="<?= e(strtolower($category)) ?>">

                            <?= e($category) ?>

                        </button>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        No categories available.
                    </p>

                <?php endif; ?>

            </div>

        </section>


    <?php endif; ?>


    <!-- ADD GAME MODAL -->
    <div
        id="addGameModal"
        class="<?= $formOpen ? '' : 'hidden' ?> fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">

        <div class="bg-white dark:bg-slate-800 w-full max-w-lg rounded-2xl shadow-2xl p-6 max-h-[90vh] overflow-y-auto">


            <!-- MODAL HEADER -->
            <div class="flex justify-between items-center mb-6">

                <div>

                    <h2 class="text-2xl font-bold">
                        🎮 Add New Game
                    </h2>

                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Enter the game information below.
                    </p>

                </div>

                <button
                    type="button"
                    onclick="closeAddGameModal()"
                    class="text-2xl text-slate-500 hover:text-red-500">

                    &times;

                </button>

            </div>


            <!-- ADD GAME FORM -->
            <form method="POST" action="" id="addGameForm" enctype="multipart/form-data">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >


                <!-- GAME NAME -->
                <div class="mb-4">

                    <label class="block text-sm font-semibold mb-2">
                        Game Name *
                    </label>

                    <input
                        type="text"
                        name="game_name"
                        required
                        maxlength="100"
                        value="<?= $formOpen ? e($_POST['game_name'] ?? '') : '' ?>"
                        placeholder="Enter game name"

                        class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"
                    >

                </div>


                <!-- GAME DESCRIPTION -->
                <div class="mb-4">

                    <label class="block text-sm font-semibold mb-2">
                        Game Description
                    </label>

                    <textarea
                        name="game_description"
                        rows="3"
                        maxlength="100"
                        required
                        placeholder="Enter game description"

                        class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"><?= $formOpen ? e($_POST['game_description'] ?? '') : '' ?></textarea>

                </div>


                <!-- GAME CATEGORY -->
                <div class="mb-4">

                    <label class="block text-sm font-semibold mb-2">
                        Game Category *
                    </label>

                    <input
                        type="text"
                        name="game_category"
                        required
                        maxlength="100"
                        value="<?= $formOpen ? e($_POST['game_category'] ?? '') : '' ?>"
                        placeholder="e.g. Action, Adventure, RPG"

                        class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"
                    >

                </div>


                <!-- GAME PRICE -->
                <div class="mb-6">

                    <label class="block text-sm font-semibold mb-2">
                        Game Price (₱) *
                    </label>

                    <input
                        type="number"
                        name="price"
                        required
                        min="0"
                        max="2147483647"
                        step="1"
                        value="<?= $formOpen ? e($_POST['price'] ?? '') : '' ?>"
                        placeholder="0"

                        class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"
                    >

                </div>


                <!-- GAME COVER IMAGE -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold mb-2">
                        Game Cover Image (optional)
                    </label>

                    <input
                        type="file"
                        name="game_image"
                        id="game-image"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700"
                    >

                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                        JPG, PNG, WEBP, or GIF. Maximum file size: 5 MB.
                    </p>

                    <img
                        id="image-preview"
                        src=""
                        alt="Selected game image preview"
                        class="hidden mt-3 w-full h-40 object-cover rounded-lg"
                    >
                </div>

                <!-- FORM BUTTONS -->
                <div class="flex gap-3">

                    <button
                        type="button"
                        onclick="closeAddGameModal()"

                        class="w-1/2 py-3 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 font-semibold">

                        Cancel

                    </button>


                    <button
                        type="submit"
                        name="add_game"

                        class="w-1/2 py-3 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">

                        ✓ Save Game

                    </button>

                </div>

            </form>

        </div>

    </div>


    <!-- UPDATE GAME MODAL -->
    <div id="editGameModal" class="hidden fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-800 w-full max-w-lg rounded-2xl shadow-2xl p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold">✏️ Update Game</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Edit the game information.</p>
                </div>
                <button type="button" onclick="closeEditGameModal()" class="text-2xl text-slate-500 hover:text-red-500">&times;</button>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" id="editGameForm">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="game_id" id="edit-game-id">

                <div class="mb-4">
                    <label class="block text-sm font-semibold mb-2">Game Name *</label>
                    <input type="text" name="game_name" id="edit-game-name" maxlength="100" required
                           class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-semibold mb-2">Game Description *</label>
                    <textarea name="game_description" id="edit-game-description" maxlength="100" rows="3" required
                              class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none"></textarea>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-semibold mb-2">Game Category *</label>
                    <input type="text" name="game_category" id="edit-game-category" maxlength="100" required
                           class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-semibold mb-2">Game Price (₱) *</label>
                    <input type="number" name="price" id="edit-game-price" min="0" max="2147483647" step="1" required
                           class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-semibold mb-2">Replace Game Cover Image (optional)</label>
                    <img id="edit-current-image" src="" alt="Current game cover"
                         class="hidden mb-3 w-full h-40 object-cover rounded-lg">
                    <input type="file" name="game_image" id="edit-game-image"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Choose a new image to replace the current one. JPG, PNG, WEBP, or GIF; maximum 5 MB.</p>
                    <img id="edit-image-preview" src="" alt="New image preview"
                         class="hidden mt-3 w-full h-40 object-cover rounded-lg">
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeEditGameModal()"
                            class="w-1/2 py-3 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 font-semibold">
                        Cancel
                    </button>
                    <button type="submit" name="update_game"
                            class="w-1/2 py-3 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="mt-12 pt-6 border-t border-slate-200 dark:border-slate-700 text-sm text-slate-500 dark:text-slate-400 flex flex-wrap justify-between gap-2">

        <span>
            © <?= date('Y') ?> GameVault
        </span>

        <span>
            PHP · MySQL · Tailwind CSS (GROUP 1/ GRANDE, JANABON, ESTACA, AMPIG) tantadoo!!!
        </span>

    </footer>

</main>


<!-- JAVASCRIPT -->
<script>

    // SEARCH AND FILTER ELEMENTS
    const searchInput = document.getElementById('search-input');

    const categoryFilter = document.getElementById('category-filter');

    const gameCards = Array.from(
        document.querySelectorAll('.game-card')
    );

    const resultCount = document.getElementById('result-count');

    const emptySearch = document.getElementById('empty-search');


    // SEARCH AND FILTER GAMES
    function filterGames() {

        const term = searchInput.value.toLowerCase().trim();

        const selectedCategory = categoryFilter.value.toLowerCase();

        let visibleCount = 0;

        gameCards.forEach((card) => {

            const matchesText =
                card.dataset.name.includes(term) ||
                card.dataset.description.includes(term) ||
                card.dataset.category.includes(term);

            const matchesCategory =
                selectedCategory === '' ||
                card.dataset.category === selectedCategory;

            const shouldShow = matchesText && matchesCategory;

            card.classList.toggle('hidden', !shouldShow);

            if (shouldShow) {
                visibleCount++;
            }

        });

        resultCount.textContent =
            `${visibleCount} of ${gameCards.length} games`;

        emptySearch.classList.toggle(
            'hidden',
            visibleCount !== 0 || gameCards.length === 0
        );

    }


    // SEARCH EVENTS
    searchInput?.addEventListener('input', filterGames);

    categoryFilter?.addEventListener('change', filterGames);


    // CATEGORY CHIPS
    document.querySelectorAll('.category-chip').forEach((chip) => {

        chip.addEventListener('click', () => {

            categoryFilter.value = chip.dataset.category;

            filterGames();

            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });

        });

    });


    // DARK MODE
    document.getElementById('theme-toggle')?.addEventListener('click', () => {

        document.documentElement.classList.toggle('dark');

    });


    // PREVIEW SELECTED GAME IMAGE
    const gameImageInput = document.getElementById('game-image');
    const imagePreview = document.getElementById('image-preview');

    gameImageInput?.addEventListener('change', function () {
        const file = this.files && this.files[0];

        if (!file) {
            imagePreview.src = '';
            imagePreview.classList.add('hidden');
            return;
        }

        if (!file.type.startsWith('image/')) {
            this.value = '';
            imagePreview.src = '';
            imagePreview.classList.add('hidden');
            alert('Please select an image file.');
            return;
        }

        imagePreview.src = URL.createObjectURL(file);
        imagePreview.classList.remove('hidden');
    });

    // OPEN ADD GAME MODAL
    function openAddGameModal() {

        document.getElementById('addGameModal')
            .classList.remove('hidden');

    }


    // CLOSE ADD GAME MODAL
    function closeAddGameModal() {

        document.getElementById('addGameModal')
            .classList.add('hidden');

    }


    // CLOSE MODAL WHEN CLICKING OUTSIDE
    document.getElementById('addGameModal')
        .addEventListener('click', function(event) {

            if (event.target === this) {

                closeAddGameModal();

            }

        });


    // OPEN MODAL AFTER VALIDATION ERROR
    <?php if ($formOpen): ?>

        openAddGameModal();

    <?php endif; ?>


    // EDIT GAME MODAL
    const editGameModal = document.getElementById('editGameModal');

    function closeEditGameModal() {
        editGameModal.classList.add('hidden');
    }

    document.querySelectorAll('.edit-game-btn').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit-game-id').value = button.dataset.id;
            document.getElementById('edit-game-name').value = button.dataset.name;
            document.getElementById('edit-game-description').value = button.dataset.description;
            document.getElementById('edit-game-category').value = button.dataset.category;
            document.getElementById('edit-game-price').value = button.dataset.price;

            const currentImage = document.getElementById('edit-current-image');
            if (button.dataset.image) {
                currentImage.src = button.dataset.image;
                currentImage.classList.remove('hidden');
            } else {
                currentImage.src = '';
                currentImage.classList.add('hidden');
            }

            document.getElementById('edit-game-image').value = '';
            document.getElementById('edit-image-preview').src = '';
            document.getElementById('edit-image-preview').classList.add('hidden');

            editGameModal.classList.remove('hidden');
        });
    });

    editGameModal?.addEventListener('click', function(event) {
        if (event.target === this) closeEditGameModal();
    });

    document.getElementById('edit-game-image')?.addEventListener('change', function() {
        const preview = document.getElementById('edit-image-preview');
        const file = this.files && this.files[0];

        if (!file) {
            preview.src = '';
            preview.classList.add('hidden');
            return;
        }

        if (!file.type.startsWith('image/')) {
            this.value = '';
            preview.src = '';
            preview.classList.add('hidden');
            alert('Please select an image file.');
            return;
        }

        preview.src = URL.createObjectURL(file);
        preview.classList.remove('hidden');
    });

    // CLOSE MODAL WITH ESCAPE KEY
    document.addEventListener('keydown', function(event) {

        if (event.key === 'Escape') {

            closeAddGameModal();
            closeEditGameModal();

        }

    });

</script>

</body>
</html>
