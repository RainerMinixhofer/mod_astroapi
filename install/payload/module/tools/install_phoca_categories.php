<?php

declare(strict_types=1);

/**
 * Install the approved AstroAPI category tree and tags into Phoca Gallery.
 *
 * Usage:
 *   php tools/install_phoca_categories.php
 *   php tools/install_phoca_categories.php --apply
 */

define('JOOMLA_ROOT', getenv('ASTROAPI_JOOMLA_ROOT') ?: '/var/www/joomla');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This installer must be run from the command line.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);

require JOOMLA_ROOT . '/configuration.php';
$config = new JConfig();

$tree = [
    'Deep Sky Objects' => [
            'Galaxies' => [
                'Spiral Galaxies',
                'Elliptical Galaxies',
                'Irregular Galaxies',
            ],
            'Nebulae' => [
                'Emission Nebulae',
                'Reflection Nebulae',
                'Dark Nebulae',
                'Planetary Nebulae',
            ],
            'Star Clusters' => [
                'Open Clusters',
                'Globular Clusters',
            ],
            'Supernova Remnants',
            'Mixed Deep Sky Fields',
    ],
    'Solar System' => [
            'Moon' => [
                'Craters',
                'Maria & Highlands',
                'Terminator Region',
                'Full Moon',
            ],
            'Planets' => [
                'Terrestrial Planets',
                'Gas Giants',
                'Planetary Details',
            ],
            'Sun' => [
                'White Light',
                'H-Alpha',
                'Solar Phenomena',
            ],
    ],
    'Milky Way & Wide Field' => [
            'Milky Way Core' => [
                'Sagittarius Region',
                'Core Details',
            ],
            'Milky Way Regions' => [
                'Orion Region',
                'Cygnus Region',
                'Other Regions',
            ],
            'Constellations',
            'AllSky Surveys' => [
                'Seasonal Views',
                'Complete Sky Coverage',
                'Special Events',
            ],
            'Starfields',
    ],
    'Sky Events & Phenomena' => [
            'Solar Eclipses',
            'Lunar Eclipses',
            'Occultations',
            'Conjunctions & Alignments',
            'Meteor Showers',
            'Comets',
            'Asteroids',
            'Atmospheric Phenomena' => [
                'Aurora',
                'Airglow',
                'Noctilucent Clouds',
                'Other Light Phenomena',
            ],
    ],
    'Landscapes & Artistic Astronomy' => [
            'Milky Way Landscapes',
            'Startrail Landscapes',
            'Observatory Landscapes',
            'Twilight & Dawn',
            'Weather & Atmosphere',
            'Creative Astronomy Photography',
    ],
    'Observatory & Equipment' => [
            'Observatory Exterior',
            'Observatory Interior',
            'Telescope Systems',
            'Imaging Equipment',
            'Observatory Automation',
    ],
    'Astronomy Society (StAV)' => [
            'Public Events',
            'Observation Sessions',
            'Meetings & Gatherings',
            'Collaborative Projects',
            'Member Observations' => [
                '2025',
                '2026',
                '2027',
                'Archive',
            ],
            'Guest Contributions',
    ],
];

$tags = [
    'Seestar_S50_Pro',
    'Observatory_NINA',
    'AllSky_Camera',
    'DSLR_Camera',
    'Smartphone',
    'StAV_Society',
    'Deep_Sky',
    'Solar_System',
    'Milky_Way',
    'Wide_Field',
    'Lunar',
    'Planetary',
    'Comets',
    'Eclipses',
    'Aurora',
    'Landscape',
    'Observatory',
    'Remote_Capture',
    'Mobile',
    'AllSky',
    'Processed',
    'Raw',
    'Featured',
    'Archive',
    'Classification_Manual',
    'Classification_Structured',
    'Needs_Manual_Classification',
    'Unclassified',
    'Year_2025',
    'Year_2026',
    'Year_2027',
];

/** @var array<string, list<string>> */
$legacyTitles = [
    'Landscapes & Artistic Astronomy' => ['Artistic & Scenery'],
    'Moon' => ['Moon & Lunar Features'],
    'Craters' => ['Lunar Craters'],
    'Terminator Region' => ['Lunar Terminology'],
    'Sun' => ['Sun & Solar Events'],
    'H-Alpha' => ['Solar Observations'],
    'Cygnus Region' => ['Cyg OB Association'],
    'Constellations' => ['Constellation Views'],
    'Complete Sky Coverage' => ['AllSky Coverage'],
    'Atmospheric Phenomena' => ['Aurora & Atmospheric'],
    'Aurora' => ['Aurora Borealis/Australis'],
    'Other Light Phenomena' => ['Light Phenomena'],
    'Startrail Landscapes' => ['Starfield Landscapes'],
    'Observatory Landscapes' => ['Observatory Context'],
    'Weather & Atmosphere' => ['Atmospheric Events'],
    'Comets' => ['Comets & Asteroids'],
    'Collaborative Projects' => ['Special Projects'],
    '2025' => ['2025 Observations'],
    '2026' => ['2026 Observations'],
    '2027' => [],
    'Seestar S50' => ['Seestar S50 Archive'],
    'Observatory' => ['Observatory Archive'],
    'DSLR' => ['DSLR Archive'],
    'AllSky' => ['AllSky Archive'],
];

