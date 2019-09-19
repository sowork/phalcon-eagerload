<?php
namespace Sowork\EagerLoad\Model\EagerLoading;

use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Relation;
use Phalcon\Mvc\Model\RelationInterface;
use Phalcon\Mvc\ModelInterface;
use Tightenco\Collect\Support\Collection;

/**
 * Represents a level in the relations tree to be eagerly loaded
 */
final class EagerLoad
{
    /** @var RelationInterface */
    private $relation;
    /** @var null|callable */
    private $constraints;
    /** @var Loader|EagerLoad */
    private $parent;
    /** @var null|\Phalcon\Mvc\ModelInterface[] */
    private $subject;
    /** @var EagerLoad[] */
    private $delayLoads;
    private $aliasName;
    private $loader;

    /**
     * @param Relation         $relation
     * @param null|\Closure    $constraints
     * @param Loader|EagerLoad $parent
     * @param                  $aliasName
     */
    public function __construct(Relation $relation, ?\Closure $constraints, $parent, string $aliasName, $loader)
    {
        $this->relation    = $relation;
        $this->constraints = is_callable($constraints) ? $constraints : null;
        $this->parent      = $parent;
        $this->loader = $loader;
        $this->aliasName = $aliasName;
    }

    private function getBindValues($subject, string $relField)
    {
        if ($subject instanceof Model) {
            return [$subject->readAttribute($relField)];
        }

        if ($subject instanceof Collection) {
            return $subject->pluck($relField)->all();
        }
        return [];
    }

    /**
     * @return null|\Phalcon\Mvc\ModelInterface[]
     */
    public function getSubject()
    {
        return $this->subject;
    }

    public function getRelationDefinition(RelationInterface $relation): array
    {
        $alias                = $relation->getOptions();
        $definition['alias']                = strtolower($alias['alias']);
        $definition['relField']             = $relation->getFields();
        $definition['relReferencedModel']   = $relation->getReferencedModel();
        $definition['relReferencedField']   = $relation->getReferencedFields();
        $definition['relIrModel']           = $relation->getIntermediateModel();
        $definition['relIrField']           = $relation->getIntermediateFields();
        $definition['relIrReferencedField'] = $relation->getIntermediateReferencedFields();

        // PHQL has problems with this slash
        if ($definition['relReferencedModel'][0] === '\\') {
            $definition['relReferencedModel'] = ltrim($definition['relReferencedModel'], '\\');
        }

        return array_values($definition);
    }

    /**
     * Executes each db query needed
     *
     * Note: The {$alias} property is set two times because Phalcon Model ignores
     * empty arrays when overloading property set.
     *
     * Also {@see https://github.com/stibiumz/phalcon.eager-loading/issues/1}
     *
     * @return $this
     */
    public function load()
    {
        $relation = $this->relation;

        $parentSubject = $this->parent->getSubject();
        if (empty($parentSubject)) {
            return $this;
        }

        $builder = new QueryBuilder;
        $builder->setCurrentEagerLoad($this, $this->aliasName, $this->loader);
        if ($this->constraints) {
            call_user_func($this->constraints, $builder);
        }

        $records = $this->getEagerLoadData($parentSubject, $builder, $relation);

        $this->subject = $records;
        if (isset($this->delayLoads[$this->aliasName])) {
            foreach ($this->delayLoads[$this->aliasName] as $delayLoad) {
                $delayLoad->load();
            }
            $this->delayLoads = [];
        }

        return $this;
    }

    private function getEagerLoadData($parentSubject, QueryBuilder $builder, RelationInterface $relation)
    {
        if ($relation->getType() === Relation::HAS_ONE || $relation->getType() === Relation::BELONGS_TO) {
            return $this->parseOneToOne($parentSubject, $builder, $relation);
        }

        if ($relation->getType() === Relation::HAS_MANY) {
            return $this->parseOneToMany($parentSubject, $builder, $relation);
        }

        if ($relation->isThrough()) {
            return $this->parseManyToMany($parentSubject, $builder, $relation);
        }

        return [];
    }

