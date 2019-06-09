<?php
namespace Sowork\GraphQL\Model\EagerLoading;

use Phalcon\Di;
use Phalcon\Mvc\Model\Relation;
use Phalcon\Mvc\Model\Resultset\Simple;
use Phalcon\Mvc\ModelInterface;

final class Loader
{
    const E_INVALID_SUBJECT = <<<'MSG'
Expected value of `subject` is either a ModelInterface object, a Simple object or an array of ModelInterface objects
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
     * @param ModelInterface|ModelInterface[]|Simple $from
     * @param string $className
     * @param ...$arguments
     * @throws \InvalidArgumentException
     */
    public function __construct($from, $className)
    {
        $error     = false;
        $arguments = array_slice(func_get_args(), 2);

        if (!$from instanceof ModelInterface) {
            if (!$from instanceof Simple) {
                if (($fromType = gettype($from)) !== 'array') {
                    if (null !== $from && $fromType !== 'boolean') {
                        $error = true;
                    } else {
                        $from = null;
                    }
                } else {
                    $from = array_filter($from);

                    if (empty($from)) {
                        $from = null;
                    } else {
                        foreach ($from as $el) {
                            if ($el instanceof ModelInterface) {
                                if ($className === null) {
                                    $className = get_class($el);
                                }
//                                else {
//                                    if ($className !== get_class($el)) {
//                                        $error = true;
//                                        break;
//                                    }
//                                }
                            } else {
                                $error = true;
                                break;
                            }
                        }
                    }
                }
            } else {
                $prev = $from;
                $from = [];

                foreach ($prev as $record) {
                    $from[] = $record;
                }

                if (empty($from)) {
                    $from = null;
                }
//                else {
//                    $className = get_class($record);
//                }
            }

            $this->mustReturnAModel = false;
        } else {
//            $className = get_class($from);
            $from      = [$from];

            $this->mustReturnAModel = true;
        }

        if ($error) {
            throw new \InvalidArgumentException(static::E_INVALID_SUBJECT);
        }

        $this->subject = $from;
        $this->subjectClassName = $className;
        $this->eagerLoads = ($from === null || empty($arguments)) ? [] : static::parseArguments($arguments);
    }

    /**
     * Create and get from a mixed $subject
     *
     * @param ModelInterface|ModelInterface[]|Simple $subject
     * @param mixed ...$arguments
     * @throws \InvalidArgumentException
     * @return mixed
     */
    public static function from($subject)
    {
        if ($subject instanceof ModelInterface) {
            $ret = call_user_func_array('static::fromModel', func_get_args());
        } elseif ($subject instanceof Simple) {
            $ret = call_user_func_array('static::fromResultset', func_get_args());
        } elseif (is_array($subject)) {
            $ret = call_user_func_array('static::fromArray', func_get_args());
        } else {
            throw new \InvalidArgumentException(static::E_INVALID_SUBJECT);
        }

        return $ret;
    }

    /**
     * Create and get from a Model
     * @param ModelInterface $subject
     * @param mixed          ...$arguments
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
     * Create and get from an array
     * @param ModelInterface[] $subject
     * @param mixed            ...$arguments
     * @return array
     * @throws \ReflectionException
     */
    public static function fromArray(array $subject)
    {
        $reflection = new \ReflectionClass(__CLASS__);
        $instance   = $reflection->newInstanceArgs(func_get_args());

        return $instance->execute()->get();
    }

    /**
     * Create and get from a Resultset
     * @param Simple $subject
     * @param mixed  ...$arguments
     * @return Simple
     * @throws \ReflectionException
     */
    public static function fromResultset(Simple $subject)
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
        $ret = $this->subject;

        if (null !== $ret && $this->mustReturnAModel) {
            $ret = $ret[0];
        }

        return $ret;
    }

    /**
     * @return null|ModelInterface[]
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

        if (count($arguments) === 1 && !empty($arguments[0]) && is_array($arguments[0])) {
            foreach ($arguments[0] as $relationAlias => $queryConstraints) {
                if (is_string($relationAlias)) {
                    $relations[$relationAlias] = is_callable($queryConstraints) ? $queryConstraints : null;
                } else {
                    if (is_string($queryConstraints)) {
                        $relations[$queryConstraints] = null;
                    }
                }
            }
        } else {
            foreach ($arguments as $relationAlias) {
                if (is_string($relationAlias)) {
                    $relations[$relationAlias] = null;
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
//        var_dump(array_keys($this->eagerRelations));
//        var_dump(array_keys($this->oldEagerRelations));
//
//        var_dump(array_keys(array_diff_key($this->eagerRelations, $this->oldEagerRelations)));
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
     * @return $this
     */
    public function execute()
    {
        foreach ($this->buildTree() as $eagerLoad) {
            $eagerLoad->load();
        }

        return $this;
    }

    /**
     * Loader::execute() alias
     *
     * @return $this
     */
    public function load()
    {
        foreach ($this->buildTree() as $eagerLoad) {
            $eagerLoad->load();
        }

        return $this;
    }
}
