<?php
namespace Sowork\GraphQL\Model\EagerLoading;

use http\Exception\RuntimeException;
use Phalcon\Mvc\Model\Relation;
use Phalcon\Mvc\Model\Resultset;
use Phalcon\Version;

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
    /** @var boolean */
    private static $isPhalcon2;
    /** @var EagerLoad[] */
    private $delayLoads;
    private $aliasName;
    private $loader;

    /**
     * @param Relation         $relation
     * @param null|callable    $constraints
     * @param Loader|EagerLoad $parent
     * @param                  $aliasName
     */
    public function __construct(Relation $relation, $constraints, $parent, $aliasName, $loader)
    {
        if (static::$isPhalcon2 === null) {
            static::$isPhalcon2 = version_compare(Version::get(), '2.0.0') >= 0;
        }

        $this->relation    = $relation;
        $this->constraints = is_callable($constraints) ? $constraints : null;
        $this->parent      = $parent;
        $this->loader = $loader;
        $this->aliasName = $aliasName;
    }

    /**
     * @return null|\Phalcon\Mvc\ModelInterface[]
     */
    public function getSubject()
    {
        return $this->subject;
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
        $parentSubject = $this->parent->getSubject();

        if (empty($parentSubject)) {
            return $this;
        }
        
        $relation = $this->relation;

        $alias                = $relation->getOptions();
        $alias                = strtolower($alias['alias']);
        $relField             = $relation->getFields();
        $relReferencedModel   = $relation->getReferencedModel();
        $relReferencedField   = $relation->getReferencedFields();
        $relIrModel           = $relation->getIntermediateModel();
        $relIrField           = $relation->getIntermediateFields();
        $relIrReferencedField = $relation->getIntermediateReferencedFields();

        // PHQL has problems with this slash
        if ($relReferencedModel[0] === '\\') {
            $relReferencedModel = ltrim($relReferencedModel, '\\');
        }

        $bindValues = [];

        foreach ($parentSubject as $record) {
            $bindValues[$record->readAttribute($relField)] = true;
        }

        $bindValues = array_keys($bindValues);

        $subjectSize         = count($parentSubject);
        $isManyToManyForMany = false;

        $builder = new QueryBuilder;
        $builder->from($relReferencedModel);

        // many-to-many
        if ($isThrough = $relation->isThrough()) {
            if ($subjectSize === 1) {
                // The query is for a single model
                $builder
                    ->innerJoin(
                        $relIrModel,
                        sprintf(
                            '[%s].[%s] = [%s].[%s]',
                            $relIrModel,
                            $relIrReferencedField,
                            $relReferencedModel,
                            $relReferencedField
                        )
                    )
                    ->inWhere("[{$relIrModel}].[{$relIrField}]", $bindValues)
                ;
            } else {
                // The query is for many models, so it's needed to execute an
                // extra query
                $isManyToManyForMany = true;

                $relIrValues = new QueryBuilder;
                $relIrValues = $relIrValues
                    ->from($relIrModel)
                    ->inWhere("[{$relIrModel}].[{$relIrField}]", $bindValues)
                    ->getQuery()
                    ->execute()
                    ->setHydrateMode(Resultset::HYDRATE_ARRAYS)
                ;

                $bindValues = $modelReferencedModelValues = [];

                foreach ($relIrValues as $row) {
                    $bindValues[$row[$relIrReferencedField]] = true;
                    $modelReferencedModelValues[$row[$relIrField]][$row[$relIrReferencedField]] = true;
                }

                unset($relIrValues, $row);

                $builder->inWhere("[{$relReferencedField}]", array_keys($bindValues));
            }
        } else {
            $builder->inWhere("[{$relReferencedField}]", $bindValues);
        }

        $builder->setCurrentEagerLoad($this, $this->aliasName, $this->loader);
        if ($this->constraints) {
            call_user_func($this->constraints, $builder);
        }

        $records = [];

        if ($isManyToManyForMany) {
            foreach ($builder->getQuery()->execute() as $record) {
                $records[$record->readAttribute($relReferencedField)] = $record;
            }

            foreach ($parentSubject as $record) {
                $referencedFieldValue = $record->readAttribute($relField);

                if (isset($modelReferencedModelValues[$referencedFieldValue])) {
                    $referencedModels = [];

                    foreach ($modelReferencedModelValues[$referencedFieldValue] as $idx => $_) {
                        $referencedModels[] = $records[$idx];
                    }

                    $record->{$alias} = $referencedModels;

                    if (static::$isPhalcon2) {
                        $record->{$alias} = null;
                        $record->{$alias} = $referencedModels;
                    }
                } else {
                    $record->{$alias} = null;
                    $record->{$alias} = [];
                }
            }

            $records = array_values($records);
        } else {
            // We expect a single object or a set of it
            $isSingle = !$isThrough && (
                $relation->getType() === Relation::HAS_ONE ||
                $relation->getType() === Relation::BELONGS_TO
            );

            if ($subjectSize === 1) {
                // Keep all records in memory
                foreach ($builder->getQuery()->execute() as $record) {
                    $records[] = $record;
                }

                $record = $parentSubject[0];

                if ($isSingle) {
                    $record->{$alias} = empty($records) ? null : $records[0];
                } else {
                    if (empty($records)) {
                        $record->{$alias} = null;
                        $record->{$alias} = [];
                    } else {
                        $record->{$alias} = $records;

                        if (static::$isPhalcon2) {
                            $record->{$alias} = null;
                            $record->{$alias} = $records;
                        }
                    }
                }
            } else {
                $indexedRecords = [];

                // Keep all records in memory
                foreach ($builder->getQuery()->execute() as $record) {
                    $records[] = $record;

                    $foreignIdValue = $record->readAttribute($relReferencedField);
                    if (!$foreignIdValue) {
                        throw new \RuntimeException(sprintf('%s may lack the %s foreign key column', $alias, $relReferencedField));
                    }
                    if ($isSingle) {
                        $indexedRecords[$foreignIdValue] = $record;
                    } else {
                        $indexedRecords[$foreignIdValue][] = $record;
                    }
                }

                foreach ($parentSubject as $record) {
                    $referencedFieldValue = $record->readAttribute($relField);

                    if (isset($indexedRecords[$referencedFieldValue])) {
                        $record->{$alias} = $indexedRecords[$referencedFieldValue];

                        if (static::$isPhalcon2 && is_array($indexedRecords[$referencedFieldValue])) {
                            $record->{$alias} = null;
                            $record->{$alias} = $indexedRecords[$referencedFieldValue];
                        }
                    } else {
                        $record->{$alias} = null;

                        if (!$isSingle) {
                            $record->{$alias} = [];
                        }
                    }
                }
            }
        }

        $this->subject = $records;
        if ($this->delayLoads) {
            foreach ($this->delayLoads as $delayLoad) {
                $delayLoad->load();
            }
            $this->delayLoads = [];
        }

        return $this;
    }

    public function delayLoad(EagerLoad $load) {
        $this->delayLoads[] = $load;
    }
}
