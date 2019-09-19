<?php

namespace Sowork\EagerLoad;

use Phalcon\Di\ServiceProviderInterface;
use Phalcon\DiInterface;
use Sowork\EagerLoad\Model\EagerLoading\QueryBuilder;
use Tightenco\Collect\Support\Collection;

class EagerLoadServiceProvider implements ServiceProviderInterface
{
    public function register(DiInterface $di)
    {
        $this->addCollectionMethods();
    }

    public function addCollectionMethods()
    {
        Collection::macro('load', function($relations){
            /**
             * Load a set of relationships onto the collection.
             *
             * @param  mixed  $relations
             * @return $this
             */
            if ($this->isNotEmpty()) {
                if (is_string($relations)) {
                    $relations = func_get_args();
                }

                return (new QueryBuilder())->with(
                    array_merge([$this, get_class($this->first())], is_string($relations) ? func_get_args() : $relations)
                );
            }
            return $this;
        });
    }
}