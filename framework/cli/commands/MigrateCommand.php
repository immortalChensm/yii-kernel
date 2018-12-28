<?php
/**
 * MigrateCommand class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright 2008-2013 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

/**
 * MigrateCommand manages the database migrations.
 *
 * The implementation of this command and other supporting classes referenced
 * the yii-dbmigrations extension ((https://github.com/pieterclaerhout/yii-dbmigrations),
 * authored by Pieter Claerhout.
 *
 * Since version 1.1.11 this command will exit with the following exit codes:
 * <ul>
 * <li>0 on success</li>
 * <li>1 on general error</li>
 * <li>2 on failed migration.</li>
 * </ul>
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.cli.commands
 * @since 1.1.6
 */
class MigrateCommand extends CConsoleCommand
{
	const BASE_MIGRATION='m000000_000000_base';

	/**
	 * @var string the directory that stores the migrations. This must be specified
	 * in terms of a path alias, and the corresponding directory must exist.
	 * Defaults to 'application.migrations' (meaning 'protected/migrations').
	 */
	public $migrationPath='application.migrations';
	/**
	 * @var string the name of the table for keeping applied migration information.
	 * This table will be automatically created if not exists. Defaults to 'tbl_migration'.
	 * The table structure is: (version varchar(180) primary key, apply_time integer)
	 */
	public $migrationTable='tbl_migration';
	/**
	 * @var string the application component ID that specifies the database connection for
	 * storing migration information. Defaults to 'db'.
	 */
	public $connectionID='db';
	/**
	 * @var string the path of the template file for generating new migrations. This
	 * must be specified in terms of a path alias (e.g. application.migrations.template).
	 * If not set, an internal template will be used.
	 */
	public $templateFile;
	/**
	 * @var string the default command action. It defaults to 'up'.
	 */
	public $defaultAction='up';
	/**
	 * @var boolean whether to execute the migration in an interactive mode. Defaults to true.
	 * Set this to false when performing migration in a cron job or background process.
	 */
	public $interactive=true;

	public function beforeAction($action,$params)
	{
		$path=Yii::getPathOfAlias($this->migrationPath);
		if($path===false || !is_dir($path))
		{
			echo 'Error: The migration directory does not exist: '.$this->migrationPath."\n";
			exit(1);
		}
		$this->migrationPath=$path;

		$yiiVersion=Yii::getVersion();
		echo "\nYii Migration Tool v1.0 (based on Yii v{$yiiVersion})\n\n";

		return parent::beforeAction($action,$params);
	}

	public function actionUp($args)
	{
		if(($migrations=$this->getNewMigrations())===array())
		{
			echo "No new migration found. Your system is up-to-date.\n";
			return 0;
		}

		$total=count($migrations);
		$step=isset($args[0]) ? (int)$args[0] : 0;
		if($step>0)
			$migrations=array_slice($migrations,0,$step);

		$n=count($migrations);
		if($n===$total)
			echo "Total $n new ".($n===1 ? 'migration':'migrations')." to be applied:\n";
		else
			echo "Total $n out of $total new ".($total===1 ? 'migration':'migrations')." to be applied:\n";

		foreach($migrations as $migration)
			echo "    $migration\n";
		echo "\n";

		if($this->confirm('Apply the above '.($n===1 ? 'migration':'migrations')."?"))
		{
			foreach($migrations as $migration)
			{
				if($this->migrateUp($migration)===false)
				{
					echo "\nMigration failed. All later migrations are canceled.\n";
					return 2;
				}
			}
			echo "\nMigrated up successfully.\n";
		}
	}

