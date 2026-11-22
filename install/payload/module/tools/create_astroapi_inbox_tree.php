<?php

declare(strict_types=1);

define('JOOMLA_ROOT', getenv('ASTROAPI_JOOMLA_ROOT') ?: '/var/www/joomla');
define('ASTRO_INBOX', getenv('ASTROAPI_INBOX') ?: '/srv/astrodata/inbox');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI.\n");
    exit(1);
}

require JOOMLA_ROOT . '/configuration.php';
$config = new JConfig();
$database = new mysqli($config->host, $config->user, $config->password, $config->db);
$database->set_charset('utf8mb4');

$categoryTable = $config->dbprefix . 'phocagallery_categories';
$query = <<<SQL
SELECT id, parent_id, title
FROM `{$categoryTable}`
WHERE published = 1
ORDER BY parent_id, ordering, title
SQL;
$result = $database->query($query);
if ($result === false) {
    throw new RuntimeException('Cannot load Phoca categories: ' . $database->error);
}

$categories = [];
while ($row = $result->fetch_assoc()) {
    $categories[(int) $row['id']] = [
        'parent_id' => (int) $row['parent_id'],
        'title' => trim((string) $row['title']),
    ];
}

if (!is_dir(ASTRO_INBOX) && !mkdir(ASTRO_INBOX, 0775, true)) {
    throw new RuntimeException('Cannot create AstroInbox: ' . ASTRO_INBOX);
}

$created = 0;
$validPaths = [];
foreach ($categories as $category) {
    $parts = [$category['title']];
    $parentId = $category['parent_id'];
    $visited = [];

    while ($parentId > 0 && isset($categories[$parentId]) && !isset($visited[$parentId])) {
        $visited[$parentId] = true;
        array_unshift($parts, $categories[$parentId]['title']);
        $parentId = $categories[$parentId]['parent_id'];
    }

    $relative = implode('/', $parts);
    $validPaths[] = $relative;
    $directory = ASTRO_INBOX . '/' . $relative;
    if (str_contains($relative, "\0") || !str_starts_with($directory, ASTRO_INBOX . '/')) {
        throw new RuntimeException('Invalid category path: ' . $relative);
    }

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create category directory: ' . $directory);
        }
        $created++;
        echo 'CREATED  ' . $relative . PHP_EOL;
    }
}

$statusDirectory = ASTRO_INBOX . '/.status';
if (!is_dir($statusDirectory) && !mkdir($statusDirectory, 0775, true) && !is_dir($statusDirectory)) {
    throw new RuntimeException('Cannot create AstroInbox status directory: ' . $statusDirectory);
}
$manifest = $statusDirectory . '/phoca_categories.json';
$manifestTmp = $manifest . '.tmp';
file_put_contents(
    $manifestTmp,
    json_encode($validPaths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX
);
if (!rename($manifestTmp, $manifest)) {
    @unlink($manifestTmp);
    throw new RuntimeException('Cannot update category manifest: ' . $manifest);
}

echo PHP_EOL . "Done. Categories: " . count($categories) . ", directories created: {$created}\n";
