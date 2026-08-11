<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Modules\PublicTickets\SignedAccessTokenService;

final class SignedAccessTokenServiceTest extends TestCase
{
  private const SECRET = 'test-secret-with-more-than-thirty-two-characters';

  public function testBuildsAndResolvesACompactToken(): void
  {
    $service = new SignedAccessTokenService(self::SECRET, 'https://example.test/app/');
    $url = $service->buildUrl('EMP-42', 3600, 'all');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $resolved = $service->resolve((string) ($query['k'] ?? ''));

    self::assertNotNull($resolved);
    self::assertSame('EMP-42', $resolved['id_empleado']);
    self::assertSame('all', $resolved['scope']);
    self::assertTrue($service->validate(
      $resolved['id_empleado'],
      $resolved['exp'],
      $resolved['sig'],
      $resolved['scope']
    ));
  }

  public function testRejectsATamperedToken(): void
  {
    $service = new SignedAccessTokenService(self::SECRET, 'https://example.test');
    $url = $service->buildUrl('100', 3600, 'assigned');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = (string) ($query['k'] ?? '');

    self::assertNull($service->resolve($token . 'x'));
  }

  public function testRequiresAStrongSecret(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    new SignedAccessTokenService('short', 'https://example.test');
  }
}
