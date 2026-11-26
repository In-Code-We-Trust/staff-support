<?php
/** Staff Support: isolated storage and workflow; never calls customer integrations. */
const HF_SUPPORT_STATUSES = ['new', 'acknowledged', 'in_progress', 'waiting_requester', 'waiting_team', 'resolved', 'closed', 'cancelled'];
const HF_SUPPORT_MODULES = ['general', 'website', 'leads', 'quotes', 'backorders', 'dispatch', 'invoices', 'price-lists', 'library', 'b2b_portal', 'credit-notes', 'cashsales'];
const HF_SUPPORT_PROBLEMS = ['status', 'blocked', 'guidance', 'availability', 'pricing', 'other'];

class HFSupportError extends RuntimeException {}
function ssFail(string $message, int $code = 422) { throw new HFSupportError($message, $code); }
function ssNow(): string { return (new DateTimeImmutable('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d H:i:s'); }
function ssQuery(PDO $db, string $sql, array $args = []): PDOStatement { $q = $db->prepare($sql); $q->execute($args); return $q; }
function ssOne(PDO $db, string $sql, array $args = []): ?array { return ssQuery($db, $sql, $args)->fetch(PDO::FETCH_ASSOC) ?: null; }
function ssText(array $b, string $key, int $max, bool $required = false): string {
    if (isset($b[$key]) && !is_scalar($b[$key])) ssFail('Invalid ' . $key);
    $v = trim((string)($b[$key] ?? ''));
    if (($required && $v === '') || strlen($v) > $max) ssFail('Please check ' . str_replace('_', ' ', $key) . ' (maximum ' . $max . ' characters).');
    return $v;
}
function ssEnum(array $b, string $key, array $values, string $default = ''): string {
    $v = ssText($b, $key, 80) ?: $default;
    if (!in_array($v, $values, true)) ssFail('Invalid ' . $key);
    return $v;
}
function ssJson($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }

/** Portable DDL: the same workflow runs against SQLite in the isolated pilot. */
function ssSchema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $tables = [
        'support_tickets' => "id $id, requester_id INTEGER NOT NULL, owner_id INTEGER NULL, branch VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL, module VARCHAR(40) NOT NULL, record_id VARCHAR(255) NOT NULL DEFAULT '',
            problem VARCHAR(40) NOT NULL, impact VARCHAR(40) NOT NULL, priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(30) NOT NULL DEFAULT 'new', context_json TEXT NOT NULL, created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL, acknowledged_at DATETIME NULL, acknowledged_by INTEGER NULL,
            next_update_at DATETIME NULL, commitment INTEGER NOT NULL DEFAULT 0, waiting_since DATETIME NULL,
            waiting_seconds INTEGER NOT NULL DEFAULT 0, resolved_at DATETIME NULL, issue_id INTEGER NULL,
            version INTEGER NOT NULL DEFAULT 1",
        'support_events' => "id $id, ticket_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, kind VARCHAR(40) NOT NULL,
            body TEXT NOT NULL, visibility VARCHAR(10) NOT NULL DEFAULT 'public', meta_json TEXT NOT NULL, created_at DATETIME NOT NULL",
        'support_reads' => 'ticket_id INTEGER NOT NULL, user_id INTEGER NOT NULL, event_id INTEGER NOT NULL, PRIMARY KEY(ticket_id,user_id)',
        'support_files' => "id $id, ticket_id INTEGER NOT NULL, event_id INTEGER NOT NULL, name VARCHAR(200) NOT NULL,
            stored_name VARCHAR(80) NOT NULL, mime VARCHAR(80) NOT NULL, size INTEGER NOT NULL, created_at DATETIME NOT NULL",
        'support_answers' => "id $id, title VARCHAR(180) NOT NULL, body TEXT NOT NULL, module VARCHAR(40) NOT NULL,
            problem VARCHAR(40) NOT NULL, keywords VARCHAR(255) NOT NULL DEFAULT '', source_module VARCHAR(40) NOT NULL,
            source_id VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', reviewed_at DATE NULL,
            review_due DATE NULL, author_id INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1",
        'support_feedback' => 'answer_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(answer_id,user_id)',
        'support_issues' => "id $id, title VARCHAR(180) NOT NULL, module VARCHAR(40) NOT NULL, branch VARCHAR(100) NOT NULL DEFAULT '',
            problem VARCHAR(40) NOT NULL, status VARCHAR(30) NOT NULL, explanation TEXT NOT NULL, workaround TEXT NOT NULL,
            owner_id INTEGER NOT NULL, next_update_at DATETIME NULL, commitment INTEGER NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL, version INTEGER NOT NULL DEFAULT 1",
        'support_issue_updates' => "id $id, issue_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL",
        'support_followers' => 'issue_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(issue_id,user_id)',
        'support_affected_records' => 'issue_id INTEGER NOT NULL, user_id INTEGER NOT NULL, module VARCHAR(40) NOT NULL, record_id VARCHAR(255) NOT NULL, context_json TEXT NOT NULL, PRIMARY KEY(issue_id,user_id,module,record_id)',
        'support_outbox' => "id $id, event_key VARCHAR(120) NOT NULL, recipient_id INTEGER NOT NULL, entity_type VARCHAR(20) NOT NULL,
            entity_id INTEGER NOT NULL, subject VARCHAR(180) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, created_at DATETIME NOT NULL,
            sent_at DATETIME NULL, last_error VARCHAR(255) NOT NULL DEFAULT '', UNIQUE(event_key,recipient_id)",
        'support_commands' => 'user_id INTEGER NOT NULL, request_key VARCHAR(80) NOT NULL, payload_hash VARCHAR(64) NOT NULL, result_json TEXT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(user_id,request_key)',
    ];
    foreach ($tables as $name => $fields) $db->exec("CREATE TABLE IF NOT EXISTS $name ($fields)$tail");
    foreach (['website_url'=>'TEXT NULL','live_at'=>'DATETIME NULL','commit_url'=>'TEXT NULL'] as $column=>$type) {
        try { $db->exec("ALTER TABLE support_tickets ADD COLUMN $column $type"); }
        catch (PDOException $e) {
            if ($mysql ? (int)($e->errorInfo[1]??0)!==1060 : strpos($e->getMessage(),'duplicate column name')===false) throw $e;
        }
    }
    foreach (['ss_queue' => ['support_tickets', 'status,next_update_at'], 'ss_requester' => ['support_tickets', 'requester_id'],
        'ss_events' => ['support_events', 'ticket_id,id'], 'ss_mail' => ['support_outbox', 'status,available_at']] as $name => $v) {
        if ($mysql) {
            $exists = ssOne($db, 'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$v[0], $name]);
            if (!$exists) $db->exec("CREATE INDEX $name ON {$v[0]} ({$v[1]})");
        } else $db->exec("CREATE INDEX IF NOT EXISTS $name ON {$v[0]} ({$v[1]})");
    }
}

function ssCan(array $u, string $module, string $level = 'view'): bool {
    $p = $u['permissions'] ?? [];
    if (is_string($p)) $p = json_decode($p, true) ?: [];
    if (($u['role'] ?? '') === 'owner') return true;
    if (($u['role'] ?? '') === 'admin') return ($p[$module] ?? '') !== 'owner';
    return in_array($p[$module] ?? '', $level === 'view' ? ['view','edit','approve','schedule','qc','return'] : ($level === 'approve' ? ['approve'] : ['edit','approve','schedule','qc']), true);
}
function ssUser(PDO $db, int $id): array {
    $u = ssOne($db, 'SELECT id,name,email,role,branch,permissions,cin7_rep FROM users WHERE id=? AND active=1', [$id]);
    if (!$u) ssFail('Account is inactive or no longer available.', 403);
    $u['permissions'] = json_decode($u['permissions'] ?? '{}', true) ?: [];
    return $u;
}
function ssSettings(PDO $db): array {
    $r = ssOne($db, "SELECT value FROM settings WHERE `key`='staff_support_settings'");
    return array_replace(['default_owner' => 0, 'backup_id' => 0, 'routing' => [], 'agent_branches' => [],
        'hours' => ['*' => ['start' => '08:00', 'end' => '17:00', 'days' => [1,2,3,4,5]]], 'escalate_hours' => 4], json_decode($r['value'] ?? '{}', true) ?: []);
}
function ssTeam(array $u): bool { return ssCan($u, 'staff_support', 'edit'); }
function ssFeature(PDO $db, string $name): bool {
    $r=ssOne($db,'SELECT value FROM settings WHERE `key`=?',['staff_support_'.$name.'_enabled']);
    return in_array(strtolower($r['value']??'false'),['1','true'],true);
}
function ssWebUrl(array $b, string $key): string {
    $url=ssText($b,$key,2000);
    if($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true) || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS))) ssFail('Enter a complete http:// or https:// URL, or leave it blank.');
    return $url;
}
function ssBranch(array $u, string $branch, array $settings): bool {
    if (in_array($u['role'], ['owner','admin'], true)) return true;
    $scope = $settings['agent_branches'][(string)$u['id']] ?? [$u['branch'] ?? ''];
    return in_array('*', $scope, true) || in_array($branch, $scope, true);
}
function ssCanTicket(array $u, array $t, array $s): bool {
    return ssCan($u, 'staff_support') && ((int)$t['requester_id'] === (int)$u['id'] || (ssTeam($u) && ssBranch($u, $t['branch'], $s)));
}
function ssManage(array $u, array $t, array $s): bool { return ssTeam($u) && ssBranch($u, $t['branch'], $s); }
function ssTicket(PDO $db, array $u, array $s, int $id, bool $lock = false): array {
    $t = ssOne($db, 'SELECT * FROM support_tickets WHERE id=?' . ($lock && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''), [$id]);
    if (!$t || !ssCanTicket($u, $t, $s)) ssFail('Request not found or unavailable.', 404);
    return $t;
}
function ssHours(array $s, string $branch): array { return $s['hours'][$branch] ?? $s['hours']['*']; }
function ssWorking(array $s, string $branch, DateTimeImmutable $d): bool {
    $h = ssHours($s, $branch);
    return in_array((int)$d->format('N'), $h['days'], true) && $d->format('H:i') >= $h['start'] && $d->format('H:i') < $h['end'];
}
function ssDue(string $value, array $s, string $branch): string {
    $value = str_replace('T', ' ', $value);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($value,0,16), new DateTimeZone('Africa/Johannesburg'));
    if (!$d || $d->format('Y-m-d H:i') !== $value || $d->format('Y-m-d H:i:s') <= ssNow()) ssFail('Choose a future next-update time in SAST.');
    if (!ssWorking($s, $branch, $d)) ssFail('Choose a next update within the branch working hours.');
    return $d->format('Y-m-d H:i:s');
}
function ssResumeDue(array $s, string $branch): string {
    $d = (new DateTimeImmutable(ssNow(), new DateTimeZone('Africa/Johannesburg')))->modify('+1 hour');
    for ($i=0; $i<15*24*4; $i++, $d=$d->modify('+15 minutes')) if (ssWorking($s, $branch, $d)) return $d->format('Y-m-d H:i:s');
    ssFail('No working hours configured.');
}
function ssTerminal(string $s): bool { return in_array($s, ['resolved','closed','cancelled'], true); }
function ssSource(PDO $db, array $u, array $q): array {
    $module = ssEnum($q, 'module', HF_SUPPORT_MODULES, 'general');
    $id = ssText($q, 'record_id', 255);
    if ($module === 'website') return ['module'=>'website','record_id'=>'','reference'=>'Website problem','fields'=>[],'captured_at'=>ssNow(),'verified'=>false];
    if ($module !== 'general' && !ssCan($u, $module)) ssFail('You no longer have access to this source.', 403);
    if ($id === '') return ['module' => $module, 'record_id' => '', 'reference' => 'General request', 'fields' => [], 'captured_at' => ssNow(), 'verified' => false];
    // Read existing caches only. Each adapter repeats the source screen's row-level access rule.
    $adapters = [
        'leads' => ['leads', ['customer_name','branch','owner_name','status']],
        'quotes' => ['quotes', ['customer','branch','rep','status']],
        'backorders' => ['backorders', ['so_number','sale_id','customer','branch','sku','qty_backordered','available','stock_checked_at','refreshed_at']],
        'dispatch' => ['dispatch_deliveries', ['doc_number','customer_name','location_name','status','delivery_date']],
    ];
    if (!isset($adapters[$module])) ssFail('This screen supports general requests. Please remove the record reference.');
    [$table, $fields] = $adapters[$module];
    $r = ssOne($db, "SELECT * FROM $table WHERE id=?", [$id]);
    if (!$r) ssFail('This source record is unavailable. You can start a general request.', 404);
    if (!in_array($u['role'], ['admin','owner'], true)) {
        if ($module === 'leads') {
            $allowed = $u['role'] === 'manager' ? ($r['branch'] ?? '') === ($u['branch'] ?? '') : in_array((int)$u['id'], [(int)$r['created_by_id'], (int)$r['owner_id']], true);
        } elseif (in_array($module, ['quotes','backorders'], true)) {
            $rep = $module === 'backorders' ? preg_replace('/\s*-\s*wholesale\s*$/i', '', trim($u['cin7_rep'] ?? '')) : ($u['cin7_rep'] ?? '');
            $allowed = $u['role'] === 'manager' ? ($r['branch'] ?? '') === ($u['branch'] ?? '') : ($rep !== '' && ($r[$module === 'backorders' ? 'rep_name' : 'rep'] ?? '') === $rep);
        } else $allowed = true; // Dispatch delivery_get authorises all dispatch viewers.
        if (!$allowed) ssFail('Source record not found or unavailable.', 404);
    }
    return ['module' => $module, 'record_id' => $id, 'reference' => (string)($r['so_number'] ?? $r['doc_number'] ?? ($module === 'leads' ? 'Lead #' . $id : $id)),
        'fields' => array_intersect_key($r, array_flip($fields)), 'captured_at' => ssNow(), 'verified' => true];
}
function ssIssueVisible(array $u, array $r, array $s): bool {
    return ssCan($u, 'staff_support') && ($r['module'] === 'general' || ssCan($u, $r['module']))
        && ($r['branch'] === '' || $r['branch'] === ($u['branch'] ?? '') || (ssTeam($u) && ssBranch($u, $r['branch'], $s)));
}
function ssSourceAnswer(PDO $db, array $u, array $a): bool {
    if (!ssCan($u, $a['source_module'])) return false;
    if ($a['source_module'] === 'manuals') {
        $name = $a['source_id'];
        if (basename($name) !== $name || substr($name,-3) !== '.md') return false;
        if (!function_exists('manualsDir') || !function_exists('manualReadPermission')) return false;
        $path = manualsDir() . $name;
        if (!is_file($path)) return false;
        $perm = manualReadPermission($path);
        if ($perm && !ssCan($u, $perm)) return false;
    }
    if (in_array($a['source_module'], ['procedures','library'], true)) {
        $table = $a['source_module'] === 'library' ? 'library_docs' : 'procedures';
        if (!ssOne($db, "SELECT id FROM $table WHERE id=?", [$a['source_id']])) return false;
    }
    return true;
}
function ssAnswerVisible(PDO $db, array $u, array $a): bool {
    return $a['status'] === 'published' && $a['review_due'] >= substr(ssNow(),0,10)
        && ($a['module'] === 'general' || ssCan($u, $a['module'])) && ssSourceAnswer($db, $u, $a);
}
function ssEvent(PDO $db, array $u, int $id, string $kind, string $body, string $visibility = 'public', array $meta = []): int {
    ssQuery($db, 'INSERT INTO support_events (ticket_id,actor_id,kind,body,visibility,meta_json,created_at) VALUES (?,?,?,?,?,?,?)', [$id,$u['id'],$kind,$body,$visibility,ssJson($meta),ssNow()]);
    return (int)$db->lastInsertId();
}
function ssQueue(PDO $db, string $key, int $recipient, string $type, int $id, string $subject): void {
    if (!$recipient || ssOne($db, 'SELECT id FROM support_outbox WHERE event_key=? AND recipient_id=?', [$key,$recipient])) return;
    ssQuery($db, 'INSERT INTO support_outbox (event_key,recipient_id,entity_type,entity_id,subject,available_at,created_at) VALUES (?,?,?,?,?,?,?)', [$key,$recipient,$type,$id,$subject,ssNow(),ssNow()]);
}
function ssNotify(PDO $db, array $u, array $t, array $s, int $event, bool $receipt = false): void {
    $recipients = array_unique([(int)$t['requester_id'], (int)($t['owner_id'] ?: $s['backup_id'])]);
    foreach ($recipients as $uid) if ($receipt || $uid !== (int)$u['id']) ssQueue($db,'ticket:' . $event,$uid,'ticket',(int)$t['id'],'Update on support request #' . $t['id']);
}
function ssSetTicket(PDO $db, array $t, array $changes): array {
    $changes['updated_at'] = ssNow(); $changes['version'] = (int)$t['version'] + 1;
    $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($changes)));
    $q = ssQuery($db, "UPDATE support_tickets SET $sets WHERE id=? AND version=?", array_merge(array_values($changes), [$t['id'],$t['version']]));
    if ($q->rowCount() !== 1) ssFail('This request changed. Refresh it before trying again.',409);
    return array_replace($t,$changes);
}
function ssAgent(PDO $db, int $id, string $branch, array $s): ?array {
    if (!$id) return null;
    try { $a = ssUser($db,$id); } catch (HFSupportError $e) { return null; }
    return ssTeam($a) && ssBranch($a,$branch,$s) ? $a : null;
}
function ssDetail(PDO $db, array $u, array $s, int $id): array {
    $t=ssTicket($db,$u,$s,$id); $manage=ssManage($u,$t,$s);
    $events=ssQuery($db,"SELECT e.*,u.name AS actor_name FROM support_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.ticket_id=?" . ($manage ? '' : " AND e.visibility='public'") . ' ORDER BY e.id',[$id])->fetchAll(PDO::FETCH_ASSOC);
    $files=ssQuery($db,"SELECT f.id,f.event_id,f.name,f.mime,f.size FROM support_files f JOIN support_events e ON e.id=f.event_id WHERE f.ticket_id=?" . ($manage ? '' : " AND e.visibility='public'"),[$id])->fetchAll(PDO::FETCH_ASSOC);
    $t['context']=json_decode($t['context_json'],true); unset($t['context_json']);
    $t['requester_name']=(ssOne($db,'SELECT name FROM users WHERE id=?',[$t['requester_id']]))['name'] ?? 'Former account';
    $t['owner_name']=(ssOne($db,'SELECT name FROM users WHERE id=?',[$t['owner_id']]))['name'] ?? 'Unassigned';
    $t['can_manage']=$manage;
    $t['source_available']=false;
    try { ssSource($db,$u,['module'=>$t['module'],'record_id'=>$t['record_id']]); $t['source_available']=$t['record_id'] !== ''; } catch (HFSupportError $e) {}
    $issue=null;
    if ($t['issue_id'] && ssFeature($db,'incidents')) { $issue=ssOne($db,'SELECT * FROM support_issues WHERE id=?',[$t['issue_id']]); if ($issue && !ssIssueVisible($u,$issue,$s)) $issue=null; }
    $mail=$manage ? ssQuery($db,"SELECT id,status,attempts,last_error FROM support_outbox WHERE entity_type='ticket' AND entity_id=? AND status IN ('pending','failed')",[$id])->fetchAll(PDO::FETCH_ASSOC) : [];
    return ['ticket'=>$t,'events'=>$events,'files'=>$files,'issue'=>$issue,'mail'=>$mail];
}

