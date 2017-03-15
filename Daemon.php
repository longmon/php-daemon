<?php namespace longmon\php;

use \swoole_process;

class Daemon
{
	private $index 		= 0;
	private $master_pid 	= 0;
	private $proc_name 	= "php-daemon";
	private $worker 	= array();
	private $logdir		= "./log";
	private $runtime_dir= "./run";
	private $pid_file 	= "./run/php-daemon.pid";
	private $task		= NULL;
	private $proc_num	= 1;
	private static $run_mode = 0; //0表示单任务版本， 1表示多任务版本
	private $reboot     = 0; //1表示子进程退出，
	
	private static $instance = NULL;
	
	/**
	 * 单任务入口
	 */
	public static function singleTaskInstance($proc_name, $proc_num = 1){
		self::$run_mode = 0;
		if( self::$instance == NULL ){
			self::$instance = new self($proc_name, $proc_num);
		}
		return self::$instance;
	}
	
	/**
	 * 多任务入口
	 */
	public static function multiTaskInstance($proc_name, $proc_num = 1){
		self::$run_mode = 1;
		if( self::$instance != NULL ){
			self::$instance = new self($proc_name, $proc_num);
		}
		return self::$instance;
	}
	
	public function __clone(){
		return NULL;
	}

	/**
	 * 构造函数
	 */
	private function __construct( $proc_name, $proc_num = 1 )
	{
		self::load_config();
		
		if( !Helper::make_dir($this->logdir) || !Helper::make_dir($this->runtime_dir) ){
			exit();
		}
		if( !function_exists("swoole_set_process_name") )
		{
			trigger_error("swoole module dose not installed!", E_USER_WARNING );
			Helper::warning("swoole module dose not installed!", E_USER_WARNING );
			exit;
		}
		swoole_process::daemon(); //  set daemon mode

		$this->proc_num = $proc_num?$proc_num:$this->proc_num;
		
		$this->proc_name = $proc_name?$proc_name:$this->proc_name;
		
		$this->pid_file = $this->runtime_dir."/".$this->proc_name.".pid";
		
		$this->master_pid = posix_getpid();
		
		$this->concurrent_control(); //并发控制
		
		swoole_set_process_name( $this->proc_name ." master process" );

		$this->register_signal_handler(); //信号控制注册
	}

	/**
	 * 执行开始
	 */
	public function run( $task = NULL )
	{
		if($task) {$this->task = $task;}
		if( self::$run_mode == 0 ){
			return $this->single_task_run();
		}else{
			return $this->multi_task_run();
		}
	}
	
	public function single_task_run()
	{
		if( count($this->worker) > 0 ){
			return true;
		}
		if( !$this->task ){return false;};
		for( $i = 0; $i < $this->proc_num; $i++ ){
			$this->create_sub_process($i);
		}
		//Helper::write_pid_file($this->pid_file, ["worker"=>$pid]);
		return true;
	}
	
	public function multi_task_run()
	{
		if( count($this->worker) > 0 ){
			return true;
		}
		$jobArray = Helper::import("job.php"); //job文件必须返回指定格式的数组
		if(empty($jobArray) || !is_array($jobArray) ){
			return false;
		}
		$no = 0;
		foreach( $jobArray as $name => $job ){
			if(!isset($job['task'])){
				continue;
			}
			$task = $job['task'];
			$proc_num = isset($job['proc_num'])?intval($job['proc_num']):0;
			for( $m = 0; $m < $proc_num; $m++ ){
				$this->create_sub_process($no, $name, $task);
				$no++;
			}
		}
		return true;
	}
	
	/**
	 * 设置任务 - 多任务模式下无效
	 */
	public function setTask($task){
		$this->task = $task;
		if(self::$mode == 1){
			Helper::warning("setting task in multiple task mode is unavailable!", E_USER_NOTICE);
			return false;
		}
	}
	
	/**
	 * 重启服务
	 */
	public static function reload($name){
		$conf = self::static_get_config();
		$pid_file = $conf['runtime_dir']."/".$name.".pid";
		$pid = Helper::get_pid_from_file($pid_file);
		swoole_process::kill($pid, SIGUSR1);
	}
	
