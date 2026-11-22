<?php

declare(strict_types=1);

define('JOOMLA_ROOT', getenv('ASTROAPI_JOOMLA_ROOT') ?: '/var/www/joomla');
const SOURCE_FILE = __DIR__ . '/../categories.md';
const COVER_DIR_REL = 'category-covers';
define('COVER_DIR_ABS', JOOMLA_ROOT . '/images/phocagallery/' . COVER_DIR_REL);
define('PYTHON_WITH_AVIF', getenv('ASTROAPI_PYTHON') ?: __DIR__ . '/../venv/bin/python');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI.\n");
    exit(1);
}

define('_JEXEC', 1);
define('JPATH_BASE', JOOMLA_ROOT);
$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_METHOD'] ??= 'GET';

require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\SessionInterface::class, 'session.cli');

$application = $container->get(Joomla\Console\Application::class);
Joomla\CMS\Factory::$application = $application;
$db = $container->get(Joomla\Database\DatabaseInterface::class);

if (!is_file(SOURCE_FILE)) {
    throw new RuntimeException('Source file not found: ' . SOURCE_FILE);
}

if (!is_dir(COVER_DIR_ABS) && !mkdir(COVER_DIR_ABS, 0775, true) && !is_dir(COVER_DIR_ABS)) {
    throw new RuntimeException('Cannot create cover directory: ' . COVER_DIR_ABS);
}

require_once JPATH_ADMINISTRATOR . '/components/com_phocagallery/libraries/loader.php';
phocagalleryimport('phocagallery.file.filethumbnail');
phocagalleryimport('phocagallery.file.filefolder');
phocagalleryimport('phocagallery.image.image');

/**
 * @return array<int, array{path: list<string>, url: string, type: string, line: int}>
 */
function parseCategoryUrls(string $content): array
{
    $lines = preg_split('/\R/', $content) ?: [];
    $stack = [];
    $result = [];

    foreach ($lines as $lineNumber => $line) {
        if (preg_match('/^(\\s*)- \\*\\*(.+?)\\*\\*\\s*$/', $line, $m) === 1) {
            $indent = (int) floor(strlen($m[1]) / 2);
            $title = trim($m[2]);
            $stack = array_slice($stack, 0, $indent);
            $stack[$indent] = $title;
            continue;
        }

        if (preg_match('/^\\s*- URL:\\s*\\[(Thumbnail|Link)\\]\\((https?:\\/\\/[^)]+)\\)\\s*$/', $line, $m) === 1) {
            if ($stack === []) {
                continue;
            }
            $result[] = [
                'path' => array_values($stack),
                'type' => $m[1],
                'url' => $m[2],
                'line' => $lineNumber + 1,
            ];
            continue;
        }

        if (preg_match('/^\\s*- URL:\\s*None\\s*$/i', $line) === 1 && $stack !== []) {
            $result[] = [
                'path' => array_values($stack),
                'type' => 'None',
                'url' => '',
                'line' => $lineNumber + 1,
            ];
        }
    }

    return $result;
}

function slug(string $title): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    if ($ascii === false) {
        $ascii = $title;
    }
    $slug = strtolower($ascii);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'category';
}

/**
 * @param list<string> $path
 */
function categoryId(Joomla\Database\DatabaseInterface $db, array $path): int
{
    $parent = 0;
    foreach ($path as $title) {
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__phocagallery_categories'))
            ->where($db->quoteName('parent_id') . ' = ' . (int) $parent)
            ->where($db->quoteName('title') . ' = ' . $db->quote($title));
        $db->setQuery($query);
        $id = (int) $db->loadResult();
        if ($id === 0) {
            throw new RuntimeException('Category path not found: ' . implode(' / ', $path));
        }
        $parent = $id;
    }
    return $parent;
}

/**
 * @return array{bytes: string, ext: string}
 */
