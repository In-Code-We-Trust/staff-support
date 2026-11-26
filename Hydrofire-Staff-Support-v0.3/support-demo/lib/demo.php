<?php
require_once __DIR__.'/staff_support.php';
$config=require dirname(__DIR__).'/config.example.php';
// The demo always captures mail locally, even if someone edits its settings.
$config['staff_support_mail_mode']='capture';
date_default_timezone_set('Africa/Johannesburg');
function getDB($config): PDO {
    if(!is_file($config['db_path'])) ssFail('Run bin/setup.php in the project folder first.',503);
    $db=new PDO('sqlite:'.$config['db_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');return $db;
}
function manualsDir(): string {return dirname(__DIR__).'/resources/';}
function manualReadPermission(string $path): ?string {return 'leads';}
