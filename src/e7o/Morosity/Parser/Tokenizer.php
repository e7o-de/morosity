<?php

namespace e7o\Morosity\Parser;

use \e7o\Morosity\Parser\Tokens;

abstract class Tokenizer
{
	public static function parse(&$tmpl)
	{
		$parsed = [];
		preg_match_all('/\{[{%#]/', $tmpl, $tokenPositions, PREG_OFFSET_CAPTURE);
		$tokenPositions = $tokenPositions[0];
		
		$i = 0;
		$oldi = 0;
		$tokenCount = count($tokenPositions);
		for ($idx = 0; $idx < $tokenCount; $idx++) {
			$i = $tokenPositions[$idx][1];
			// Find closing tag
			$c = $tokenPositions[$idx][0][1];
			switch ($c) {
				case '{':
					$c = '}';
					$type = Tokens::VARIABLE;
					break;
				case '#':
					$type = Tokens::COMMENT;
					break;
				default:
					$type = null;
			}
			$toClose = $c . '}';
			$j = strpos($tmpl, $toClose, $i);
			if ($j > $i) {
				// Closing tag found, push both parts to array
				$parsed[] = [Tokens::PLAIN_TEXT, substr($tmpl, $oldi, $i - $oldi), null];
				
				$currentToken = substr($tmpl, $i, $j - $i);
				if ($type === null) {
					$commandType = explode(' ', trim(substr($currentToken, 2)), 2);
					$commandParams = isset($commandType[1]) ? $commandType[1] : '';
					$commandType = strtolower(trim($commandType[0]));
					switch ($commandType) {
						case 'for':
							$type = Tokens::LOOP_START;
							break;
						case 'endfor':
							$type = Tokens::LOOP_END;
							break;
						case 'if':
							$type = Tokens::CONDITION_IF;
							break;
						case 'elseif':
							$type = Tokens::CONDITION_ELSEIF;
							break;
						case 'else':
							$type = Tokens::CONDITION_ELSE;
							break;
						case 'endif':
							$type = Tokens::CONDITION_END;
							break;
						case 'set':
							$type = Tokens::VAR_SET;
							break;
						case 'include':
							$type = Tokens::TEMPLATE_INCLUDE;
							break;
						case 'import':
							$type = Tokens::TEMPLATE_IMPORT;
							break;
						case 'macro':
							$type = Tokens::FUNCTION_START;
							break;
						case 'endmacro':
							$type = Tokens::FUNCTION_END;
							break;
						default:
							$type = Tokens::CUSTOM_COMMAND;
					}
				} else {
					$commandParams = trim(substr($currentToken, 2));
				}
				$parsed[] = [$type, $commandParams, $commandType ?? null];
				// Remember positions
				$i = $j + 2;
				$oldi = $i;
			} else {
				// Parse error.
				throw new \Exception('Missing end tag around #' . $i);
			}
		}
		// Also add last piece of code to array
		$parsed[] = [Tokens::PLAIN_TEXT, substr($tmpl, $oldi), null];
		return $parsed;
	}
}