	public function actionDown($args)
	{
		$step=isset($args[0]) ? (int)$args[0] : 1;
		if($step<1)
		{
			echo "Error: The step parameter must be greater than 0.\n";
			return 1;
		}

		if(($migrations=$this->getMigrationHistory($step))===array())
		{
			echo "No migration has been done before.\n";
			return 0;
		}
		$migrations=array_keys($migrations);

		$n=count($migrations);
		echo "Total $n ".($n===1 ? 'migration':'migrations')." to be reverted:\n";
		foreach($migrations as $migration)
			echo "    $migration\n";
		echo "\n";

		if($this->confirm('Revert the above '.($n===1 ? 'migration':'migrations')."?"))
		{
			foreach($migrations as $migration)
			{
				if($this->migrateDown($migration)===false)
				{
					echo "\nMigration failed. All later migrations are canceled.\n";
					return 2;
				}
			}
			echo "\nMigrated down successfully.\n";
		}
	}

	/**
    yiic migrate redo [step]
     * 删除所有的迁移数据表，再重新生成
     **/
	public function actionRedo($args)
	{
		$step=isset($args[0]) ? (int)$args[0] : 1;
		if($step<1)
		{
			echo "Error: The step parameter must be greater than 0.\n";
			return 1;
		}

		if(($migrations=$this->getMigrationHistory($step))===array())
		{
			echo "No migration has been done before.\n";
			return 0;
		}
		$migrations=array_keys($migrations);

		$n=count($migrations);
		echo "Total $n ".($n===1 ? 'migration':'migrations')." to be redone:\n";
		foreach($migrations as $migration)
			echo "    $migration\n";
		echo "\n";

		if($this->confirm('Redo the above '.($n===1 ? 'migration':'migrations')."?"))
		{
			foreach($migrations as $migration)
			{
				if($this->migrateDown($migration)===false)
				{
					echo "\nMigration failed. All later migrations are canceled.\n";
					return 2;
				}
			}
			foreach(array_reverse($migrations) as $migration)
			{
				if($this->migrateUp($migration)===false)
				{
					echo "\nMigration failed. All later migrations are canceled.\n";
					return 2;
				}
			}
			echo "\nMigration redone successfully.\n";
		}
	}

	/**
	运行 yiic migrate to 101129_185401时运行此方法
     *
     **/
	public function actionTo($args)
	{
	    //请指定版本或是时间
		if(!isset($args[0]))
			$this->usageError('Please specify which version, timestamp or datetime to migrate to.');

		//是数字的话
		if((string)(int)$args[0]==$args[0])
			return $this->migrateToTime($args[0]);
		//是字符
		elseif(($time=strtotime($args[0]))!==false)
			return $this->migrateToTime($time);
		else
			return $this->migrateToVersion($args[0]);
	}

	private function migrateToTime($time)
	{
	    //按迁移时间查取
		$data=$this->getDbConnection()->createCommand()
			->select('version,apply_time')
			->from($this->migrationTable)
			->where('apply_time<=:time',array(':time'=>$time))
			->order('apply_time DESC')
			->limit(1)
			->queryRow();

		//查不到报错
		if($data===false)
		{
			echo "Error: Unable to find a version before ".date('Y-m-d H:i:s',$time).".\n";
			return 1;
		}
		else
		{
			echo "Found version ".$data['version']." applied at ".date('Y-m-d H:i:s',$data['apply_time']).", it is before ".date('Y-m-d H:i:s',$time).".\n";
			return $this->migrateToVersion(substr($data['version'],1,13));
		}
	}

	/**
	迁移到某个版本 根据迁移版本【版本=迁移文件名称】
     * 要么生成数据表，要么不生成
     **/
	private function migrateToVersion($version)
	{
		$originalVersion=$version;
		if(preg_match('/^m?(\d{6}_\d{6})(_.*?)?$/',$version,$matches))
			$version='m'.$matches[1];
		else
		{
			echo "Error: The version option must be either a timestamp (e.g. 101129_185401)\nor the full name of a migration (e.g. m101129_185401_create_user_table).\n";
			return 1;
		}

		// try migrate up
		$migrations=$this->getNewMigrations();
		foreach($migrations as $i=>$migration)
		{
			if(strpos($migration,$version.'_')===0)
				return $this->actionUp(array($i+1));
		}

		// try migrate down
		$migrations=array_keys($this->getMigrationHistory(-1));
		foreach($migrations as $i=>$migration)
		{
			if(strpos($migration,$version.'_')===0)
			{
				if($i===0)
				{
					echo "Already at '$originalVersion'. Nothing needs to be done.\n";
					return 0;
				}
				else
					return $this->actionDown(array($i));
			}
		}

		echo "Error: Unable to find the version '$originalVersion'.\n";
		return 1;
	}

