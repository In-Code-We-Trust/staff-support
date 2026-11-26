<?php
/** Fictional local presentation data only. Production discovery and adapters remain separate. */
function demoNormal(string $s): string {return strtolower(trim(preg_replace('/\s+/u',' ',str_replace("\xc2\xa0",' ',$s))));}
function demoPhone(string $s): string {
    $n=preg_replace('/\D/','',$s);
    if(strpos($n,'0027')===0)$n=substr($n,2);
    if(strlen($n)===10 && $n[0]==='0')$n='27'.substr($n,1);
    return $n;
}
function demoOrders(PDO $db,array $u): array {
    if(!ssCan($u,'quotes'))return [];
    $sql='SELECT o.*,c.name,c.email,c.phone,c.branch,c.rep FROM demo_orders o JOIN demo_customers c ON c.id=o.customer_id';$args=[];
    if(!in_array($u['role'],['owner','admin'],true)) {
        $sql.=$u['role']==='manager'?' WHERE c.branch=?':' WHERE c.rep=?';
        $args[]=$u['role']==='manager'?$u['branch']:($u['cin7_rep']??'');
    }
    return ssQuery($db,$sql.' ORDER BY o.created_at DESC,o.id DESC',$args)->fetchAll(PDO::FETCH_ASSOC);
}
function demoSearch(PDO $db,array $u,array $q): array {
    $term=demoNormal(ssText($q,'q',100));if(strlen($term)<2)return ['groups'=>[]];
    $ref=preg_replace('/[\s-]/','',$term);$phone=demoPhone($term);$groups=['Customers'=>[],'Sales orders'=>[],'Invoices'=>[]];$seen=[];
    foreach(demoOrders($db,$u) as $o) {
        $customer=strpos(demoNormal($o['name']),$term)!==false || demoNormal($o['email'])===$term || (strlen($phone)>=9 && demoPhone($o['phone'])===$phone);
        $so=strpos(preg_replace('/[\s-]/','',strtolower($o['id'])),$ref)!==false;
        $inv=$o['invoice_number']!==''&&strpos(preg_replace('/[\s-]/','',strtolower($o['invoice_number'])),$ref)!==false;
        if(!$customer&&!$so&&!$inv)continue;
        $base=['customer'=>$o['name'],'customer_id'=>$o['customer_id'],'branch'=>$o['branch'],'date'=>$o['created_at']];
        if(!isset($seen[$o['customer_id']])){$groups['Customers'][]=$base+['ref'=>$o['name'],'status'=>'Customer','open'=>['module'=>'customer','id'=>$o['customer_id']]];$seen[$o['customer_id']]=true;}
        $groups['Sales orders'][]=$base+['ref'=>$o['id'],'status'=>$o['status'],'open'=>['module'=>'quotes','id'=>$o['id']]];
        if($o['invoice_number']!=='' && ssCan($u,'invoices'))$groups['Invoices'][]=$base+['ref'=>$o['invoice_number'],'status'=>$o['invoice_status'],'open'=>['module'=>'customer','id'=>$o['customer_id']]];
    }
    foreach($groups as &$rows)$rows=array_slice($rows,0,5);
    return ['groups'=>array_filter($groups),'demo'=>true];
}
function demoCustomer(PDO $db,array $u,int $id): array {
    $orders=array_values(array_filter(demoOrders($db,$u),fn($o)=>(int)$o['customer_id']===$id));
    if(!$orders)ssFail('Customer not found or unavailable.',404);
    $c=array_intersect_key($orders[0],array_flip(['name','email','phone','branch','rep','customer_id']));
    $refs=array_column($orders,'id');$tickets=[];
    foreach(ssQuery($db,"SELECT * FROM support_tickets WHERE module='quotes' AND record_id IN (".implode(',',array_fill(0,count($refs),'?')).')',$refs)->fetchAll(PDO::FETCH_ASSOC) as $t)
        if(ssCanTicket($u,$t,ssSettings($db)))$tickets[]=array_intersect_key($t,array_flip(['id','title','status','created_at']));
    foreach($orders as &$o){if(!ssCan($u,'invoices')){unset($o['invoice_number'],$o['invoice_status']);}if(!ssCan($u,'dispatch'))unset($o['delivery_status']);}
    return ['customer'=>$c,'orders'=>$orders,'tickets'=>$tickets,'demo'=>true];
}
