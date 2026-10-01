<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');

use SCM\Core\Database;
use SCM\Modules\CorrectiveReview\CorrectiveReviewPhotos;

$directory = sys_get_temp_dir() . '/scm-corrective-photos-' . bin2hex(random_bytes(5));
mkdir($directory);
define('SCM_UPLOAD_PATH', $directory);
define('SCM_BASE_URL', 'https://example.test/panel');
define('SCM_APP_SECRET', 'test-secret');
define('SCM_UPLOAD_MAX_BYTES', 25000000);

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, guid TEXT, post_mime_type TEXT, post_type TEXT)');
$pdo->exec("INSERT INTO wp_posts VALUES (42, 'https://example.test/wp-content/uploads/dano.jpg', 'image/jpeg', 'attachment')");
$db = new Database($pdo);
$refs = CorrectiveReviewPhotos::refs(serialize([42, 'file.php?n=aaaaaaaaaaaaaaaaaaaaaaaa_123.jpg&s=old']));
$editor = new class {
  use \SCM\App\Concerns\HandlesCorrectiveReviewActions;
  public function attachExisting(array $items): array {
    $stored = [];
    return $this->correctiveReviewAttachUploadedPhotos($items, $stored);
  }
};
$many = array_map(static fn(int $i): string => 'https://example.test/photo-' . $i . '.jpg', range(1, 35));
$attached = $editor->attachExisting([['registro_foto_dano' => implode(',', $many)]]);
if (count(CorrectiveReviewPhotos::refs($attached[0]['registro_foto_dano'])) !== 35) {
  throw new RuntimeException('La revisión limitó la cantidad de fotos existentes.');
}
if ($refs !== ['42', 'file.php?n=aaaaaaaaaaaaaaaaaaaaaaaa_123.jpg&s=old']) {
  throw new RuntimeException('No se recuperaron las referencias de fotos serializadas.');
}
if (CorrectiveReviewPhotos::url($db, '42') !== 'https://example.test/wp-content/uploads/dano.jpg') {
  throw new RuntimeException('No se resolvió el adjunto de WordPress.');
}
if (CorrectiveReviewPhotos::url($db, '999') !== '') {
  throw new RuntimeException('Se aceptó un adjunto inexistente.');
}
$name = 'aaaaaaaaaaaaaaaaaaaaaaaa_123.jpg';
file_put_contents($directory . '/' . $name, 'fixture');
$url = CorrectiveReviewPhotos::url($db, $refs[1]);
if (!str_starts_with($url, SCM_BASE_URL . '/file.php?n=' . $name . '&s=') || str_ends_with($url, 's=old')) {
  throw new RuntimeException('No se renovó la firma de la foto guardada.');
}
unlink($directory . '/' . $name);
rmdir($directory);
echo "Corrective review photo checks passed.\n";
