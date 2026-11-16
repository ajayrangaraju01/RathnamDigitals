<?php

function loadAppEnvironment(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read the application .env file.');
    }

    $allowedVariables = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!in_array($name, $allowedVariables, true) || getenv($name) !== false) {
            continue;
        }

        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_NAME') ?: '';
        $username = getenv('DB_USER') ?: '';
        $password = getenv('DB_PASSWORD');

        if ($database === '' || $username === '') {
            throw new RuntimeException('MySQL configuration is missing. Set DB_NAME and DB_USER in the environment.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('DB_NAME may contain only letters, numbers, and underscores.');
        }
        if (!preg_match('/^\d+$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('DB_PORT must be a valid TCP port.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('The PDO MySQL driver is not enabled. Enable pdo_mysql in php.ini and restart PHP.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $pdo = new PDO($dsn, $username, $password === false ? '' : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

function ensureAppDatabase(bool $seedDefaultPackages = true): void
{
    $pdo = getDb();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS images (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NULL,
            file VARCHAR(1024) NOT NULL,
            category VARCHAR(50) DEFAULT 'other',
            caption TEXT,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            carousel_crop_x DOUBLE NOT NULL DEFAULT 50,
            carousel_crop_y DOUBLE NOT NULL DEFAULT 50,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS booking_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(255) NOT NULL,
            email VARCHAR(320) NOT NULL,
            phone VARCHAR(50) NOT NULL DEFAULT '',
            service_type VARCHAR(100) NOT NULL,
            requested_date VARCHAR(32) NOT NULL,
            requested_time VARCHAR(32) NOT NULL DEFAULT '',
            notes TEXT,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS services (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            price VARCHAR(255) NOT NULL,
            service_icon VARCHAR(255) NOT NULL DEFAULT '',
            background_image VARCHAR(1024) NOT NULL DEFAULT '',
            service_crop_x DOUBLE NOT NULL DEFAULT 50,
            service_crop_y DOUBLE NOT NULL DEFAULT 50,
            order_index INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hero_slides (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            subtitle TEXT,
            media_file VARCHAR(1024) NOT NULL DEFAULT '',
            overlay_opacity INT NOT NULL DEFAULT 50,
            button_text VARCHAR(255) NOT NULL DEFAULT 'Book a Session',
            button_link VARCHAR(1024) NOT NULL DEFAULT '/book',
            order_index INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $packagesTableExists = (bool) $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'packages'")->fetchColumn();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS packages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            price VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            items TEXT NOT NULL,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            order_index INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!$packagesTableExists && $seedDefaultPackages) {
        seedDefaultPackages($pdo);
    }
}

function seedDefaultPackages(PDO $pdo): void
{
    $defaultPackages = [
        [
            'Signature Portrait',
            '₹12,000',
            'Perfect for personal branding, lifestyle sessions, or modern portraits.',
            "1 hour session\nProfessional editing\n20 final images",
            0,
            0,
        ],
        [
            'Wedding Story',
            '₹35,000',
            'A complete documentary-style coverage designed around your celebration.',
            "8 hours coverage\nCandid + portrait coverage\nHigh-resolution gallery",
            1,
            1,
        ],
        [
            'Brand Content',
            '₹22,000',
            'Creative, polished imagery for founders, businesses, and campaigns.',
            "Creative direction\nStudio or location shoot\nSocial-ready export set",
            0,
            2,
        ],
    ];
    $insertPackage = $pdo->prepare('INSERT INTO packages (name, price, description, items, is_featured, order_index) VALUES (:name, :price, :description, :items, :is_featured, :order_index)');
    foreach ($defaultPackages as [$name, $price, $description, $items, $featured, $order]) {
        $insertPackage->execute([
            ':name' => $name,
            ':price' => $price,
            ':description' => $description,
            ':items' => $items,
            ':is_featured' => $featured,
            ':order_index' => $order,
        ]);
    }
}

function addFlash(string $type, string $message): void
{
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }

    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
}

function getFlashMessages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function assetUrl(string $path): string
{
    $path = ltrim($path, '/');
    if ($path === '') {
        return '/';
    }
    return '/' . $path;
}

function ensureDirectories(string $relativePath): string
{
    $fullPath = APP_ROOT . '/' . ltrim($relativePath, '/');
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0777, true);
    }
    return $fullPath;
}

function saveUploadedFile(array $file, string $targetDir): ?string
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName);
    $safeName = ($safeBase ?: 'upload') . '_' . time() . '.' . $extension;

    $directory = ensureDirectories($targetDir);
    $destination = $directory . '/' . $safeName;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ltrim(str_replace(APP_ROOT . '/', '', $destination), '/');
    }

    return null;
}

function isAdminAuthenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function validateAdminLogin(string $username, string $password): bool
{
    $expectedUser = getenv('ADMIN_USER') ?: 'admin';
    $expectedPass = getenv('ADMIN_PASS') ?: 'studio2026';

    return $username === $expectedUser && $password === $expectedPass;
}

function getServiceChoices(): array
{
    return [
        'portrait' => 'Portrait Session',
        'wedding' => 'Wedding Photography',
        'event' => 'Event Coverage',
        'studio' => 'Studio Shoot',
        'commercial' => 'Commercial / Product',
    ];
}

function getImageCategories(): array
{
    return [
        'wedding' => 'Wedding',
        'portrait' => 'Portrait',
        'event' => 'Event',
        'studio' => 'Studio',
        'commercial' => 'Commercial',
        'other' => 'Other',
    ];
}

function getFeaturedImages(): array
{
    $pdo = getDb();
    $featured = $pdo->query('SELECT * FROM images WHERE is_featured = 1 ORDER BY id DESC LIMIT 5')->fetchAll();
    if (count($featured) >= 2) {
        return $featured;
    }

    $images = [];
    foreach ($featured as $image) {
        $images[$image['id']] = $image;
    }
    $recent = $pdo->query('SELECT * FROM images ORDER BY uploaded_at DESC, id DESC LIMIT 5')->fetchAll();
    foreach ($recent as $image) {
        $images[$image['id']] = $image;
        if (count($images) >= 5) {
            break;
        }
    }

    return array_values($images);
}

function getHeroSlides(): array
{
    $pdo = getDb();
    $stmt = $pdo->query("SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY order_index ASC, id DESC");
    return $stmt->fetchAll();
}

function getActiveServices(): array
{
    $pdo = getDb();
    $stmt = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY order_index ASC, id DESC");
    return $stmt->fetchAll();
}

function getActivePackages(): array
{
    $pdo = getDb();
    $packages = $pdo->query('SELECT * FROM packages WHERE is_active = 1 ORDER BY order_index ASC, id ASC')->fetchAll();
    foreach ($packages as &$package) {
        $package['items'] = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $package['items']))), static fn(string $item): bool => $item !== ''));
    }
    unset($package);
    return $packages;
}
