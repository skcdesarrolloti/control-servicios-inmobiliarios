<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

header('Location: ' . rtrim((string) SCM_BASE_URL, '/') . '/ticket', true, 302);
exit;
