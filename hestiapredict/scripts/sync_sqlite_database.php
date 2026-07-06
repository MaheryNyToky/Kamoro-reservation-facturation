<?php

$appRoot = dirname(__DIR__);
$projectRoot = dirname($appRoot);
$rootDb = $projectRoot . DIRECTORY_SEPARATOR . 'database.sqlite';
$laravelDb = $appRoot . DIRECTORY_SEPARATOR . 'database.sqlite';
$legacyDb = $appRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite';

$candidates = array_values(array_filter([$rootDb, $laravelDb, $legacyDb], static function (string $path): bool {
    return is_file($path) && filesize($path) > 0;
}));

if (count($candidates) === 0) {
    exit(0);
}

if (count($candidates) === 1) {
    $source = $candidates[0];
    $targets = array_values(array_diff([$rootDb, $laravelDb, $legacyDb], [$source]));
} else {
    usort($candidates, static function (string $a, string $b): int {
        return filemtime($b) <=> filemtime($a);
    });
    $source = $candidates[0];
    $targets = array_values(array_diff([$rootDb, $laravelDb, $legacyDb], [$source]));
}

foreach ($targets as $target) {
    if ($target === $source) {
        continue;
    }

    $targetDir = dirname($target);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    if (!is_file($target) || filemtime($source) >= filemtime($target) || filesize($target) === 0) {
        copy($source, $target);
    }
}
