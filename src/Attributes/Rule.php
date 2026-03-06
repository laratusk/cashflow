<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class Rule
{
    /** @var list<string|array<int, string>> */
    public array $rules;

    /**
     * @param  string|array<int, string>  ...$rules
     */
    public function __construct(string|array ...$rules)
    {
        $this->rules = array_values($rules);
    }
}
