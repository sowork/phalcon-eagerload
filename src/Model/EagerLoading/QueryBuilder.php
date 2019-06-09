<?php
namespace Sowork\GraphQL\Model\EagerLoading;

use Phalcon\Mvc\Model\Manager;
use Phalcon\Mvc\Model\Query\Builder;

final class QueryBuilder extends Builder
{
//    const E_NOT_ALLOWED_METHOD_CALL = 'When eager loading relations queries must return full entities';
    
//    public function distinct($distinct)
//    {
//        throw new \LogicException(static::E_NOT_ALLOWED_METHOD_CALL);
//    }

    public function columns($columns)
    {
        if (is_string($columns)) {
            $columns = array_map(function($column) {
                return '[' . trim($column) . ']';
            }, explode(',', $columns));
        } elseif (is_array($columns)) {
            foreach ($columns as &$column) {
                $column = "[$column]";
            }
        }
        $this->_columns = $columns;

        return $this;
    }

    /** @var Loader|EagerLoad */
    private $parent;
    /** @var string */
    private $currentAliasName;
    /** @var Loader */
    private $loader;

    public function setCurrentEagerLoad($parent, $aliasName, $loader)
    {
        $this->parent = $parent;
        $this->currentAliasName = $aliasName;
        $this->loader = $loader;
    }

    public function where($conditions, $bindParams = null, $bindTypes = null)
    {
        $currentConditions = $this->_conditions;

        /**
         * Nest the condition to current ones or set as unique
         */
        if ($currentConditions) {
            $conditions = $currentConditions . " AND " . $conditions;
        }

        return parent::where($conditions, $bindParams, $bindTypes);
    }

    public function andWhere($conditions, $bindParams = null, $bindTypes = null)
    {
        return $this->where($conditions, $bindParams, $bindTypes);
    }

    public function with()
    {
        $arguments = func_get_args();
        $relations = $this->loader->parseArguments($arguments);
        $nestedRelations = [];
        foreach ($relations as $key => $relation) {
            $nestedRelations["{$this->currentAliasName}.$key"] = $relation;
        }
        $eagerTrees = $this->loader->buildLoad($nestedRelations, true, count(explode('.', $this->currentAliasName)));
        foreach ($eagerTrees as $key => $eagerTree) {
            $this->parent->delayLoad($eagerTree);
        }

        return $this;
    }
}
