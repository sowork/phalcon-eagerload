<?php
namespace Sowork\EagerLoad\Model\EagerLoading;

use Phalcon\Mvc\Model\Manager;
use Phalcon\Mvc\Model\Query\Builder;

final class QueryBuilder extends Builder
{
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

    public function with($relations)
    {
//        dd($relations);
        $arguments = is_string($relations) ? func_get_args() : $relations;
//        dump($arguments);
        $isNestedLoader = true;
        if (!$this->loader) {
            $isNestedLoader = false;
            $this->loader = new Loader($arguments[0], $arguments[1]);
            unset($arguments[0]);
            unset($arguments[1]);
        }
//        dump(($arguments));
        if (!$arguments) {
            return $this;
        }
        $relations = $this->loader->parseArguments($arguments);
//        dump($this->currentAliasName);
        if ($this->currentAliasName && $isNestedLoader) {
            $nestedRelations = [];
            foreach ($relations as $key => $relation) {
                $nestedRelations["{$this->currentAliasName}.$key"] = $relation;
            }
            $relations = $nestedRelations;
        }
        $nestedLevel = $this->currentAliasName ? count(explode('.', $this->currentAliasName)) : 0;
        $eagerTrees = $this->loader->buildLoad($relations, $isNestedLoader, $nestedLevel);

        if ($isNestedLoader) {
            foreach ($eagerTrees as $key => $eagerTree) {
                $this->parent->delayLoad($eagerTree, $this->currentAliasName);
            }
            return $this;
        }

        return $this->loader->execute($eagerTrees)->get();
    }
}
