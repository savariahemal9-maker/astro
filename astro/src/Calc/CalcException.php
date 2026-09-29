<?php
namespace App\Calc;

/** Raised when a calculation cannot be performed exactly. Never caught to substitute a value. */
class CalcException extends \RuntimeException {}
