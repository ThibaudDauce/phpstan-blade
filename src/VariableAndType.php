<?php

namespace ThibaudDauce\PHPStanBlade;

use PHPStan\Type\Type;
use PHPStan\Type\ThisType;
use PHPStan\Type\VerbosityLevel;

class VariableAndType
{
    public function __construct(
        public string $name,
        public Type $type,
    ) {}

    public function get_type_as_string(): string
    {
        /**
         * Some PHPStan types are wrong in our case.
         * 
         * The first one is the `ThisType` when we pass `$this` to a view.
         *
         * For example, inside a `Invoice` model:
         * return view('invoice', [
         *     'invoice' => $this,
         * ]);
         * 
         * We will get:
         * new VariableAndType('invoice', ThisType<Invoice>)
         * 
         * And generate a docblock:
         * [AT]var $this(Invoice) $invoice
         * 
         * This docblock is incorrect so we need to replace the `ThisType<Invoice>` by just `Invoice` to have the correct docblock:
         * [AT]var Invoice $invoice
         * 
         * The method ThisType::getStaticObjectType() returns the type of the object inside `ThisType`.
         */
        if ($this->type instanceof ThisType) {
            return $this->type->getStaticObjectType()->describe(VerbosityLevel::typeOnly());
        }

        return $this->type->describe(VerbosityLevel::typeOnly());
    }
}