function fetchImage(string $url): array
{
    $http = Joomla\CMS\Http\HttpFactory::getHttp();
    $response = $http->get($url, [], 20);
    if ((int) $response->code !== 200 || $response->body === '') {
        throw new RuntimeException('Download failed (' . $response->code . '): ' . $url);
    }

    $bytes = $response->body;
    $info = @getimagesizefromstring($bytes);
    if ($info === false) {
        throw new RuntimeException('Downloaded file is not a valid image: ' . $url);
    }

    $type = $info[2];
    $ext = match ($type) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_AVIF => 'avif',
        default => 'jpg',
    };

    if ($type === IMAGETYPE_AVIF) {
        $bytes = convertAvifToJpeg($bytes);
        $ext = 'jpg';
    }

    return ['bytes' => $bytes, 'ext' => $ext];
}

function convertAvifToJpeg(string $bytes): string
{
    if (!is_executable(PYTHON_WITH_AVIF)) {
        throw new RuntimeException('Cannot convert AVIF: Python environment not found at ' . PYTHON_WITH_AVIF);
    }

    $script = <<<'PYTHON'
import io
import sys
from PIL import Image, ImageOps

try:
    image = ImageOps.exif_transpose(Image.open(io.BytesIO(sys.stdin.buffer.read())))
    if image.mode in ("RGBA", "LA") or (
        image.mode == "P" and "transparency" in image.info
    ):
        rgba = image.convert("RGBA")
        background = Image.new("RGBA", rgba.size, "white")
        background.alpha_composite(rgba)
        image = background.convert("RGB")
    else:
        image = image.convert("RGB")

    output = io.BytesIO()
    image.save(output, "JPEG", quality=90, optimize=True)
    sys.stdout.buffer.write(output.getvalue())
except Exception as error:
    print(str(error), file=sys.stderr)
    sys.exit(1)
PYTHON;

    $process = proc_open(
        [PYTHON_WITH_AVIF, '-c', $script],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start AVIF converter');
    }

    fwrite($pipes[0], $bytes);
    fclose($pipes[0]);
    $jpeg = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $jpeg === false || $jpeg === '') {
        throw new RuntimeException(
            'Cannot convert AVIF to JPEG: ' . trim($error ?: 'Unknown conversion error')
        );
    }

    return $jpeg;
}

function upsertCoverImage(
    Joomla\Database\DatabaseInterface $db,
    int $categoryId,
    string $categoryTitle,
    string $relativeFilename
): int {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $alias = 'cover-' . $categoryId . '-' . slug($categoryTitle);

    $query = $db->getQuery(true)
        ->select($db->quoteName('image_id'))
        ->from($db->quoteName('#__phocagallery_categories'))
        ->where($db->quoteName('id') . ' = ' . (int) $categoryId);
    $db->setQuery($query);
    $preferredId = (int) $db->loadResult();

    $query = $db->getQuery(true)
        ->select($db->quoteName('id'))
        ->from($db->quoteName('#__phocagallery'))
        ->where(
            '('
            . $db->quoteName('extid') . ' = ' . $db->quote('cover:category:' . $categoryId)
            . ' OR ' . $db->quoteName('filename') . ' = ' . $db->quote($relativeFilename)
            . ')'
        )
        ->order($db->quoteName('id'))
        ->setLimit(0);
    $db->setQuery($query);
    $matchingIds = array_map('intval', $db->loadColumn());
    $existingId = in_array($preferredId, $matchingIds, true)
        ? $preferredId
        : ($matchingIds[0] ?? 0);

    $record = (object) [
        'id' => $existingId,
        'catid' => 0,
        'title' => 'Category Cover: ' . $categoryTitle,
        'alias' => $alias,
        'filename' => $relativeFilename,
        'description' => 'Auto-generated category cover from categories.md',
        'date' => $now,
        'published' => 1,
        'approved' => 1,
        'access' => 1,
        'ordering' => 0,
        'language' => '*',
        'extid' => '',
    ];

    if ($existingId > 0) {
        $db->updateObject('#__phocagallery', $record, 'id');

        $duplicateIds = array_values(array_diff($matchingIds, [$existingId]));
        if ($duplicateIds !== []) {
            $deleteQuery = $db->getQuery(true)
                ->delete($db->quoteName('#__phocagallery'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $duplicateIds) . ')')
                ->where(
                    $db->quoteName('id') . ' NOT IN ('
                    . 'SELECT ' . $db->quoteName('image_id')
                    . ' FROM ' . $db->quoteName('#__phocagallery_categories')
                    . ' WHERE ' . $db->quoteName('image_id') . ' IS NOT NULL'
                    . ')'
                );
            $db->setQuery($deleteQuery)->execute();
        }

        return $existingId;
    }

    $db->insertObject('#__phocagallery', $record);
    return (int) $db->insertid();
}