	/**
    yiic migrate mark 101129_185401
    指定某个迁移版本更新
     **/
	public function actionMark($args)
	{
		if(isset($args[0]))
			$version=$args[0];
		else
			$this->usageError('Please specify which version to mark to.');
		$originalVersion=$version;
		if(preg_match('/^m?(\d{6}_\d{6})(_.*?)?$/',$version,$matches))
			$version='m'.$matches[1];
		else {
			echo "Error: The version option must be either a timestamp (e.g. 101129_185401)\nor the full name of a migration (e.g. m101129_185401_create_user_table).\n";
			return 1;
		}

		$db=$this->getDbConnection();

		// try mark up
		$migrations=$this->getNewMigrations();
		foreach($migrations as $i=>$migration)
		{
			if(strpos($migration,$version.'_')===0)
			{
				if($this->confirm("Set migration history at $originalVersion?"))
				{
					$command=$db->createCommand();
					for($j=0;$j<=$i;++$j)
					{
						$command->insert($this->migrationTable, array(
							'version'=>$migrations[$j],
							'apply_time'=>time(),
						));
					}
					echo "The migration history is set at $originalVersion.\nNo actual migration was performed.\n";
				}
				return 0;
			}
		}

		// try mark down
		$migrations=array_keys($this->getMigrationHistory(-1));
		foreach($migrations as $i=>$migration)
		{
			if(strpos($migration,$version.'_')===0)
			{
				if($i===0)
					echo "Already at '$originalVersion'. Nothing needs to be done.\n";
				else
				{
					if($this->confirm("Set migration history at $originalVersion?"))
					{
						$command=$db->createCommand();
						for($j=0;$j<$i;++$j)
							$command->delete($this->migrationTable, $db->quoteColumnName('version').'=:version', array(':version'=>$migrations[$j]));
						echo "The migration history is set at $originalVersion.\nNo actual migration was performed.\n";
					}
				}
				return 0;
			}
		}

		echo "Error: Unable to find the version '$originalVersion'.\n";
		return 1;
	}

	/**
    yiic migrate history [limit] 从迁移数据表查询迁移记录
     **/
	public function actionHistory($args)
	{
		$limit=isset($args[0]) ? (int)$args[0] : -1;
		$migrations=$this->getMigrationHistory($limit);
		if($migrations===array())
			echo "No migration has been done before.\n";
		else
		{
			$n=count($migrations);
			if($limit>0)
				echo "Showing the last $n applied ".($n===1 ? 'migration' : 'migrations').":\n";
			else
				echo "Total $n ".($n===1 ? 'migration has' : 'migrations have')." been applied before:\n";
			foreach($migrations as $version=>$time)
				echo "    (".date('Y-m-d H:i:s',$time).') '.$version."\n";
		}
	}

	//yiic migrate new [limit]
	public function actionNew($args)
	{
		$limit=isset($args[0]) ? (int)$args[0] : -1;
		$migrations=$this->getNewMigrations();
		if($migrations===array())
			echo "No new migrations found. Your system is up-to-date.\n";
		else
		{
			$n=count($migrations);
			if($limit>0 && $n>$limit)
			{
				$migrations=array_slice($migrations,0,$limit);
				echo "Showing $limit out of $n new ".($n===1 ? 'migration' : 'migrations').":\n";
			}
			else
				echo "Found $n new ".($n===1 ? 'migration' : 'migrations').":\n";

			foreach($migrations as $migration)
				echo "    ".$migration."\n";
		}
	}

