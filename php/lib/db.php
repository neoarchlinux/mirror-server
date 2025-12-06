<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function getPdo(): PDO {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;

    try {
        $dsn = "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME";

        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed: ' . $e->getMessage()
        ]);
        exit;
    }
}

function getRepoId(PDO $pdo, string $repo): int {
    $stmt = $pdo->prepare("SELECT id FROM repos WHERE repo_name = :repo");
    $stmt->execute([':repo' => $repo]);
    $id = $stmt->fetchColumn();
    if (!$id) jsonError("Repository '$repo' does not exist", 400);
    return (int) $id;
}

function getArchId(PDO $pdo, string $arch): int {
    $stmt = $pdo->prepare("SELECT id FROM arches WHERE arch_name = :arch");
    $stmt->execute([':arch' => $arch]);
    $id = $stmt->fetchColumn();
    if (!$id) jsonError("Architecture '$arch' does not exist", 400);
    return (int) $id;
}

function getPackageId(PDO $pdo, string $packageName): int {
    $stmt = $pdo->prepare("SELECT id FROM packages WHERE package_name = :package_name");
    $stmt->execute([':package_name' => $packageName]);
    $id = $stmt->fetchColumn();
    if (!$id) jsonError("Package '$packageName' does not exist", 400);
    return (int) $id;
}

function insertPackageVersion(
    PDO $pdo,
    int $packageId,
    int $repoId,
    int $archId,
    string $packageVersion,
    string $packageRelease,
    string $fileName,
    string $sourceIp
): int {
    $stmt = $pdo->prepare("UPDATE package_versions
                           SET overwritten = TRUE
                           WHERE package_id = :package_id
                           AND repo_id = :repo_id
                           AND arch_id = :arch_id");

    $stmt->execute([
        ':package_id' => $packageId,
        ':repo_id' => $repoId,
        ':arch_id' => $archId
    ]);

    $stmt = $pdo->prepare("INSERT INTO package_versions(package_id, repo_id, arch_id, package_version, package_release, file_name)
                           VALUES(:package_id, :repo_id, :arch_id, :package_version, :package_release, :file_name)
                           RETURNING id");

    $stmt->execute([
        ':package_id' => $packageId,
        ':repo_id' => $repoId,
        ':arch_id' => $archId,
        ':package_version' => $packageVersion,
        ':package_release' => $packageRelease,
        ':file_name' => $fileName
    ]);

    $pkgVersionId = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO package_history(package_version_id, source_ip)
                           VALUES(:pkg_version_id, :ip)");

    $stmt->execute([
        ':pkg_version_id' => $pkgVersionId,
        ':ip' => $sourceIp
    ]);

    return $pkgVersionId;
}
