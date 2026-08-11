<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Core\Settings;

final class SettingsTest extends TestCase
{
  private string $directory;

  protected function setUp(): void
  {
    $this->directory = sys_get_temp_dir() . '/scm_settings_' . bin2hex(random_bytes(8));
  }

  protected function tearDown(): void
  {
    $file = $this->directory . '/settings.json';
    if (is_file($file)) {
      unlink($file);
    }
    if (is_dir($this->directory)) {
      rmdir($this->directory);
    }
  }

  public function testPersistsValuesAsJson(): void
  {
    $settings = new Settings($this->directory);
    $settings->set('sla_days', 12);
    $settings->set('label', 'Prueba');

    $reloaded = new Settings($this->directory);
    self::assertSame(12, $reloaded->get('sla_days'));
    self::assertSame('Prueba', $reloaded->get('label'));
    self::assertSame('fallback', $reloaded->get('missing', 'fallback'));
  }
}