	//运行yiic migrate create <name> 命令时运行此方法
    //从指定的迁移模板文件读取内容生成迁移文件
	public function actionCreate($args)
	{
		if(isset($args[0]))
			$name=$args[0];
		else
			$this->usageError('Please provide the name of the new migration.');

		if(!preg_match('/^\w+$/',$name)) {
			echo "Error: The name of the migration must contain letters, digits and/or underscore characters only.\n";
			return 1;
		}

		//构造文件名称
		$name='m'.gmdate('ymd_His').'_'.$name;
		//读取构造文件的模板内容
		$content=strtr($this->getTemplate(), array('{ClassName}'=>$name));
		//迁移文件完整名称
		$file=$this->migrationPath.DIRECTORY_SEPARATOR.$name.'.php';

		//终端询问用户是否创建迁移文件
		if($this->confirm("Create new migration '$file'?"))
		{
		    //生成迁移文件
			file_put_contents($file, $content);
			echo "New migration created successfully.\n";
		}
	}

	public function confirm($message,$default=false)
	{
		if(!$this->interactive)
			return true;
		return parent::confirm($message,$default);
	}

	/**

     上级动作是actionUp
    yiic migrate = yiic migrate up会运行这个破东西
     当运行这个命令时，将会执行指定迁移类的up方法
     up方法将会得到数据库的连接资源，并执行createTable动作
     创建好数据表，然后将创建好的记录保存，主要保存创建表的名称，创建时间
     **/
	protected function migrateUp($class)
	{
		if($class===self::BASE_MIGRATION)
			return;

		echo "*** applying $class\n";
		$start=microtime(true);
		//得到迁移文件类文件对象
		$migration=$this->instantiateMigration($class);

		//如果创建成功时
		if($migration->up()!==false)
		{
		    //将把创建成功的迁移表，保存迁移文件的名称，迁移时间
			$this->getDbConnection()->createCommand()->insert($this->migrationTable, array(
				'version'=>$class,
				'apply_time'=>time(),
			));
			$time=microtime(true)-$start;
			echo "*** applied $class (time: ".sprintf("%.3f",$time)."s)\n\n";
		}
		else
		{
			$time=microtime(true)-$start;
			echo "*** failed to apply $class (time: ".sprintf("%.3f",$time)."s)\n\n";
			return false;
		}
	}

	/**
    yiic migrate down [step]此命令运行时


     **/
	protected function migrateDown($class)
	{
		if($class===self::BASE_MIGRATION)
			return;

		echo "*** reverting $class\n";
		$start=microtime(true);

		//取得迁移文件类对象
		$migration=$this->instantiateMigration($class);
		//执行迁移类的down方法
		if($migration->down()!==false)
		{
			$db=$this->getDbConnection();
			//得到数据库连接
            //删除数据表，删除迁移记录
			$db->createCommand()->delete($this->migrationTable, $db->quoteColumnName('version').'=:version', array(':version'=>$class));
			$time=microtime(true)-$start;
			echo "*** reverted $class (time: ".sprintf("%.3f",$time)."s)\n\n";
		}
		else
		{
			$time=microtime(true)-$start;
			echo "*** failed to revert $class (time: ".sprintf("%.3f",$time)."s)\n\n";
			return false;
		}
	}

	/**
	得到迁移类文件的实例【基类已经保存了数据库的连接资源】
     **/
	protected function instantiateMigration($class)
	{
	    //得到迁移类文件
		$file=$this->migrationPath.DIRECTORY_SEPARATOR.$class.'.php';
		//引入迁移类文件
		require_once($file);
		//实例迁移类文件
		$migration=new $class;
		//将当前的数据库链接传递给迁移类文件的基类【父亲】
		$migration->setDbConnection($this->getDbConnection());
		//返回迁移类对象
		return $migration;
	}

	/**
	 * @var CDbConnection
	 */
	private $_db;
	protected function getDbConnection()
	{
		if($this->_db!==null)
			return $this->_db;
		elseif(($this->_db=Yii::app()->getComponent($this->connectionID)) instanceof CDbConnection)
			return $this->_db;

		echo "Error: CMigrationCommand.connectionID '{$this->connectionID}' is invalid. Please make sure it refers to the ID of a CDbConnection application component.\n";
		exit(1);
	}

