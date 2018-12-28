<?php
/**
 * CFilterChain class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright 2008-2013 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */


/**
 * CFilterChain represents a list of filters being applied to an action.
 *
 * CFilterChain executes the filter list by {@link run()}.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.web.filters
 * @since 1.0
 */
class CFilterChain extends CList
{
	/**
	 * @var CController the controller who executes the action.
	 */
	public $controller;
	/**
	 * @var CAction the action being filtered by this chain.
	 */
	public $action;
	/**
	 * @var integer the index of the filter that is to be executed when calling {@link run()}.
	 */
	public $filterIndex=0;


	/**
	 * Constructor.
	 * @param CController $controller the controller who executes the action.
	 * @param CAction $action the action being filtered by this chain.
	 */
	public function __construct($controller,$action)
	{
	    //控制器对象

		$this->controller=$controller;
		//方法对象
		$this->action=$action;
	}

	/**
	 * CFilterChain factory method.
	 * This method creates a CFilterChain instance.
	 * @param CController $controller the controller who executes the action.
	 * @param CAction $action the action being filtered by this chain.
	 * @param array $filters list of filters to be applied to the action.
	 * @return CFilterChain
	 * @throws CException
     * 解析得到过滤器的名称，过滤器后面的+-名称会去掉
     * 如果过滤器是个类名则直接实例化，如果不是则是CInlineFilter
     * 最终将过滤器放入过滤器池里
	 */
	public static function create($controller,$action,$filters)
	{
		$chain=new CFilterChain($controller,$action);

		//从CInlineAction对象取得方法名
		$actionID=$action->getId();
		foreach($filters as $filter)
		{
			if(is_string($filter))  // filterName [+|- action1 action2]
			{
				if(($pos=strpos($filter,'+'))!==false || ($pos=strpos($filter,'-'))!==false)
				{
					$matched=preg_match("/\b{$actionID}\b/i",substr($filter,$pos+1))>0;
					if(($filter[$pos]==='+')===$matched)
					    //实例化Inline过滤器，并保存过滤器的名称
						$filter=CInlineFilter::create($controller,trim(substr($filter,0,$pos)));
				}
				else
					$filter=CInlineFilter::create($controller,$filter);
			}
			elseif(is_array($filter))  // array('path.to.class [+|- action1, action2]','param1'=>'value1',...)
			{
				if(!isset($filter[0]))
					throw new CException(Yii::t('yii','The first element in a filter configuration must be the filter class.'));
				$filterClass=$filter[0];
				unset($filter[0]);
				if(($pos=strpos($filterClass,'+'))!==false || ($pos=strpos($filterClass,'-'))!==false)
				{
					$matched=preg_match("/\b{$actionID}\b/i",substr($filterClass,$pos+1))>0;
					if(($filterClass[$pos]==='+')===$matched)
						$filterClass=trim(substr($filterClass,0,$pos));
					else
						continue;
				}
				$filter['class']=$filterClass;
				//得到过滤器的名称
				$filter=Yii::createComponent($filter);
			}

			if(is_object($filter))
			{
				$filter->init();
				//将过滤器对象放入过滤器池里
				$chain->add($filter);
			}
		}
		return $chain;
	}

	/**
	 * Inserts an item at the specified position.
	 * This method overrides the parent implementation by adding
	 * additional check for the item to be added. In particular,
	 * only objects implementing {@link IFilter} can be added to the list.
	 * @param integer $index the specified position.
	 * @param mixed $item new item
	 * @throws CException If the index specified exceeds the bound or the list is read-only, or the item is not an {@link IFilter} instance.
	 */
	public function insertAt($index,$item)
	{
		if($item instanceof IFilter)
			parent::insertAt($index,$item);
		else
			throw new CException(Yii::t('yii','CFilterChain can only take objects implementing the IFilter interface.'));
	}

	/**
	 * Executes the filter indexed at {@link filterIndex}.
	 * After this method is called, {@link filterIndex} will be automatically incremented by one.
	 * This method is usually invoked in filters so that the filtering process
	 * can continue and the action can be executed.
     *
     * 过滤器链对象会被多次执行，就看过滤器的方法里是执行了CFilterChina->run()
     * 从而会继续从过滤器池里取取每个过滤器对象，并运行过滤器的filter过滤方法
     * 当没有过滤器对象时，才会支持runAction方法
	 */
	public function run()
	{
		if($this->offsetExists($this->filterIndex))
		{
		    //从过滤器池里取出各个过滤器
			$filter=$this->itemAt($this->filterIndex++);
			Yii::trace('Running filter '.($filter instanceof CInlineFilter ? get_class($this->controller).'.filter'.$filter->name.'()':get_class($filter).'.filter()'),'system.web.filters.CFilterChain');
			//运行过滤器的过滤方法
            //$this表示当前过滤器链对象
			$filter->filter($this);
		}
		else
			$this->controller->runAction($this->action);
	}
}