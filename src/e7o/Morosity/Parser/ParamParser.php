<?php

namespace e7o\Morosity\Parser;

class ParamParser
{
	private static $simpleEscapers = [
		't' => "\t", 'n' => "\n", 'r' => "\r", 'a' => "\a",
		'b' => "\b", 'f' => "\f", 'v' => "\v", "\n" => null, "\r" => null,
	];
	
	/**
	* Splits a text into tokens, separated by "|" and ",". Additional characters
	* can be given in $splitInAddition (they need to be regex-safe, so e.g. a "["
	* should be passed as "\[").
	*/
	public static function split(string $str, $keepSeparators = false, $splitInAddition = [], $preventSplit = [])
	{
		return static::splitInternal($str, $keepSeparators, [], $splitInAddition, $preventSplit);
	}
	
	public static function splitOnly(string $str, $tokens = [])
	{
		return static::splitInternal($str, false, $tokens);
	}
	
	private static function splitInternal(string $str, $keepSeparators = false, $splitOnly = [], $splitInAddition = [], $preventSplit = [])
	{
		$l = strlen($str);
		$start = 0;
		$quotesOpen = null;
		$charsToRemove = [];
		$collected = [];
		$parents = 0;
		if (empty($splitOnly)) {
			$defaultSplit = '"\'\\\\()\[\]|,';
			// TODO: Cache or input from outside as ready-to-use string (via preparation function)
			foreach ($preventSplit as $token) {
				$defaultSplit = str_replace(preg_quote($token), '', $defaultSplit);
			}
			$altSplits = [];
			foreach ($splitInAddition as $token) {
				if (strlen($token) == 1) {
					$defaultSplit .= preg_quote($token);
				} else {
					if (ctype_alnum($token[0])) {
						$altSplits[] = '[^a-z0-9]' . preg_quote($token);
					} else {
						$altSplits[] = preg_quote($token);
					}
				}
			}
			$regex = '[' . $defaultSplit . ']';
			if (!empty($altSplits)) {
				$regex = '(' . $regex . '|' . implode('|', $altSplits) . ')';
			}
		} else {
			$s = [];
			foreach ($splitOnly as $token) {
				if (ctype_alnum($token[0])) {
					$s[] = '[^a-z0-9]' . preg_quote($token);
				} else {
					$s[] = preg_quote($token);
				}
			}
			$regex = '(["\'()\\\\]|' . implode('|', $s) . ')';
		}
		preg_match_all('/' . $regex . '/', $str, $positions, PREG_OFFSET_CAPTURE);
		$positions = $positions[0];
		$l = count($positions);
		for ($i = 0; $i < $l; $i++) {
			$pos = $positions[$i][1];
			$c = $positions[$i][0];
			switch ($c) {
				case '\\':
					if ($parents == 0) {
						$charsToRemove[] = $pos - $start;
						$cn = $str[$pos + 1];
						if (array_key_exists($cn, static::$simpleEscapers)) {
							if (static::$simpleEscapers[$cn] === null) {
								$charsToRemove[] = $pos - $start + 1;
							} else {
								$str[$pos + 1] = static::$simpleEscapers[$cn];
							}
						// todo: check for \u0000 or \x0000 and so
						} // keep everything else as it is
					}
					if ($positions[$i + 1][1] == $pos + 1) {
						// Skipping next one if it's the thing we've escaped
						$i++;
					}
					break;
				case '"':
				case "'":
					if ($c === $quotesOpen) {
						$quotesOpen = null;
					} else {
						$quotesOpen = $c;
					}
					break;
				case '(':
				case '[':
					// No differentiation between types - not ideal, but easy and
					// with correct syntax usage by the user, it works as expected.
					if ($quotesOpen === null) {
						$parents++;
					}
					break;
				case ')':
				case ']':
					if ($quotesOpen === null) {
						$parents--;
					}
					break;
				// All others, like "|" and "," and additionally specified ones
				default:
					if ($quotesOpen === null && $parents == 0) {
						if ($pos - $start > 0) {
							$collected[] = static::removeChars(substr($str, $start, $pos - $start), $charsToRemove);
						}
						$charsToRemove = [];
						if (!empty($splitOnly)) {
							$collected[] = trim($c);
						}
						$start = $pos + strlen($c);
					}
					break;
			}
			if ($keepSeparators && empty($splitOnly)) {
				$collected[] = trim($c);
			}
		}
		
		$collected[] = static::removeChars(substr($str, $start), $charsToRemove);
		return $collected;
	}
	
	private static function removeChars(string $str, array $chars)
	{
		$r = [];
		$len = count($chars);
		$last = 0;
		for ($i = 0; $i < $len; $i++) {
			$r[] = substr($str, $last, $chars[$i] - $last);
			$last = $chars[$i] + 1;
		}
		$r[] = substr($str, $last);
		return trim(implode('', $r));
	}
}
