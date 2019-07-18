<?php
namespace Sowork\EagerLoad\Traits;

use Sowork\EagerLoad\Model\EagerLoading\QueryBuilder;

trait EagerLoadingTrait
{

    private static $eagerWith = [];

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

    private static function callWith($results, $modelName)
    {
         return (new QueryBuilder())->with(
             array_merge([$results, $modelName], static::$eagerWith)
         );
    }
}
