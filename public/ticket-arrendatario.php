<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';

(new \SCM\Http\Controller\PublicTicketPageController($scmConfig))->renderActor('arrendatario');
