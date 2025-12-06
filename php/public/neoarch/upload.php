<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/utils.php';
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../../lib/storage.php';
require_once __DIR__ . '/../../lib/db.php';

foreach (['repo', 'arch', 'packageName', 'packageVersion', 'packageRelease', 'hmac'] as $f) {
    if (!isset($_POST[$f])) jsonError("Missing POST field: $f");
}

$pdo = getPdo();
$ip = getIpAddress();

$repo = $_POST['repo'];
$arch = $_POST['arch'];
$packageName = $_POST['packageName'];
$packageVersion = $_POST['packageVersion'];
$packageRelease = $_POST['packageRelease'];
$hmacClient = $_POST['hmac'];

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonError("File upload error");
}

$fileTmpPath = $_FILES['file']['tmp_name'];
$fileContents = file_get_contents($fileTmpPath);
$fileName = $_FILES['file']['name'];

assertHmac([
    'repo' => $repo,
    'arch' => $arch,
    'packageName' => $packageName,
    'packageVersion' => $packageVersion,
    'packageRelease' => $packageRelease
], $fileContents, $hmacClient, $UPLOAD_SECRET);

$expectedFileName = sprintf("%s-%s-%s-%s.pkg.tar.zst", $packageName, $packageVersion, $packageRelease, $arch);
if ($fileName !== $expectedFileName) jsonError("Filename does not match expected: $expectedFileName != $fileName");
if (pathinfo($fileName, PATHINFO_EXTENSION) !== 'zst') jsonError("File must have .pkg.tar.zst extension");

$repoId = getRepoId($pdo, $repo);
$archId = getArchId($pdo, $arch);
$packageId = getPackageId($pdo, $packageName);

$stagingPath = getStagingPath($STAGING_BASE, $repo, $arch, $fileName);
$stagingBase = dirname($stagingPath);
ensureDir($stagingBase);

$finalPath = getFinalPath($MIRROR_BASE, $repo, $arch, $fileName);
$finalBase = dirname($finalPath);
ensureDir($finalBase);

$tmpUploaded = "$stagingBase/_upload_" . bin2hex(random_bytes(8));
if (!move_uploaded_file($fileTmpPath, $tmpUploaded)) {
    jsonError("Failed to store uploaded file", 500);
}

$buildId   = "build_" . date("YmdHis") . "_" . bin2hex(random_bytes(8));
$buildRoot = "$stagingBase/$buildId";
$buildPkgs = "$buildRoot/packages";
$buildDb   = "$buildRoot/db";

ensureDir($buildPkgs);
ensureDir($buildDb);

$dh = opendir($finalBase);
if ($dh === false) jsonError("Failed to open final repo", 500);

while (($entry = readdir($dh)) !== false) {
    if ($entry === "." || $entry === "..") continue;

    if (str_ends_with($entry, ".pkg.tar.zst")) {
        copy("$finalBase/$entry", "$buildPkgs/$entry");
    }
}

closedir($dh);

$workspacePkg = "$buildPkgs/$fileName";
move($tmpUploaded, $workspacePkg);

$repoDbPath    = "$buildDb/$repo.db.tar.gz";
$repoFilesPath = "$buildDb/$repo.files.tar.gz";
// TODO: $repoLinksPath

if (file_exists($repoDbPath)) unlink($repoDbPath);
if (file_exists($repoFilesPath)) unlink($repoFilesPath);

exec("repo-remove $repoDbPath '{$packageName}' 2>&1", $r1, $code1);

$pkgFiles = glob("$buildPkgs/*.pkg.tar.zst");
$pkgList = implode(' ', array_map('escapeshellarg', $pkgFiles));

exec("repo-add $repoDbPath $pkgList 2>&1", $r2, $code2);

if ($code2 !== 0) {
    jsonError("repo-add failed: " . implode('<br />', $r2), 500);
}

$filesSource = str_replace(".db.tar.gz", ".files.tar.gz", $repoDbPath);
move($filesSource, $repoFilesPath);

$publishId = "publish_" . date("YmdHis") . "_" . bin2hex(random_bytes(8));
$publishTmp = "$stagingBase/$publishId";
ensureDir($publishTmp);

foreach ($pkgFiles as $pf) {
    $bn = basename($pf);
    copy($pf, "$publishTmp/$bn");
}

copy($repoDbPath, "$publishTmp/$repo.db.tar.gz");
copy($repoFilesPath, "$publishTmp/$repo.files.tar.gz");

$tmpName = "$finalBase.tmp_" . bin2hex(random_bytes(8));
move($publishTmp, $tmpName);

$oldFinal = "$finalBase.old_" . bin2hex(random_bytes(8));
move($finalBase, $oldFinal);
move($tmpName, $finalBase);

exec("rm -rf " . escapeshellarg($oldFinal), $rmo, $rmc);
if ($rmc !== 0) {
    jsonError("Failed to remove old version of packages directory", 500);
}

insertPackageVersion(
    $pdo,
    $packageId,
    $repoId,
    $archId,
    $packageVersion,
    $packageRelease,
    $expectedFileName,
    $ip
);

echo json_encode(['success' => true]);