	protected function getMigrationHistory($limit)
	{
		$db=$this->getDbConnection();
		if($db->schema->getTable($this->migrationTable,true)===null)
		{
			$this->createMigrationHistoryTable();
		}
		return CHtml::listData($db->createCommand()
			->select('version, apply_time')
			->from($this->migrationTable)
			->order('version DESC')
			->limit($limit)
			->queryAll(), 'version', 'apply_time');
	}

	protected function createMigrationHistoryTable()
	{
		$db=$this->getDbConnection();
		echo 'Creating migration history table "'.$this->migrationTable.'"...';
		$db->createCommand()->createTable($this->migrationTable,array(
			'version'=>'varchar(180) NOT NULL PRIMARY KEY',
			'apply_time'=>'integer',
		));
		$db->createCommand()->insert($this->migrationTable,array(
			'version'=>self::BASE_MIGRATION,
			'apply_time'=>time(),
		));
		echo "done.\n";
	}

	protected function getNewMigrations()
	{
		$applied=array();
		foreach($this->getMigrationHistory(-1) as $version=>$time)
			$applied[substr($version,1,13)]=true;

		$migrations=array();
		$handle=opendir($this->migrationPath);
		while(($file=readdir($handle))!==false)
		{
			if($file==='.' || $file==='..')
				continue;
			$path=$this->migrationPath.DIRECTORY_SEPARATOR.$file;
			if(preg_match('/^(m(\d{6}_\d{6})_.*?)\.php$/',$file,$matches) && is_file($path) && !isset($applied[$matches[2]]))
				$migrations[]=$matches[1];
		}
		closedir($handle);
		sort($migrations);
		return $migrations;
	}

	public function getHelp()
	{
		return <<<EOD
USAGE
  yiic migrate [action] [parameter]

DESCRIPTION
  This command provides support for database migrations. The optional
  'action' parameter specifies which specific migration task to perform.
  It can take these values: up, down, to, create, history, new, mark.
  If the 'action' parameter is not given, it defaults to 'up'.
  Each action takes different parameters. Their usage can be found in
  the following examples.

EXAMPLES
 * yiic migrate
   Applies ALL new migrations. This is equivalent to 'yiic migrate up'.

 * yiic migrate create create_user_table
   Creates a new migration named 'create_user_table'.

 * yiic migrate up 3
   Applies the next 3 new migrations.

 * yiic migrate down
   Reverts the last applied migration.

 * yiic migrate down 3
   Reverts the last 3 applied migrations.

 * yiic migrate to 101129_185401
   Migrates up or down to version 101129_185401.

 * yiic migrate to 1392447720
   Migrates to the given UNIX timestamp. This means that all the versions
   applied after the specified timestamp will be reverted. Versions applied
   before won't be touched.

 * yiic migrate to "2014-02-15 13:00:50"
   Migrates to the given datetime parseable by the strtotime() function.
   This means that all the versions applied after the specified datetime
   will be reverted. Versions applied before won't be touched.

 * yiic migrate mark 101129_185401
   Modifies the migration history up or down to version 101129_185401.
   No actual migration will be performed.

 * yiic migrate history
   Shows all previously applied migration information.

 * yiic migrate history 10
   Shows the last 10 applied migrations.

 * yiic migrate new
   Shows all new migrations.

 * yiic migrate new 10
   Shows the next 10 migrations that have not been applied.

EOD;
	}

	protected function getTemplate()
	{
		if($this->templateFile!==null)
			return file_get_contents(Yii::getPathOfAlias($this->templateFile).'.php');
		else
			return <<<EOD
<?php

class {ClassName} extends CDbMigration
{
	public function up()
	{
	}

	public function down()
	{
		echo "{ClassName} does not support migration down.\\n";
		return false;
	}

	/*
	// Use safeUp/safeDown to do migration with transaction
	public function safeUp()
	{
	}

	public function safeDown()
	{
	}
	*/
}
EOD;
	}
}
