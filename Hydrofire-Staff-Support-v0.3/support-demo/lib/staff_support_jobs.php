<?php
/** CLI worker helpers. Does not load api.php or run customer integrations. */
require_once __DIR__.'/staff_support.php';

function ssReminderValid(array $job, array $record): bool {
    if (strpos($job['event_key'],'reminder:')!==0 && strpos($job['event_key'],'escalation:')!==0) return true;
    $parts=explode(':',$job['event_key']);
    return (int)end($parts)===(int)$record['commitment'] && !empty($record['next_update_at'])
        && $record['next_update_at']<ssNow() && !ssTerminal($record['status']) && $record['status']!=='waiting_requester';
}
function ssReminders(PDO $db, ?string $at=null): void {
    $s=ssSettings($db);$now=$at??ssNow();$date=new DateTimeImmutable($now,new DateTimeZone('Africa/Johannesburg'));
    foreach(['ticket'=>'support_tickets','issue'=>'support_issues'] as $type=>$table) {
        if($type==='issue'&&!ssFeature($db,'incidents'))continue;
        $rows=ssQuery($db,"SELECT * FROM $table WHERE next_update_at IS NOT NULL AND next_update_at < ?",[$now])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r) {
            if(ssTerminal($r['status']) || $r['status']==='waiting_requester' || !ssWorking($s,$r['branch'],$date)) continue;
            $key=$type.':'.$r['id'].':'.$r['commitment'];
            $owner=ssAgent($db,(int)$r['owner_id'],$r['branch'],$s);
            $backup=ssAgent($db,(int)$s['backup_id'],$r['branch'],$s);
            if($owner||$backup) ssQueue($db,'reminder:'.$key,(int)($owner['id']??$backup['id']),$type,(int)$r['id'],'Support update overdue #'.$r['id']);
            $late=$date->getTimestamp()-(new DateTimeImmutable($r['next_update_at'],new DateTimeZone('Africa/Johannesburg')))->getTimestamp();
            if($backup && $late>=(int)$s['escalate_hours']*3600) ssQueue($db,'escalation:'.$key,(int)$backup['id'],$type,(int)$r['id'],'Support escalation #'.$r['id']);
        }
    }
}
function ssMailData(PDO $db,array $config,array $job): ?array {
    $s=ssSettings($db);
    try{$u=ssUser($db,(int)$job['recipient_id']);}catch(HFSupportError $e){return null;}
    $type=$job['entity_type'];$id=(int)$job['entity_id'];
    if($type==='ticket') {
        $r=ssOne($db,'SELECT * FROM support_tickets WHERE id=?',[$id]);
        if(!$r || !ssCanTicket($u,$r,$s) || !ssReminderValid($job,$r)) return null;
        $message='There is an update on your staff support request.';
        if(preg_match('/^ticket:(\d+)$/',$job['event_key'],$m)) {
            $event=ssOne($db,"SELECT body FROM support_events WHERE id=? AND ticket_id=? AND visibility='public'",[(int)$m[1],$id]);
            if(!$event) return null;
            $message=$event['body'];
        }
        $hash='#support-'.$id;
    } elseif($type==='issue') {
        if(!ssFeature($db,'incidents'))return null;
        $r=ssOne($db,'SELECT * FROM support_issues WHERE id=?',[$id]);
        if(!$r || !ssIssueVisible($u,$r,$s) || !ssReminderValid($job,$r)) return null;
        if(strpos($job['event_key'],'issue:')===0 && !ssOne($db,'SELECT user_id FROM support_followers WHERE issue_id=? AND user_id=?',[$id,$u['id']])
            && !ssOne($db,'SELECT id FROM support_tickets WHERE issue_id=? AND requester_id=?',[$id,$u['id']])) return null;
        $message=$r['explanation'];$hash='#support-issue-'.$id;
    } else return null;
    $base=$config['staff_support_base_url']??'';
    if(!preg_match('~^https?://~i',$base) || parse_url($base,PHP_URL_USER) || parse_url($base,PHP_URL_PASS)) throw new RuntimeException('Configure the authenticated intranet URL.');
    $url=explode('#',$base)[0].$hash;
    $text=$r['title']."\n\n".$message."\n\nStatus: ".str_replace('_',' ',$r['status'])."\nNext update: ".($r['next_update_at']?$r['next_update_at'].' SAST':'Not set')."\n\nOpen securely: ".$url."\nReply inside the intranet; email replies are not monitored by this feature.";
    return ['to'=>$u['email'],'name'=>$u['name'],'subject'=>$job['subject'],'text'=>$text,'url'=>$url];
}
function ssSendMail(array $config,array $mail,int $jobId): void {
    $mode=$config['staff_support_mail_mode']??'capture';
    if($mode==='capture') {
        $dir=ssFilesDir($config).'/mail';if(!is_dir($dir)) mkdir($dir,0700,true);
        if(file_put_contents($dir.'/'.$jobId.'.json',ssJson($mail),LOCK_EX)===false) throw new RuntimeException('Could not capture email.');
        chmod($dir.'/'.$jobId.'.json',0600);return;
    }
    // Real sending must be enabled explicitly in production. No live configuration in the demo.
    if($mode!=='brevo' || empty($config['brevo_api_key']) || !filter_var($mail['to'],FILTER_VALIDATE_EMAIL)
        || !filter_var($config['brevo_from_email']??'',FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email configuration or recipient is invalid.');
    $payload=['sender'=>['name'=>$config['brevo_from_name']??'Hydrofire Staff Support','email'=>$config['brevo_from_email']],
        'to'=>[['email'=>$mail['to'],'name'=>$mail['name']]],'subject'=>$mail['subject'],'textContent'=>$mail['text']];
    $ch=curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>ssJson($payload),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','api-key: '.$config['brevo_api_key']],CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true]);
    $result=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($result===false || $code<200 || $code>=300) throw new RuntimeException('Mail provider did not confirm delivery (HTTP '.$code.').');
}
function ssDrainOutbox(PDO $db,array $config): array {
    $summary=['sent'=>0,'captured'=>0,'failed'=>0,'suppressed'=>0,'deferred'=>0];$s=ssSettings($db);
    $jobs=ssQuery($db,"SELECT * FROM support_outbox WHERE status='pending' AND available_at<=? ORDER BY id LIMIT 100",[ssNow()])->fetchAll(PDO::FETCH_ASSOC);
    foreach($jobs as $job) {
        try {
            $mail=ssMailData($db,$config,$job);
            if(!$mail){ssQuery($db,"UPDATE support_outbox SET status='suppressed',last_error='Access or subscription changed; or reminder superseded.' WHERE id=?",[$job['id']]);$summary['suppressed']++;continue;}
            $table=$job['entity_type']==='ticket'?'support_tickets':'support_issues';
            $r=ssOne($db,"SELECT branch FROM $table WHERE id=?",[$job['entity_id']]);
            if(!ssWorking($s,$r['branch'],new DateTimeImmutable(ssNow(),new DateTimeZone('Africa/Johannesburg')))){$summary['deferred']++;continue;}
            ssSendMail($config,$mail,(int)$job['id']);
            $status=($config['staff_support_mail_mode']??'capture')==='capture'?'captured':'sent';
            ssQuery($db,'UPDATE support_outbox SET status=?,attempts=attempts+1,sent_at=?,last_error=? WHERE id=?',[$status,ssNow(),'',$job['id']]);$summary[$status]++;
        } catch(Throwable $e) {
            $attempts=(int)$job['attempts']+1;
            $retry=(new DateTimeImmutable(ssNow(),new DateTimeZone('Africa/Johannesburg')))->modify('+'.min(60,5*(2**min(6,$attempts-1))).' minutes')->format('Y-m-d H:i:s');
            ssQuery($db,'UPDATE support_outbox SET status=?,attempts=?,available_at=?,last_error=? WHERE id=?',[$attempts>=5?'failed':'pending',$attempts,$retry,substr($e->getMessage(),0,255),$job['id']]);$summary['failed']++;
        }
    }
    return $summary;
}
function ssRunJobs(PDO $db,array $config): array {
    if(empty($config['staff_support_enabled'])) return ['disabled'=>true];
    $lock=fopen(ssFilesDir($config).'/worker.lock','c');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) return ['busy'=>true];
    try{ssReminders($db);return ssDrainOutbox($db,$config);}finally{flock($lock,LOCK_UN);fclose($lock);}
}
