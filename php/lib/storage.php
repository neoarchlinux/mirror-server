<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.php';

function getStagingPath(string $base, string $repo, string $arch, string $fileName): string {
    $dir = "$base/$repo/$arch";
    ensureDir($dir);
    return "$dir/$fileName";
}

function getFinalPath(string $base, string $repo, string $arch, string $fileName): string {
    $dir = "$base/$repo/os/$arch";
    ensureDir($dir);
    return "$dir/$fileName";
}

function removeOldPackages(array $paths, string $packageDir): void {
    foreach ($paths as $path) {
        if (str_starts_with($path, $packageDir) && file_exists($path)) {
            unlink($path);
        }
    }
}

function moveUploadedToStaging(string $tmpPath, string $stagingPath): void {
    if (!move_uploaded_file($tmpPath, $stagingPath)) {
        jsonError("Failed to move uploaded file to staging", 500);
    }
}

function moveToFinal(string $stagingPath, string $finalPath): void {
    if (!rename($stagingPath, $finalPath)) {
        jsonError("Failed to move package to final directory", 500);
    }
}
