<?php

namespace Mmb\Support\Caller;

use Attribute;

/**
 * @deprecated
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
abstract class ParameterPassingInstead extends CallingParameterAttribute
{

    public abstract function getInsteadOf($value);

}
