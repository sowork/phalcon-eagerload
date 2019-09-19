<?php
namespace Sowork\EagerLoad\Model\EagerLoading;

use Phalcon\Di;
use Phalcon\Mvc\Model\Relation;
use Phalcon\Mvc\Model\Resultset\Simple;
use Phalcon\Mvc\Model\ResultsetInterface;
use Phalcon\Mvc\ModelInterface;
use Tightenco\Collect\Support\Collection;

final class Loader
{
    const E_INVALID_SUBJECT = <<<'MSG'
Expected value of `subject` is either a ModelInterface object、 a Simple object or Collection object of ModelInterface objects
MSG;

    /** @var ModelInterface[] */
    protected $subject;
    /** @var string */
    protected $subjectClassName;
    /** @var array */
    protected $eagerLoads;
    /** @var array */
    protected $eagerRelations = [];
    /** @var array */
    protected $oldEagerRelations = [];
    /** @var boolean */
    protected $mustReturnAModel;
    /** @var array */
    protected $resolvedRelations;

    /**
     * @param ModelInterface|ResultsetInterface $from
     * @param string $className
     * @throws \InvalidArgumentException
     */
    public function __construct($from, $className)
    {
        $this->subjectClassName = $className;
        if ($from instanceof ResultsetInterface) {
            $this->subject = self::convertResultSetToCollection($from, $this->subjectClassName);
        } else if ($from instanceof ModelInterface || $from instanceof Collection) {
            $this->subject = $from;
        } else {
            throw new \InvalidArgumentException(static::E_INVALID_SUBJECT);
        }
    }

    /**
     * Create and get from a mixed $subject
     *
     * @param ModelInterface|ResultsetInterface $subject
     * @throws \InvalidArgumentException
     * @return mixed
     */
    public static function from($subject)
    {
        if ($subject instanceof ModelInterface) {
            $ret = call_user_func_array('static::fromModel', func_get_args());
        } elseif ($subject instanceof ResultsetInterface) {
            $ret = call_user_func_array('static::fromResultset', func_get_args());
        } else {
            throw new \InvalidArgumentException(static::E_INVALID_SUBJECT);
        }

        return $ret;
    }

    /**
     * Create and get from a Model
     * @param ModelInterface $subject
     * @return ModelInterface
     * @throws \ReflectionException
     */
    public static function fromModel(ModelInterface $subject)
    {
        $reflection = new \ReflectionClass(__CLASS__);
        $instance   = $reflection->newInstanceArgs(func_get_args());

        return $instance->execute()->get();
    }

    /**
     * Create and get from a Resultset
     * @param ResultsetInterface $subject
     * @return Collection
     * @throws \ReflectionException
     */
    public static function fromResultset($subject)
    {
        $reflection = new \ReflectionClass(__CLASS__);
        $instance   = $reflection->newInstanceArgs(func_get_args());

        return $instance->execute()->get();
    }

    /**
     * @return null|ModelInterface[]|ModelInterface
     */
    public function get()
    {
        return $this->subject;
    }

    /**
     * @return Simple|ModelInterface
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Parses the arguments that will be resolved to Relation instances
     *
     * @param array $arguments
     * @throws \InvalidArgumentException
     * @return array
     */
    public static function parseArguments(array $arguments)
    {
        if (empty($arguments)) {
            throw new \InvalidArgumentException('Arguments can not be empty');
        }

        $relations = [];

        foreach ($arguments as $relationAlias => $queryConstraints) {
            if (is_string($relationAlias)) {
                $relations[$relationAlias] = is_callable($queryConstraints) ? $queryConstraints : null;
            } else {
                if (is_string($queryConstraints)) {
                    $relations[$queryConstraints] = null;
                }
            }
        }
        if (empty($relations)) {
            return [];
        }

        return $relations;
    }

    /**
     * @param string $relationAlias
     * @param null|callable $constraints
     * @return $this
     */
    public function addEagerLoad($relationAlias, $constraints = null)
    {
        if (!is_string($relationAlias)) {
            throw new \InvalidArgumentException(sprintf(
                '$relationAlias expects to be a string, `%s` given',
                gettype($relationAlias)
            ));
        }

        if ($constraints !== null && !is_callable($constraints)) {
            throw new \InvalidArgumentException(sprintf(
                '$constraints expects to be a callable, `%s` given',
                gettype($constraints)
            ));
        }

        $this->eagerLoads[$relationAlias] = $constraints;

        return $this;
    }

