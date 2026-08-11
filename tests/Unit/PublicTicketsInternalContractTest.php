<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SCM\Modules\PublicTickets\PublicTicketsService;

final class PublicTicketsInternalContractTest extends TestCase
{
  public function testEveryInternalServiceCallResolvesToAMethod(): void
  {
    $reflection = new ReflectionClass(PublicTicketsService::class);
    $defined = [];
    foreach ($reflection->getMethods() as $method) {
      $defined[strtolower($method->getName())] = true;
    }

    $moduleRoot = dirname(__DIR__, 2) . '/src/Modules/PublicTickets';
    $files = [$moduleRoot . '/PublicTicketsService.php'];
    foreach (glob($moduleRoot . '/Concerns/*.php') ?: [] as $concern) {
      $files[] = $concern;
    }

    $missing = [];
    foreach ($files as $file) {
      $source = (string) file_get_contents($file);
      preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);
      foreach ($matches[1] ?? [] as $name) {
        if (!isset($defined[strtolower((string) $name)])) {
          $missing[(string) $name] = true;
        }
      }
    }

    self::assertSame([], array_keys($missing));
  }

  public function testAccessScopeNormalizationFailsClosed(): void
  {
    $reflection = new ReflectionClass(PublicTicketsService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('normalizePublicPqrAccessScope');

    self::assertSame('all', $method->invoke($service, ' ALL '));
    self::assertSame('assigned', $method->invoke($service, 'assigned'));
    self::assertSame('assigned', $method->invoke($service, 'unexpected'));
  }
}