$entries = parseCategoryUrls((string) file_get_contents(SOURCE_FILE));
$categoryFilter = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--category=')) {
        $categoryFilter = substr($argument, strlen('--category='));
        break;
    }
}

$updated = 0;
$skippedLinks = 0;
$skippedNone = 0;
$errors = 0;

foreach ($entries as $entry) {
    $path = $entry['path'];
    $url = $entry['url'];
    $type = $entry['type'];
    $line = $entry['line'];
    $title = $path[array_key_last($path)];

    if ($categoryFilter !== null && strcasecmp($title, $categoryFilter) !== 0) {
        continue;
    }

    if ($type !== 'Thumbnail') {
        if ($type === 'None') {
            printf("UNCHANGED %s [categories.md:%d: URL: None]\n", implode(' / ', $path), $line);
            $skippedNone++;
        } else {
            $skippedLinks++;
        }
        continue;
    }

    try {
        $categoryId = categoryId($db, $path);
        $image = fetchImage($url);
        $filename = slug($title) . '.' . $image['ext'];
        $relative = COVER_DIR_REL . '/' . $filename;
        $absolute = COVER_DIR_ABS . '/' . $filename;
        $tmp = $absolute . '.tmp';

        if (file_put_contents($tmp, $image['bytes'], LOCK_EX) === false) {
            throw new RuntimeException('Cannot write temporary file: ' . $tmp);
        }
        if (!rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot move file into place: ' . $absolute);
        }

        $coverImageId = upsertCoverImage($db, $categoryId, $title, $relative);

        $oldAvif = COVER_DIR_ABS . '/' . slug($title) . '.avif';
        if ($image['ext'] !== 'avif' && is_file($oldAvif) && !unlink($oldAvif)) {
            throw new RuntimeException('Cannot remove unsupported AVIF file: ' . $oldAvif);
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__phocagallery_categories'))
            ->set($db->quoteName('image') . ' = ' . $db->quote($relative))
            ->set($db->quoteName('image_id') . ' = ' . $coverImageId)
            ->where($db->quoteName('id') . ' = ' . (int) $categoryId);
        $db->setQuery($query)->execute();

        if (!PhocaGalleryFileThumbnail::deleteFileThumbnail($relative, 1, 1, 1)) {
            throw new RuntimeException('Cannot delete existing thumbnails for ' . $relative);
        }

        $result = PhocaGalleryFileThumbnail::getOrCreateThumbnail($relative, '', 1, 1, 1, 1);
        if (is_string($result) && str_contains(strtolower($result), 'error')) {
            throw new RuntimeException('Thumbnail generation failed for ' . $relative . ': ' . $result);
        }

        printf("UPDATED  %s -> %s\n", implode(' / ', $path), $relative);
        $updated++;
    } catch (Throwable $e) {
        fprintf(
            STDERR,
            "ERROR    %s [categories.md:%d] (%s)\n",
            implode(' / ', $path),
            $line,
            $e->getMessage()
        );
        $errors++;
    }
}

printf(
    "\nDone. Updated: %d, skipped(Link): %d, unchanged(None): %d, errors: %d\n",
    $updated,
    $skippedLinks,
    $skippedNone,
    $errors
);

if ($errors > 0) {
    exit(1);
}
