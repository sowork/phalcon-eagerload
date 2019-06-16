<?php
namespace Sowork\EagerLoad\Traits;

use Phalcon\Mvc\Model\Resultset\Simple;
use Sowork\EagerLoad\Model\EagerLoading\QueryBuilder;

trait EagerLoadingTrait
{

    private static $eagerWith;

    public static function find($params = null)
    {
        $results = parent::find($params);
        if (static::$eagerWith) {
            return self::callWith($results, __CLASS__);
        }
        return $results;
    }

    public static function findFirst($params = null)
    {
        $results = parent::findFirst($params);
        if (static::$eagerWith) {
            return self::callWith($results, __CLASS__);
        }
        return $results;
    }

    public static function with($relations)
    {
        static::$eagerWith = is_string($relations) ? func_get_args() : $relations;
        if (empty(static::$eagerWith)) {
            throw new \BadMethodCallException(sprintf('%s requires at least one argument', __METHOD__));
        }
        return (new static());
    }

    public static function callWith($results, $modelName)
    {
         return (new QueryBuilder())->with(
            array_merge([$results, $modelName], static::$eagerWith)
        );
    }

    /**
     * <code>
     * <?php
     * $manufacturer = Manufacturer::findFirstById(51);
     * $manufacturer->load('Robots.Parts');
     * foreach ($manufacturer->robots as $robot) {
     *    foreach ($robot->parts as $part) { ... }
     * }
     * </code>
     * @param mixed ...$arguments
     * @return self
     */
    public function load()
    {
        $arguments = func_get_args();
        array_unshift($arguments, $this);
        return call_user_func_array('Sowork\EagerLoad\Model\EagerLoading\Loader::fromModel', $arguments);
    }

    public static function with1()
    {
        $arguments = func_get_args();
        if (!empty($arguments)) {
            $numArgs = count($arguments);
            $lastArg = $numArgs - 1;
            $parameters = null;
            if ($numArgs >= 2 && is_array($arguments[$lastArg])) {
                $parameters = $arguments[$lastArg];
                unset($arguments[$lastArg]);
                //                if (isset($parameters['columns'])) {
                //                    throw new \LogicException('Results from database must be full models, do not use `columns` key');
                //                }
            }
        } else {
            throw new \BadMethodCallException(sprintf('%s requires at least one argument', __METHOD__));
        }
        $ret = static::find($parameters);
        if ($ret->count()) {
            array_unshift($arguments, __CLASS__);
            array_unshift($arguments, $ret);
            $ret = call_user_func_array('Sowork\EagerLoad\Model\EagerLoading\Loader::fromResultset', $arguments);
        }
        return $ret;
    }

    /**
     * Same as EagerLoadingTrait::with() for a single record
     * @param mixed ...$arguments
     * @return false|\Phalcon\Mvc\ModelInterface
     */
    public static function findFirstWith()
    {
        $arguments = func_get_args();
        if (!empty($arguments)) {
            $numArgs = count($arguments);
            $lastArg = $numArgs - 1;
            $parameters = null;
            if ($numArgs >= 2 && is_array($arguments[$lastArg])) {
                $parameters = $arguments[$lastArg];
                unset($arguments[$lastArg]);
                if (isset($parameters['columns'])) {
                    throw new \LogicException('Results from database must be full models, do not use `columns` key');
                }
            }
        } else {
            throw new \BadMethodCallException(sprintf('%s requires at least one argument', __METHOD__));
        }
        if ($ret = static::findFirst($parameters)) {
            array_unshift($arguments, $ret);
            $ret = call_user_func_array('Phalcon\Mvc\Model\EagerLoading\Loader::fromModel', $arguments);
        }
        return $ret;
    }
}
