<?php
declare(strict_types=1);

// Exercise the actual allowlisted login redirect without bootstrapping a database or logging in.
$source = file_get_contents(dirname(__DIR__) . '/public/login.php');
$start = strpos($source, '$safeNext = static function');
$end = strpos($source, '$next = $safeNext', $start);
if ($start === false || $end === false) throw new RuntimeException('Login redirect helper not found.');
eval(substr($source, $start, $end - $start));
foreach ([
  'index.php?scm_case=10999' => 'index.php?scm_case=10999',
  'index.php?scm_case=10999&next=https://evil.invalid' => 'index.php?scm_case=10999',
  'https://evil.invalid/index.php?scm_case=10999' => '',
  '//evil.invalid/index.php?scm_case=10999' => '',
  'index.php?scm_case=-1' => '',
  'index.php?scm_case=1<script>' => '',
  'crear-acta.php?ticket_pk=123' => 'crear-acta.php?ticket_pk=123',
] as $input => $expected) {
  if ($safeNext($input) !== $expected) throw new RuntimeException('Unsafe or lost redirect: ' . $input);
}
echo "PASS: case link survives login and rejects external or malformed destinations.\n";