function ssRead(PDO $db, array $u, array $s, string $action, array $q): array {
    switch ($action) {
        case 'support_meta':
            $agents=[];
            if (ssTeam($u)) foreach (ssQuery($db,'SELECT id FROM users WHERE active=1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $a=ssUser($db,(int)$row['id']); if (ssTeam($a)) $agents[]=['id'=>$a['id'],'name'=>$a['name'],'branch'=>$a['branch']];
            }
            return ['features'=>['answers'=>ssFeature($db,'answers'),'incidents'=>ssFeature($db,'incidents')],'team'=>ssTeam($u),'publisher'=>ssCan($u,'staff_support','approve'),'admin'=>in_array($u['role'],['admin','owner'],true),'agents'=>$agents,'settings'=>ssTeam($u)?$s:['hours'=>$s['hours']], 'csrf'=>$_SESSION['staff_support_csrf'] ?? ''];
        case 'support_context':
            return ['context'=>ssSource($db,$u,$q)];
        case 'support_list':
            $where=[];$args=[];
            // Scope in SQL, so pagination and count never hide authorised results behind another branch.
            if (!ssTeam($u)) { $where[]='t.requester_id=?';$args[]=$u['id']; }
            elseif (!in_array($u['role'],['admin','owner'],true)) {
                $branches=$s['agent_branches'][(string)$u['id']] ?? [$u['branch'] ?? ''];
                if (!in_array('*',$branches,true)) { $where[]='(t.requester_id=? OR t.branch IN ('.implode(',',array_fill(0,count($branches),'?')).'))';$args=array_merge([$u['id']],$branches); }
            }
            $view=$q['view'] ?? 'open';
            if ($view==='mine') {$where[]='t.owner_id=?';$args[]=$u['id'];}
            elseif ($view==='my_requests') {$where[]='t.requester_id=?';$args[]=$u['id'];}
            elseif ($view==='unassigned') $where[]='t.owner_id IS NULL';
            elseif ($view==='overdue') {$where[]="t.next_update_at < ? AND t.status NOT IN ('waiting_requester','resolved','closed','cancelled')";$args[]=ssNow();}
            elseif (in_array($view,HF_SUPPORT_STATUSES,true)) {$where[]='t.status=?';$args[]=$view;}
            if (in_array($view,['open','mine','unassigned'],true)) $where[]="t.status NOT IN ('resolved','closed','cancelled')";
            foreach (['module','branch','priority','owner_id'] as $k) if (!empty($q[$k])) {$where[]="t.$k=?";$args[]=ssText($q,$k,100);}
            if (!empty($q['search'])) { $where[]='(t.title LIKE ? OR t.id=? OR t.context_json LIKE ?)';$v=ssText($q,'search',100);$args[]='%'.$v.'%';$args[]=ltrim($v,'#');$args[]='%'.$v.'%'; }
            $sql=' FROM support_tickets t WHERE '.($where?implode(' AND ',$where):'1=1');
            $total=(int)ssQuery($db,'SELECT COUNT(*)'.$sql,$args)->fetchColumn();
            $offset=max(0,(int)($q['offset']??0));
            $select="SELECT t.id,t.title,t.module,t.branch,t.status,t.priority,t.owner_id,t.requester_id,t.created_at,t.updated_at,t.next_update_at,t.acknowledged_at,t.version,
                u.name AS requester_name,o.name AS owner_name,
                (SELECT COUNT(*) FROM support_events e WHERE e.ticket_id=t.id AND e.visibility='public' AND e.actor_id<>? AND e.id>COALESCE((SELECT event_id FROM support_reads r WHERE r.ticket_id=t.id AND r.user_id=?),0)) AS unread";
            $rows=ssQuery($db,$select.str_replace(' FROM support_tickets t',' FROM support_tickets t LEFT JOIN users u ON u.id=t.requester_id LEFT JOIN users o ON o.id=t.owner_id',$sql).
                " ORDER BY CASE WHEN t.next_update_at < ? AND t.status NOT IN ('waiting_requester','resolved','closed','cancelled') THEN 0 ELSE 1 END, CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,t.created_at LIMIT 50 OFFSET $offset",array_merge([$u['id'],$u['id']],$args,[ssNow()]))->fetchAll(PDO::FETCH_ASSOC);
            return ['tickets'=>$rows,'total'=>$total,'offset'=>$offset];
        case 'support_detail': return ssDetail($db,$u,$s,(int)($q['id']??0));
        case 'support_suggestions':
            $ctx=ssSource($db,$u,$q);$problem=ssEnum($q,'problem',HF_SUPPORT_PROBLEMS,'guidance');
            $answers=[];
            foreach (ssQuery($db,"SELECT * FROM support_answers WHERE status='published' AND module IN (?, 'general') AND problem=? ORDER BY CASE WHEN module=? THEN 0 ELSE 1 END,id DESC",[$ctx['module'],$problem,$ctx['module']])->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if (ssAnswerVisible($db,$u,$a)) $answers[]=$a;
                if(count($answers)===3) break;
            }
            $issues=[];
            foreach (ssQuery($db,"SELECT * FROM support_issues WHERE status<>'resolved' AND module IN (?, 'general') AND problem IN (?, 'other') ORDER BY id DESC",[$ctx['module'],$problem])->fetchAll(PDO::FETCH_ASSOC) as $i) if(ssIssueVisible($u,$i,$s)) $issues[]=$i;
            $duplicates=ssQuery($db,"SELECT id,title,status FROM support_tickets WHERE requester_id=? AND module=? AND record_id=? AND problem=? AND status NOT IN ('resolved','closed','cancelled') ORDER BY id DESC LIMIT 5",[$u['id'],$ctx['module'],$ctx['record_id'],$problem])->fetchAll(PDO::FETCH_ASSOC);
            return ['answers'=>ssFeature($db,'answers')?$answers:[],'issues'=>ssFeature($db,'incidents')?$issues:[],'duplicates'=>$duplicates];
        case 'support_issues':
            if(!ssFeature($db,'incidents')) return ['issues'=>[]];
            $rows=[];
            foreach(ssQuery($db,'SELECT * FROM support_issues ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC) as $i) if(ssIssueVisible($u,$i,$s)) {
                $i['following']=(bool)ssOne($db,'SELECT user_id FROM support_followers WHERE issue_id=? AND user_id=?',[$i['id'],$u['id']]);
                $i['updates']=ssQuery($db,'SELECT body,created_at FROM support_issue_updates WHERE issue_id=? ORDER BY id DESC',[$i['id']])->fetchAll(PDO::FETCH_ASSOC);
                $rows[]=$i;
            }
            return ['issues'=>$rows];
        case 'support_answers':
            if(!ssFeature($db,'answers')) return ['answers'=>[]];
            if(!ssTeam($u)) ssFail('Support access required.',403);
            return ['answers'=>array_values(array_filter(ssQuery($db,'SELECT * FROM support_answers ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC),fn($a)=>ssSourceAnswer($db,$u,$a)))];
        case 'support_report': return ssReport($db,$u,$s);
        default: ssFail('Unknown support action.',404);
    }
}

function ssWrite(PDO $db, array $config, array $u, array $s, string $action, array $b): array {
    if ($action==='support_create') {
        $ctx=ssSource($db,$u,$b); $problem=ssEnum($b,'problem',HF_SUPPORT_PROBLEMS,'guidance');
        $website=$ctx['module']==='website';
        $body=ssText($b,'body',8000,$website||$problem==='other');
        $url=$website?ssWebUrl($b,'website_url'):'';
        if($website && $url!=='') $ctx['fields']['page_url']=$url;
        $impact=ssEnum($b,'impact',['normal','customer_waiting','sale_blocked','several_people'],'normal');
        $branch=$u['branch'] ?? '';
        $owner=(int)($s['routing'][$ctx['module']] ?? $s['default_owner']);
        if(!ssAgent($db,$owner,$branch,$s)) $owner=0;
        $labels=['status'=>'Status looks wrong','blocked'=>'Cannot complete action','guidance'=>'Need guidance','availability'=>'Availability question','pricing'=>'Price discrepancy','other'=>'Other issue'];
        preg_match('/^.{0,55}/us',$body,$summary);
        $title=$website?'Website · '.($summary[0]??'Problem reported'):$labels[$problem].' · '.$ctx['reference'];
        ssQuery($db,'INSERT INTO support_tickets (requester_id,owner_id,branch,title,module,record_id,problem,impact,context_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$u['id'],$owner?:null,$branch,substr($title,0,255),$ctx['module'],$ctx['record_id'],$problem,$impact,ssJson($ctx),ssNow(),ssNow()]);
        $id=(int)$db->lastInsertId();
        if($website)ssQuery($db,'UPDATE support_tickets SET website_url=? WHERE id=?',[$url,$id]);
        $t=ssTicket($db,$u,$s,$id);
        $e=ssEvent($db,$u,$id,'created',$body?:$labels[$problem]);ssNotify($db,$u,$t,$s,$e,true);
        return ['id'=>$id,'version'=>1];
    }
    if(in_array($action,['support_update','support_reply','support_read','support_upload'],true)) {
        $t=ssTicket($db,$u,$s,(int)($b['id']??0),true);$id=(int)$t['id'];$manage=ssManage($u,$t,$s);
        if($action==='support_read') {
            $e=(int)($b['event_id']??0);
            if($e && !ssOne($db,"SELECT id FROM support_events WHERE id=? AND ticket_id=?".($manage?'':" AND visibility='public'"),[$e,$id])) ssFail('Invalid read marker.');
            $old=ssOne($db,'SELECT event_id FROM support_reads WHERE ticket_id=? AND user_id=?',[$id,$u['id']]);
            if(!$old) ssQuery($db,'INSERT INTO support_reads(ticket_id,user_id,event_id) VALUES(?,?,?)',[$id,$u['id'],$e]);
            elseif($e>(int)$old['event_id']) ssQuery($db,'UPDATE support_reads SET event_id=? WHERE ticket_id=? AND user_id=?',[$e,$id,$u['id']]);
            return ['id'=>$id];
        }
        if((int)($b['version']??0)!==(int)$t['version']) ssFail('This request changed. Refresh it before trying again.',409);
        if($action==='support_upload') return ssUpload($db,$config,$u,$s,$t,$b);
        $body=ssText($b,'body',8000,true);$changes=[];$meta=[];$visibility='public';
        if($action==='support_reply') {
            if(ssTerminal($t['status'])) ssFail('Reopen this request before replying.');
            $visibility=ssEnum($b,'visibility',['public','internal'],'public');
            if($visibility==='internal' && !$manage) ssFail('Internal notes require support access.',403);
            $kind=$visibility==='internal'?'internal_note':'reply';
            if((int)$t['requester_id']===(int)$u['id'] && $t['status']==='waiting_requester' && $visibility==='public') {
                $changes=['status'=>'in_progress','waiting_since'=>null,'waiting_seconds'=>(int)$t['waiting_seconds']+max(0,strtotime(ssNow())-strtotime($t['waiting_since'])),
                    'next_update_at'=>ssResumeDue($s,$t['branch']),'commitment'=>(int)$t['commitment']+1];
                $meta['resumed_next_update']=$changes['next_update_at'];
            }
        } else {
            $kind=ssEnum($b,'operation',['acknowledge','status','assign','commitment','priority','link_issue','reopen','live']);
            if(!$manage && !in_array($kind,['reopen','status'],true)) ssFail('Support access required.',403);
            if($kind==='live') {
                if($t['module']!=='website'||ssTerminal($t['status'])||!$t['acknowledged_at'])ssFail('Acknowledge an open website request before marking it live.');
                $commit=ssWebUrl($b,'commit_url');
                if($commit!=='' && (strtolower(parse_url($commit,PHP_URL_HOST)??'')!=='github.com'||!preg_match('~^/[^/]+/[^/]+/commit/[a-f0-9]{7,64}/?$~i',parse_url($commit,PHP_URL_PATH)??'')))ssFail('Use a GitHub commit URL, or leave it blank.');
                $changes=['status'=>'resolved','live_at'=>ssNow(),'resolved_at'=>ssNow(),'next_update_at'=>null,'commit_url'=>$commit];
                $body='Live on site — please check and confirm, or reopen. '.$body;
            } elseif($kind==='acknowledge') {
                if($t['status']!=='new') ssFail('This request has already been acknowledged.');
                $changes=['status'=>'acknowledged','owner_id'=>$u['id'],'acknowledged_at'=>ssNow(),'acknowledged_by'=>$u['id'],
                    'next_update_at'=>ssDue(ssText($b,'next_update_at',16,true),$s,$t['branch']),'commitment'=>(int)$t['commitment']+1];
            } elseif($kind==='status') {
                $status=ssEnum($b,'status',HF_SUPPORT_STATUSES);
                if(!$manage && !($status==='cancelled' || ($status==='closed' && $t['status']==='resolved'))) ssFail('Support access required.',403);
                if(ssTerminal($t['status']) && !($t['status']==='resolved' && $status==='closed')) ssFail('Reopen the request first.');
                if(in_array($status,['new','acknowledged'],true)) ssFail('Use the acknowledgement or reopen action.');
                if(!$t['acknowledged_at'] && !in_array($status,['cancelled'],true)) ssFail('Acknowledge the request first.');
                $changes['status']=$status;
                if($status===$t['status']) ssFail('Choose a different status or post a reply.');
                if($status==='waiting_requester') { $changes['waiting_since']=ssNow();$changes['next_update_at']=null; }
                elseif(!ssTerminal($status)) { $changes['next_update_at']=ssDue(ssText($b,'next_update_at',16,true),$s,$t['branch']);$changes['commitment']=(int)$t['commitment']+1; }
                else { $changes['next_update_at']=null;$changes['resolved_at']=$status==='resolved'?ssNow():$t['resolved_at']; }
            } elseif($kind==='reopen') {
                if(!ssTerminal($t['status'])) ssFail('This request is already open.');
                $changes=['status'=>'new','resolved_at'=>null,'acknowledged_at'=>null,'acknowledged_by'=>null,'next_update_at'=>null,'waiting_since'=>null,'live_at'=>null,'commit_url'=>null];
            } elseif($kind==='assign') {
                $owner=(int)($b['owner_id']??0);
                if($owner && !ssAgent($db,$owner,$t['branch'],$s)) ssFail('Choose an active support agent with access to this branch.');
                $changes['owner_id']=$owner?:null;
            } elseif($kind==='commitment') {
                if(ssTerminal($t['status']) || in_array($t['status'],['new','waiting_requester'],true)) ssFail('Acknowledge or resume this request before setting an update.');
                $changes=['next_update_at'=>ssDue(ssText($b,'next_update_at',16,true),$s,$t['branch']),'commitment'=>(int)$t['commitment']+1];
            } elseif($kind==='priority') $changes['priority']=ssEnum($b,'priority',['normal','high','urgent']);
            elseif($kind==='link_issue') {
                $iid=(int)($b['issue_id']??0);$i=$iid?ssOne($db,'SELECT * FROM support_issues WHERE id=?',[$iid]):null;
                if($iid && (!$i || !ssIssueVisible($u,$i,$s) || !ssIssueVisible(ssUser($db,(int)$t['requester_id']),$i,$s))) ssFail('Choose an issue visible to the requester.');
                $changes['issue_id']=$iid?:null;
            }
            if($t['waiting_since'] && isset($changes['status']) && $changes['status']!=='waiting_requester') {
                $changes['waiting_since']=null;$changes['waiting_seconds']=(int)$t['waiting_seconds']+max(0,strtotime(ssNow())-strtotime($t['waiting_since']));
            }
            foreach($changes as $key=>$value) $meta[$key]=['from'=>$t[$key]??null,'to'=>$value];
        }
        $t=ssSetTicket($db,$t,$changes);$e=ssEvent($db,$u,$id,$kind,$body,$visibility,$meta);
        if($visibility==='public') ssNotify($db,$u,$t,$s,$e);
        return ['id'=>$id,'version'=>$t['version']];
    }
    if($action==='support_answer_save') {
        if(!ssTeam($u)) ssFail('Support access required.',403);
        $id=(int)($b['id']??0);$a=$id?ssOne($db,'SELECT * FROM support_answers WHERE id=?',[$id]):null;
        if($id && (!$a || !ssSourceAnswer($db,$u,$a))) ssFail('Answer unavailable.',404);
        if($a && (int)$a['version']!==(int)($b['version']??0)) ssFail('This answer changed. Refresh first.',409);
        $status=ssEnum($b,'status',['draft','published','withdrawn'],'draft');
        if(($status!=='draft' || ($a && $a['status']==='published')) && !ssCan($u,'staff_support','approve')) ssFail('Publishing requires support approval permission.',403);
        $v=['title'=>ssText($b,'title',180,true),'body'=>ssText($b,'body',6000,true),'module'=>ssEnum($b,'module',HF_SUPPORT_MODULES,'general'),
            'problem'=>ssEnum($b,'problem',HF_SUPPORT_PROBLEMS),'keywords'=>ssText($b,'keywords',255),'source_module'=>ssEnum($b,'source_module',['manuals','procedures','library']),
            'source_id'=>ssText($b,'source_id',255,true),'status'=>$status,'reviewed_at'=>$status==='published'?substr(ssNow(),0,10):null,'review_due'=>ssText($b,'review_due',10,true),'author_id'=>$u['id']];
        if(!ssSourceAnswer($db,$u,$v)) ssFail('You cannot use that source.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v['review_due']);
        if(!$d || $d->format('Y-m-d')!==$v['review_due'] || $v['review_due']<substr(ssNow(),0,10)) ssFail('Choose a future review date.');
        if(in_array($v['source_module'],['procedures','library'],true) && !ssOne($db,'SELECT id FROM '.($v['source_module']==='library'?'library_docs':'procedures').' WHERE id=?',[$v['source_id']])) ssFail('Source document not found.');
        if($a) {
            $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($v)));
            if(ssQuery($db,"UPDATE support_answers SET $sets,version=version+1 WHERE id=? AND version=?",array_merge(array_values($v),[$id,$a['version']]))->rowCount()!==1) ssFail('Answer changed. Refresh first.',409);
        } else {ssQuery($db,'INSERT INTO support_answers ('.implode(',',array_keys($v)).') VALUES ('.implode(',',array_fill(0,count($v),'?')).')',array_values($v));$id=(int)$db->lastInsertId();}
        return ['id'=>$id];
    }
    if($action==='support_answer_helped') {
        $id=(int)($b['id']??0);$a=ssOne($db,'SELECT * FROM support_answers WHERE id=?',[$id]);
        if(!$a || !ssAnswerVisible($db,$u,$a)) ssFail('Answer unavailable.',404);
        if(!ssOne($db,'SELECT answer_id FROM support_feedback WHERE answer_id=? AND user_id=?',[$id,$u['id']])) ssQuery($db,'INSERT INTO support_feedback VALUES(?,?,?)',[$id,$u['id'],ssNow()]);
        return ['id'=>$id];
    }
    if($action==='support_issue_follow') {
        $id=(int)($b['id']??0);$i=ssOne($db,'SELECT * FROM support_issues WHERE id=?',[$id]);
        if(!$i || !ssIssueVisible($u,$i,$s)) ssFail('Known issue unavailable.',404);
        if(!empty($b['unfollow'])) {
            ssQuery($db,'DELETE FROM support_followers WHERE issue_id=? AND user_id=?',[$id,$u['id']]);
            ssQuery($db,'DELETE FROM support_affected_records WHERE issue_id=? AND user_id=?',[$id,$u['id']]);
        } else {
            if(!ssOne($db,'SELECT user_id FROM support_followers WHERE issue_id=? AND user_id=?',[$id,$u['id']])) ssQuery($db,'INSERT INTO support_followers VALUES(?,?,?)',[$id,$u['id'],ssNow()]);
            if(!empty($b['record_id'])) {
                $ctx=ssSource($db,$u,$b);
                if($i['module']!=='general' && $i['module']!==$ctx['module']) ssFail('The record does not match this issue.');
                if(!ssOne($db,'SELECT issue_id FROM support_affected_records WHERE issue_id=? AND user_id=? AND module=? AND record_id=?',[$id,$u['id'],$ctx['module'],$ctx['record_id']]))
                    ssQuery($db,'INSERT INTO support_affected_records VALUES(?,?,?,?,?)',[$id,$u['id'],$ctx['module'],$ctx['record_id'],ssJson($ctx)]);
            }
        }
        return ['id'=>$id];
    }
    if($action==='support_issue_save') {
        if(!ssCan($u,'staff_support','approve')) ssFail('Publishing known issues requires support approval permission.',403);
        $id=(int)($b['id']??0);$i=$id?ssOne($db,'SELECT * FROM support_issues WHERE id=?',[$id]):null;
        if($id && (!$i || !ssIssueVisible($u,$i,$s) || !ssBranch($u,$i['branch'],$s))) ssFail('Known issue unavailable.',404);
        if($i && (int)$i['version']!==(int)($b['version']??0)) ssFail('This issue changed. Refresh first.',409);
        $branch=ssText($b,'branch',100);if(!ssBranch($u,$branch,$s)) ssFail('You cannot publish to this branch.',403);
        $status=ssEnum($b,'status',['investigating','workaround','monitoring','resolved'],'investigating');
        $v=['title'=>ssText($b,'title',180,true),'module'=>ssEnum($b,'module',HF_SUPPORT_MODULES,'general'),'branch'=>$branch,
            'problem'=>ssEnum($b,'problem',HF_SUPPORT_PROBLEMS,'other'),'status'=>$status,'explanation'=>ssText($b,'body',6000,true),
            'workaround'=>ssText($b,'workaround',4000),'owner_id'=>$u['id'],
            'next_update_at'=>$status==='resolved'?null:ssDue(ssText($b,'next_update_at',16,true),$s,$branch),
            'commitment'=>(int)($i['commitment']??0)+1,'updated_at'=>ssNow()];
        if($v['module']!=='general' && !ssCan($u,$v['module'])) ssFail('Module access required.',403);
        if($i) {
            $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($v)));
            if(ssQuery($db,"UPDATE support_issues SET $sets,version=version+1 WHERE id=? AND version=?",array_merge(array_values($v),[$id,$i['version']]))->rowCount()!==1) ssFail('Issue changed. Refresh first.',409);
        } else {ssQuery($db,'INSERT INTO support_issues ('.implode(',',array_keys($v)).') VALUES ('.implode(',',array_fill(0,count($v),'?')).')',array_values($v));$id=(int)$db->lastInsertId();}
        ssQuery($db,'INSERT INTO support_issue_updates(issue_id,actor_id,body,created_at) VALUES(?,?,?,?)',[$id,$u['id'],ssJson($v),ssNow()]);$event=(int)$db->lastInsertId();
        $ids=ssQuery($db,'SELECT user_id FROM support_followers WHERE issue_id=? UNION SELECT requester_id AS user_id FROM support_tickets WHERE issue_id=?',[$id,$id])->fetchAll(PDO::FETCH_COLUMN);
        $issue=array_merge($v,['id'=>$id]);
        foreach($ids as $uid) {
            try{$recipient=ssUser($db,(int)$uid);}catch(HFSupportError $e){continue;}
            if((int)$uid!==(int)$u['id'] && ssIssueVisible($recipient,$issue,$s)) ssQueue($db,'issue:'.$event,(int)$uid,'issue',$id,'Update on known issue #'.$id);
        }
        // An incident update never silently resolves linked tickets.
        return ['id'=>$id];
    }
    if($action==='support_settings_save') {
        if(!in_array($u['role'],['owner','admin'],true)) ssFail('Administrator access required.',403);
        $v=$b['settings']??[];
        if(!is_array($v)) ssFail('Invalid settings.');
        $v=array_intersect_key($v,array_flip(['default_owner','backup_id','routing','agent_branches','hours','escalate_hours']));
        $v=array_replace($s,$v);
        foreach(['default_owner','backup_id'] as $k) { $v[$k]=(int)$v[$k];if($v[$k] && !ssTeam(ssUser($db,$v[$k]))) ssFail('Choose active support agents.'); }
        if(!is_array($v['routing']) || !is_array($v['agent_branches']) || !is_array($v['hours']) || empty($v['hours']['*'])) ssFail('Invalid routing or hours.');
        foreach($v['routing'] as $m=>$uid) if(!in_array($m,HF_SUPPORT_MODULES,true) || ($uid && !ssTeam(ssUser($db,(int)$uid)))) ssFail('Invalid route.');
        foreach($v['agent_branches'] as $uid=>$branches) {
            if(!ssTeam(ssUser($db,(int)$uid)) || !is_array($branches) || !$branches || count($branches)>30) ssFail('Invalid agent branches.');
            foreach($branches as $br) if(!is_string($br) || strlen($br)>100) ssFail('Invalid branch.');
        }
        foreach($v['hours'] as $br=>$h) {
            if(!is_array($h) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$h['start']??'') || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$h['end']??'') || $h['start']>=$h['end'] || empty($h['days']) || !is_array($h['days'])) ssFail('Invalid working hours.');
            foreach($h['days'] as $day) if(!is_int($day) || $day<1 || $day>7) ssFail('Invalid working days.');
        }
        $v['escalate_hours']=max(1,min(168,(int)$v['escalate_hours']));
        if(ssOne($db,"SELECT value FROM settings WHERE `key`='staff_support_settings'")) ssQuery($db,"UPDATE settings SET value=? WHERE `key`='staff_support_settings'",[ssJson($v)]);
        else ssQuery($db,'INSERT INTO settings (`key`,value) VALUES(?,?)',['staff_support_settings',ssJson($v)]);
        return ['settings'=>$v];
    }
    ssFail('Unknown support action.',404);
}

function ssHandle(PDO $db, array $config, array $user, string $action, array $data, bool $write): array {
    if(empty($config['staff_support_enabled'])) ssFail('Staff Support is not enabled.',404);
    $u=ssUser($db,(int)$user['id']); // Fresh active account, permissions and branch on EVERY request.
    if(!ssCan($u,'staff_support')) ssFail('Staff Support access required.',403);
    $s=ssSettings($db);
    if((strpos($action,'support_answer_')===0 || $action==='support_answers') && !ssFeature($db,'answers'))ssFail('Suggested answers are switched off.',404);
    if((strpos($action,'support_issue_')===0 || ($action==='support_update'&&($data['operation']??'')==='link_issue')) && !ssFeature($db,'incidents'))ssFail('Known issues are switched off.',404);
    if(!$write) return ['success'=>true]+ssRead($db,$u,$s,$action,$data);
    $key=ssText($data,'request_key',80,true);
    if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/',$key)) ssFail('Invalid request key.');
    $fileHash=$action==='support_upload' && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) ? hash_file('sha256',$_FILES['file']['tmp_name']) : '';
    $hash=hash('sha256',$action.ssJson($data).$fileHash);
    $prior=ssOne($db,'SELECT * FROM support_commands WHERE user_id=? AND request_key=?',[$u['id'],$key]);
    if($prior) { if(!hash_equals($prior['payload_hash'],$hash)) ssFail('Request key already used for different content.',409);return json_decode($prior['result_json'],true); }
    $db->beginTransaction();
    try {
        // Reserve the idempotency key before applying any workflow effects.
        ssQuery($db,'INSERT INTO support_commands(user_id,request_key,payload_hash,result_json,created_at) VALUES(?,?,?,?,?)',[$u['id'],$key,$hash,'{}',ssNow()]);
        $result=['success'=>true]+ssWrite($db,$config,$u,$s,$action,$data);
        ssQuery($db,'UPDATE support_commands SET result_json=? WHERE user_id=? AND request_key=?',[ssJson($result),$u['id'],$key]);
        $db->commit();return $result;
    } catch(Throwable $e) {
        if($db->inTransaction()) $db->rollBack();
        $prior=ssOne($db,'SELECT * FROM support_commands WHERE user_id=? AND request_key=?',[$u['id'],$key]);
        if($prior && hash_equals($prior['payload_hash'],$hash)) return json_decode($prior['result_json'],true);
        throw $e;
    }
}

function ssFilesDir(array $config): string {
    $dir=$config['staff_support_files_dir'] ?? dirname(__DIR__,2).'/intranet_data/staff_support';
    if(!is_dir($dir) && !mkdir($dir,0700,true)) ssFail('Protected file storage is unavailable.',500);
    $real=realpath($dir);$root=realpath(!empty($_SERVER['DOCUMENT_ROOT'])?$_SERVER['DOCUMENT_ROOT']:($config['staff_support_public_dir']??dirname(__DIR__)));
    if($root && ($real===$root || strpos($real,$root.DIRECTORY_SEPARATOR)===0)) ssFail('Support file storage must be outside the public web root.',500);
    return $real;
}

function ssReport(PDO $db,array $u,array $s): array {
    if(!ssTeam($u)) ssFail('Support access required.',403);
    $counts=['total'=>0,'open'=>0,'new'=>0,'unassigned'=>0,'overdue'=>0,'resolved'=>0,'reopened'=>0];$ack=[];$modules=[];$waiting=0;
    foreach(ssQuery($db,'SELECT * FROM support_tickets')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if(!ssManage($u,$t,$s)) continue;
        $counts['total']++;$modules[$t['module']]=($modules[$t['module']]??0)+1;
        if(!ssTerminal($t['status'])){$counts['open']++;if(!$t['owner_id'])$counts['unassigned']++;}
        if($t['status']==='new')$counts['new']++;
        if(in_array($t['status'],['resolved','closed'],true))$counts['resolved']++;
        if($t['next_update_at'] && $t['next_update_at']<ssNow() && !ssTerminal($t['status']) && $t['status']!=='waiting_requester')$counts['overdue']++;
        $first=ssOne($db,"SELECT created_at FROM support_events WHERE ticket_id=? AND kind='acknowledge' ORDER BY id LIMIT 1",[$t['id']]);
        if($first)$ack[]=max(0,(strtotime($first['created_at'])-strtotime($t['created_at']))/60);
        if(ssOne($db,"SELECT id FROM support_events WHERE ticket_id=? AND kind='reopen' LIMIT 1",[$t['id']]))$counts['reopened']++;
        $waiting+=(int)$t['waiting_seconds']+($t['waiting_since']?max(0,strtotime(ssNow())-strtotime($t['waiting_since'])):0);
    }
    sort($ack);$n=count($ack);$median=$n?($ack[(int)floor(($n-1)/2)]+$ack[(int)floor($n/2)])/2:null;
    return ['counts'=>$counts,'median_ack_minutes'=>$median===null?null:round($median,1),'ack_sample'=>$n,'requester_wait_hours'=>round($waiting/3600,1),'by_module'=>$modules,'basis'=>'All authorised tickets. Acknowledgement uses elapsed calendar time, including non-working hours.'];
}
function ssUpload(PDO $db, array $config, array $u, array $s, array $t, array $b): array {
    if(ssTerminal($t['status'])) ssFail('Reopen the request before adding evidence.');
    $f=$_FILES['file']??null;
    if(!$f || $f['error']!==UPLOAD_ERR_OK || $f['size']<1 || $f['size']>5*1024*1024 || !is_uploaded_file($f['tmp_name'])) ssFail('Choose an image or PDF up to 5 MB.');
    if((int)ssQuery($db,'SELECT COUNT(*) FROM support_files WHERE ticket_id=?',[$t['id']])->fetchColumn()>=20) ssFail('Maximum 20 files per request.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if(!in_array($mime,['image/png','image/jpeg','image/webp','application/pdf'],true)) ssFail('Only PNG, JPEG, WebP and PDF files are supported.');
    $visibility=ssEnum($b,'visibility',['public','internal'],'public');if($visibility==='internal' && !ssManage($u,$t,$s)) ssFail('Support access required.',403);
    $name=substr(preg_replace('/[\x00-\x1F\x7F]/','',basename($f['name'])),0,180) ?: 'attachment';
    $stored=bin2hex(random_bytes(24));$path=ssFilesDir($config).'/'.$stored;
    if(!move_uploaded_file($f['tmp_name'],$path)) ssFail('Could not store evidence.',500);
    chmod($path,0600);
    try {
        $t=ssSetTicket($db,$t,[]);$event=ssEvent($db,$u,(int)$t['id'],'file_added',$name,$visibility);
        ssQuery($db,'INSERT INTO support_files(ticket_id,event_id,name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?)',[$t['id'],$event,$name,$stored,$mime,$f['size'],ssNow()]);
        $id=(int)$db->lastInsertId();if($visibility==='public') ssNotify($db,$u,$t,$s,$event);
        return ['id'=>$t['id'],'file_id'=>$id,'version'=>$t['version']];
    } catch(Throwable $e) {unlink($path);throw $e;}
}
function ssDownload(PDO $db,array $config,array $user,int $id): void {
    if(empty($config['staff_support_enabled'])) ssFail('Staff Support is disabled.',404);
    $u=ssUser($db,(int)$user['id']);$s=ssSettings($db);
    $f=ssOne($db,'SELECT f.*,e.visibility FROM support_files f JOIN support_events e ON e.id=f.event_id WHERE f.id=?',[$id]);
    if(!$f) ssFail('File unavailable.',404);
    $t=ssTicket($db,$u,$s,(int)$f['ticket_id']);
    if($f['visibility']==='internal' && !ssManage($u,$t,$s)) ssFail('File unavailable.',404);
    $path=ssFilesDir($config).'/'.$f['stored_name'];if(!is_file($path)) ssFail('File unavailable.',404);
    header('Content-Type: '.$f['mime']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox");
    header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($f['name']));header('Content-Length: '.filesize($path));readfile($path);
}

/** Called only after the intranet auth guard; writes additionally require a session CSRF token. */
function ssRoute(array $config, array $user, string $action): void {
    try {
        if(empty($_SESSION['staff_support_csrf'])) $_SESSION['staff_support_csrf']=bin2hex(random_bytes(24));
        $write=!in_array($action,['support_meta','support_context','support_list','support_detail','support_suggestions','support_issues','support_answers','support_report','support_file'],true);
        if($write && (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !hash_equals($_SESSION['staff_support_csrf'],$_SERVER['HTTP_X_SUPPORT_CSRF']??''))) ssFail('Session check failed. Refresh the page and try again.',403);
        $db=getDB($config);
        if($action==='support_file') {ssDownload($db,$config,$user,(int)($_GET['id']??0));return;}
        $data=$write ? ($action==='support_upload'?$_POST:(json_decode(file_get_contents('php://input'),true)?:[])) : $_GET;
        echo ssJson(ssHandle($db,$config,$user,$action,$data,$write));
    } catch(HFSupportError $e) {http_response_code($e->getCode()?:422);echo ssJson(['success'=>false,'error'=>$e->getMessage()]);}
    catch(Throwable $e) {error_log('Staff Support: '.$e->getMessage());http_response_code(500);echo ssJson(['success'=>false,'error'=>'Could not save or load support data. Your request can be retried.']);}
}
