<?php

declare(strict_types=1);

namespace RainerMinixhofer\Plugin\System\AstroapiPhocaSync\Extension;

use Joomla\CMS\Event\Application\AfterInitialiseEvent;
use Joomla\CMS\Event\Application\AfterDispatchEvent;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

final class AstroapiPhocaSync extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    private const OPENNGC_URL = 'https://raw.githubusercontent.com/mattiaverga/OpenNGC/refs/heads/master/database_files/NGC.csv';
    /** @var array<string, array{type: string, hubble: string}>|null */
    private ?array $openNgcTypeCache = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterDispatch' => 'onAfterDispatch',
        ];
    }

    public function onAfterDispatch(AfterDispatchEvent $event): void
    {
        $application = $event->getApplication();

        if (
            !$application->isClient('site')
            || $application->getInput()->getCmd('option') !== 'com_phocagallery'
            || $application->getDocument()->getType() !== 'html'
        ) {
            return;
        }

        $assetBase = Uri::root(true) . '/plugins/system/astroapi_phoca_sync/media';
        $pluginPath = JPATH_PLUGINS . '/system/astroapi_phoca_sync/media';
        $application->getDocument()->addScriptOptions(
            'astroapiPhoca',
            [
                'categoryCounts' => $this->recursiveCategoryCounts(),
                'classificationTags' => $this->classificationTags(),
                'homeUrl' => Uri::root(true) . '/',
                'homeLabel' => 'Back to Intranet',
                'statusUrl' => Uri::root(true) . '/index.php?astroapi_status=1&option=com_phocagallery',
            ]
        );
        $application->getDocument()->addStyleSheet(
            $assetBase . '/css/phoca-rotation.css?v=' . (int) filemtime($pluginPath . '/css/phoca-rotation.css')
        );
        $application->getDocument()->addScript(
            $assetBase . '/js/phoca-rotation.js?v=' . (int) filemtime($pluginPath . '/js/phoca-rotation.js'),
            ['defer' => true]
        );
    }

    /**
     * @return array<string, int>
     */
    private function recursiveCategoryCounts(): array
    {
        $database = $this->getDatabase();
        $categoryQuery = $database->createQuery()
            ->select([$database->quoteName('id'), $database->quoteName('parent_id')])
            ->from($database->quoteName('#__phocagallery_categories'));
        $database->setQuery($categoryQuery);
        $categories = $database->loadObjectList();

        $imageQuery = $database->createQuery()
            ->select([$database->quoteName('catid'), 'COUNT(*) AS ' . $database->quoteName('total')])
            ->from($database->quoteName('#__phocagallery'))
            ->where($database->quoteName('published') . ' = 1')
            ->where($database->quoteName('approved') . ' = 1')
            ->group($database->quoteName('catid'));
        $database->setQuery($imageQuery);

        $counts = [];
        foreach ($database->loadObjectList() as $imageCount) {
            $counts[(int) $imageCount->catid] = (int) $imageCount->total;
        }

        $parents = [];
        foreach ($categories as $category) {
            $categoryId = (int) $category->id;
            $counts[$categoryId] ??= 0;
            $parents[$categoryId] = (int) $category->parent_id;
        }

        $depths = [];
        $getDepth = static function (int $categoryId) use (&$getDepth, &$depths, $parents): int {
            if (isset($depths[$categoryId])) {
                return $depths[$categoryId];
            }

            $parentId = $parents[$categoryId] ?? 0;
            $depths[$categoryId] = $parentId > 0 && isset($parents[$parentId])
                ? $getDepth($parentId) + 1
                : 0;

            return $depths[$categoryId];
        };

        arsort($depths);
        foreach (array_keys($counts) as $categoryId) {
            $getDepth($categoryId);
        }
        arsort($depths);

        foreach (array_keys($depths) as $categoryId) {
            $parentId = $parents[$categoryId] ?? 0;
            if ($parentId > 0) {
                $counts[$parentId] = ($counts[$parentId] ?? 0) + $counts[$categoryId];
            }
        }

        ksort($counts);

        return array_map(static fn (int $count): int => $count, $counts);
    }

    /**
     * @return list<array{id: int, title: string, count: int}>
     */
    private function classificationTags(): array
    {
        $database = $this->getDatabase();
        $query = $database->createQuery()
            ->select([
                $database->quoteName('t.id'),
                $database->quoteName('t.title'),
                'COUNT(' . $database->quoteName('r.imgid') . ') AS ' . $database->quoteName('total'),
            ])
            ->from($database->quoteName('#__phocagallery_tags', 't'))
            ->join(
                'LEFT',
                $database->quoteName('#__phocagallery_tags_ref', 'r')
                . ' ON ' . $database->quoteName('r.tagid')
                . ' = ' . $database->quoteName('t.id')
            )
            ->where(
                '('
                . $database->quoteName('t.title') . ' LIKE ' . $database->quote('Classification_%')
                . ' OR ' . $database->quoteName('t.title') . ' IN ('
                . $database->quote('Unclassified') . ','
                . $database->quote('Needs_Manual_Classification')
                . '))'
            )
            ->group([$database->quoteName('t.id'), $database->quoteName('t.title')])
            ->order($database->quoteName('t.ordering'))
            ->order($database->quoteName('t.title'));
        $database->setQuery($query);

        return array_map(
            static fn (object $tag): array => [
                'id' => (int) $tag->id,
                'title' => (string) $tag->title,
                'count' => (int) $tag->total,
            ],
            $database->loadObjectList()
        );
    }

    public function onAfterInitialise(AfterInitialiseEvent $event): void
    {
        $application = $this->getApplication();
        if ($application->getInput()->getInt('astroapi_status') === 1) {
            try {
                $status = $this->getJson('/import-status');
                $pending = $this->getJson('/objects');
                $new = 0;
                $existing = 0;
                foreach ($pending as $observation) {
                    if (!is_array($observation) || empty($observation['id'])) {
                        continue;
                    }
                    if ($this->imageId((int) $observation['id']) > 0) {
                        $existing++;
                    } else {
                        $new++;
                    }
                }
                $status['new_observations'] = $new;
                $status['existing_observations'] = $existing;
                $statusCode = 200;
            } catch (\Throwable $exception) {
                $status = [
                    'available' => false,
                    'watcher' => ['phase' => 'unavailable'],
                    'pending_observations' => 0,
                    'new_observations' => 0,
                    'existing_observations' => 0,
                    'last_uploaded_at' => null,
                ];
                $statusCode = 503;
            }

            $application->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            $application->setHeader('Cache-Control', 'no-store', true);
            $application->setHeader('X-AstroAPI-Status', (string) $statusCode, true);
            echo json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $application->close($statusCode);
        }

        $requested = $application !== null
            && $application->getInput()->getInt('sync_astro') === 1;

        if (!$requested) {
            return;
        }

        $configuredSecret = trim((string) $this->params->get('sync_secret', ''));
        $providedSecret = (string) $application->getInput()->getString('sync_secret', '');

        if (
            $configuredSecret === ''
            || !hash_equals($configuredSecret, $providedSecret)
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
        ) {
            $application->enqueueMessage('AstroAPI Phoca sync rejected: invalid secret or request method.', 'error');
            return;
        }

        try {
            $result = $this->sync(
                $application->getInput()->getInt('sync_reclassify') === 1
            );
            $application->setHeader('X-AstroAPI-Sync', 'success', true);
            $application->enqueueMessage(
                sprintf(
                    'AstroAPI Phoca sync completed: %d created, %d updated, %d skipped.',
                    $result['created'],
                    $result['updated'],
                    $result['skipped']
                ),
                'success'
            );
            $application->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            echo json_encode(
                ['success' => true, 'result' => $result],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $application->close(200);
        } catch (\Throwable $exception) {
            $application->setHeader('X-AstroAPI-Sync', 'failed', true);
            Log::add(
                'AstroAPI Phoca sync failed: ' . $exception->getMessage(),
                Log::ERROR,
                'astroapi.phoca'
            );
            $application->enqueueMessage(
                'AstroAPI Phoca sync failed. See the Joomla log.',
                'error'
            );
            $application->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            echo json_encode(
                ['success' => false, 'error' => $exception->getMessage()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $application->close(500);
        }
    }

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function sync(bool $includeUploaded = false): array
    {
        $database = $this->getDatabase();
        $observations = $this->getJson(
            '/objects' . ($includeUploaded ? '?include_uploaded=1' : '')
        );
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($observations as $summary) {
            if (!is_array($summary) || empty($summary['id']) || empty($summary['observation_key'])) {
                $result['skipped']++;
                continue;
            }

            $observation = $this->getJson('/observation/' . (int) $summary['id']);

            if (!is_array($observation) || empty($observation['id'])) {
                throw new \RuntimeException(
                    'Invalid observation response for AstroAPI ID ' . (int) $summary['id']
                );
            }

            $classification = $this->classify($observation);
            $categoryId = $this->categoryId($classification['category']);
            $tagIds = $this->tagIds($classification['tags']);
            $existingId = $this->imageId((int) $observation['id']);
            if ($includeUploaded && $existingId === 0) {
                Log::add(
                    'AstroAPI reclassification skipped missing Phoca image for ID '
                    . (int) $observation['id'],
                    Log::WARNING,
                    'astroapi.phoca'
                );
                $result['skipped']++;
                continue;
            }
            $filename = $existingId > 0
                ? $this->imageFilename($existingId)
                : $this->downloadStack($observation);
            $archivedLinks = ($includeUploaded && $existingId > 0)
                ? []
                : $this->archiveObservationFiles(
                    $observation,
                    !$includeUploaded
                );
            $observation['media_links'] = $archivedLinks
                + $this->existingMediaLinks($existingId);
            $observation['video_relative'] = $this->selectVideoRelative(
                $observation['media_links']
            ) ?? ($existingId > 0 ? $this->existingVideo($existingId) : null);

            $database->transactionStart();

            try {
                $isNew = $this->storeImage($observation, $filename, $categoryId);
                $imageId = $isNew ? $database->insertid() : $this->imageId((int) $observation['id']);
                $this->storeTags($imageId, $tagIds);
                $this->assignObservationAsCategoryCover($categoryId, $imageId);
                $database->transactionCommit();
            } catch (\Throwable $exception) {
                $database->transactionRollback();
                throw $exception;
            }

            $this->createPhocaThumbnails($filename);
            if (!$includeUploaded) {
                $this->confirmPhocaUpload((int) $observation['id']);
            }
            $result[$isNew ? 'created' : 'updated']++;
        }

        return $result;
    }

    private function confirmPhocaUpload(int $observationId): void
    {
        $imageId = $this->imageId($observationId);
        $mediaLinks = $this->existingMediaLinks($imageId);
        $response = HttpFactory::getHttp()->post(
            $this->apiUrl() . '/observation/' . $observationId . '/phoca-uploaded',
            json_encode(
                ['media_links' => (object) $mediaLinks],
                JSON_THROW_ON_ERROR
            ),
            ['Content-Type' => 'application/json'],
            20
        );

        if ((int) $response->code !== 200) {
            throw new \RuntimeException(
                'Phoca upload succeeded, but AstroInbox cleanup failed for AstroAPI ID '
                . $observationId . ' (' . $response->code . ')'
            );
        }
    }

    /**
     * @param array<string, mixed> $observation
     *
     * @return array{category: list<string>, tags: list<string>}
     */
    private function classify(array $observation): array
    {
        $telescope = strtolower((string) ($observation['telescope'] ?? ''));
        $camera = strtolower((string) ($observation['camera'] ?? ''));
        $target = strtolower(trim((string) ($observation['target_name'] ?? '')));
        $catalogId = strtoupper(preg_replace('/\s+/', '', (string) (
            $observation['simbad_oid']
            ?? $observation['target_name']
            ?? ''
        )));
        $targetType = strtolower((string) ($observation['target_type'] ?? ''));
        $objectClass = strtolower((string) ($observation['object_class'] ?? ''));
        $source = strtolower((string) ($observation['source'] ?? ''));
        $observationText = preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            $telescope . ' ' . $camera . ' ' . $source . ' ' . $objectClass . ' ' . $targetType . ' ' . $target
        ) ?? '';
        $tags = ['Processed'];
        $year = $this->observationYear($observation);

        if ($year !== null) {
            $tags[] = 'Year_' . $year;
        }

        foreach ($this->projectNames($observation) as $projectName) {
            $tags[] = 'Project_' . $this->tagSlug($projectName);
        }

        if (str_contains($observationText, 's50') || str_contains($observationText, 'seestar')) {
            $tags[] = 'Seestar_S50_Pro';
        } elseif (
            str_contains($observationText, 'allsky')
            || str_contains($observationText, 'all-sky')
        ) {
            $tags[] = 'AllSky_Camera';
            $tags[] = 'AllSky';
        } elseif (str_contains($observationText, 'nina') || str_contains($observationText, 'observatory')) {
            $tags[] = 'Observatory_NINA';
            $tags[] = 'Observatory';
        } elseif (str_contains($observationText, 'smartphone') || str_contains($observationText, 'iphone')) {
            $tags[] = 'Smartphone';
            $tags[] = 'Mobile';
        } elseif (
            str_contains($observationText, 'canon')
            || str_contains($observationText, 'nikon')
            || str_contains($observationText, 'sony')
            || str_contains($observationText, 'dslr')
        ) {
            $tags[] = 'DSLR_Camera';
        }

        $manualCategory = $this->explicitCategory($observation['phoca_category'] ?? null);
        if ($manualCategory !== null) {
            $tags[] = 'Classification_Manual';

            return [
                'category' => $manualCategory,
                'tags' => array_values(array_unique($tags)),
            ];
        }

        if (str_contains($source, 'stav') || str_contains($observationText, 'stav colleague')) {
            $tags[] = 'StAV_Society';

            return [
                'category' => ['Astronomy Society (StAV)', 'Member Observations', $year ?? 'Archive'],
                'tags' => array_values(array_unique($tags)),
            ];
        }

        $structuredCategory = $this->structuredCategory(
            implode(' ', array_filter([$objectClass, $targetType]))
        );
        $coarse = preg_match(
            '/^(?:galaxy|nebula|cluster|galax|nebul)$/i',
            trim((string) ($observation['target_type'] ?? $observation['object_class'] ?? ''))
        ) === 1;

        if ($structuredCategory !== null && !$coarse) {
            return [
                'category' => $structuredCategory['category'],
                'tags' => array_values(array_unique([
                    ...$tags,
                    ...$structuredCategory['tags'],
                    'Classification_Structured',
                ])),
            ];
        }

        $openNgcType = $this->resolveOpenNgcClassification($catalogId);
        if ($openNgcType !== null) {
            $openNgcCategory = $this->structuredCategory($openNgcType);
            if ($openNgcCategory !== null) {
                return [
                    'category' => $openNgcCategory['category'],
                    'tags' => array_values(array_unique([
                        ...$tags,
                        ...$openNgcCategory['tags'],
                        'Classification_Structured',
                    ])),
                ];
            }
        }

        if ($structuredCategory !== null) {
            return [
                'category' => $structuredCategory['category'],
                'tags' => array_values(array_unique([
                    ...$tags,
                    ...$structuredCategory['tags'],
                    'Classification_Structured',
                    ...($coarse ? ['Needs_Manual_Classification'] : []),
                ])),
            ];
        }

        if (preg_match('/\b(?:m\s*31|andromeda|m\s*33|m\s*51|m\s*81|m\s*101)\b/', $target)) {
            return [
                'category' => ['Deep Sky Objects', 'Galaxies', 'Spiral Galaxies'],
                'tags' => [...$tags, 'Deep_Sky'],
            ];
        }

        if (preg_match('/\b(?:moon|lunar|mond)\b/', $observationText)) {
            return [
                'category' => ['Solar System', 'Moon', 'Craters'],
                'tags' => [...$tags, 'Solar_System', 'Lunar'],
            ];
        }

        if (preg_match('/\b(?:planet|jupiter|saturn|mars|venus|mercury|uranus|neptune)\b/', $observationText)) {
            return [
                'category' => ['Solar System', 'Planets', 'Planetary Details'],
                'tags' => [...$tags, 'Solar_System', 'Planetary'],
            ];
        }

        if (preg_match('/\b(?:sun|solar|sonne)\b/', $observationText)) {
            return [
                'category' => ['Solar System', 'Sun', 'White Light'],
                'tags' => [...$tags, 'Solar_System'],
            ];
        }

        if (str_contains($observationText, 'milky way')) {
            return [
                'category' => ['Milky Way & Wide Field', 'Milky Way Regions', 'Other Regions'],
                'tags' => [...$tags, 'Milky_Way', 'Wide_Field'],
            ];
        }

        if (str_contains($observationText, 'allsky') || str_contains($observationText, 'all-sky')) {
            return [
                'category' => ['Milky Way & Wide Field', 'AllSky Surveys', 'Complete Sky Coverage'],
                'tags' => [...$tags, 'Wide_Field'],
            ];
        }

        if (str_contains($observationText, 'aurora')) {
            return [
                'category' => ['Sky Events & Phenomena', 'Atmospheric Phenomena', 'Aurora'],
                'tags' => [...$tags, 'Aurora'],
            ];
        }

        if (str_contains($observationText, 'meteor')) {
            return [
                'category' => ['Sky Events & Phenomena', 'Meteor Showers'],
                'tags' => [...$tags, 'Wide_Field'],
            ];
        }

        if (str_contains($observationText, 'eclipse')) {
            return [
                'category' => ['Sky Events & Phenomena', 'Solar Eclipses'],
                'tags' => [...$tags, 'Eclipses'],
            ];
        }

        if (str_contains($observationText, 'comet')) {
            return [
                'category' => ['Sky Events & Phenomena', 'Comets'],
                'tags' => [...$tags, 'Comets'],
            ];
        }

        if (str_contains($observationText, 'asteroid')) {
            return [
                'category' => ['Sky Events & Phenomena', 'Asteroids'],
                'tags' => [...$tags, 'Comets'],
            ];
        }

        if (str_contains($observationText, 'scenery') || str_contains($observationText, 'landscape')) {
            return [
                'category' => ['Landscapes & Artistic Astronomy', 'Milky Way Landscapes'],
                'tags' => [...$tags, 'Landscape'],
            ];
        }

        return [
            'category' => ['Deep Sky Objects', 'Mixed Deep Sky Fields'],
            'tags' => [...$tags, 'Unclassified'],
        ];
    }

    /**
     * @return list<string>|null
     */
    private function explicitCategory(mixed $value): ?array
    {
        if (is_array($value)) {
            $path = array_values(array_filter(
                array_map(static fn (mixed $part): string => trim((string) $part), $value),
                static fn (string $part): bool => $part !== ''
            ));
        } else {
            $path = preg_split('/\s*(?:\/|>|::)\s*/', trim((string) $value)) ?: [];
            $path = array_values(array_filter($path, static fn (string $part): bool => $part !== ''));
        }

        return $path !== [] ? $path : null;
    }

    /**
     * @return array{category: list<string>, tags: list<string>}|null
     */
    private function structuredCategory(string $value): ?array
    {
        $value = strtolower(trim($value));
        $map = [
            '/spiral galax|spiral nebula/' => [['Deep Sky Objects', 'Galaxies', 'Spiral Galaxies'], ['Deep_Sky']],
            '/elliptical galax/' => [['Deep Sky Objects', 'Galaxies', 'Elliptical Galaxies'], ['Deep_Sky']],
            '/irregular galax|dwarf galax/' => [['Deep Sky Objects', 'Galaxies', 'Irregular Galaxies'], ['Deep_Sky']],
            '/galax/' => [['Deep Sky Objects', 'Galaxies'], ['Deep_Sky']],
            '/planetary nebul/' => [['Deep Sky Objects', 'Nebulae', 'Planetary Nebulae'], ['Deep_Sky']],
            '/supernova remnant|snr/' => [['Deep Sky Objects', 'Supernova Remnants'], ['Deep_Sky']],
            '/dark nebul/' => [['Deep Sky Objects', 'Nebulae', 'Dark Nebulae'], ['Deep_Sky']],
            '/reflection nebul/' => [['Deep Sky Objects', 'Nebulae', 'Reflection Nebulae'], ['Deep_Sky']],
            '/emission nebul|h ii region|hii region/' => [['Deep Sky Objects', 'Nebulae', 'Emission Nebulae'], ['Deep_Sky']],
            '/nebul/' => [['Deep Sky Objects', 'Nebulae'], ['Deep_Sky']],
            '/globular cluster/' => [['Deep Sky Objects', 'Star Clusters', 'Globular Clusters'], ['Deep_Sky']],
            '/open cluster/' => [['Deep Sky Objects', 'Star Clusters', 'Open Clusters'], ['Deep_Sky']],
            '/cluster/' => [['Deep Sky Objects', 'Star Clusters'], ['Deep_Sky']],
            '/starfield|star field|multiple star|single star|stellar association/' => [['Milky Way & Wide Field', 'Starfields'], ['Milky_Way']],
            '/lunar|moon/' => [['Solar System', 'Moon'], ['Solar_System', 'Lunar']],
            '/gas giant/' => [['Solar System', 'Planets', 'Gas Giants'], ['Solar_System', 'Planetary']],
            '/terrestrial planet/' => [['Solar System', 'Planets', 'Terrestrial Planets'], ['Solar_System', 'Planetary']],
            '/planet/' => [['Solar System', 'Planets', 'Planetary Details'], ['Solar_System', 'Planetary']],
            '/solar flare|prominence/' => [['Solar System', 'Sun', 'Solar Phenomena'], ['Solar_System']],
            '/sun/' => [['Solar System', 'Sun'], ['Solar_System']],
            '/meteor/' => [['Sky Events & Phenomena', 'Meteor Showers'], ['Wide_Field']],
            '/comet/' => [['Sky Events & Phenomena', 'Comets'], ['Comets']],
            '/asteroid|minor planet/' => [['Sky Events & Phenomena', 'Asteroids'], []],
            '/aurora/' => [['Sky Events & Phenomena', 'Atmospheric Phenomena', 'Aurora'], ['Aurora']],
            '/constellation/' => [['Milky Way & Wide Field', 'Constellations'], ['Wide_Field']],
        ];

        foreach ($map as $pattern => [$category, $tags]) {
            if (preg_match($pattern, $value) === 1) {
                return ['category' => $category, 'tags' => $tags];
            }
        }

        return null;
    }

    private function resolveOpenNgcClassification(string $catalogId): ?string
    {
        if ($catalogId === '' || !preg_match('/^(?:NGC|IC|M)\d+$/', $catalogId)) {
            return null;
        }

        $types = $this->openNgcTypes();
        $entry = $types[$catalogId] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $rawType = strtoupper(trim((string) ($entry['type'] ?? '')));
        $hubble = strtoupper(trim((string) ($entry['hubble'] ?? '')));
        if ($rawType === '') {
            return null;
        }

        if ($rawType === 'G') {
            $galaxySubtype = $this->classifyGalaxyFromHubble($hubble);
            if ($galaxySubtype !== null) {
                return $galaxySubtype;
            }
        }

        $typeMap = [
            'G' => 'Galaxy',
            'GPAIR' => 'Galaxy',
            'GTRPL' => 'Galaxy',
            'GGROUP' => 'Galaxy',
            'OCL' => 'Open Cluster',
            'GCL' => 'Globular Cluster',
            '*' => 'Starfield',
            '**' => 'Starfield',
            '*ASS' => 'Starfield',
            'HII' => 'Emission Nebula',
            'SNR' => 'Supernova Remnant',
            'PN' => 'Planetary Nebula',
            'RFN' => 'Reflection Nebula',
            'NEB' => 'Nebula',
            'EMN' => 'Emission Nebula',
            'CL+N' => 'Emission Nebula',
        ];

        if (isset($typeMap[$rawType])) {
            return $typeMap[$rawType];
        }

        if (str_contains($rawType, 'HII') || str_ends_with($rawType, '+N')) {
            return 'Emission Nebula';
        }
        if (str_contains($rawType, 'PN')) {
            return 'Planetary Nebula';
        }
        if (str_contains($rawType, 'RFN') || str_contains($rawType, 'RN')) {
            return 'Reflection Nebula';
        }
        if (str_contains($rawType, 'DN')) {
            return 'Dark Nebula';
        }
        if (str_contains($rawType, 'SNR')) {
            return 'Supernova Remnant';
        }
        if (str_starts_with($rawType, 'N') || str_contains($rawType, 'NEB')) {
            return 'Nebula';
        }
        if (str_contains($rawType, 'GCL')) {
            return 'Globular Cluster';
        }
        if (str_contains($rawType, 'OCL') || str_contains($rawType, 'CL*')) {
            return 'Open Cluster';
        }
        if (in_array($rawType, ['*', '**', '*ASS'], true)) {
            return 'Starfield';
        }
        if (str_starts_with($rawType, 'G')) {
            return 'Galaxy';
        }

        return null;
    }

    /**
     * @return array<string, array{type: string, hubble: string}>
     */
    private function openNgcTypes(): array
    {
        if ($this->openNgcTypeCache !== null) {
            return $this->openNgcTypeCache;
        }

        try {
            $response = HttpFactory::getHttp()->get(self::OPENNGC_URL, [], 20);
            if ((int) $response->code !== 200) {
                $this->openNgcTypeCache = [];
                return $this->openNgcTypeCache;
            }

            $lines = preg_split('/\r\n|\n|\r/', (string) $response->body) ?: [];
            $types = [];
            $header = null;
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $fields = str_getcsv($line, ';');
                if ($header === null) {
                    $header = $fields;
                    continue;
                }
                if (!is_array($header) || count($fields) !== count($header)) {
                    continue;
                }

                $row = array_combine($header, $fields);
                if (!is_array($row)) {
                    continue;
                }

                $name = strtoupper(trim((string) ($row['Name'] ?? '')));
                $type = strtoupper(trim((string) ($row['Type'] ?? '')));
                $hubble = strtoupper(trim((string) ($row['Hubble'] ?? '')));
                if ($name !== '' && $type !== '') {
                    $types[$name] = ['type' => $type, 'hubble' => $hubble];
                }

                $messier = ltrim(trim((string) ($row['M'] ?? '')), '0');
                if ($messier !== '' && $type !== '') {
                    $types['M' . $messier] = ['type' => $type, 'hubble' => $hubble];
                }
            }

            $this->openNgcTypeCache = $types;
            return $this->openNgcTypeCache;
        } catch (\Throwable $exception) {
            Log::add(
                'OpenNGC type lookup unavailable: ' . $exception->getMessage(),
                Log::WARNING,
                'astroapi.phoca'
            );
            $this->openNgcTypeCache = [];
            return $this->openNgcTypeCache;
        }
    }

    private function classifyGalaxyFromHubble(string $hubble): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $hubble) ?? '');
        if ($value === '') {
            return null;
        }

        if (
            preg_match('/^E[0-9.+-]*$/', $value) === 1
            || str_starts_with($value, 'S0')
            || str_contains($value, 'LENT')
        ) {
            return 'Elliptical Galaxy';
        }
        if (str_contains($value, 'IRR') || str_starts_with($value, 'IM')) {
            return 'Irregular Galaxy';
        }
        if (preg_match('/^(?:S|SA|SAB|SB)/', $value) === 1) {
            return 'Spiral Galaxy';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $observation
     */
    private function observationYear(array $observation): ?string
    {
        $value = trim((string) ($observation['observation_time'] ?? ''));
        if (preg_match('/^(?:19|20)\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $observation
     *
     * @return list<string>
     */
    private function projectNames(array $observation): array
    {
        $values = [];

        foreach (['project_name', 'project_title', 'project'] as $field) {
            $value = $observation[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            } elseif (is_array($value)) {
                $candidate = $value['name'] ?? $value['title'] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $values[] = trim($candidate);
                }
            }
        }

        foreach (['projects', 'project_names'] as $field) {
            $projects = $observation[$field] ?? [];
            if (!is_array($projects)) {
                continue;
            }

            foreach ($projects as $project) {
                $candidate = is_array($project)
                    ? ($project['name'] ?? $project['title'] ?? null)
                    : $project;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $values[] = trim($candidate);
                }
            }
        }

        return array_values(array_unique($values));
    }

    private function tagSlug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $ascii) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'Unknown';
    }

    /**
     * @param list<string> $path
     */
    private function categoryId(array $path): int
    {
        $database = $this->getDatabase();
        $startParents = [0];

        $rootQuery = $database->createQuery()
            ->select($database->quoteName('id'))
            ->from($database->quoteName('#__phocagallery_categories'))
            ->where($database->quoteName('parent_id') . ' = 0')
            ->where($database->quoteName('title') . ' = ' . $database->quote('Astronomy Gallery'));
        $database->setQuery($rootQuery);
        $rootId = (int) $database->loadResult();

        if ($rootId > 0) {
            $startParents[] = $rootId;
        }

        foreach ($startParents as $startParent) {
            $parentId = $startParent;
            $resolved = true;

            foreach ($path as $title) {
                $query = $database->createQuery()
                    ->select($database->quoteName('id'))
                    ->from($database->quoteName('#__phocagallery_categories'))
                    ->where($database->quoteName('parent_id') . ' = ' . (int) $parentId)
                    ->where($database->quoteName('title') . ' = ' . $database->quote($title));
                $database->setQuery($query);
                $categoryId = (int) $database->loadResult();

                if ($categoryId === 0) {
                    $resolved = false;
                    break;
                }

                $parentId = $categoryId;
            }

            if ($resolved) {
                return $parentId;
            }
        }

        throw new \RuntimeException('Required Phoca category is missing: ' . implode(' / ', $path));
    }

    /**
     * @param list<string> $titles
     *
     * @return list<int>
     */
    private function tagIds(array $titles): array
    {
        $database = $this->getDatabase();
        $ids = [];

        foreach (array_unique($titles) as $title) {
            $query = $database->createQuery()
                ->select($database->quoteName('id'))
                ->from($database->quoteName('#__phocagallery_tags'))
                ->where($database->quoteName('title') . ' = ' . $database->quote($title));
            $database->setQuery($query);
            $tagId = (int) $database->loadResult();

            if ($tagId === 0) {
                $alias = strtolower($title);
                $record = (object) [
                    'title' => $title,
                    'alias' => $alias,
                    'description' => 'AstroAPI classification tag: ' . $title . '.',
                    'published' => 1,
                    'ordering' => 0,
                    'language' => '*',
                ];
                $database->insertObject('#__phocagallery_tags', $record);
                $tagId = (int) $database->insertid();
            }

            $ids[] = $tagId;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $observation
     */
    private function downloadStack(array $observation): string
    {
        $apiId = (int) $observation['id'];
        $response = HttpFactory::getHttp()->get(
            $this->apiUrl() . '/stack/' . $apiId,
            [],
            30
        );

        if ($response->code === 404 || ($response->code === 200 && $response->body === '{}')) {
            $response = HttpFactory::getHttp()->get($this->apiUrl() . '/thumb/' . $apiId);
        }

        if ($response->code !== 200 || $response->body === '' || $response->body === '{}') {
            throw new \RuntimeException('Could not download image for AstroAPI ID ' . $apiId);
        }

        $imageInfo = @getimagesizefromstring($response->body);

        if ($imageInfo === false) {
            throw new \RuntimeException('AstroAPI returned an invalid image for ID ' . $apiId);
        }

        $extension = match ($imageInfo[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };
        $version = substr(hash('sha256', $response->body), 0, 12);
        $relativeName = 'astroapi/' .
            $this->slug((string) $observation['observation_key']) .
            '-' . $version . '.' . $extension;
        $absoluteName = JPATH_ROOT . '/images/phocagallery/' . $relativeName;
        $directory = dirname($absoluteName);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create Phoca image directory: ' . $directory);
        }

        $temporaryName = $absoluteName . '.tmp';

        if (file_put_contents($temporaryName, $response->body, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write Phoca image: ' . $temporaryName);
        }

        if (!rename($temporaryName, $absoluteName)) {
            @unlink($temporaryName);
            throw new \RuntimeException('Cannot replace Phoca image: ' . $absoluteName);
        }

        return $relativeName;
    }

    /**
     * @param array<string, mixed> $observation
     *
     * @return array<int, string>
     */
    private function archiveObservationFiles(array $observation, bool $required): array
    {
        $archived = [];
        foreach ($observation['files'] ?? [] as $file) {
            if (!is_array($file)) {
                continue;
            }

            $fileId = (int) ($file['id'] ?? 0);
            $name = basename((string) ($file['file_name'] ?? ''));
            if ($fileId <= 0 || $name === '') {
                continue;
            }

            $directory = JPATH_ROOT . '/images/phocagallery/astroapi-media/'
                . (int) $observation['id'];
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot create observation media directory: ' . $directory);
            }

            $temporaryName = $directory . '/.download-' . $fileId . '-' . bin2hex(random_bytes(6));
            $output = fopen($temporaryName, 'wb');
            if ($output === false) {
                throw new \RuntimeException('Cannot create temporary media file: ' . $temporaryName);
            }

            $curl = curl_init(
                $this->apiUrl() . '/observation/' . (int) $observation['id'] . '/file/' . $fileId
            );
            if ($curl === false) {
                fclose($output);
                @unlink($temporaryName);
                throw new \RuntimeException('Cannot initialize media download');
            }

            curl_setopt_array($curl, [
                CURLOPT_FILE => $output,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FAILONERROR => false,
            ]);
            $downloaded = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($output);

            if ($downloaded !== true || $status !== 200 || filesize($temporaryName) === 0) {
                @unlink($temporaryName);
                if (!$required && $status === 404) {
                    continue;
                }
                throw new \RuntimeException(
                    'Could not archive observation file ' . $name
                    . ' (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ')'
                );
            }

            $version = substr((string) hash_file('sha256', $temporaryName), 0, 12);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $filename = $this->slug((string) pathinfo($name, PATHINFO_FILENAME))
                . '-' . $version . ($extension !== '' ? '.' . $extension : '');
            $relativeName = 'astroapi-media/' . (int) $observation['id'] . '/' . $filename;
            $absoluteName = JPATH_ROOT . '/images/phocagallery/' . $relativeName;
            if (!rename($temporaryName, $absoluteName)) {
                @unlink($temporaryName);
                throw new \RuntimeException('Cannot replace archived file: ' . $absoluteName);
            }

            $archived[$fileId] = $relativeName;
        }

        return $archived;
    }

    /**
     * @param array<int, string> $mediaLinks
     */
    private function selectVideoRelative(array $mediaLinks): ?string
    {
        foreach ($mediaLinks as $relative) {
            if (in_array(
                strtolower(pathinfo($relative, PATHINFO_EXTENSION)),
                ['mp4', 'webm'],
                true
            )) {
                return $relative;
            }
        }

        return null;
    }

    private function existingVideo(int $imageId): ?string
    {
        $database = $this->getDatabase();
        $query = $database->createQuery()
            ->select($database->quoteName('params'))
            ->from($database->quoteName('#__phocagallery'))
            ->where($database->quoteName('id') . ' = ' . (int) $imageId);
        $database->setQuery($query);
        $params = json_decode((string) $database->loadResult(), true);

        $relative = is_array($params) ? ($params['video_relative'] ?? null) : null;
        return is_string($relative) && $relative !== '' ? $relative : null;
    }

    /**
     * @return array<int, string>
     */
    private function existingMediaLinks(int $imageId): array
    {
        if ($imageId <= 0) {
            return [];
        }

        $database = $this->getDatabase();
        $query = $database->createQuery()
            ->select($database->quoteName('params'))
            ->from($database->quoteName('#__phocagallery'))
            ->where($database->quoteName('id') . ' = ' . (int) $imageId);
        $database->setQuery($query);
        $params = json_decode((string) $database->loadResult(), true);
        $links = is_array($params) ? ($params['media_links'] ?? []) : [];

        if (!is_array($links)) {
            return [];
        }

        if (array_is_list($links)) {
            return [];
        }

        $normalized = [];
        foreach ($links as $fileId => $relative) {
            $normalized[(int) $fileId] = (string) $relative;
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $observation
     */
    private function storeImage(array $observation, string $filename, int $categoryId): bool
    {
        $database = $this->getDatabase();
        $apiId = (int) $observation['id'];
        $existingId = $this->imageId($apiId);
        $target = trim((string) ($observation['target_name'] ?? 'Unknown target'));
        $equipment = trim((string) ($observation['telescope'] ?? ($observation['camera'] ?? 'Unknown equipment')));
        $observationTime = (string) ($observation['observation_time'] ?? '');
        $date = $this->normaliseDate($observationTime);
        $record = (object) [
            'id' => $existingId,
            'catid' => $categoryId,
            'title' => $target,
            'alias' => 'astroapi-' . $apiId . '-' . $this->slug($target),
            'filename' => $filename,
            'description' => $this->description($observation),
            'date' => $date,
            'published' => 1,
            'approved' => 1,
            'ordering' => 0,
            'language' => '*',
            'extid' => '',
            'extlink1' => $this->simbadLink($observation),
            'params' => json_encode(
                [
                    'source' => 'astroapi',
                    'observation_id' => $apiId,
                    'observation_key' => (string) $observation['observation_key'],
                    'equipment' => $equipment,
                    'video_relative' => $observation['video_relative'] ?? null,
                    'media_links' => (object) ($observation['media_links'] ?? []),
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];

        if ($existingId > 0) {
            $database->updateObject('#__phocagallery', $record, 'id');

            return false;
        }

        $database->insertObject('#__phocagallery', $record);

        return true;
    }

    private function imageId(int $apiId): int
    {
        $database = $this->getDatabase();
        $query = $database->createQuery()
            ->select($this->getDatabase()->quoteName('id'))
            ->from($this->getDatabase()->quoteName('#__phocagallery'))
            ->where(
                '(' .
                $database->quoteName('extid') . ' = ' . $database->quote('astroapi:' . $apiId) .
                ' OR ' . $database->quoteName('alias') . ' LIKE ' .
                $database->quote('astroapi-' . $apiId . '-%') .
                ')'
            );
        $database->setQuery($query);

        return (int) $database->loadResult();
    }

    private function imageFilename(int $imageId): string
    {
        $database = $this->getDatabase();
        $query = $database->createQuery()
            ->select($database->quoteName('filename'))
            ->from($database->quoteName('#__phocagallery'))
            ->where($database->quoteName('id') . ' = ' . (int) $imageId);
        $database->setQuery($query);
        $filename = trim((string) $database->loadResult());

        if ($filename === '') {
            throw new \RuntimeException('Existing Phoca image has no filename: ' . $imageId);
        }

        return $filename;
    }

    /**
     * @param list<int> $tagIds
     */
    private function storeTags(int $imageId, array $tagIds): void
    {
        $database = $this->getDatabase();
        $delete = $database->createQuery()
            ->delete($database->quoteName('#__phocagallery_tags_ref'))
            ->where($database->quoteName('imgid') . ' = ' . $imageId);
        $database->setQuery($delete)->execute();

        foreach ($tagIds as $tagId) {
            $reference = (object) ['imgid' => $imageId, 'tagid' => $tagId];
            $database->insertObject('#__phocagallery_tags_ref', $reference);
        }
    }

    private function assignObservationAsCategoryCover(int $categoryId, int $imageId): void
    {
        $database = $this->getDatabase();

        while ($categoryId > 0) {
            $currentImageQuery = $database->createQuery()
                ->select($database->quoteName('image_id'))
                ->from($database->quoteName('#__phocagallery_categories'))
                ->where($database->quoteName('id') . ' = ' . $categoryId);
            $database->setQuery($currentImageQuery);
            $currentImageId = (int) $database->loadResult();

            if ($currentImageId === 0) {
                $update = $database->createQuery()
                    ->update($database->quoteName('#__phocagallery_categories'))
                    ->set($database->quoteName('image_id') . ' = ' . $imageId)
                    ->where($database->quoteName('id') . ' = ' . $categoryId);
                $database->setQuery($update)->execute();
            }

            $query = $database->createQuery()
                ->select($database->quoteName('parent_id'))
                ->from($database->quoteName('#__phocagallery_categories'))
                ->where($database->quoteName('id') . ' = ' . $categoryId);
            $database->setQuery($query);
            $categoryId = (int) $database->loadResult();
        }
    }

    /**
     * @param array<string, mixed> $observation
     */
    private function description(array $observation): string
    {
        $identifier = trim((string) (
            $observation['simbad_oid']
            ?? $observation['target_name']
            ?? ''
        ));
        $seestarMetadata = $this->extractSeestarStackMetadata($observation);
        $classification = $this->classify($observation);
        $rows = [];
        $addRow = static function (string $label, string $value, bool $isHtml = false) use (&$rows): void {
            if ($value === '') {
                return;
            }
            $rows[] = [
                'label' => $label,
                'value' => $value,
                'is_html' => $isHtml,
            ];
        };
        $details = [
            '<p><strong>AstroAPI observation</strong><br>' .
            htmlspecialchars((string) $observation['observation_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
            '</p>',
        ];

        foreach (
            [
                'Observation time' => 'observation_time',
                'Telescope' => 'telescope',
                'Camera' => 'camera',
                'Filter' => 'filter_used',
                'Exposure' => 'exposure_time',
                'Gain' => 'gain_value',
            ] as $label => $field
        ) {
            if ($observation[$field] ?? null) {
                $addRow($label, (string) $observation[$field]);
            }
        }

        $classificationLabels = $this->formatClassificationLabels($classification['tags'] ?? []);
        if ($classificationLabels !== '') {
            $addRow('Classification', $classificationLabels);
        }

        if ($identifier !== '') {
            $url = $this->simbadUrl($identifier);
            $addRow('Catalogue', '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '" target="_blank" rel="noopener noreferrer">In SIMBAD öffnen</a>', true);
        }

        if ($seestarMetadata !== []) {
            $addRow('Stack creator', trim((string) ($seestarMetadata['creator'] ?? '')));
            $addRow('Stack target', trim((string) ($seestarMetadata['obj_name'] ?? '')));
            if (isset($seestarMetadata['stack_num'])) {
                $addRow('Stacked frames', (string) (int) $seestarMetadata['stack_num']);
            }
            if (isset($seestarMetadata['exp_sec'])) {
                $addRow('Sub exposure', (string) $seestarMetadata['exp_sec'] . ' s');
            }
            if (isset($seestarMetadata['tot_exp_sec'])) {
                $addRow('Total integration', $this->formatExposureDuration((float) $seestarMetadata['tot_exp_sec']));
            }
            if (
                isset($seestarMetadata['size'])
                && is_array($seestarMetadata['size'])
                && count($seestarMetadata['size']) === 2
            ) {
                $addRow(
                    'Frame size',
                    (int) $seestarMetadata['size'][0] . ' × ' . (int) $seestarMetadata['size'][1] . ' px'
                );
            }
            $addRow('Bayer pattern', trim((string) ($seestarMetadata['bayer_pat'] ?? '')));
            if (isset($seestarMetadata['lp_position'])) {
                $addRow('LP position', (string) (int) $seestarMetadata['lp_position']);
            }
            if (isset($seestarMetadata['mosaic']) && is_array($seestarMetadata['mosaic'])) {
                $mosaic = $seestarMetadata['mosaic'];
                if (isset($mosaic['angle'])) {
                    $addRow('Mosaic angle', (string) $mosaic['angle'] . '°');
                }
                if (isset($mosaic['scale'])) {
                    $addRow('Mosaic scale', (string) $mosaic['scale']);
                }
                if (isset($mosaic['star_map_angle'])) {
                    $addRow('Sky angle', (string) $mosaic['star_map_angle'] . '°');
                }
            }
            if (array_key_exists('is_solved', $seestarMetadata)) {
                $addRow('Plate solved', $seestarMetadata['is_solved'] ? 'yes' : 'no');
            }
        }

        if ($rows !== []) {
            $details[] = '<div class="astro-observation-meta-grid">';
            foreach ($rows as $row) {
                $details[] = '<div class="astro-observation-meta-item"><span class="astro-observation-meta-label">' .
                    htmlspecialchars((string) $row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                    '</span><span class="astro-observation-meta-value">' .
                    (
                        ($row['is_html'] ?? false)
                            ? (string) $row['value']
                            : htmlspecialchars((string) $row['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    ) .
                    '</span></div>';
            }
            $details[] = '</div>';
        }

        $files = array_values(array_filter(
            is_array($observation['files'] ?? null) ? $observation['files'] : [],
            static fn (mixed $file): bool => is_array($file) && !empty($file['file_name'])
        ));
        if ($files !== []) {
            $details[] = '<p><strong>Files (' . count($files) . ')</strong></p><ul class="astro-observation-files">';
            foreach ($files as $file) {
                $type = trim((string) ($file['file_type'] ?? 'FILE'));
                $name = trim((string) $file['file_name']);
                $fileId = (int) ($file['id'] ?? 0);
                $mediaLinks = is_array($observation['media_links'] ?? null)
                    ? $observation['media_links']
                    : [];
                $relative = $mediaLinks[$fileId] ?? null;
                $details[] = '<li><strong>' .
                    htmlspecialchars($type !== '' ? $type : 'FILE', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                    ':</strong> ' .
                    (is_string($relative) && $relative !== ''
                        ? '<a href="' . htmlspecialchars(
                            Uri::root(true) . '/images/phocagallery/' . ltrim($relative, '/'),
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ) . '" download="' .
                            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                            '">' . htmlspecialchars(
                                $name,
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            ) . '</a>'
                        : htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    ) .
                    '</li>';
            }
            $details[] = '</ul>';
        }

        $video = trim((string) ($observation['video_relative'] ?? ''));
        if ($video !== '') {
            $videoUrl = Uri::root(true) . '/images/phocagallery/' . ltrim($video, '/');
            $details[] = '<p><strong>Video</strong></p>';
            $details[] = '<video class="astro-observation-video" controls preload="metadata" playsinline>';
            $details[] = '<source src="' .
                htmlspecialchars($videoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                '" type="' . (
                    str_ends_with(strtolower($video), '.webm') ? 'video/webm' : 'video/mp4'
                ) . '">';
            $details[] = '</video>';
        }

        return implode('', $details);
    }

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    private function extractSeestarStackMetadata(array $observation): array
    {
        $files = is_array($observation['files'] ?? null) ? $observation['files'] : [];
        if ($files === []) {
            return [];
        }

        $mediaLinks = is_array($observation['media_links'] ?? null)
            ? $observation['media_links']
            : [];
        $primaryCandidates = [];
        $fallbackCandidates = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $type = strtoupper(trim((string) ($file['file_type'] ?? '')));
            if ($type !== 'STACK') {
                continue;
            }

            $name = trim((string) ($file['file_name'] ?? ''));
            $lowerName = strtolower($name);
            if (
                !str_ends_with($lowerName, '.jpg')
                && !str_ends_with($lowerName, '.jpeg')
                && !str_ends_with($lowerName, '.png')
                && !str_ends_with($lowerName, '.webp')
            ) {
                continue;
            }

            $fileId = (int) ($file['id'] ?? 0);
            $relative = $fileId > 0 ? ($mediaLinks[$fileId] ?? null) : null;
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $absolutePath = JPATH_ROOT . '/images/phocagallery/' . ltrim($relative, '/');
            if (!is_file($absolutePath)) {
                continue;
            }

            if (str_contains($lowerName, '_astroai.') || str_contains($lowerName, '_thn.')) {
                $fallbackCandidates[] = $absolutePath;
                continue;
            }

            $primaryCandidates[] = $absolutePath;
        }

        $candidates = array_values(array_unique([...$primaryCandidates, ...$fallbackCandidates]));
        foreach ($candidates as $candidate) {
            $metadata = $this->extractEmbeddedJsonMetadata($candidate);
            if ($metadata !== []) {
                return $metadata;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEmbeddedJsonMetadata(string $absolutePath): array
    {
        $binary = @file_get_contents($absolutePath);
        if (!is_string($binary) || $binary === '') {
            return [];
        }

        $start = strpos($binary, '{"result":');
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $end = null;
        $length = strlen($binary);

        for ($i = $start; $i < $length; $i++) {
            $char = $binary[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null || $end <= $start) {
            return [];
        }

        $json = substr($binary, $start, ($end - $start) + 1);
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['result'] ?? null)) {
            return [];
        }

        $result = $decoded['result'];
        if (!array_key_exists('stack_num', $result) && !array_key_exists('tot_exp_sec', $result)) {
            return [];
        }

        return $result;
    }

    private function formatExposureDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '00:00:00';
        }

        $totalSeconds = (int) round($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $remainingSeconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * @param list<string> $tags
     */
    private function formatClassificationLabels(array $tags): string
    {
        $labels = [];
        foreach ($tags as $tag) {
            $value = trim((string) $tag);
            if (
                $value === ''
                || $value === 'Processed'
                || str_starts_with($value, 'Year_')
                || str_starts_with($value, 'Project_')
            ) {
                continue;
            }
            $labels[] = str_replace('_', ' ', $value);
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    /**
     * Phoca external-link fields use: url|title|target|icon.
     */
    private function simbadLink(array $observation): string
    {
        $identifier = trim((string) (
            $observation['simbad_oid']
            ?? $observation['target_name']
            ?? ''
        ));
        if ($identifier === '') {
            return '';
        }

        return $this->simbadUrl($identifier) . '|SIMBAD: ' . str_replace('|', '/', $identifier)
            . '|_blank|1';
    }

    private function simbadUrl(string $identifier): string
    {
        return 'https://simbad.cfa.harvard.edu/simbad/sim-id?Ident=' . rawurlencode($identifier);
    }

    private function createPhocaThumbnails(string $filename): void
    {
        $loader = JPATH_ADMINISTRATOR . '/components/com_phocagallery/libraries/loader.php';

        if (!is_file($loader)) {
            throw new \RuntimeException('Phoca Gallery library is not installed.');
        }

        require_once $loader;
        \phocagalleryimport('phocagallery.file.filethumbnail');
        \phocagalleryimport('phocagallery.file.filefolder');
        \phocagalleryimport('phocagallery.image.image');
        \PhocaGalleryFileThumbnail::deleteFileThumbnail($filename, 1, 1, 1);
        $result = \PhocaGalleryFileThumbnail::getOrCreateThumbnail($filename, '', 1, 1, 1, 1);

        if (is_string($result) && str_contains(strtolower($result), 'error')) {
            throw new \RuntimeException('Phoca thumbnail generation failed: ' . $result);
        }
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path): array
    {
        $response = HttpFactory::getHttp()->get($this->apiUrl() . $path);

        if ($response->code !== 200) {
            throw new \RuntimeException('AstroAPI request failed with HTTP ' . $response->code . ': ' . $path);
        }

        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('AstroAPI returned an invalid response for: ' . $path);
        }

        return $decoded;
    }

    private function apiUrl(): string
    {
        $url = rtrim((string) $this->params->get('api_url', 'http://localhost:8010'), '/');

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('The configured AstroAPI URL is invalid.');
        }

        return $url;
    }

    private function normaliseDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }
    }

    private function slug(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower((string) $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'observation';
    }
}
