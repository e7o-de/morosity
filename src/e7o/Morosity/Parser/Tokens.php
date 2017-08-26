<?php

namespace e7o\Morosity\Parser;

abstract class Tokens
{
	const PLAIN_TEXT = 0;
	const VARIABLE = 5;
	const CUSTOM_COMMAND = 10;
	const COMMENT = 15;
	
	const CONDITION_IF = 50;
	const CONDITION_ELSEIF = 55;
	const CONDITION_ELSE = 60;
	const CONDITION_END = 65;
	
	const LOOP_START = 100;
	const LOOP_END = 110;
	
	const TEMPLATE_INCLUDE = 150;
	const TEMPLATE_IMPORT = 155;
	
	const FUNCTION_START = 200;
	const FUNCTION_END = 205;
	
	const VAR_SET = 250;
}
