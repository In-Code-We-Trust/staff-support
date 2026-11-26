<?php
require_once dirname(__DIR__).'/lib/demo.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
session_name('HF_SUPPORT_DEMO');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
try {
    $action=$_GET['action']??'me';$db=getDB($config);
    if(empty($_SESSION['demo_csrf']))$_SESSION['demo_csrf']=bin2hex(random_bytes(24));
    if(isset($_SESSION['last_seen'])&&time()-$_SESSION['last_seen']>14400)unset($_SESSION['user']);
    if($action==='me') {echo ssJson(['success'=>true,'user'=>isset($_SESSION['user'])?ssUser($db,(int)$_SESSION['user']['id']):null,'csrf'=>$_SESSION['demo_csrf']]);exit;}
    if(in_array($action,['login','logout'],true)) {
        if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals($_SESSION['demo_csrf'],$_SERVER['HTTP_X_DEMO_CSRF']??''))ssFail('Refresh the page and try again.',403);
        $b=json_decode(file_get_contents('php://input'),true)?:[];
        if($action==='logout'){$_SESSION=[];session_destroy();echo ssJson(['success'=>true]);exit;}
        $name=strtolower(ssText($b,'username',80,true));$ip=$_SERVER['REMOTE_ADDR']??'local';$since=date('Y-m-d H:i:s',time()-900);
        if((int)ssQuery($db,'SELECT COUNT(*) FROM demo_attempts WHERE (ip=? OR username=?) AND created_at>?',[$ip,$name,$since])->fetchColumn()>=10)ssFail('Too many attempts. Try again in 15 minutes.',429);
        $u=ssOne($db,'SELECT * FROM users WHERE username=? AND active=1',[$name]);
        if(!$u||!password_verify(ssText($b,'password',200,true),$u['password'])){ssQuery($db,'INSERT INTO demo_attempts VALUES(?,?,?)',[$ip,$name,ssNow()]);ssFail('Username or password is incorrect.',401);}
        session_regenerate_id(true);$_SESSION['user']=['id'=>$u['id']];$_SESSION['last_seen']=time();$_SESSION['staff_support_csrf']=bin2hex(random_bytes(24));
        echo ssJson(['success'=>true,'user'=>ssUser($db,(int)$u['id'])]);exit;
    }
    if(empty($_SESSION['user']))ssFail('Please sign in.',401);
    $_SESSION['last_seen']=time();$u=ssUser($db,(int)$_SESSION['user']['id']);
    if(in_array($action,['demo_search','demo_customer'],true)) {
        require_once dirname(__DIR__).'/lib/demo_search.php';
        if(!ssCan($u,'staff_support')||!ssCan($u,'quotes'))ssFail('Source unavailable.',403);
        echo ssJson(['success'=>true]+($action==='demo_search'?demoSearch($db,$u,$_GET):demoCustomer($db,$u,(int)($_GET['id']??0))));exit;
    }
    if($action==='demo_records') {
        $records=[];foreach(['leads'=>[1,2],'quotes'=>['SO-DEMO-101'],'backorders'=>[1],'dispatch'=>[1]] as $m=>$ids)foreach($ids as $id){try{$records[]=ssSource($db,$u,['module'=>$m,'record_id'=>$id]);}catch(HFSupportError $e){}}
        echo ssJson(['success'=>true,'records'=>$records]);exit;
    }
    if($action==='demo_guide') {if(!ssCan($u,'manuals')||!ssCan($u,'leads'))ssFail('Access denied.',403);echo ssJson(['success'=>true,'text'=>file_get_contents(manualsDir().'Demo-Lead-Guide.md')]);exit;}
    if($action==='demo_mail') {
        if(!ssTeam($u))ssFail('Support access required.',403);
        require_once dirname(__DIR__).'/lib/staff_support_jobs.php';
        // Presentation-only delivery: always captured locally, including queued events from earlier requests.
        ssRunJobs($db,$config);
        $mail=[];foreach(ssQuery($db,"SELECT * FROM support_outbox WHERE status='captured' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) as $job) {
            try{
                if($job['entity_type']==='ticket'){$t=ssTicket($db,$u,ssSettings($db),(int)$job['entity_id']);if(!ssManage($u,$t,ssSettings($db)))continue;}
                else{$i=ssOne($db,'SELECT * FROM support_issues WHERE id=?',[$job['entity_id']]);if(!$i||!ssIssueVisible($u,$i,ssSettings($db)))continue;}
                $path=ssFilesDir($config).'/mail/'.$job['id'].'.json';if(is_file($path))$mail[]=json_decode(file_get_contents($path),true);
            }catch(HFSupportError $e){}
        }
        echo ssJson(['success'=>true,'mail'=>$mail]);exit;
    }
    if(strpos($action,'support_')===0){
        ssRoute($config,$u,$action);
        if($_SERVER['REQUEST_METHOD']==='POST' && http_response_code()<400) {
            try {require_once dirname(__DIR__).'/lib/staff_support_jobs.php';ssRunJobs($db,$config);}
            catch(Throwable $e){error_log('Demo capture deferred: '.$e->getMessage());}
        }
        exit;
    }
    ssFail('Unknown action.',404);
}catch(HFSupportError $e){http_response_code($e->getCode()?:422);echo ssJson(['success'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){error_log($e->getMessage());http_response_code(500);echo ssJson(['success'=>false,'error'=>'Unable to load the demo. Check the terminal.']);}
