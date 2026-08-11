<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Support\FileRateLimiter;

final class FileRateLimiterTest extends TestCase
{
  private string $directory;

  protected function setUp(): void
  {
    $this->directory = sys_get_temp_dir() . '/scm_rate_' . bin2hex(random_bytes(8));
  }

  protected function tearDown(): void
  {
    if (!is_dir($this->directory)) {
      return;
    }
    foreach (glob($this->directory . '/*') ?: [] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    rmdir($this->directory);
  }

  public function testBlocksAttemptsAfterTheConfiguredLimit(): void
  {
    $limiter = new FileRateLimiter($this->directory);

    self::assertTrue($limiter->consume('same-client', 2, 60));
    self::assertTrue($limiter->consume('same-client', 2, 60));
    self::assertFalse($limiter->consume('same-client', 2, 60));
    self::assertTrue($limiter->consume('different-client', 2, 60));
  }
}