	/**
	 * 创建子进程
	 */
	private function create_sub_process( $index, $sub_proc_name = NULL, $sub_proc_task = NULL )
	{
		$proc = new swoole_process(function( swoole_process $worker)use($index, $sub_proc_name, $sub_proc_task){
			
			$worker_name = $this->proc_name.( $sub_proc_name?" ".$sub_proc_name:" ")."worker";
			
			//$this->register_subproc_signal_handler();
			
			swoole_set_process_name($worker_name);
			
			$task = $sub_proc_task?$sub_proc_task:$this->task;
			
			$this->task_loop($task, $worker );
			
		}, false );
		
		$pid = $proc->start();
		$this->worker[$index] = ["pid"=>$pid, "name"=>$sub_proc_name, "task"=>$sub_proc_task];
		return $pid;
	}
	
		/**
	 * 循环处理任务
	 * 要求任务体内不得出现无限循环的逻辑, 否则
	 */
	private function task_loop($task, &$worker )
	{
		for(;;){
			$this->process_task( $task );
			$this->detect_master_alive( $worker );
		}
	}
	
	/**
	 * 任务处理
	 */
	private function process_task($callFunc)
	{
		if(is_callable($callFunc) )
		{
			return call_user_func($callFunc);
		}
		else if(is_array($callFunc) && count($callFunc) >= 2 ){
			list($obj, $method, $param ) = $callFunc;
			if( !$param ){
				$param = [];
			}elseif(!is_array($param)){
				$param = [$param];
			}
			if( $obj ){
				return call_user_func_array([$obj, $method], $param);
			}else{
				return call_user_func_array($method, $param);
			}
		}else{
			return false;
		}
	}

	
	/**
	 * 检查主进程是否存活
	 */
	public function detect_master_alive(&$worker)
	{
		if( !swoole_process::kill($this->master_pid, 0) ){
			$worker->exit();
		}
	}
	
	/**
	 * 并发控制处理
	 */
	public function concurrent_control()
	{
		Helper::log("php-daemon starting ...", $this->logdir."/run.log");
		if($pid = Helper::get_pid_from_file($this->pid_file)){
			var_dump($pid);
			if( swoole_process::kill($pid, 0) ){
				Helper::log(" failed!(master process has been started with pid:{$pid})\n", $this->logdir."/run.log", true );
				exit(0);
			}
		}
		if( !Helper::write_pid_file($this->pid_file, $this->master_pid) ){
			Helper::log(" failed!(write to pid file failed)\n", $this->logdir."/run.log", true);
			exit(0);
		}
		Helper::log(" Sucess!\n", $this->logdir."/run.log", true);
		return;
	}
	
	public function register_signal_handler()
	{
		swoole_process::signal(SIGUSR1, function($signo){
			Helper::log("Caught a SIGUSR1({$signo}) signal\n", $this->logdir."/run.log");
			$this->reboot_all_process();
		});
		
		swoole_process::signal(SIGUSR2, function($signo){
			Helper::log("Caught a SIGUSR2({$signo}) signal\n", $this->logdir."/run.log");
			$this->kill_all_sub_process();
		});
		
		swoole_process::signal(SIGTERM, function($signo){
			Helper::log("Caught a SIGTERM({$signo}) signal\n", $this->logdir."/run.log");
			unlink($this->pid_file);
			swoole_process::kill($this->master_pid, SIGKILL);
		});
		
		swoole_process::signal(SIGCHLD, function($signo){
			Helper::log("Caught a SIGCHLD({$signo}) signal\n", $this->logdir."/run.log");
			if($ret = swoole_process::wait(false)){
				$this->reboot_process($ret['pid']);
			}
		});
	}
	
	private function reboot_all_process()
	{
		$this->kill_all_sub_process();
		$this->run();
	}
	
	private function kill_all_sub_process()
	{
		foreach($this->worker as $index=>$worker){
			swoole_process::kill($worker['pid'], SIGTERM);
		}
		$this->worker = [];
		return;
	}
	
	public function reboot_process($pid)
	{
		$index = array_search($pid, $this->worker);
		if( $index ){
			$this->create_sub_process($index, $this->worker[$index]['name'], $this->worker[$index]['task']);
		}
	}
	
	public function load_config(){
		$conf = Helper::import("conf/php-daemon.php");
		foreach($conf as $k => $v ){
			$this->$k = $v;
		}
		return $conf;
	}
	
	public static function static_get_config(){
		return Helper::import("conf/php-daemon.php");
	}
}

/**
 * 同时创建两个实例，并且注册信号句柄的时候会出现不确定的错误
 */