function slug(string $title): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
    $slug = strtolower((string) $ascii);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    if ($slug === '') {
        throw new RuntimeException('Cannot generate an alias for "' . $title . '".');
    }

    return $slug;
}

function categoryDescription(string $title, int $level): string
{
    return match ($level) {
        0 => $title . ' collection.',
        1 => 'Observations organized under ' . $title . '.',
        default => $title . ' images and observations.',
    };
}

function countCategories(array $nodes): int
{
    $count = count($nodes);

    foreach ($nodes as $children) {
        if (is_array($children)) {
            $count += countCategories($children);
        }
    }

    return $count;
}

/**
 * @return array<int, array{id:int,parent_id:int,title:string,alias:string}>
 */
function loadCategories(mysqli $database, string $categoryTable): array
{
    $result = $database->query(
        'SELECT id, parent_id, title, alias FROM `' . $categoryTable . '`'
    );

    if ($result === false) {
        throw new RuntimeException('Unable to load existing categories.');
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        $rows[$id] = [
            'id' => $id,
            'parent_id' => (int) $row['parent_id'],
            'title' => (string) $row['title'],
            'alias' => (string) $row['alias'],
        ];
    }

    return $rows;
}

/**
 * @param array<int, array{id:int,parent_id:int,title:string,alias:string}> $categories
 * @param list<string> $candidateTitles
 * @param array<int, true> $usedIds
 */
function findCategoryId(
    array $categories,
    int $parentId,
    string $alias,
    array $candidateTitles,
    array $usedIds
): int {
    foreach ($categories as $category) {
        if (
            !isset($usedIds[$category['id']])
            && $category['parent_id'] === $parentId
            && $category['alias'] === $alias
        ) {
            return $category['id'];
        }
    }

    foreach ($categories as $category) {
        if (
            !isset($usedIds[$category['id']])
            && $category['parent_id'] === $parentId
            && in_array($category['title'], $candidateTitles, true)
        ) {
            return $category['id'];
        }
    }

    foreach ($categories as $category) {
        if (!isset($usedIds[$category['id']]) && in_array($category['title'], $candidateTitles, true)) {
            return $category['id'];
        }
    }

    return 0;
}

/**
 * @param array<int, array{id:int,parent_id:int,title:string,alias:string}> $categories
 * @return array<int, list<int>>
 */
function childrenByParent(array $categories): array
{
    $children = [];

    foreach ($categories as $category) {
        $children[$category['parent_id']][] = $category['id'];
    }

    return $children;
}

/**
 * @param array<int, list<int>> $children
 * @param array<int, true> $target
 */
function collectDescendants(array $children, int $id, array &$target): void
{
    if (isset($target[$id])) {
        return;
    }

    $target[$id] = true;

    foreach ($children[$id] ?? [] as $childId) {
        collectDescendants($children, $childId, $target);
    }
}

$categoryTable = $config->dbprefix . 'phocagallery_categories';
$tagTable = $config->dbprefix . 'phocagallery_tags';
$database = new mysqli($config->host, $config->user, $config->password, $config->db);

if ($database->connect_errno !== 0) {
    throw new RuntimeException('Database connection failed: ' . $database->connect_error);
}

$database->set_charset('utf8mb4');

$requiredTables = [
    $categoryTable,
    $tagTable,
];

foreach ($requiredTables as $table) {
    $result = $database->query(
        sprintf(
            'SHOW TABLES LIKE %s',
            "'" . $database->real_escape_string($table) . "'"
        )
    );

    if ($result === false || $result->num_rows !== 1) {
        throw new RuntimeException('Required Phoca Gallery table is missing: ' . $table);
    }
}

echo $apply ? "Applying Phoca Gallery AstroAPI structure...\n" : "Dry run: no database changes will be made.\n";
echo 'Categories: ' . countCategories($tree) . "\n";
echo 'Tags: ' . count($tags) . "\n";

if (!$apply) {
    echo "Run with --apply to install the structure.\n";
    exit(0);
}

$database->begin_transaction();

