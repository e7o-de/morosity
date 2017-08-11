<?php

namespace e7o\Morosity\Executor;

interface VariableContext
{
	/**
	 * Basically a method to convert a variable name (and maybe some additional
	 * params) to a actual value.
	 */
	public function evaluateExpression(
		$expression,
		ExecutionContext $context = null
	);
	
	/**
	 * Writes a variable value into store. Existing value with same name will
	 * be overwritten.
	 */
	public function addValue($name, $value);
	
	/**
	 * Get a variable value without any modification by filters etc.
	 */
	public function getValue($name);
	
	/**
	 * Checks if a variable is known at all.
	 */
	public function hasValue($name);
}