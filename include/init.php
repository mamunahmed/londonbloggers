<?
	#
	# $Id$
	#

	#
	# some startup tasks which come before anything else:
	#  * set up the timezone
	#  * record the time
	#  * set the mbstring encoding
	#

	putenv('TZ=PST8PDT');
	date_default_timezone_set('America/Los_Angeles');

	$GLOBALS['timings'] = array();
	$GLOBALS['timings']['execution_start'] = microtime_ms();
	$GLOBALS['timing_keys'] = array();

	mb_internal_encoding('UTF-8');


	#
	# the module loading code.
	#
	# we track which modules we've loaded ourselves instead of
	# using include_once(). we do this so that we can avoid the
	# stat() overhead involved in figuring out the canonical path
	# to a file. so long as we always load modules via this
	# method, we save some filesystem overhead.
	#
	# we can also ensure that modules don't pollute the global
	# namespace accidentally, since they are always loaded in a
	# function's private scope.
	#

	$GLOBALS['loaded_libs'] = array();

	define('INCLUDE_DIR', dirname(__FILE__));

	function loadlib($name){

		if ($GLOBALS['loaded_libs'][$name]) return;
		$GLOBALS['loaded_libs'][$name] = 1;

		include(INCLUDE_DIR."/lib_$name.php");
	}

	function loadpear($name){

		if ($GLOBALS['loaded_libs']['PEAR:'.$name]) return;
		$GLOBALS['loaded_libs']['PEAR:'.$name] = 1;

		include(INCLUDE_DIR."/pear/$name.php");
	}


	#
	# load config
	#

	include(INCLUDE_DIR."/config.php");


	#
	# install an error handler to check for dubious notices?
	# we do this because we only care about one of the notices
	# that gets generated. we only want to run this code in
	# devel environments. we also want to run it before any
	# libraries get loaded so that we get to check their syntax.
	#

	if ($cfg['check_notices']){
		set_error_handler('handle_error_notices', E_NOTICE);
		error_reporting(E_ALL | E_STRICT);
	}

	function handle_error_notices($errno, $errstr){
		if (preg_match('!^Use of undefined constant!', $errstr)) return false;
		return true;
	}


	#
	# figure out some global flags
	#

	$this_is_apache		= strlen($_SERVER['REQUEST_URI']) ? 1 : 0;
	$this_is_shell		= $_SERVER['SHELL'] ? 1 : 0;
	$this_is_webpage	= $this_is_apache && !$this_is_api ? 1 : 0;


	#
	# load some libraries which we will 'always' need
	#

	loadlib('log');		# logging comes first, so that other modules can log during startup
	loadlib('smarty');	# smarty comes next, since other libs register smarty modules


	#
	# we check env after loading lib_log but before lib_db, since
	# we want to set up logging correctly before we connect to the DB.
	#

	if ($cfg['environment'] == 'dev'){
		$cfg['is_admin'] = 1;
	}else{
		$cfg['is_admin'] = 0;
		$GLOBALS['log_handlers']['notice'] = array();
	}


	#
	# load the rest of the usual suspects
	#

	loadlib('error');
	loadlib('db');
	loadlib('email');
	loadlib('utf8');
	loadlib('http');


	#
	# disable precaching
	#

	if (StrToLower($_SERVER['HTTP_X_MOZ']) == 'prefetch'){

		if (!$allow_precache){

			error_forbidden();
		}
	}


	#
	# general utility functions
	#

	function dumper($foo){
		echo "<pre style=\"text-align: left;\">";
		echo HtmlSpecialChars(var_export($foo, 1));
		echo "</pre>\n";
	}

	function intval_range($in, $lo, $hi){
		return min(max(intval($in), $lo), $hi);
	}

	function microtime_ms(){
		list($usec, $sec) = explode(" ", microtime());
		return intval(1000 * ((float)$usec + (float)$sec));
	}


	#
	# this timer stores the end of core library loading
	#

	$GLOBALS['timings']['init_end'] = microtime_ms();
?>
