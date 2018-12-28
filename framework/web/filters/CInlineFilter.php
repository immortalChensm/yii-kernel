<?php
/**
 * CInlineFilter class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link http://www.yiiframework.com/
 * @copyright 2008-2013 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

/**
 * CInlineFilter represents a filter defined as a controller method.
 *
 * CInlineFilter executes the 'filterXYZ($action)' method defined
 * in the controller, where the name 'XYZ' can be retrieved from the {@link name} property.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.web.filters
 * @since 1.0
 */
class CInlineFilter extends CFilter
{
	/**
	 * @var string name of the filter. It stands for 'XYZ' in the filter method name 'filterXYZ'.
	 */
	public $name;

	/**
	 * Creates an inline filter instance.
	 * The creation is based on a string describing the inline method name
	 * and action names that the filter shall or shall not apply to.
	 * @param CController $controller the controller who hosts the filter methods
	 * @param string $filterName the filter name
	 * @return CInlineFilter the created instance
	 * @throws CException if the filter method does not exist
     * 创建一个指定的过滤器对象返回
	 */
	public static function create($controller,$filterName)
	{
	    //判断控制器是否存在指定的过过滤器名称
		if(method_exists($controller,'filter'.$filterName))
		{
			$filter=new CInlineFilter;
			//设置过滤器的名称
			$filter->name=$filterName;
			return $filter;
		}
		else
			throw new CException(Yii::t('yii','Filter "{filter}" is invalid. Controller "{class}" does not have the filter method "filter{filter}".',
				array('{filter}'=>$filterName, '{class}'=>get_class($controller))));
	}

	/**
	 * Performs the filtering.
	 * This method calls the filter method defined in the controller class.
	 * @param CFilterChain $filterChain the filter chain that the filter is on. 过滤器链对象
     *
	 */
	public function filter($filterChain)
	{
		$method='filter'.$this->name;
		//得到当前过滤器链的控制器->控制器的过滤器动作(过滤器链对象)
		$filterChain->controller->$method($filterChain);
	}
}
