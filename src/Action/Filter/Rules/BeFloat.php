<?php

namespace Mmb\Action\Filter\Rules;

use Mmb\Core\Updates\Update;

class BeFloat extends BeText
{

    public function __construct(
        public      $numberError = null,
                    $textError = null,
                    $messageError = null,
        public bool $unsigned = false,
    )
    {
        parent::__construct($textError, $messageError);
    }

    public function pass(Update $update, &$value)
    {
        parent::pass($update, $value);

        $text = $update->message->text;
        if ($this->unsigned)
        {
            if (!is_numeric($text) || !str_contains($text, '.'))
            {
                $this->fail(value($this->numberError ?? __('mmb::filter.unsigned-float')));
            }
            $value = (float) $text;

            if ($value < 0)
            {
                $this->fail(value($this->numberError ?? __('mmb::filter.unsigned-float')));
            }
        }
        else
        {
            if (!is_numeric($text) || !str_contains($text, '.'))
            {
                $this->fail(value($this->numberError ?? __('mmb::filter.float')));
            }
            $value = (float) +$text;
        }
    }

}
