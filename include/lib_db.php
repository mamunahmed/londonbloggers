<?
	#
	# $Id$
	#

	$GLOBALS['db_conns'] = array();

	$GLOBALS['timings']['db_conns_count']	= 0;
	$GLOBALS['timings']['db_conns_time']	= 0;
	$GLOBALS['timings']['db_queries_count']	= 0;
	$GLOBALS['timings']['db_queries_time']	= 0;
	$GLOBALS['timings']['db_rows_count']	= 0;
	$GLOBALS['timings']['db_rows_time']		= 0;

	$GLOBALS['timing_keys']['db_conns']		= 'DB Connections';
	$GLOBALS['timing_keys']['db_queries']	= 'DB Queries';
	$GLOBALS['timing_keys']['db_rows']		= 'DB Rows Returned';

	#################################################################

	#
	# These are just shortcuts to the real functions which allow
	# us to skip passing the cluster name. these are the only functions
	# we should call from outside the library.
	#
	# In this example we have 2 cluster - one monolith called 'main' and
	# one partitioned/sharded cluster called 'users' When making calls
	# to the sharded cluster, we need to pass the shard number as the first
	# argument.
	#

	function db_insert($tbl, $hash){		return _db_insert($tbl, $hash, 'main'); }
	function db_insert_users($k, $tbl, $hash){	return _db_insert($tbl, $hash, 'main', $k); }

	function db_insert_dupe($tbl, $hash, $hash2){		return _db_insert_dupe($tbl, $hash, $hash2, 'main'); }
	function db_insert_dupe_users($k, $tbl, $hash, $hash2){	return _db_insert_dupe($tbl, $hash, $hash2, 'users', $k); }

	function db_update($tbl, $hash, $where){		return _db_update($tbl, $hash, $where, 'main'); }
	function db_update_users($k, $tbl, $hash, $where){	return _db_update($tbl, $hash, $where, 'users', $k); }

	function db_fetch($sql){		return _db_fetch($sql, 'main'); }
	function db_fetch_users($k, $sql){	return _db_fetch($sql, 'users', $k); }

	function db_fetch_paginated($sql, $args){		return _db_fetch_paginated($sql, $args, 'main'); }
	function db_fetch_paginated_users($k, $sql, $args){	return _db_fetch_paginated($sql, $args, 'users', $k); }

	function db_write($sql){		return _db_write($sql, 'main'); }
	function db_write_users($k, $sql){	return _db_write($sql, 'main', $k); }


	#
	# we connect to the main cluster immediately so that we can show
	# a downtime notice it's it's not available. you might not want to
	# so this - depends on whether you can ever stand the main cluster
	# being down.
	#

	_db_connect('main');

	#################################################################

	function _db_connect($cluster, $k=null){

		$cluster_key = $k ? "{$cluster}-{$k}" : $cluster;

		$host = $GLOBALS['cfg']["db_{$cluster}"]["host"];
		$user = $GLOBALS['cfg']["db_{$cluster}"]["user"];
		$pass = $GLOBALS['cfg']["db_{$cluster}"]["pass"];
		$name = $GLOBALS['cfg']["db_{$cluster}"]["name"];

		if ($k){
			$host = $host[$k];
			$name = $name[$k];
		}

		if (!$host){
			log_fatal("no such cluster: ".$cluster);
		}


		#
		# try to connect
		#

		$start = microtime_ms();

		$GLOBALS['db_conns'][$cluster_key] = @mysql_connect($host, $user, $pass, 1);

		if ($GLOBALS['db_conns'][$cluster_key]){
			@mysql_select_db($name, $GLOBALS['db_conns'][$cluster_key]);
			@mysql_query("SET character_set_results='utf8', character_set_client='utf8', character_set_connection='utf8', character_set_database='utf8', character_set_server='utf8'", $GLOBALS['db_conns'][$cluster_key]);
		}

		$end = microtime_ms();


		#
		# log
		#

		log_notice('db', "DB-$cluster_key: Connect", $end-$start);

		if (!$GLOBALS['db_conns'][$cluster_key] || $_GET['no_db']){

			log_fatal("Connection to database cluster '$cluster_key' failed");
		}


		$GLOBALS['timings']['db_conns_count']++;
		$GLOBALS['timings']['db_conns_time'] += $end-$start;
	}

	#################################################################

	function _db_query($sql, $cluster, $k=null){

		$cluster_key = $k ? "{$cluster}-{$k}" : $cluster;

		if (!$GLOBALS['db_conns'][$cluster_key]){
			_db_connect($cluster, $k);
		}

		$start = microtime_ms();
		$result = @mysql_query($sql, $GLOBALS['db_conns'][$cluster_key]);
		$end = microtime_ms();

		$GLOBALS['timings']['db_queries_count']++;
		$GLOBALS['timings']['db_queries_time'] += $end-$start;

		log_notice('db', "DB-$cluster_key: $sql", $end-$start);

		if (!$result){
			$error_msg	= mysql_error($GLOBALS['db_conns'][$cluster_key]);
			$error_code	= mysql_errno($GLOBALS['db_conns'][$cluster_key]);

			log_error("DB-$cluster_key: $error_code ".HtmlSpecialChars($error_msg));

			return array(
				'ok'		=> 0,
				'error'		=> $error_msg,
				'error_code'	=> $error_code,
				'sql'		=> $sql,
				'cluster'	=> $cluster,
				'shard'		=> $k,
			);
		}

		return array(
			'ok'		=> 1,
			'result'	=> $result,
			'sql'		=> $sql,
			'cluster'	=> $cluster,
			'shard'		=> $k,
		);
	}

	#################################################################

	function _db_insert($tbl, $hash, $cluster, $k=null){

		$fields = array_keys($hash);

		return _db_write("INSERT INTO $tbl (`".implode('`,`',$fields)."`) VALUES ('".implode("','",$hash)."')", $cluster, $k);
	}

	#################################################################

	function _db_insert_dupe($tbl, $hash, $hash2, $cluster, $shard=null){

		$fields = array_keys($hash);

		$bits = array();
		foreach(array_keys($hash2) as $k){
			$bits[] = "`$k`='$hash2[$k]'";
		}

		return _db_write("INSERT INTO $tbl (`".implode('`,`',$fields)."`) VALUES ('".implode("','",$hash)."') ON DUPLICATE KEY UPDATE ".implode(', ',$bits), $cluster, $shard);
	}

	#################################################################

	function _db_update($tbl, $hash, $where, $cluster, $shard=null){

		$bits = array();
		foreach(array_keys($hash) as $k){
			$bits[] = "`$k`='$hash[$k]'";
		}

		return _db_write("UPDATE $tbl SET ".implode(', ',$bits)." WHERE $where", $cluster, $shard);
	}

	#################################################################

	function db_escape_like($string){
		return str_replace(array('%','_'), array('\\%','\\_'), $string);
	}

	function db_escape_rlike($string){
		return preg_replace("/([.\[\]*^\$()])/", '\\\$1', $string);
	}

	#################################################################

	function _db_fetch($sql, $cluster, $k=null){

		$ret = _db_query($sql, $cluster, $k);

		if (!$ret['ok']) return $ret;

		$out = $ret;
		$out['ok'] = 1;
		$out['rows'] = array();
		unset($out['result']);

		$start = microtime_ms();
		$count = 0;
		while ($row = mysql_fetch_array($ret['result'], MYSQL_ASSOC)){
			$out['rows'][] = $row;
			$count++;
		}
		$end = microtime_ms();
		$GLOBALS['timings']['db_rows_count'] += $count;
		$GLOBALS['timings']['db_rows_time'] += $end-$start;

		return $out;
	}

	#################################################################

	function _db_fetch_paginated($sql, $args, $cluster, $k=null){

		#
		# Setup some defaults
		#

		$page		= isset($args['page'])		? max(1, $args['page'])		: 1;
		$per_page	= isset($args['per_page'])	? max(1, $args['per_page'])	: 10;
		$spill		= isset($args['spill'])		? max(0, $args['spill'])	: 2;

		if ($spill >= $per_page) $spill = $per_page - 1;


		#
		# figure out what we're dealing with
		# (yes, this is a horrible hack)
		#

		$ret = _db_fetch(preg_replace(array('/^SELECT .* FROM/i', '/ ORDER BY .*$/'), array('SELECT COUNT(*) FROM', ''), $sql), $cluster, $k);
		if (!$ret['ok']) return $ret;

		$total_count = array_pop($ret['rows'][0]);
		$page_count = ceil($total_count / $per_page);


		#
		# generate limit values
		#

		$start = ($page - 1) * $per_page;
		$limit = $per_page;

		$last_page_count = $total_count - (($page_count - 1) * $per_page);

		if ($last_page_count <= $spill && $page_count > 1){
			$page_count--;
		}

		if ($page == $page_count){
			$limit += $spill;
		}


		#
		# build sql
		#

		$sql .= " LIMIT $start, $limit";

		$ret = _db_fetch($sql, $cluster, $k);

		$ret['pagination'] = array(
			'total_count' => $total_count,
			'page' => $page,
			'per_page' => $per_page,
			'page_count' => $page_count,
		);

		return $ret;
	}

	#################################################################

	function _db_write($sql, $cluster, $k=null){

		$cluster_key = $k ? "{$cluster}-{$k}" : $cluster;

		$ret = _db_query($sql, $cluster, $k);

		if (!$ret['ok']) return $ret;

		return array(
			'ok'		=> 1,
			'affected_rows'	=> mysql_affected_rows($GLOBALS['db_conns'][$cluster_key]),
			'insert_id'	=> mysql_insert_id($GLOBALS['db_conns'][$cluster_key]),
		);
	}

	#################################################################

	function db_single($ret){
		return $ret['ok'] && count($ret['rows']) ? $ret['rows'][0] : FALSE;
	}

	function db_list($ret){
		return $ret['ok'] && count($ret['rows']) ? array_values($ret['rows'][0]) : FALSE;
	}

	#################################################################

	function db_get_shard($cluster){

		if ($cluster == 'users') return rand(1,2);

		return 0;
	}

	function db_shards($cluster){

		return array_keys($GLOBALS['cfg']["db_{$cluster}"]['host']);
	}

	#################################################################
?>