try {
    $createdCategories = 0;
    $updatedCategories = 0;
    $createdTags = 0;
    $updatedTags = 0;
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $categories = loadCategories($database, $categoryTable);
    $usedCategoryIds = [];
    $wantedCategoryIds = [];
    $insertCategory = $database->prepare(
        'INSERT INTO `' . $categoryTable . '` ' .
        '(parent_id, title, name, alias, description, date, published, approved, ordering, access, language) ' .
        'VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, 1, \'*\')'
    );
    $updateCategory = $database->prepare(
        'UPDATE `' . $categoryTable . '` SET parent_id = ?, title = ?, name = ?, alias = ?, description = ?, ' .
        'published = 1, approved = 1, ordering = ?, access = 1, language = \'*\' WHERE id = ?'
    );

    $installCategories = function (array $nodes, int $parentId = 0, int $level = 0) use (
        &$installCategories,
        &$createdCategories,
        &$updatedCategories,
        &$categories,
        &$usedCategoryIds,
        &$wantedCategoryIds,
        $legacyTitles,
        $insertCategory,
        $updateCategory,
        $now
    ): void {
        $ordering = 1;

        foreach ($nodes as $titleOrIndex => $children) {
            if (is_int($titleOrIndex)) {
                $title = (string) $children;
                $children = [];
            } else {
                $title = (string) $titleOrIndex;
                $children = is_array($children) ? $children : [];
            }

            $alias = slug($title);
            $description = categoryDescription($title, $level);
            $candidates = array_values(array_unique([$title, ...($legacyTitles[$title] ?? [])]));
            $categoryId = findCategoryId(
                $categories,
                $parentId,
                $alias,
                $candidates,
                $usedCategoryIds
            );

            if ($categoryId === 0) {
                $insertCategory->bind_param(
                    'isssssi',
                    $parentId,
                    $title,
                    $title,
                    $alias,
                    $description,
                    $now,
                    $ordering
                );
                $insertCategory->execute();
                $categoryId = $insertCategory->insert_id;
                $createdCategories++;
                $categories[$categoryId] = [
                    'id' => $categoryId,
                    'parent_id' => $parentId,
                    'title' => $title,
                    'alias' => $alias,
                ];
            } else {
                $updateCategory->bind_param(
                    'issssii',
                    $parentId,
                    $title,
                    $title,
                    $alias,
                    $description,
                    $ordering,
                    $categoryId
                );
                $updateCategory->execute();
                $updatedCategories++;
                $categories[$categoryId]['parent_id'] = $parentId;
                $categories[$categoryId]['title'] = $title;
                $categories[$categoryId]['alias'] = $alias;
            }

            $usedCategoryIds[$categoryId] = true;
            $wantedCategoryIds[$categoryId] = true;
            echo '  category ' . $title . ' => ' . $categoryId . "\n";

            if ($children !== []) {
                $installCategories($children, $categoryId, $level + 1);
            }

            $ordering++;
        }
    };

    $installCategories($tree);

    $categories = loadCategories($database, $categoryTable);
    $children = childrenByParent($categories);
    $scopeCategoryIds = [];
    $scopeRoots = [
        'Astronomy Gallery',
        'Deep Sky Objects',
        'Solar System',
        'Milky Way & Wide Field',
        'Sky Events & Phenomena',
        'Artistic & Scenery',
        'Landscapes & Artistic Astronomy',
        'Astronomy Society (StAV)',
        'Observatory & Equipment',
    ];

    foreach ($categories as $category) {
        if (in_array($category['title'], $scopeRoots, true)) {
            collectDescendants($children, $category['id'], $scopeCategoryIds);
        }
    }

    $toDisable = array_diff_key($scopeCategoryIds, $wantedCategoryIds);
    if ($toDisable !== []) {
        $disableIds = implode(',', array_map('intval', array_keys($toDisable)));
        $disable = $database->query(
            'UPDATE `' . $categoryTable . '` SET published = 0 WHERE id IN (' . $disableIds . ')'
        );

        if ($disable === false) {
            throw new RuntimeException('Failed to disable obsolete categories.');
        }

        echo '  disabled obsolete categories: ' . count($toDisable) . "\n";
    }

    $findTag = $database->prepare(
        'SELECT id FROM `' . $tagTable . '` WHERE alias = ? LIMIT 1'
    );
    $insertTag = $database->prepare(
        'INSERT INTO `' . $tagTable . '` (title, alias, description, published, ordering, language) ' .
        'VALUES (?, ?, ?, 1, ?, \'*\')'
    );
    $updateTag = $database->prepare(
        'UPDATE `' . $tagTable . '` SET title = ?, description = ?, published = 1, ordering = ?, ' .
        'language = \'*\' WHERE id = ?'
    );

    foreach ($tags as $index => $title) {
        $alias = strtolower($title);
        $description = 'AstroAPI classification tag: ' . $title . '.';
        $ordering = $index + 1;
        $findTag->bind_param('s', $alias);
        $findTag->execute();
        $existing = $findTag->get_result()->fetch_assoc();

        if ($existing === null) {
            $insertTag->bind_param('sssi', $title, $alias, $description, $ordering);
            $insertTag->execute();
            $tagId = $insertTag->insert_id;
            $createdTags++;
        } else {
            $tagId = (int) $existing['id'];
            $updateTag->bind_param('ssii', $title, $description, $ordering, $tagId);
            $updateTag->execute();
            $updatedTags++;
        }

        echo '  tag ' . $title . ' => ' . $tagId . "\n";
    }

    $database->commit();

    printf(
        "Completed: %d categories created, %d updated; %d tags created, %d updated.\n",
        $createdCategories,
        $updatedCategories,
        $createdTags,
        $updatedTags
    );
} catch (Throwable $exception) {
    $database->rollback();
    throw $exception;
}
