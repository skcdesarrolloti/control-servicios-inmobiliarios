<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use SCM\Core\Database;
use SCM\Core\Settings;

final class SettingsTest extends TestCase
{
  private Database $database;

  protected function setUp(): void
  {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
      'CREATE TABLE wp_jet_cct_confi_sistema ('
      . '_ID INTEGER PRIMARY KEY AUTOINCREMENT,'
      . 'cct_status TEXT NULL,'
      . 'cct_author_id INTEGER NULL,'
      . 'cct_created TEXT NULL,'
      . 'cct_modified TEXT NULL,'
      . 'funcion VARCHAR(100) NOT NULL UNIQUE,'
      . 'valor TEXT NULL,'
      . 'departamento TEXT NULL)'
    );
    $this->database = new Database($pdo, 'wp_');
  }

  public function testPersistsValuesInExistingConfigurationTable(): void
  {
    $settings = new Settings($this->database);
    $settings->set('sla_days', 12, 7);
    $settings->set('label', 'Prueba', 7);

    $reloaded = new Settings($this->database);
    self::assertSame(12, $reloaded->get('sla_days'));
    self::assertSame('Prueba', $reloaded->get('label'));
    self::assertSame('fallback', $reloaded->get('missing', 'fallback'));

    $row = $this->database->getRow(
      'SELECT funcion, valor, cct_author_id FROM wp_jet_cct_confi_sistema WHERE funcion = ?',
      [Settings::FUNCTION_KEY]
    );
    self::assertSame(Settings::FUNCTION_KEY, $row['funcion'] ?? null);
    self::assertSame(7, (int) ($row['cct_author_id'] ?? 0));
    $document = json_decode((string) ($row['valor'] ?? ''), true);
    self::assertSame(1, $document['version'] ?? null);
    self::assertSame('Prueba', $document['settings']['label'] ?? null);
  }

  public function testReadOnlyConsumerCannotWrite(): void
  {
    $settings = new Settings($this->database, true);

    $this->expectException(\LogicException::class);
    $settings->set('forbidden', true);
  }
}
