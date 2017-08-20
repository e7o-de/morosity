<?php

namespace e7o\Morosity\Executor;

interface ExecutionContext
{
	// For $currentLoops:
	const LOOP_CURRENT_INDEX = 0;
	const LOOP_COUNT = 1;
	const LOOP_ARRAY = 2;
	const LOOP_ID = 3;
	const LOOP_JUMPBACK = 4;
	const LOOP_VARS = 5;
	
	public function getLoop($above = 0);
	public function hasMacro($name);
	public function callMacro($name, $args);
}