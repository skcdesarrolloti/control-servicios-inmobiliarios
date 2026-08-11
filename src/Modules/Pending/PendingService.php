<?php

namespace SCM\Modules\Pending;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

final class PendingService
{
  use \SCM\Modules\Pending\Concerns\PendingQueriesConcern;
  use \SCM\Modules\Pending\Concerns\AdministrativeTicketCreationConcern;
  use \SCM\Modules\Pending\Concerns\PendingNotificationsAndDatesConcern;

  private PendingRepository $repo;

  public function __construct(PendingRepository $repo)
  {
    $this->repo = $repo;
  }

}
