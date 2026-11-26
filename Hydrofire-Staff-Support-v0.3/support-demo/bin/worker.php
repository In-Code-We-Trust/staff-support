<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib/demo.php';
require_once __DIR__.'/../lib/staff_support_jobs.php';
try{echo ssJson(ssRunJobs(getDB($config),$config))."\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
