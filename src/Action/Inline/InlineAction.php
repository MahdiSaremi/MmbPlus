<?php

namespace Mmb\Action\Inline;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Mmb\Action\Action;
use Mmb\Action\Memory\ConvertableToStep;
use Mmb\Action\Memory\StepHandler;
use Mmb\Action\Section\Menu;
use Mmb\Core\Updates\Update;
use Mmb\Support\Db\ModelFinder;

abstract class InlineAction implements ConvertableToStep
{

    public Update $update;

    public function __construct(
        Update $update = null,
    )
    {
        $this->update = $update ?? app(Update::class);
    }

    protected         $initializerClass  = null;
    protected ?string $initializerMethod = null;

    /**
     * Set initializer method to reload with next update
     *
     * @param mixed  $object
     * @param string $method
     * @return $this
     */
    public function initializer($object, string $method)
    {
        if(!is_a($object, Action::class, true))
        {
            throw new \TypeError(
                sprintf(
                    "Initializer object must be instance of [%s], given [%s]",
                    Action::class,
                    is_string($object) ? $object : smartTypeOf($object)
                )
            );
        }

        $this->initializerClass = $object;
        $this->initializerMethod = $method;

        return $this;
    }

    /**
     * Get initializer
     *
     * @return array
     */
    public function getInitializer()
    {
        return [
            is_string($this->initializerClass) ?
                $this->initializerClass :
                get_class($this->initializerClass),
            $this->initializerMethod,
        ];
    }


    /**
     * Creating mode
     *
     * @var bool
     */
    public bool $isCreating = true;

    /**
     * Checks is creating mode
     *
     * @return bool
     */
    public function isCreating()
    {
        return $this->isCreating;
    }

    /**
     * Checks is loading mode
     *
     * @return bool
     */
    public function isLoading()
    {
        return !$this->isCreating;
    }

    /**
     * Fire callback on creating object (not invoke when user clicks)
     *
     * @param Closure(static $this): mixed $callback
     * @return $this
     */
    public function creating(Closure $callback)
    {
        if($this->isCreating())
        {
            $callback($this);
        }

        return $this;
    }

    /**
     * Fire callback on loading object (when user clicks)
     *
     * @param Closure(static $this): mixed $callback
     * @return $this
     */
    public function loading(Closure $callback)
    {
        if($this->isLoading())
        {
            $callback($this);
        }

        return $this;
    }

    protected array $storedWithData;
    protected array $withs            = [];
    public ?array   $cachedWithinData = null;

    /**
     * With properties
     *
     * If menu is loading, load properties from stored data
     *
     * @param string ...$names
     * @return $this
     */
    public function with(string ...$names)
    {
        array_push($this->withs, ...$names);

        if($this->isLoading() && isset($this->storedWithData) && $this->initializerClass)
        {
            foreach($names as $name)
            {
                if(array_key_exists($name, $this->storedWithData))
                {
                    $this->initializerClass->$name = $this->storedWithData[$name];
                }
            }
        }

        return $this;
    }

    protected array $haveData = [];

    /**
     * Save or reload data from menu data
     *
     * @param string $name
     * @param        $value
     * @param        $default
     * @return $this
     */
    public function have(string $name, &$value, $default = null)
    {
        if($this->isCreating())
        {
            if(count(func_get_args()) > 2)
            {
                $value = value($default);
            }
        }
        elseif($this->isLoading())
        {
            $value = $this->storedWithData[$name];
        }

        $this->haveData[$name] = $value;
        return $this;
    }

    protected function haveAs(string $name, &$value, $saving, $loading, bool $hasDefault = false, $default = null)
    {
        if($this->isCreating())
        {
            if($hasDefault)
            {
                $value = value($default);
            }

            $this->haveData[$name] = $saving($value);
        }
        elseif($this->isLoading())
        {
            $value = $loading(
                $this->haveData[$name] = $this->storedWithData[$name]
            );
        }

        return $this;
    }

    /**
     * Save or reload model data from menu data
     *
     * This function will save model key only
     *
     * @template T
     * @param string          $name
     * @param class-string<T> $class
     * @param Model|null|T    $value
     * @param null            $default
     * @return $this
     */
    public function haveModel(string $name, string $class, ?Model &$value, $default = null)
    {
        return $this->haveAs(
            $name,
            $value,
            fn($model) => ModelFinder::store($model),
            fn($key) => ModelFinder::findOrFail($class, $key),
            count(func_get_args()) > 3,
            $default,
        );
    }

