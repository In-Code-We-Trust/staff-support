<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/lib/demo.php';
$dir=dirname($config['db_path']);if(!is_dir($dir))mkdir($dir,0700,true);
$db=new PDO('sqlite:'.$config['db_path'],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=5000');
$db->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,username TEXT UNIQUE,email TEXT,role TEXT,branch TEXT,permissions TEXT,cin7_rep TEXT,password TEXT,active INTEGER DEFAULT 1)');
$db->exec('CREATE TABLE IF NOT EXISTS settings(`key` TEXT PRIMARY KEY,value TEXT)');
$db->exec('CREATE TABLE IF NOT EXISTS demo_attempts(ip TEXT,username TEXT,created_at TEXT)');
ssSchema($db);
$people=[['Samantha','sam','admin','Cape Town','approve'],['Stacey','stacey','staff','Cape Town','approve'],['Sales Demo','sales','staff','Cape Town','view'],['Other Sales Demo','sales2','staff','Johannesburg','view']];
$reset=in_array('--reset-passwords',$argv,true);
$db->beginTransaction();
try {
    $credentials=[];
    foreach($people as [$name,$username,$role,$branch,$access]) {
        $existing=ssOne($db,'SELECT id FROM users WHERE username=?',[$username]);
        if($existing&&!$reset)continue;
        $pw=bin2hex(random_bytes(8));$hash=password_hash($pw,PASSWORD_BCRYPT,['cost'=>12]);
        $perms=array_fill_keys(['leads','quotes','backorders','dispatch','manuals','procedures','library','price-lists','invoices','b2b_portal','credit-notes','cashsales'],'view');$perms['staff_support']=$access;
        if($existing)ssQuery($db,'UPDATE users SET password=? WHERE id=?',[$hash,$existing['id']]);
        else ssQuery($db,'INSERT INTO users(name,username,email,role,branch,permissions,cin7_rep,password) VALUES(?,?,?,?,?,?,?,?)',[$name,$username,$username.'@example.invalid',$role,$branch,ssJson($perms),$name,$hash]);
        $credentials[]="$name | username: $username | password: $pw";
    }
    $ids=[];foreach(ssQuery($db,'SELECT id,username FROM users')->fetchAll(PDO::FETCH_ASSOC) as $u)$ids[$u['username']]=$u['id'];
    if(!ssOne($db,"SELECT value FROM settings WHERE `key`='staff_support_settings'")) {
        $settings=ssSettings($db);$settings['default_owner']=$ids['stacey'];$settings['backup_id']=$ids['sam'];$settings['routing']['website']=$ids['sam'];
        $settings['agent_branches']=[(string)$ids['sam']=>['*'],(string)$ids['stacey']=>['*']];
        // Open all week for presentations. Production hours are configured separately.
        $settings['hours']['*']=['start'=>'00:00','end'=>'23:59','days'=>[1,2,3,4,5,6,7]];
        ssQuery($db,'INSERT INTO settings VALUES(?,?)',['staff_support_settings',ssJson($settings)]);
    }
    $db->exec('CREATE TABLE IF NOT EXISTS leads(id INTEGER PRIMARY KEY,customer_name TEXT,branch TEXT,owner_name TEXT,status TEXT,created_by_id INTEGER,owner_id INTEGER)');
    $db->exec('CREATE TABLE IF NOT EXISTS quotes(id TEXT PRIMARY KEY,customer TEXT,branch TEXT,rep TEXT,status TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS backorders(id INTEGER PRIMARY KEY,so_number TEXT,sale_id TEXT,customer TEXT,branch TEXT,sku TEXT,qty_backordered INTEGER,available INTEGER,stock_checked_at TEXT,refreshed_at TEXT,rep_name TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS dispatch_deliveries(id INTEGER PRIMARY KEY,doc_number TEXT,customer_name TEXT,location_name TEXT,status TEXT,delivery_date TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS procedures(id INTEGER PRIMARY KEY,title TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS library_docs(id INTEGER PRIMARY KEY,title TEXT)');
    if(!ssOne($db,'SELECT id FROM leads LIMIT 1')) {
        ssQuery($db,'INSERT INTO leads VALUES(?,?,?,?,?,?,?)',[1,'Demo Patio Customer','Cape Town','Sales Demo','quote_pending',$ids['sales'],$ids['sales']]);
        ssQuery($db,'INSERT INTO leads VALUES(?,?,?,?,?,?,?)',[2,'Demo JHB Customer','Johannesburg','Other Sales Demo','call_back',$ids['sales2'],$ids['sales2']]);
        ssQuery($db,'INSERT INTO quotes VALUES(?,?,?,?,?)',['SO-DEMO-101','Demo Patio Customer','Cape Town','Sales Demo','pending']);
        ssQuery($db,'INSERT INTO backorders VALUES(?,?,?,?,?,?,?,?,?,?,?)',[1,'SO-DEMO-101','demo-sale-1','Demo Patio Customer','Cape Town','DEMO-APOLLO',2,0,ssNow(),ssNow(),'Sales Demo']);
        ssQuery($db,'INSERT INTO dispatch_deliveries VALUES(?,?,?,?,?,?)',[1,'SO-DEMO-101','Demo Patio Customer','Cape Town','scheduled',substr(ssResumeDue(ssSettings($db),'Cape Town'),0,10)]);
        ssQuery($db,'INSERT INTO procedures VALUES(?,?)',[1,'Demo escalation procedure']);
        ssQuery($db,'INSERT INTO library_docs VALUES(?,?)',[1,'Demo product document']);
        $due=substr(ssResumeDue(ssSettings($db),'Cape Town'),0,16);
        $sam=ssUser($db,(int)$ids['sam']);$s=ssSettings($db);
    }
    foreach(['answers','incidents'] as $feature)if(!ssOne($db,'SELECT value FROM settings WHERE `key`=?',['staff_support_'.$feature.'_enabled']))ssQuery($db,'INSERT INTO settings VALUES(?,?)',['staff_support_'.$feature.'_enabled','false']);
    $db->exec('CREATE TABLE IF NOT EXISTS demo_customers(id INTEGER PRIMARY KEY,name TEXT,email TEXT,phone TEXT,branch TEXT,rep TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS demo_orders(id TEXT PRIMARY KEY,customer_id INTEGER,status TEXT,created_at TEXT,invoice_number TEXT,invoice_status TEXT,delivery_status TEXT)');
    if(!ssOne($db,'SELECT id FROM demo_customers LIMIT 1')) {
        ssQuery($db,'INSERT INTO demo_customers VALUES(?,?,?,?,?,?)',[1,'Demo Patio Customer','patio@example.invalid','0821234567','Cape Town','Sales Demo']);
        ssQuery($db,'INSERT INTO demo_customers VALUES(?,?,?,?,?,?)',[2,'Demo Mountain Lodge','lodge@example.invalid','0837654321','Cape Town','Sales Demo']);
        ssQuery($db,'INSERT INTO demo_customers VALUES(?,?,?,?,?,?)',[3,'Demo JHB Customer','jhb@example.invalid','0841234567','Johannesburg','Other Sales Demo']);
        foreach([['SO-10482',1,'Awaiting delivery','2026-10-27','INV-20482','Paid','Scheduled'],['SO-10483',1,'Backordered','2026-10-28','INV-20483','Unpaid','Awaiting stock'],['SO-10484',2,'Quote sent','2026-10-29','','Not invoiced','Not scheduled'],['SO-10501',3,'Ready for dispatch','2026-10-29','INV-20501','Paid','Ready']] as $order) {
            ssQuery($db,'INSERT INTO demo_orders VALUES(?,?,?,?,?,?,?)',$order);
            $c=ssOne($db,'SELECT * FROM demo_customers WHERE id=?',[$order[1]]);
            ssQuery($db,'INSERT INTO quotes VALUES(?,?,?,?,?)',[$order[0],$c['name'],$c['branch'],$c['rep'],$order[2]]);
        }
    }
    if($reset)$db->exec('DELETE FROM demo_attempts');
    $db->commit();
    echo implode("\n",$credentials)."\n";
    echo $credentials?"Save these passwords privately. Emails are captured locally.\n":"Existing accounts kept. Use --reset-passwords if needed.\n";
    echo 'Database: '.$config['db_path']."\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,'Setup failed: '.$e->getMessage()."\n");exit(1);}
