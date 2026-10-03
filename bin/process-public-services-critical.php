<?php
declare(strict_types=1);
// Domain integration processor. It never delivers email or WhatsApp directly.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap/app.php';
$fp=fopen(sys_get_temp_dir().'/scm-services-critical.lock','c');
if(!$fp||!flock($fp,LOCK_EX|LOCK_NB)){echo "Seguimiento crítico ya en ejecución.\n";exit;}
try{
  $critical=new \SCM\Modules\Pending\PublicServicesCritical(\SCM\Core\App::db());
  if(!$critical->available())throw new RuntimeException('Ejecuta bin/migrate-public-services.php.');
  $stats=$critical->run(max(1,min(30,(int)($argv[1]??10))));
  echo json_encode($stats,JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
finally{flock($fp,LOCK_UN);fclose($fp);}
