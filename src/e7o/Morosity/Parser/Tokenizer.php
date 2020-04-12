<?php

namespace e7o\Morosity\Parser;

use \e7o\Morosity\Parser\Tokens;

abstract class Tokenizer
{
	public static function parse(&$tmpl)
	{
		$parsed = [];
		$templateLength = strlen($tmpl);
		$posStart = 0;
		$previousEnd = 0;
		$nextCheckPos = 0;
		while ($posStart < $templateLength) {
			$posStart = strpos($tmpl, '{', $nextCheckPos);
			if ($posStart === false) {
				break;
			}
			$c = $tmpl[$posStart + 1];
			switch ($c) {
				case '{':
					$c = '}';
					$type = Tokens::VARIABLE;
					break;
				case '#':
					$type = Tokens::COMMENT;
					break;
				case '%':
					// We don't know the type yet, depends on the content
					$type = null;
					break;
				default:
					// Random {, just skipping this one
					$nextCheckPos = $posStart + 1;
					continue 2;
			}
			$toClose = $c . '}';
			$posEnd = strpos($tmpl, $toClose, $posStart + 2);
			if ($posEnd !== false) {
				// Closing tag found, push both parts to array
				if ($posStart - $previousEnd > 0) {
					$parsed[] = [Tokens::PLAIN_TEXT, substr($tmpl, $previousEnd, $posStart - $previousEnd), null];
				}
				
				$currentToken = trim(substr($tmpl, $posStart + 2, $posEnd - $posStart - 2));
				$custom = null;
				if ($type === null) {
					$commandType = explode(' ', $currentToken, 2);
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
						case 'switch':
							$type = Tokens::SWITCH_START;
							break;
						case 'case':
							$type = Tokens::SWITCH_CASE;
							break;
						case 'endswitch':
							$type = Tokens::SWITCH_END;
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
							$custom = $commandType;
					}
				} else {
					$commandParams = $currentToken;
				}
				$parsed[] = [$type, $commandParams, $custom];
				$nextCheckPos = $previousEnd = $posEnd + 2;
			} else {
				// Parse error.
				throw new \Exception('Missing end tag for tag started at #' . $posStart);
			}
		}
		// Also add last piece of code to array
		$parsed[] = [Tokens::PLAIN_TEXT, substr($tmpl, $previousEnd), null];
		return $parsed;
	}
}
