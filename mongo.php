<?php
error_reporting(E_ALL);
if(version_compare(PHP_VERSION,'7.0.0','<')) die('Require PHP 7.0 or higher');
if(!extension_loaded('mongodb')) die('Install Mongodb extension!');
session_name('Mongo');
session_start();
$bg=2;
$step=20;
$version="1.0";
class DBT {
	private static $instance=NULL;
	protected $_cnx,$db,$bw,$wc;
	public static function factory($host='',$pwd='',$usr='',$db='') {
		if(!isset(self::$instance)) self::$instance=new DBT($host,$pwd,$usr,$db);
		return self::$instance;
	}
	public function __construct($host,$pwd,$usr,$db) {
		$this->db=empty($db)?"":"/".$db;
		$con="mongodb://".(empty($usr)?"":$usr).(empty($pwd)?"":":".$pwd).(!empty($usr)?"@":"").(empty($host)?"localhost:27017":$host).$this->db;
		$this->_cnx=new MongoDB\Driver\Manager($con);
	}
	public function commands($db,$cmd) {
		return $this->_cnx->executeCommand($db, new MongoDB\Driver\Command($cmd));
	}
	public function select($con,$filter=[],$option=[]) {
		$qry=new MongoDB\Driver\Query($filter,$option);
		return $this->_cnx->executeQuery($con,$qry)->toArray();
	}
	protected function prepare($time=1000) {
		$this->wc=new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY,$time);
		$this->bw=new MongoDB\Driver\BulkWrite;
	}
	public function insert($con,$doc) {
		$this->prepare();
		if(count($doc) != count($doc,COUNT_RECURSIVE)) {
			foreach ($doc as $dc) $this->bw->insert($dc);
		} else {
			$this->bw->insert($doc);
		}
		$result=$this->execute($con);
		return $result->getInsertedCount();
	}
	public function update($con,$filter,$doc) {
		$this->prepare();
		$this->bw->update($filter,$doc,['multi'=>true,'upsert'=>false]);
		$result=$this->execute($con);
		return $result->getModifiedCount();
	}
	public function delete($con,$doc=[]) {
		$this->prepare();
		$this->bw->delete($doc,['limit'=>0]);
		$result=$this->execute($con);
		return $result->getDeletedCount();
	}
	protected function execute($con) {
		return $this->_cnx->executeBulkWrite($con,$this->bw,$this->wc);
	}
	public function convert_id($doc, $oid='') {
		if($doc instanceof MongoDB\BSON\ObjectId) {
			$doc=$doc->__toString();
		} elseif(is_numeric($doc)) {
			$doc=intval($doc);
		} elseif($oid=='oid') {
			$doc=new MongoDB\BSON\ObjectId($doc);
		} else {
			$doc=$doc;
		}
		return $doc;
	}
	public function convert_bin($doc) {
		return ($doc instanceof MongoDB\BSON\Binary) ? json_decode(json_encode($doc),true)['$binary']:json_encode($doc);
	}
	public function convert_arr($doc) {
		return json_encode($doc);
	}
	public function num_row($con,$filter=[],$option=[]) {
		return count($this->select($con, $filter, $option));
	}
}
class ED {
	public $con,$sg,$path,$type,$deny=['admin','config','local'];
	public function __construct() {
		$pi=(isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : @getenv('PATH_INFO'));
		$this->sg=preg_split('!/!',$pi,-1,PREG_SPLIT_NO_EMPTY);
		$scheme='http'.(empty($_SERVER['HTTPS'])===true || $_SERVER['HTTPS']==='off' ? '' : 's').'://';
		$r_uri=isset($_SERVER['PATH_INFO'])===true ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF'];
		$script=$_SERVER['SCRIPT_NAME'];
		$this->path=$scheme.$_SERVER['HTTP_HOST'].(strpos($r_uri,$script)===0 ? $script : rtrim(dirname($script),'/.\\')).'/';
	}
	public function sanitize($el) {
		return preg_replace(['/[^A-Za-z0-9]/'],'_',trim($el));
	}
	public function utf($fi) {
		if(function_exists("iconv") && preg_match("~^\xFE\xFF|^\xFF\xFE~",$fi)) {
		$fi=iconv("utf-16","utf-8",$fi);
		}
		return $fi;
	}
	public function isJson($str) {
		json_decode($str);
		return json_last_error() === JSON_ERROR_NONE;
	}
	public function form($furl,$enc='') {
		return "<form action='".$this->path.$furl."' method='post'".($enc==1 ? " enctype='multipart/form-data'":"").">";
	}
	public function post($key='',$op='') {
		if($key==='' && !empty($_POST)) {
		return ($_SERVER['REQUEST_METHOD']==='POST' ? TRUE : FALSE);
		}
		if(!isset($_POST[$key])) return FALSE;
		if(is_array($_POST[$key])) {
		if(isset($op) && is_numeric($op)) {
		return $_POST[$key][$op];
		} else {
		$aout=[];
		foreach($_POST[$key] as $k=>$val) {
		if($val !='') $aout[$k]=$val;
		}
		}
		} else {
		$aout=$_POST[$key];
		}
		if($op=='i') return isset($aout);
		if($op=='e') return empty($aout);
		if($op=='!i') return !isset($aout);
		if($op=='!e') return !empty($aout);
		return $aout;
	}
	public function redir($way='',$msg=[]) {
		if(count($msg) > 0) {
		foreach($msg as $ks=>$ms) $_SESSION[$ks]=$ms;
		}
		header('Location: '.$this->path.$way);exit;
	}
	public function enco($str) {
		$salt=$_SERVER['HTTP_USER_AGENT'];
		$count=strlen($str);
		$str=(string)$str;
		$kount=strlen($salt);
		$x=0;$y=0;
		$eStr="";
		while($x < $count) {
			$char=ord($str[$x]);
			$keyS=is_numeric($salt[$y]) ? $salt[$y] : ord($salt[$y]);
			$encS=$char + $keyS;
			$eStr.=chr($encS);
			++$x;++$y;
			if($y==$kount) $y=0;
		}
		return base64_encode(base64_encode($eStr));
	}
	public function deco($str) {
		$salt=$_SERVER['HTTP_USER_AGENT'];
		$str=base64_decode(base64_decode($str));
		$count=strlen($str);
		$str=(string)$str;
		$kount=strlen($salt);
		$x=0;$y=0;
		$eStr="";
		while($x < $count) {
			$char=ord($str[$x]);
			$keyS=is_numeric($salt[$y]) ? $salt[$y] : ord($salt[$y]);
			$decS=$char - $keyS;
			$eStr.=chr($decS);
			++$x;++$y;
			if($y==$kount) $y=0;
		}
		return $eStr;
	}
	public function check($level=[],$param=[]) {
		if(isset($_SESSION['token']) && !empty($_SESSION['user'])) {//check login
			$pwd=$this->deco($_SESSION['token']);
			$usr=$_SESSION['user'];
			$ho=$_SESSION['host'];
			$db=$_SESSION['db'];
			try {
			$this->con=DBT::factory($ho,$usr,$pwd,$db);
			} catch(Exception $e) {
			$this->redir("50",['err'=>"Incorrect credentials"]);
			}
		} elseif(isset($_SESSION['user']) && empty($_SESSION['user'])) {
			$this->con=DBT::factory();
		} else {
			$this->redir("50");
		}
		//check connection
		try {
		$this->con->commands('admin',['ping'=>1]);
		$this->listdb();
		} catch(Exception $e) {
		$this->redir("50",['err'=>"Can't connect to the server"]);
		}
		$h='HTTP_X_REQUESTED_WITH';
		if(isset($_SERVER[$h]) && !empty($_SERVER[$h]) && strtolower($_SERVER[$h]) == 'xmlhttprequest') session_regenerate_id(true);
		//check db
		if(in_array('1',$level)) {
			$db=$this->sg[1];
			in_array($db,$this->listdb()) ? "" : $this->redir("",['err'=>"Not authorized"]);
		}
		//check tb
		if(in_array('2',$level)) {
			$db=$this->sg[1];
			$tb=$this->sg[2];
			array_key_exists($tb,$this->listCollection($db)) ? "" : $this->redir("5/$db",['err'=>"Not authorized"]);
			$this->type=$this->listCollection($db)[$tb];
		}
		//check id
		if(in_array('3',$level)) {
			$oid=$this->sg[4]??'';
			$q_=$this->con->select($db.'.'.$tb,['_id'=>$this->con->convert_id($this->sg[3],$oid)],['limit'=>1]);
			if(empty($q_)) $this->redir("20/$db/$tb",['err'=>"Don't have access to this document"]);
		}
		//check access users
		if(in_array('4',$level)) {
			try {
			$this->con->select('admin.system.users');
			} catch(Exception $e) {
			$this->redir("",['err'=>"Not authorized to manage users"]);
			}
		}
	}
	public function listdb() {
		$dbs=$this->con->commands('admin',['listDatabases'=>1,'nameOnly'=>true])->toArray()[0]->databases;
		$dbn=[];
		foreach($dbs as $dbx) $dbn[]=$dbx->name;
		sort($dbn);
		return $dbn;
	}
	public function listCollection($db) {
		$tbs=$this->con->commands($db,['listCollections'=>1,'nameOnly'=>true])->toArray();
		$tbn=[];
		foreach($tbs as $tbx) $tbn[$tbx->name]=$tbx->type;
		ksort($tbn);
		return $tbn;
	}
	public function menu($db='',$tb='',$left='') {
		$str=""; $path=$this->path;
		if($db==1 || $db!='') $str.="<div class='l2'><ul><li><a href='{$path}'>Databases</a></li>";
		if($db!='' && $db!=1) $str.="<li><a href='{$path}31/$db'>Export</a></li><li><a href='{$path}5/$db'>Collections</a></li>";
		$dv="<li class='divider'>---</li>";
		if($tb!="") $str.=$dv."<li><a href='{$path}20/$db/$tb'>Browse</a></li><li><a href='{$path}15/$db/$tb'>Indexes</a></li><li><a href='{$path}24/$db/$tb'>Search</a></li><li><a class='del' href='{$path}25/$db/$tb'>Empty</a></li><li><a class='del' href='{$path}26/$db/$tb'>Drop</a></li>";//table
		if($db!='') $str.="</ul></div>";

		if($db!="" && $db!=1) {
		$str.="<div class='l3 auto'><select onchange='location=this.value;'><optgroup label='databases'>";
		foreach($this->listdb() as $udb) $str.="<option value='{$path}5/$udb'".($udb==$db?" selected":"").">$udb</option>";
		$str.="</optgroup></select>";

		$q_ts=$this->listCollection($db);
		if($tb!="") {
		$sl2="<select onchange='location=this.value;'>";
		$sl2.='<optgroup label="collections">';
		foreach($q_ts as $k=>$r_ts) $sl2.="<option value='{$path}20/$db/".$k."'".($k==$tb?" selected":"").">".$k."</option>";
		$str.=$sl2."</optgroup></select>".((!empty($_SESSION["_mosearch_{$db}_{$tb}"]) && $this->sg[0]==20) ? " [<a href='{$path}24/$db/$tb/reset'>reset search</a>]":"");
		}
		$str.="</div>";
		}

		$str.="<div class='container'>";
		if($left==1) {
		if(!in_array($db,$this->deny)) {
		$tbl='';
		foreach($q_ts as $k=>$r_tb) if($r_tb!='view' && substr($k,0,7)!='system.') $tbl.="<option value='$k'>$k</option>";
		$str.="<div class='col1'>".$this->form("2")."<input type='hidden' name='dbn' value='$db'/><input type='text' name='colln' placeholder='Collection'/><br/><button type='submit'>Create</button></form>".$this->form("30/$db",1)."<h3>Import</h3><small>json, xml, gz, zip</small><br/><input type='file' name='importfile' /><br/><button type='submit'>Import</button></form>".$this->form("9/$db")."<h3>Rename Collection</h3><select name='oldtb'>$tbl</select><br/><input type='text' name='newtb' placeholder='New Name' /><br/><button type='submit'>Rename</button></form>".$this->form("9/$db")."<h3>Create View</h3><select name='tbl'>$tbl</select><br/><input type='text' name='vn' placeholder='View Name' /><br/><input type='text' name='fld' placeholder='Romove fields (comma separated)' /><br/><button type='submit'>Create</button></form></div><div class='col2'>";
		} else {
		$str.="<div class='col3'>";
		}
		}
		if($left==2) $str.="<div class='col3'>";
		if($left==3) $str.="<div class='col4'>";
		return $str;
	}
	public function pg_number($pg,$totalpg) {
		if($totalpg > 1) {
		if($this->sg[0]==20) $lnk=$this->path."20/".$this->sg[1]."/".$this->sg[2];
		elseif($this->sg[0]==5) $lnk=$this->path."5/".$this->sg[1];
		$pgs='';$k=1;
		while($k <=$totalpg) {
		$pgs.="<option ".(($k==$pg) ? "selected='selected'>":"value='$lnk/$k'>")."$k</option>";
		++$k;
		}
		$lft=($pg>1?"<a href='$lnk/1'>First</a><a href='$lnk/".($pg-1)."'>Prev</a>":"");
		$rgt=($pg < $totalpg?"<a href='$lnk/".($pg+1)."'>Next</a><a href='$lnk/$totalpg'>Last</a>":"");
		return "<div class='pg ce'>".$lft."<select onchange='location=this.value;'>$pgs</select>".$rgt."</div>";
		}
	}
	public function imp_json($db,$tb,$body) {
		$e=[];
		if(array_key_exists($tb,$this->listCollection($db))) {
			$e[]=[$tb=>['err'=>"Already exists"]];
			return $e;
		}
		if(@is_file($body)) $body=file_get_contents($body);
		$body=$this->utf($body);
		if(empty($body)) {
			$this->con->commands($db,['create'=>$tb]);
		} else {
			$rgx="~^\xEF\xBB\xBF|^\xFE\xFF|^\xFF\xFE|(\/\/).*\n*|(\/\*)*.*(\*\/)\n*|((\"*.*\")*('*.*')*)(*SKIP)(*F)~";
			$json=preg_split($rgx,$body,-1,PREG_SPLIT_NO_EMPTY);
			$rows=json_decode($json[0],true);
			foreach($rows as $row) {
			$this->con->insert($db.".".$tb,$row);
			}
		}
		$e[]=[$tb=>['ok'=>"Successfully created"]];
		return $e;
	}
	public function imp_xml($body) {
		$e=[];
		if(@is_file($body)) $body=file_get_contents($body);
		$body=$this->utf($body);
		libxml_use_internal_errors(false);
		$xml=simplexml_load_string($body,"SimpleXMLElement",LIBXML_COMPACT);
		$nspace=$xml->getNameSpaces(true);
		$ns=key($nspace);
		//data
		$db=(string)$xml->xpath('//database')[0]->attributes();
		$datas=$xml->xpath('//database/table');
		$tbs=[];$i=0;
		foreach($datas as $data) {
		$tbs[]=(string)$data->attributes();
		}
		$tbs=array_unique($tbs);
		$tx=[];
		foreach($tbs as $tb) {
			$dtb=$db.".".$tb;
			if(array_key_exists($tb,$this->listCollection($db))) {
			$e[]=[$dtb=>['err'=>"Already exists"]];
			} else {
			$tx[]=$tb;
			$e[]=[$dtb=>['ok'=>"Successfully created"]];
			}
		}
		foreach($datas as $data) {
			$tb=(string)$data->attributes();
			if(!in_array($tb,$tx)) continue;
			$row=[];
			foreach($data as $dt) {
			$cn=(string)$dt->attributes();
			$row[$cn]=(string)$dt;
			}
			$this->con->insert($db.".".$tb,$row);
		}
		return $e;
	}
}
$ed=new ED;
$head='<!DOCTYPE html><html lang="en"><head>
<title>EdMongoAdmin</title><meta charset="utf-8">
<style>
*{margin:0;padding:0;font-size:12px;color:#333;font-family:Arial}
html{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%;background:#fff}
html,textarea{overflow:auto}
.container{overflow:auto;overflow-y:hidden;-ms-overflow-y:hidden;white-space:nowrap;scrollbar-width:thin}
[hidden],.mn ul{display:none}
[disabled]{pointer-events:none}
.m1{position:absolute;right:0;top:0}
.mn li:hover ul{display:block;position:absolute}
.ce{text-align:center}
.pg *{margin:0 2px;width:auto}
.l1 ul,.l2 ul{list-style:none}
.left{float:left}
.left button{margin:0 1px}
h3{margin:2px 0 1px;padding:2px 0}
a{color:#842;text-decoration:none}
a:hover{text-decoration:underline}
a,a:active,a:hover{outline:0}
table a,.l1 a,.l2 a{padding:0 3px}
table{border-collapse:collapse;border-spacing:0;border-bottom:1px solid #555}
td,th{padding:4px;vertical-align:top}
input[type=checkbox],input[type=radio]{position:relative;vertical-align:middle;bottom:1px}
input[type=text],input[type=password],input[type=file],textarea,button,select{width:100%;padding:2px;border:1px solid #9be;outline:none;-webkit-border-radius:3px;-moz-border-radius:3px;border-radius:3px;-webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box}
select{padding:1px 0}
optgroup option{padding-left:8px}
textarea{white-space:pre-wrap}
.msg{position:absolute;top:0;right:0;z-index:9}
.ok,.err{padding:8px;font-weight:bold;font-size:13px}
.ok{background:#efe;color:#080;border-bottom:2px solid #080}
.err{background:#fee;color:#f00;border-bottom:2px solid #f00}
.l1,th,button{background:#9be}
.l2,.c1,.col1,h3{background:#cdf}
.c2,.mn ul{background:#fff}
.l3,tr:hover.r,button:hover{background:#fe3 !important}
.ok,.err,.l2 li,.mn>li{display:inline-block;zoom:1}
.col1,.col2{display:table-cell}
.col1{vertical-align:top;padding:3px}
.col1,.dw{width:180px}
.col2 table{margin:3px}
.col3 table,.col4 table,.dw{margin:3px auto}
.auto button,.auto input,.auto select{width:auto}
.l3.auto select{border:0;padding:0;background:#fe3}
.sort tbody tr{cursor:default;position:relative}
.handle{font:18px/12px Arial;vertical-align:middle}
.handle:hover{cursor:move}
.opacity{opacity:0.7}
.drag{opacity:1;top:3px;left:0}
.l1,.l2,.l3,.col4 table{width:100%}
.msg,.a{cursor:pointer}
</style>
</head><body>'.(empty($_SESSION['ok'])?'':'<div class="msg ok">'.$_SESSION['ok'].'</div>').(empty($_SESSION['err'])?'':'<div class="msg err">'.$_SESSION['err'].'</div>').'<div class="l1"><b><a href="https://github.com/edmondsql/edmongoadmin">EdMongoAdmin '.$version.'</a></b>'.(isset($ed->sg[0]) && $ed->sg[0]==50 ? "":'<ul class="mn m1"><li>More <small>&#9660;</small><ul><li><a href="'.$ed->path.'60">Info</a></li></ul></li><li><a href="'.$ed->path.'52">Users</a></li><li><a href="'.$ed->path.'51">Logout</a></li></ul>').'</div>';

if(!isset($ed->sg[0])) $ed->sg[0]=0;
switch($ed->sg[0]) {
default:
case ""://show DBs
	$ed->check();
	echo $head.$ed->menu()."<div class='col1'>".$ed->form("2")."<input type='text' name='dbn' placeholder='Database'/><br/><input type='text' name='colln' placeholder='Collection'/><br/><button type='submit'>Create</button></form></div><div class='col2'><table><tr><th>Database</th><th>Collection</th><th>Actions</th></tr>";
	foreach($ed->listdb() as $db) {
		$bg=($bg==1)?2:1;
		try {
		$tbn=count($ed->listCollection($db))."</td><td class='ce'><a href='{$ed->path}31/$db'>Exp</a><a class='del' href='{$ed->path}4/$db'>Drop</a><a href='{$ed->path}5/$db'>Browse</a>";
		}catch(Exception $e) {
		$tbn="</td><td class='ce'>Not authorized";
		}
		echo "<tr class='r c$bg'><td>$db</td><td class='ce'>$tbn</td></tr>";
	}
	echo "</table>";
break;

case "2"://create db collection
	$ed->check();
	if($ed->post('dbn','!e') && $ed->post('colln','!e')) {
		$db=$ed->sanitize($ed->post('dbn'));
		$tb=$ed->sanitize($ed->post('colln'));
		try {
		if(array_key_exists($tb,$ed->listCollection($db))) $ed->redir("5/$db",['err'=>"Collection already exists"]);
		$ed->con->commands($db,['create'=>$tb]);
		} catch(Exception $e) {
		$ed->redir("5/$db",['err'=>"Not authorized"]);
		}
		$ed->redir("5/$db",['ok'=>"Successfully created"]);
	} else $ed->redir("",['err'=>"Fields must not be empty"]);
break;

case "4"://drop db
	$ed->check([1]);
	$db=$ed->sg[1];
	try {
	$del=$ed->con->commands($db,["dropDatabase"=>1]);
	if($del->toArray()[0]->ok == 1) $ed->redir("",['ok'=>"Successfully dropped"]);
	else $ed->redir("",['err'=>"Can't drop DB"]);
	} catch(Exception $e) {
	$ed->redir("",['err'=>"Can't drop DB"]);
	}
break;

case "5"://show collections
	$ed->check([1]);
	$db=$ed->sg[1];
	try {
	$q_tb=$ed->listCollection($db);
	} catch(Exception $e) {
	$ed->redir("",['err'=>"Not authorized"]);
	}
	$totalpg=ceil(count($q_tb)/$step);
	$pg=(empty($ed->sg[2]) || $ed->sg[2] > $totalpg) ? 1 : $ed->sg[2];
	$offset=($pg - 1) * $step;
	$q_tb=array_slice($q_tb,$offset,$offset+$step);
	echo $head.$ed->menu($db,'',1)."<table><tr><th>Collections</th><th>Rows</th><th>Actions</th></tr>";
	foreach($q_tb as $k=>$r_tb) {
		$bg=($bg==1)?2:1;
		try{
		$q_n=$ed->con->num_row($db.'.'.$k)."</td><td class='ce'><a class='del' href='{$ed->path}26/$db/$k'>Drop</a><a href='{$ed->path}20/$db/$k'>Browse</a>";
		}catch(Exception $e){
		$q_n="</td><td class='ce'>Not authorized";
		}
		echo "<tr class='r c$bg'><td>$k".($r_tb=='view'?" (view)":"")."</td><td class='ce'>$q_n</td></tr>";
	}
	echo "</table>".$ed->pg_number($pg,$totalpg);
break;

case "9":
	$ed->check([1]);
	$db=$ed->sg[1];
	if($ed->post('newtb','!e')) {//rename collection
		$old=$ed->post('oldtb');
		$new=$ed->sanitize($ed->post('newtb'));
		try {
		$rtb=$ed->con->commands('admin',['renameCollection'=>$db.'.'.$old,'to'=>$db.'.'.$new]);
		} catch(Exception $e) {
		$ed->redir("5/$db",['err'=>"Not authorized"]);
		}
		if($rtb->toArray()[0]->ok == 1) $ed->redir("5/$db",['ok'=>"Successfully renamed"]);
		$ed->redir("5/$db",['err'=>"Can't rename"]);
	}
	if($ed->post('vn','!e')) {//create view
		$flds=[];
		if(!empty($ed->post('fld'))) {
		$fld=explode(",", $ed->post('fld'));
		foreach($fld as $fl) $flds[trim($fl)]=0;
		}
		$create=['create'=>$ed->sanitize($ed->post('vn')),'viewOn'=>$ed->post('tbl')];
		$opt=empty($flds) ? []:['pipeline'=>[['$project'=>$flds]]];
		try {
		$ed->con->commands($db,array_merge($create,$opt));
		} catch(Exception $e) {
		$ed->redir("5/$db",['err'=>"Can't create view"]);
		}
		$ed->redir("5/$db",['ok'=>"Successfully created view"]);
	}
	$ed->redir("5/$db",['err'=>"Check fields"]);
break;

case "15"://index
	$ed->check([1,2]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	if($ed->type=='view') $ed->redir("20/$db/$tb",['err'=>"Not authorized"]);
	if($ed->post('field','!e')) {//create
		$field=$ed->post('field'); $order=$ed->post('order');
		try {
		$ed->con->commands($db,['createIndexes'=>$tb,'indexes'=>[[
		'name'=>$field."_".$order,
		'key'=>[$field=>intval($order)],
		'unique'=>$ed->post('unique') ? true : false
		]]]);
		$ed->redir("15/$db/$tb",['ok'=>"Successfully created index"]);
		} catch(Exception $e) {
		$ed->redir("15/$db/$tb",['err'=>"Can't create index"]);
		}
	}
	if(!empty($ed->sg[3])) {//drop
		try {
		$ed->con->commands($db,['dropIndexes'=>$tb, 'index'=>$ed->sg[3]]);
		$ed->redir("15/$db/$tb",['ok'=>"Successfully dropped index"]);
		} catch(Exception $e) {
		$ed->redir("15/$db/$tb",['err'=>"Can't drop index"]);
		}
	}
	$q_r=$ed->con->select($db.'.'.$tb,[],['limit'=>1]);
	echo $head.$ed->menu($db,$tb,1);
	if(!in_array($db,$ed->deny)) echo $ed->form("15/$db/$tb")."<table><tr><td>Field</td><td><input type='text' name='field' /></td></tr>
	<tr><td>Order</td><td><select name='order'><option value='1'>ASC</option><option value='-1'>DESC</option></select></td></tr>
	<tr><td>Unique</td><td><select name='unique'><option value='0'>No</option><option value='1'>Yes</option></select></td></tr>
	<tr><td colspan='2'><button type='submit'>Create</button></td></tr></table></form>";
	echo "<table><tr><th>Name</th><th>Key</th><th>Order</th><th>Unique</th><th>Actions</th></tr>";
	foreach($ed->con->commands($db,['listIndexes'=>$tb])->toArray() as $idx) {
		$bg=($bg==1)?2:1;
		$key=key($idx->key);
		echo "<tr class='r c$bg'><td>{$idx->name}</td><td>".$key."</td><td>".($idx->key->$key==1 ? 'ASC':'DESC')."</td><td>".(empty($idx->unique)?'No':'Yes')."</td><td>".($idx->name=="_id_" ? "Primary":(in_array($db,$ed->deny)?"":"<a href='{$ed->path}15/$db/$tb/".$idx->name."'>Drop</a>"))."</td></tr>";
	}
	echo "</table>";
break;

case "20"://browse
	$ed->check([1,2]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	$sr="_mosearch_{$db}_{$tb}";
	$where=(!empty($_SESSION[$sr]) && $ed->isJson($_SESSION[$sr]['search'])) ? json_decode($_SESSION[$sr]['search'],true) : [];
	$opt=empty($_SESSION[$sr]['field'])?[]:['sort'=>[$_SESSION[$sr]['field']=>intval($_SESSION[$sr]['sort'])]];
	$all=$ed->con->num_row($db.'.'.$tb,$where,$opt);
	$totalpg=ceil($all/$step);
	if(empty($ed->sg[3])) {
	$pg=1;
	} else {
	$pg=$ed->sg[3];
	}
	$offset=($pg - 1) * $step;
	$q_rw=$ed->con->select($db.'.'.$tb,$where,array_merge(['skip'=>$offset,'limit'=>$step],$opt));

	echo $head.$ed->menu($db,$tb,1)."<table>";
	if($ed->type!='view') echo "<tr><td colspan='2'>".$ed->form("21/$db/$tb")."<textarea placeholder='{\"json\":\"format\"}' name='json'></textarea><br/><button type='submit'>Insert</button></form></td></tr>";
	if(count($q_rw)>0) {
	foreach($q_rw as $row) {
		$bg=($bg==1)?2:1;
		echo "<tr class='r c$bg'>";
		$id=$row->_id??'';
		$oid=($id instanceof MongoDB\BSON\ObjectId ? '/oid':'');
		if($ed->type!='view') echo "<td><a href='{$ed->path}22/$db/$tb/$id{$oid}'>Edit</a><a class='del' href='{$ed->path}23/$db/$tb/$id{$oid}'>Delete</a></td>";
		echo "<td>";
		$j=0;
		$r=(array)$row;
		$k=array_keys($r);
		$cols=count($r);
		while($j<$cols) {
			$c=$row->{$k[$j]};
			if($c=='') {
			$c='';
			} elseif($k[$j] == '_id') {
			$c=($ed->con->convert_id($id));
			} elseif(is_object($c)) {
			$c=$ed->con->convert_bin($c);
			} elseif(is_array($c)) {
			$c=$ed->con->convert_arr($c);
			} else {
			$c=($row->{$k[$j]});
			}
			$val="<b>".$k[$j].":</b> ".$c;
			if(strlen($val) > 70) {
			echo substr($val,0,70)."[...]";
			} else echo $val;
			++$j;
			if($j<$cols) echo ", ";
		}
		echo "</td></tr>";
	}
	}
	echo "</table>".$ed->pg_number($pg,$totalpg);
break;

case "21"://insert
	$ed->check([1,2]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	if($ed->post('json','!e')) {
		if($ed->isJson($ed->post('json'))!=true) $ed->redir("20/$db/$tb",['err'=>"Data format is not json"]);
		$ed->con->insert($db.'.'.$tb,json_decode($ed->post('json'),true));
		$ed->redir("20/$db/$tb",['ok'=>"Successfully saved"]);
	} else $ed->redir("20/$db/$tb",['err'=>"Empty data"]);
break;

case "22"://edit
	$ed->check([1,2,3]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	$id=$ed->sg[3];
	$oid=$ed->sg[4]??'';
	if(empty($id)) $ed->redir("20/$db/$tb",['err'=>"Can't edit empty field"]);
	$q_rd=$ed->con->select($db.'.'.$tb,['_id'=>$ed->con->convert_id($id,$oid)],['limit'=>1]);
	if($ed->post('edit','i')) {
		$origin=array_keys((array)$q_rd[0]);
		$post=json_decode($ed->post('json'),true)[0];
		if(empty($post)) $ed->redir("20/$db/$tb",['err'=>"Need to have minimum one field"]);
		$unset=[];
		foreach($origin as $org) {
		if($org!='_id' && !in_array($org,array_keys($post))) $unset[$org]=1;
		}
		$set=['$set'=>$post];
		if(!empty($unset)) $set=array_merge(['$unset'=>$unset],$set);
		$q=$ed->con->update($db.'.'.$tb,['_id'=>$ed->con->convert_id($id,$oid)],$set);
		if($q > 0) $ed->redir("20/$db/$tb",['ok'=>"Successfully updated"]);
		else $ed->redir("20/$db/$tb",['err'=>"Update failed"]);
	} else {
		unset($q_rd[0]->_id);
		echo $head.$ed->menu($db,$tb,1).$ed->form("22/$db/$tb/$id/$oid")."<table>
		<tr><td><textarea name='json'>".json_encode($q_rd)."</textarea></td></tr>
		<tr><td><button type='submit' name='edit'>Update</button></td></tr></table></form>";
	}
break;

case "23"://delete
	$ed->check([1,2,3]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	$id=$ed->sg[3];
	$oid=$ed->sg[4]??'';
	$del=$ed->con->delete($db.'.'.$tb,['_id'=>$ed->con->convert_id($id,$oid)]);
	if($del) $ed->redir("20/$db/$tb",['ok'=>"Successfully deleted"]);
	else $ed->redir("20/$db/$tb",['err'=>"Delete failed"]);
break;

case "24"://search
	$ed->check([1,2]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	$sr="_mosearch_{$db}_{$tb}";
	if(!empty($ed->sg[3]) && $ed->sg[3]=='reset') {
	unset($_SESSION[$sr]);
	$ed->redir("20/$db/$tb",['ok'=>"Reset search"]);
	}
	if($ed->post('search','i')) {//post
	$_SESSION[$sr]['search']=trim($ed->post('search'));
	$_SESSION[$sr]['field']=trim($ed->post('field'));
	$_SESSION[$sr]['sort']=$ed->post('sort');
	$ed->redir("20/$db/$tb");
	}
	echo $head.$ed->menu($db,$tb,1).$ed->form("24/$db/$tb");
	echo "<table><tr><td colspan='2'><textarea placeholder='{\"json\":\"format\"}' name='search'></textarea></td></tr>
	<tr class='c1'><td><input type='text' name='field' placeholder='Field name'/></td>
	<td><select name='sort'><option value='1'>ASC</option><option value='-1'>DESC</option></select></td></tr>
	<tr><td colspan='2'><button type='submit'>Search</button></td></tr></table></form>";
break;

case "25"://empty collection
	$ed->check([1,2]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	$ed->con->delete($db.'.'.$tb);
	$ed->redir("20/$db/$tb",['ok'=>"Table is empty"]);
break;

case "26"://drop collection
	$ed->check([1,2]);
	$db=$ed->sg[1];
	$tb=$ed->sg[2];
	$tbs=$ed->listCollection($db);
	$ed->con->commands($db,["drop"=>$tb]);
	$ed->redir((count($tbs)<2?"":"5/$db"),['ok'=>"Successfully dropped"]);
break;

case "30"://import
	$ed->check([1]);
	$db=$ed->sg[1];
	@set_time_limit(7200);
	$e='';
	if(empty($_FILES['importfile']['tmp_name'])) {
		$ed->redir("5/$db",['err'=>"No file to upload"]);
	} else {
		$tmp=$_FILES['importfile']['tmp_name']; $file=$_FILES['importfile']['name'];
		preg_match("/^(.*)\.(json|xml|gz|zip)$/i",$file,$fext);
		if($fext[2]=='json') {//json file
			$e=[$ed->imp_json($db,$fext[1],$tmp)];
		} elseif($fext[2]=='xml') {//xml file
			$e=[$ed->imp_xml($tmp)];
		} elseif($fext[2]=='gz') {//gz file
			if(($fgz=fopen($tmp,'r')) !==FALSE) {
			if(@fread($fgz,3) !="\x1F\x8B\x08") $ed->redir("5/$db",['err'=>"Not a valid GZ file"]);
			fclose($fgz);
			}
			if(@function_exists('gzopen')) {
				preg_match("/^(.*)\.(json|xml|tar)$/i",$fext[1],$ex);
				$gzfile=@gzopen($tmp,'rb');
				if(!$gzfile) $ed->redir("5/$db",['err'=>"Can't open GZ file"]);
				$gf='';
				while(!gzeof($gzfile)) {
				$gf.=gzgetc($gzfile);
				}
				gzclose($gzfile);
				if($ex[2]=='json') $e=[$ed->imp_json($db,$ex[1],$gf)];
				elseif($ex[2]=='xml') $e=[$ed->imp_xml($gf)];
				elseif($ex[2]=='tar') {
					$fh=gzopen($tmp,'rb');
					$fsize=strlen($gf);
					$total=0;$e=[];
					while(false !== ($block=gzread($fh,512))) {
					$total+=512;
					$t=unpack("a100name/a8mode/a8uid/a8gid/a12size/a12mtime",$block);
					$file=['name'=>$t['name'],'mode'=>@octdec($t['mode']),'uid'=>@octdec($t['uid']),'size'=>@octdec($t['size']),'mtime'=>@octdec($t['mtime'])];
					$fi=trim($file['name']);
					preg_match("/^(.*)\.(json|xml)$/i",$fi,$fx);
					$file['bytes']=($file['size'] + 511) & ~511;
					$block='';
					if($file['bytes'] > 0) {
					$block=gzread($fh,$file['bytes']);
					}
					$f_b=substr($block,0,$file['size']);
					if($fx[2]=='json') $e[]=$ed->imp_json($db,$fx[1],$f_b);
					elseif($fx[2]=='xml') $e[]=$ed->imp_xml($f_b);
					$total+=$file['bytes'];
					if($total >= $fsize-1024) break;
					}
					gzclose($fh);
				}
			} else {
				$ed->redir("5/$db",['err'=>"Can't open GZ file"]);
			}
		} elseif($fext[2]=='zip') {//zip file
			if(($fzip=fopen($tmp,'r')) !==FALSE) {
				if(@fread($fzip,4) !="\x50\x4B\x03\x04") $ed->redir("5/$db",['err'=>"Not a valid ZIP file"]);
				fclose($fzip);
			}
			$zip=new ZipArchive;
			$res=$zip->open($tmp);
			if($res === TRUE) {
				$i=0;$e=[];
				while($i < $zip->numFiles) {
				$zentry=$zip->getNameIndex($i);
				$buf=$zip->getFromName($zentry);
				preg_match("/^(.*)\.(json|xml)$/i",$zentry,$zn);
				if(!empty($zn[2])) {
				if($zn[2]=='json') $e[]=$ed->imp_json($db,$zn[1],$buf);
				elseif($zn[2]=='xml') $e[]=$ed->imp_xml($buf);
				}
				++$i;
				}
				$zip->close();
			}
		}
	}
	echo $head.$ed->menu($db,'',1);
	if(!empty($e) && is_array($e)) {
	$e=call_user_func_array('array_merge',$e);
	foreach($e as $k=>$q) {
	$k=key($q);
	echo "<p>[$k]: ".($q[$k]['ok']??'').($q[$k]['err']??'')."</p>";
	}
	}
break;

case "31"://export form
	$ed->check([1]);
	$db=$ed->sg[1];
	$q_tb=$ed->listCollection($db);
	if(count($q_tb) < 1) $ed->redir("5/$db",["err"=>"No export empty DB"]);
	echo $head.$ed->menu($db,'',2).$ed->form("32/$db")."<div class='dw'><h3 class='l1'>Export</h3><h3>Select collection(s)</h3>
	<p><input type='checkbox' onclick='selectall(this,\"tbs\");dbx()' /> All/None</p>
	<select id='tbs' name='tbs[]' multiple='multiple' onchange='dbx()'>";
	foreach($q_tb as $k=>$r_tb) {
	if($r_tb!='view' && substr($k,0,7)!='system.') echo "<option value='$k'>$k</option>";
	}
	echo "</select><h3>File format</h3>";
	$ffo=['json'=>'JSON','xml'=>'XML'];
	foreach($ffo as $k=> $ff) echo "<p><input type='radio' name='ffmt[]' onclick='opt()' value='$k'".($k=='json' ? ' checked':'')." /> $ff</p>";
	echo "<h3>File compression</h3><p><select name='ftype'>";
	$fty=['plain'=>'None','zip'=>'Zip','gz'=>'GZ'];
	foreach($fty as $k=> $ft) echo "<option value='$k'>$ft</option>";
	echo "</select></p><button type='submit' name='exp'>Export</button></div></form>";
break;

case "32"://export
	$ed->check([1]);
	$dbs=$ed->sg[1]; $tbs=$ed->post('tbs');
	$ftype=$ed->post('ftype'); $ffmt=$ed->post('ffmt');
	if(empty($tbs)) $ed->redir("31/$dbs",['err'=>"You didn't selected any collection"]);
	if($ffmt[0]=='json') {//json
		$ffty="text/json"; $ffext=".json"; $fname=$dbs.$ffext;
		$sql=[];
		foreach($tbs as $tb) {
			$sq='';
			try {
			$q_rw=$ed->con->select($dbs.'.'.$tb);
			} catch(Exception $e) {
			$ed->redir("31/$dbs",['err'=>"Not authorized to export `$dbs.$tb`"]);
			}
			if(!empty($q_rw)) {
			$sq.='[';
			foreach($q_rw as $r_rw) {
			$jh='{';
			foreach($r_rw as $k=>$v) {
			if($k=="_id") {
			$v=$ed->con->convert_id($v);
			} elseif(is_object($v)) {
			$v=$ed->con->convert_bin($v);
			} elseif(is_array($v)) {
			$v=$ed->con->convert_arr($v);
			}
			$jh.='"'.$k.'":'.($ed->isJson($v)?$v:'"'.$v.'"').',';
			}
			$sq.=substr($jh,0,-1).'},';
			}
			$sq=substr($sq,0,-1).']';
			}
			$sql[$tb.$ffext]=$sq;
		}
		if($ftype=="plain" || count($tbs)<2) {
		$fname=$tbs[0].$ffext;
		$sql=$sql[$fname];
		}
	} elseif($ffmt[0]=='xml') {//xml
		$ffty="application/xml"; $ffext=".xml"; $fname=$dbs.$ffext;
		$sql='<?xml version="1.0" encoding="utf-8"?>';
		$sql.="\n<!-- EdMongoAdmin $version XML Dump -->";
		$sql.="\n<export version=\"1.0\" xmlns:ed=\"https://github.com/edmondsql\">";
		$sql.="\n\t<database name=\"$dbs\">";
		$sq='';
		foreach($tbs as $tb) {
			try {
			$q_rw=$ed->con->select($dbs.'.'.$tb);
			} catch(Exception $e) {
			$ed->redir("31/$dbs",['err'=>"Not authorized to export `$dbs.$tb`"]);
			}
			if(!empty($q_rw)) {
			foreach($q_rw as $r_rw) {
				$sq.="\n\t\t<table name=\"$tb\">";
				foreach($r_rw as $k=>$v) {
				if($k=="_id") {
				$v=$ed->con->convert_id($v);
				} elseif(is_object($v)) {
				$v=$ed->con->convert_bin($v);
				} elseif(is_array($v)) {
				$v=$ed->con->convert_arr($v);
				}
				$sq.="\n\t\t\t<column name=\"".$k."\">".($ed->isJson($v)?$v:addslashes(htmlspecialchars($v)))."</column>";
				}
				$sq.="\n\t\t</table>";
			}
			}
		}
		$sql.=$sq."\n\t</database>\n</export>";
	}

	if($ftype=="gz") {//gz
		$zty="application/x-gzip"; $zext=".gz";
		ini_set('zlib.output_compression','Off');
		if(is_array($sql) && count($sql)>1) {
		$sq='';
		foreach($sql as $qname=>$sqa) {
			$tmpf=tmpfile();
			$len=strlen($sqa);
			$ctxt=pack("a100a8a8a8a12a12",$qname,644,0,0,decoct($len),decoct(time()));
			$checksum=8*32;
			for($i=0; $i < strlen($ctxt); $i++) $checksum +=ord($ctxt[$i]);
			$ctxt.=sprintf("%06o",$checksum)."\0 ";
			$ctxt.=str_repeat("\0",512 - strlen($ctxt));
			$ctxt.=$sqa;
			$ctxt.=str_repeat("\0",511 - ($len + 511) % 512);
			fwrite($tmpf,$ctxt);
			fseek($tmpf,0);
			$fs=fstat($tmpf);
			$sq.=fread($tmpf,$fs['size']);
			fclose($tmpf);
		}
		$fname=$fname.".tar";
		$sql=$sq.pack('a1024','');
		}
		$sql=gzencode($sql,9);
		header('Accept-Encoding: gzip;q=0,deflate;q=0');
	} elseif($ftype=="zip") {//zip
		$zty="application/x-zip";
		$zext=".zip";
		$info=[];
		$ctrl_dir=[];
		$eof="\x50\x4b\x05\x06\x00\x00\x00\x00";
		$old_offset=0;
		if(is_array($sql)) $sqlx=$sql;
		else $sqlx[$fname]=$sql;
		foreach($sqlx as $qname=>$sqa) {
		$ti=getdate();
		if($ti['year'] < 1980) {
		$ti['year']=1980;$ti['mon']=1;$ti['mday']=1;$ti['hours']=0;$ti['minutes']=0;$ti['seconds']=0;
		}
		$time=(($ti['year'] - 1980) << 25) | ($ti['mon'] << 21) | ($ti['mday'] << 16) | ($ti['hours'] << 11) | ($ti['minutes'] << 5) | ($ti['seconds'] >> 1);
		$dtime=substr("00000000".dechex($time),-8);
		$hexdtime='\x'.$dtime[6].$dtime[7].'\x'.$dtime[4].$dtime[5].'\x'.$dtime[2].$dtime[3].'\x'.$dtime[0].$dtime[1];
		eval('$hexdtime="'.$hexdtime.'";');
		$fr="\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00".$hexdtime;
		$unc_len=strlen($sqa);
		$crc=crc32($sqa);
		$zdata=gzcompress($sqa);
		$zdata=substr(substr($zdata,0,strlen($zdata) - 4),2);
		$c_len=strlen($zdata);
		$fr.=pack('V',$crc).pack('V',$c_len).pack('V',$unc_len).pack('v',strlen($qname)).pack('v',0).$qname.$zdata;
		$info[]=$fr;
		$cdrec="\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00".$hexdtime.
		pack('V',$crc).pack('V',$c_len).pack('V',$unc_len).pack('v',strlen($qname)).
		pack('v',0).pack('v',0).pack('v',0).pack('v',0).pack('V',32).pack('V',$old_offset);
		$old_offset +=strlen($fr);
		$cdrec.=$qname;
		$ctrl_dir[]=$cdrec;
		}
		$ctrldir=implode('',$ctrl_dir);
		$end=$ctrldir.$eof.pack('v',sizeof($ctrl_dir)).pack('v',sizeof($ctrl_dir)).pack('V',strlen($ctrldir)).pack('V',$old_offset)."\x00\x00";
		$datax=implode('',$info);
		$sql=$datax.$end;
	}
	header("Cache-Control: no-store,no-cache,must-revalidate,pre-check=0,post-check=0,max-age=0");
	header("Content-Type: ".($ftype=="plain" ? $ffty."; charset=utf-8":$zty));
	header("Content-Length: ".strlen($sql));
	header("Content-Disposition: attachment; filename=".$fname.($ftype=="plain" ? "":$zext));
	die($sql);
break;

case "50"://login
	if($ed->post('lhost','!e') && $ed->post('username','i') && $ed->post('password','i')) {
	$_SESSION['host']=$ed->post('lhost');
	$_SESSION['user']=$ed->post('username');
	$_SESSION['token']=$ed->enco($ed->post('password'));
	$_SESSION['db']=$ed->post('db');
	$ed->redir();
	}
	session_unset();
	session_destroy();
	echo $head.$ed->menu('','',2).$ed->form("50")."<div class='dw'><h3>LOGIN</h3>
	<div>Host<br/><input type='text' id='host' name='lhost' value='localhost:27017'/></div>
	<div>Username<br/><input type='text' name='username' value=''/></div>
	<div>Password<br/><input type='password' name='password'/></div>
	<div>Database<br/><input type='text' name='db'/></div>
	<div><button type='submit'>Login</button></div></div></form>";
break;

case "51"://logout
	$ed->check();
	session_unset();
	session_destroy();
	$ed->redir();
break;

case "52"://users
	$ed->check([4]);
	echo $head.$ed->menu(1,'',2)."<table><tr><th>User</th><th>DB</th><th colspan='2'><a href='{$ed->path}53'>Add</a></th></tr>";
	foreach($ed->listdb() as $db) {
		$bg=($bg==1)?2:1;
		$users=$ed->con->select($db.'.system.users');
		foreach($users as $user) {
		$r=[];
		foreach($user->roles as $rl) $r[]=$rl->role;
		$udb=$user->db;
		$user=$user->user;
		$r=implode(",",$r);
		echo "<tr class='r c$bg'><td>$user</td><td>$udb</td><td><a class='del' href='{$ed->path}59/$udb/$user'>Drop</a></td><td>".
		$ed->form("53/$udb/$user")."<input type='hidden' name='role' value='{$r}'><button type='submit' name='edit'>Edit</button>
		</form></td></tr>";
		}
	}
	echo "</table>";
break;

case "53"://add,edit,update user
	$ed->check([4]);
	$db=$ed->sg[1]??'';
	$user=$ed->sg[2]??'';
	$r=$ed->post('role')??[];
	if($ed->post('save','i')) {
	$dbu=$db?:$ed->post('db');
	$user=$user?:$ed->post('username');
	if(empty($user) || empty($r) || $ed->post('password','e')) $ed->redir('53',['err'=>"All fields required"]);
	$roles=[];
	foreach($r as $ro) array_push($roles,["role"=>$ro,"db"=>$dbu]);
	$op=($db==''?"createUser":"updateUser");
	$pwd=["pwd"=>$ed->post('password')];
	$usr=[$op=>$user,"roles"=>$roles];
	$ed->con->commands($dbu,array_merge($usr,$pwd));
	$ed->redir('52',['ok'=>"User ".($db==''?"created":"updated")]);
	}
	$r=explode(",",$r);
	echo $head.$ed->menu(1,'',2).$ed->form("53".($db==''?"":"/$db/$user"))."<table><tr><th colspan='2'>User</th></tr>
	<tr><td>Name </td><td><input type='text' name='username' value='$user'".($db==''?'':' disabled')."/></td></tr>
	<tr><td>Password </td><td><input type='password' name='password'/></td></tr>
	<tr><td>Role </td><td><select name='role[]' multiple>";
	$role=['read','readWrite','dbAdmin','userAdmin','dbOwner','readAnyDatabase','readWriteAnyDatabase','userAdminAnyDatabase','dbAdminAnyDatabase','root'];
	foreach($role as $rl) {
		echo "<option value='$rl'".(in_array($rl,$r)?" selected":"").">$rl</option>";
	}
	echo "</select></td></tr><tr><td>DB </td><td><select name='db'".($db==''?'':' disabled').">";
	array_shift($ed->deny);
	foreach($ed->listdb() as $dbs) {
		if(!in_array($dbs,$ed->deny)) echo "<option value='$dbs'".($dbs==$db?" selected":"").">$dbs</option>";
	}
	echo "</select></td></tr><tr><td colspan='2' class='c1'><button type='submit' name='save'>Save</button></td></tr></table>";
break;

case "59"://drop user
	$ed->check([1,4]);
	$db=$ed->sg[1];
	$user=$ed->sg[2];
	if(!empty($db) && !empty($user)) {
	try {
	$ed->con->commands($db,["dropUser"=>$user]);
	} catch(Exception $e) {
	$ed->redir("52",['err'=>"Not authorized"]);
	}
	$ed->redir('52',['ok'=>"User dropped"]);
	} else {
	$ed->redir('52',['err'=>"Not authorized"]);
	}
break;

case "60"://info
	$ed->check();
	echo $head.$ed->menu(1,'',2)."<table><tr><th colspan='2'>INFO</th></tr>";
	$q_var=['Php_mongodb'=>phpversion('mongodb'),'Mongodb'=>$ed->con->commands("admin",["buildInfo"=>1])->toArray()[0]->version,'Php'=>PHP_VERSION,'Software'=>$_SERVER['SERVER_SOFTWARE']];
	foreach($q_var as $r_k=>$r_var) {
	$bg=($bg==1)?2:1;
	echo "<tr class='r c$bg'><td>$r_k</td><td>$r_var</td></tr>";
	}
	echo "</table>";
break;
}
$ed->con=null;
unset($_POST,$_SESSION["ok"],$_SESSION["err"]);
?></div></div><div class="l1 ce"><a href="http://edmondsql.github.io">edmondsql</a></div>
<script>
let msg=document.querySelectorAll(".msg");
document.querySelectorAll(".del").forEach(d=>{
d.addEventListener('click',(e)=>{
e.preventDefault();
msg.forEach(m=>m.remove());
let hrf=e.target.getAttribute("href"),nMsg=document.createElement("div"),nOk=document.createElement("div"),nEr=document.createElement("div");
nMsg.className='msg';
nOk.className='ok';nOk.innerText='Yes';
nEr.className='err';nEr.innerText='No';
nMsg.appendChild(nOk);nMsg.lastChild.onclick=()=>window.location=hrf;
nMsg.appendChild(nEr);nMsg.lastChild.onclick=()=>nMsg.remove();
document.body.appendChild(nMsg);
document.body.addEventListener('keyup',(e)=>{
e.preventDefault();
let key=e.which||e.keyCode||e.key||0;
if(key==32||key==89)window.location=hrf;
if(key==27||key==78)nMsg.remove();
});
});
});
msg.forEach(m=>{if(m.innerText!="")setTimeout(()=>{m.remove()},7000);m.addEventListener('dblclick',()=>m.remove())});
function selectall(cb,lb){
let i,multi=document.getElementById(lb);
if(cb.checked) for(i=0;i<multi.options.length;i++) multi.options[i].selected=true;
else multi.selectedIndex=-1;
}
function toggle(cb,el){
let i,cbox=document.getElementsByName(el);
for(i=0;i<cbox.length;i++) cbox[i].checked=cb.checked;
}
function dbx(){
let ft=document.getElementsByName("ftype")[0],db=document.querySelectorAll("#tbs option:checked").length;
if(db<2 && ft[0].value!="plain"){
let op=document.createElement("option");
op.value="plain";op.text="None";
ft.options.add(op,0);
ft.options[0].selected=true;
}else if(db>1 && ft[0].value=="plain")ft[0].remove();
}
</script>
</body></html>