    /**
     * Save or reload model data from menu data
     *
     * This function will save the model name and key only.
     *
     * @param string     $name
     * @param array      $classes
     * @param Model|null $value
     * @param null       $default
     * @return $this
     */
    public function haveDynamicModel(string $name, array $classes, ?Model &$value, $default = null)
    {
        return $this->haveAs(
            $name,
            $value,
            fn($model) => ModelFinder::storeDynamic($model),
            fn($key) => ModelFinder::findDynamicOrFail($classes, $key),
            count(func_get_args()) > 3,
            $default,
        );
    }

    /**
     * Save or reload enum
     *
     * This function will save the enum value only.
     *
     * @template T
     * @param string          $name
     * @param class-string<T> $class
     * @param Model|null|T    $value
     * @param null            $default
     * @return $this
     */
    public function haveEnum(string $name, string $class, &$value, $default = null)
    {
        if(is_a($class, \UnitEnum::class))
        {
            return $this->haveAs(
                $name,
                $value,
                fn($enum) => $enum->name,
                function($key) use($class)
                {
                    foreach($class::cases() as $case)
                    {
                        if($case->name == $key)
                        {
                            return $case;
                        }
                    }

                    abort(404); // TODO: test this methods
                },
                count(func_get_args()) > 3,
                $default,
            );
        }
        elseif(is_a($class, \BackedEnum::class))
        {
            return $this->haveAs(
                $name,
                $value,
                fn($enum) => $enum->value,
                fn($key) => $class::tryFrom($key) ?? abort(404),
                count(func_get_args()) > 3,
                $default,
            );
        }
    }

    protected bool $isReady = false;

    /**
     * Load and cache keys and other data
     *
     * @return void
     */
    public function makeReady()
    {
        if($this->isReady)
        {
            return;
        }

        $this->makeReadyThis();

        $this->isReady = true;
    }

    protected function makeReadyThis()
    {
        $this->makeReadyWithinData();
    }

    protected function makeReadyWithinData()
    {
        if(is_object($this->initializerClass))
        {
            $this->cachedWithinData = $this->haveData;

            foreach($this->withs as $with)
            {
                $this->cachedWithinData[$with] = $this->initializerClass->$with;
            }
        }
    }

    /**
     * Get variant
     *
     * @param string $name
     * @param        $default
     * @return mixed
     */
    public function get(string $name, $default = null)
    {
        if($this->isCreating())
        {
            if(in_array($name, $this->withs) && $this->initializerClass)
            {
                return $this->initializerClass->$name;
            }
            elseif(array_key_exists($name, $this->haveData))
            {
                return $this->haveData[$name];
            }
        }
        elseif($this->isLoading())
        {
            if(array_key_exists($name, $this->storedWithData))
            {
                return $this->storedWithData[$name];
            }
        }

        return value($default);
    }

    /**
     * Get data as model
     *
     * @param string $name
     * @param string $class
     * @param        $default
     * @return Model|mixed
     */
    public function getModel(string $name, string $class, $default = null)
    {
        $isDefault = false;
        $id = $this->get(
            $name, function() use (&$isDefault)
        {
            $isDefault = true;
        }
        );

        if($isDefault)
        {
            return value($default);
        }

        return ModelFinder::find($class, $id);
    }

    /**
     * Get variant
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->get(
            $name, static function() use ($name)
        {
            error_log("Undefined property [$name]");
            return null;
        }
        );
    }


    /**
     * @var class-string
     */
    protected $stepHandlerClass;

    /**
     * Load from step
     *
     * @param InlineStepHandler $step
     * @param Update            $update
     * @return void
     */
    protected function loadFromStep(InlineStepHandler $step, Update $update)
    {
        $this->storedWithData = $step->withinData ?: [];

        if(
            $step->initalizeClass &&
            is_a($step->initalizeClass, Action::class, true)
        )
        {
            $step->initalizeClass::initializeInlineOf($step->initalizeMethod, $this, $update);
        }
    }

    /**
     * Save to step
     *
     * @param InlineStepHandler $step
     * @return void
     */
    protected function saveToStep(InlineStepHandler $step)
    {
        $this->makeReady();

        [$step->initalizeClass, $step->initalizeMethod] = $this->getInitializer();
        $step->withinData = $this->cachedWithinData ?: null;
    }

    /**
     * @return StepHandler|null
     */
    public function toStep() : ?StepHandler
    {
        $step = $this->stepHandlerClass::make();
        $this->saveToStep($step);
        return $step;
    }

    /**
     * Handle from step handler
     *
     * @param InlineStepHandler $step
     * @param Update            $update
     * @return void
     */
    public static function handleFrom(InlineStepHandler $step, Update $update)
    {
        $inline = new static($update);
        $inline->isCreating = false;
        $inline->loadFromStep($step, $update);

        if($inline->handle($update) === false)
        {
            $update->skipHandler();
        }
    }

    /**
     * Handle update
     *
     * @param Update $update
     * @return mixed
     */
    public abstract function handle(Update $update);

}