    /**
     * @param Collection|ModelInterface  $parentSubject
     * @param QueryBuilder      $builder
     * @param RelationInterface $relation
     */
    private function parseOneToOne($parentSubject, QueryBuilder $builder, RelationInterface $relation)
    {
        list($alias, $relField, $relReferencedModel, $relReferencedField) = $this->getRelationDefinition($relation);

        if ($relation->getType() !== Relation::HAS_ONE && $relation->getType() !== Relation::BELONGS_TO) {
            return $parentSubject;
        }
        $bindValues = $this->getBindValues($parentSubject, $relField);
        $builder->inWhere("[{$relReferencedField}]", $bindValues);

        $records = Loader::convertResultSetToCollection($builder->from($relReferencedModel)->getQuery()->execute(), $relReferencedModel);
        if ($parentSubject instanceof ModelInterface) {
            $parentSubject->$alias = $records->first();
            return $records;
        }

        if ($parentSubject instanceof Collection) {
            $indexedRecordsCollections = $records->keyBy($relReferencedField);
            $parentSubject->map(function($item) use ($relField, $alias, $indexedRecordsCollections) {
                // fix phalcon bug   https://github.com/phalcon/cphalcon/issues/10556
                $item->$alias = null;
                $item->$alias = $indexedRecordsCollections->get($item->$relField);
                return $item;
            });
        }

        return $records;
    }

    /**
     * @param Collection|ModelInterface  $parentSubject
     * @param QueryBuilder               $builder
     * @param RelationInterface          $relation
     */
    private function parseOneToMany($parentSubject, QueryBuilder $builder, RelationInterface $relation)
    {
        list($alias, $relField, $relReferencedModel, $relReferencedField) = $this->getRelationDefinition($relation);

        if ($relation->getType() !== Relation::HAS_MANY) {
            return $parentSubject;
        }
        $bindValues = $this->getBindValues($parentSubject, $relField);
        $builder->inWhere("[{$relReferencedField}]", $bindValues);

        $records = Loader::convertResultSetToCollection($builder->from($relReferencedModel)->getQuery()->execute(), $relReferencedModel);

        if ($parentSubject instanceof ModelInterface) {
            $parentSubject->$alias = null;
            $parentSubject->$alias = $records->all();
            return $records;
        }

        if ($parentSubject instanceof Collection) {
            $indexedRecordsCollections = $records->groupBy($relReferencedField);
            $parentSubject->transform(function($item) use ($relField, $alias, $indexedRecordsCollections, $relReferencedField) {
                // fix phalcon bug   https://github.com/phalcon/cphalcon/issues/10556
                $item->$alias = null;
                $item->$alias[] = $indexedRecordsCollections->get($item->$relField);
                return $item;
            });
        }

        return $records;
    }

    /**
     * @param Collection|ModelInterface  $parentSubject
     * @param QueryBuilder               $builder
     * @param RelationInterface          $relation
     */
    private function parseManyToMany($parentSubject, QueryBuilder $builder, RelationInterface $relation)
    {
        list($alias, $relField, $relReferencedModel, $relReferencedField, $relIrModel, $relIrField, $relIrReferencedField) = $this->getRelationDefinition($relation);

        if (!$relation->isThrough()) {
            return $parentSubject;
        }

        $bindValues = $this->getBindValues($parentSubject, $relField);

        $indexedRecordsRelIrData = (new QueryBuilder)->from($relIrModel)
            ->inWhere("[{$relIrField}]", $bindValues)
            ->getQuery()
            ->execute();
        $intermediateCollection = collect($indexedRecordsRelIrData);

        $intermediateBindValues = $intermediateCollection->pluck($relIrReferencedField)
            ->all();

        $records = Loader::convertResultSetToCollection(
            $builder->from($relReferencedModel)
                ->inWhere("[{$relReferencedField}]", $intermediateBindValues)
                ->getQuery()
                ->execute()
        , $relReferencedModel);

        $indexedRecordsCollections = $intermediateCollection->groupBy($relIrField)->transform(function($item) use ($records, $relIrReferencedField, $relReferencedField) {
            return $records->whereIn($relReferencedField, $item->pluck($relIrReferencedField));
        });

        if ($parentSubject instanceof ModelInterface) {
            $parentSubject->$alias = null;
            $parentSubject->$alias = $indexedRecordsCollections->all();
            return $records;
        }

        if ($parentSubject instanceof Collection) {
            $parentSubject->transform(function($item) use ($relField, $alias, $indexedRecordsCollections) {
                // fix phalcon bug   https://github.com/phalcon/cphalcon/issues/10556
                $item->$alias = null;
                $item->$alias[] = $indexedRecordsCollections->get($item->$relField);
                return $item;
            });
        }

        return $records;
    }

    public function delayLoad(EagerLoad $load, $withAliasName) {
        $this->delayLoads[$withAliasName][] = $load;
    }
}
