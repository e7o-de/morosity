<?php

namespace e7o\Morosity\Parser;

use \e7o\Morosity\Executor\VariableContext;

// TODO: This is a 20-minutes-hack, needs a more advanced approach
class ParamParser
{
	private $context;
	
	public function __construct(VariableContext $context = null)
	{
		$this->context = $context;
	}
	
	public function parse($params)
	{
		$result = [];
		$collected = '';
		$key = '';
		
		for ($i = 0; $i < strlen($params); $i++) {
			$char = $params[$i];
			switch ($char) {
				case ' ':
				case "\t":
				case "\n":
				case "\r":
					// Ignoring this characters
					break;
				case ':':
					$key = $collected;
					$collected = '';
					break;
				case '"':
				case "'":
					$j = $this->findEndOfQuote($params, $i);
					$collected = substr($params, $i, $j - $i);
					if ($this->context !== null) {
						$collected = $this->context->evaluateExpression($collected);
					}
					$i = $j;
				case ',':
				case ']':
					if (is_array($collected) || strlen($collected) > 0 || strlen($key) > 0) {
						(strlen($key) > 0) ? $result[$key] = $collected : $result[] = $collected;
					}
					$collected = '';
					$key = '';
					break;
				case '[';
					$j = $this->findEndOfArray($params, $i);
					$collected = $this->parse(substr($params, $i + 1, $j - $i - 1));
					$i = $j;
					break;
				default:
					$collected .= $char;
			}
		}
		
		if (is_array($collected) || strlen($collected) > 0 || strlen($key) > 0) {
			(strlen($key) > 0) ? $result[$key] = $collected : $result[] = $collected;
		}
		
		return $result;
	}
	
	private function findEndOfArray(&$string, $start)
	{
		$open = 1;
		
		for ($i = $start + 1; $i < strlen($string); $i++) {
			$char = $string[$i];
			switch ($char) {
				case '[';
					$open++;
					break;
				case ']':
					$open--;
					if ($open == 0) {
						return $i;
					}
					break;
			}
		}
		
		return strlen($string);
	}
	
	private function findEndOfQuote(&$string, $start)
	{
		$char = $string[$start];
		do {
			$nextQuote = strpos($string, $char, $start + 1);
			if ($nextQuote === false) {
				return strlen($string);
			}
			if ($string[$nextQuote - 1] != '\\') {
				return $nextQuote + 1;
			}
			$start = $nextQuote;
		} while (true);
	}
}
