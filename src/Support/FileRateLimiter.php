<?php

declare(strict_types=1);

namespace SCM\Support;

final class FileRateLimiter
{
  private string $directory;

  public function __construct(string $directory)
  {
    $this->directory = rtrim($directory, '/\\');
  }

  public function consume(string $key, int $maximumAttempts, int $windowSeconds): bool
  {
    if ($maximumAttempts < 1 || $windowSeconds < 1) {
      return false;
    }
    if (!is_dir($this->directory)
      && !mkdir($this->directory, 0750, true)
      && !is_dir($this->directory)) {
      return false;
    }

    $path = $this->directory . '/' . hash('sha256', $key) . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
      return false;
    }

    try {
      if (!flock($handle, LOCK_EX)) {
        return false;
      }

      $raw = stream_get_contents($handle);
      $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
      $attempts = is_array($decoded) ? $decoded : [];
      $now = time();
      $cutoff = $now - $windowSeconds;
      $attempts = array_values(array_filter(
        $attempts,
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
      ));

      if (count($attempts) >= $maximumAttempts) {
        return false;
      }

      $attempts[] = $now;
      rewind($handle);
      ftruncate($handle, 0);
      fwrite($handle, (string) json_encode($attempts));
      fflush($handle);
      return true;
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  public function retryAfter(string $key, int $maximumAttempts, int $windowSeconds): int
  {
    if ($maximumAttempts < 1 || $windowSeconds < 1) {
      return $windowSeconds;
    }

    $path = $this->directory . '/' . hash('sha256', $key) . '.json';
    if (!is_file($path)) {
      return 0;
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
      return $windowSeconds;
    }

    try {
      if (!flock($handle, LOCK_EX)) {
        return $windowSeconds;
      }

      $raw = stream_get_contents($handle);
      $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
      $attempts = is_array($decoded) ? $decoded : [];
      $now = time();
      $cutoff = $now - $windowSeconds;
      $attempts = array_values(array_filter(
        $attempts,
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
      ));

      rewind($handle);
      ftruncate($handle, 0);
      fwrite($handle, (string) json_encode($attempts));
      fflush($handle);

      if (count($attempts) < $maximumAttempts) {
        return 0;
      }

      return max(1, $windowSeconds - ($now - min($attempts)));
    } finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  public function clear(string $key): void
  {
    $path = $this->directory . '/' . hash('sha256', $key) . '.json';
    if (is_file($path)) {
      @unlink($path);
    }
  }
}
