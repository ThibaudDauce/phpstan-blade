<?php

namespace ThibaudDauce\PHPStanBlade;

use PHPStan\Type\Type;

class VariableAndType
{
    public function __construct(
        public string $name,
        public Type $type,
    ) {}
}