    public function buildLoad($eagerDatas, $isNestedLoader = false, $nestedLevel = 0)
    {
        $this->oldEagerRelations = $this->eagerRelations;
        uksort($eagerDatas, 'strcmp');

        $di = DI::getDefault();
        $mM = $di['modelsManager'];

        /**
         * $eagerDatas
         * Array
        (
        [blogs] =>
        [users] =>
        [users.comments] =>
        )
         */
        foreach ($eagerDatas as $relationAliases => $queryConstraints) {
            $nestingLevel    = $isNestedLoader ? $nestedLevel : 0;
            $relationAliases = explode('.', $relationAliases);
            $nestingLevels   = count($relationAliases);

            do {
                do {
                    $alias = $relationAliases[$nestingLevel];
                    $name  = join('.', array_slice($relationAliases, 0, $nestingLevel + 1));
                } while (isset($this->eagerRelations[$name]) && ++$nestingLevel);

                if ($nestingLevel === 0 && !$isNestedLoader) {
                    $parentClassName = $this->subjectClassName;
                } else {
                    try {
                        $parentName = join('.', array_slice($relationAliases, 0, $nestingLevel));
                        $parentClassName = $this->resolvedRelations[$parentName]->getReferencedModel();
                        if ($parentClassName[0] === '\\') {
                            ltrim($parentClassName, '\\');
                        }
                    } catch(\Throwable $e) {
                        echo $e->getMessage();
                    }
                }

                if (!isset($this->resolvedRelations[$name])) {
                    // load model
                    $mM->load($parentClassName);
                    $relation = $mM->getRelationByAlias($parentClassName, $alias);
                    if (!$relation instanceof Relation) {
                        throw new \RuntimeException(sprintf('There is no defined relation for the model `%s` using alias `%s`', $parentClassName, $alias));
                    }
                    $this->resolvedRelations[$name] = $relation;
                } else {
                    $relation = $this->resolvedRelations[$name];
                }

                $relType = $relation->getType();

                if ($relType !== Relation::BELONGS_TO &&
                    $relType !== Relation::HAS_ONE &&
                    $relType !== Relation::HAS_MANY &&
                    $relType !== Relation::HAS_MANY_THROUGH) {
                    throw new \RuntimeException(sprintf('Unknown relation type `%s`', $relType));
                }

                if (is_array($relation->getFields()) ||
                    is_array($relation->getReferencedFields())) {
                    throw new \RuntimeException('Relations with composite keys are not supported');
                }
                $parent = $nestingLevel > 0 ? $this->eagerRelations[$parentName] : $this;
                $constraints = $nestingLevel + 1 === $nestingLevels ? $queryConstraints : null;
                $this->eagerRelations[$name] = new EagerLoad($relation, $constraints, $parent, $name, $this);
            } while (++$nestingLevel < $nestingLevels);
        }

        return array_diff_key($this->eagerRelations, $this->oldEagerRelations);
    }

    /**
     * Resolves the relations
     *
     * @throws \RuntimeException
     * @return EagerLoad[]
     */
    private function buildTree()
    {
        return $this->buildLoad($this->eagerLoads);
    }

    /**
     * @param array $eagerTrees
     * @return $this
     */
    public function execute($eagerTrees = [])
    {
        $eagerTrees = $eagerTrees ?? $this->buildTree();
        foreach ($eagerTrees as $eagerLoad) {
            $eagerLoad->load();
        }

        return $this;
    }

    /**
     * Loader::execute() alias
     * @param array $eagerTrees
     * @return $this
     */
    public function load($eagerTrees = [])
    {
        $eagerTrees = $eagerTrees ?? $this->buildTree();
        foreach ($eagerTrees as $eagerLoad) {
            $eagerLoad->load();
        }

        return $this;
    }

    public function getSubjectClassName()
    {
        return $this->subjectClassName;
    }

    public static function convertResultSetToCollection(ResultsetInterface $resultset, string $modelClass)
    {
        return collect($resultset)->map(function($item) use ($modelClass) {
            return new $modelClass($item);
        });
    }
}
