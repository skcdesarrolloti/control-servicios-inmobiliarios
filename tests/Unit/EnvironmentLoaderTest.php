<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Core\EnvironmentLoader;

final class EnvironmentLoaderTest extends TestCase
{
  private string $path;
  private string $key;

  protected function setUp(): void
  {
    $this->key = 'SCM_TEST_' . strtoupper(bin2hex(random_bytes(6)));
    $this->path = sys_get_temp_dir() . '/scm_env_' . bin2hex(random_bytes(8));
  }

  protected function tearDown(): void
  {
    putenv($this->key);
    unset($_ENV[$this->key], $_SERVER[$this->key]);
    if (is_file($this->path)) {
      unlink($this->path);
    }
  }

  public function testLoadsQuotedValuesWithoutOverwritingRuntimeVariables(): void
  {
    file_put_contents($this->path, $this->key . "=\"from file\"\n");
    EnvironmentLoader::load($this->path);
    self::assertSame('from file', getenv($this->key));

    file_put_contents($this->path, $this->key . "=changed\n");
    EnvironmentLoader::load($this->path);
    self::assertSame('from file', getenv($this->key));
  }
}
