<?php

namespace e7o\Morosity\Parser;

class ParamParser
{
	private const TYPE_SEPARATOR = 1;
	private const TYPE_BETWEEN = 2;
	
	/**
	* Splits a text into tokens, separated by "|" and ",". Additional characters
	* can be given in $splitInAddition.
	*/
	public static function split(string $str, $keepSeparators = false, $splitInAddition = [], $preventSplit = [])
	{
		return static::splitInternal($str, $keepSeparators, [], $splitInAddition, $preventSplit);
	}
	
	public static function splitOnly(string $str, $keepSeparators = false, $tokens = [])
	{
		return static::splitInternal($str, $keepSeparators, $tokens);
	}
	
	private static function splitInternal(string $str, $keepSeparators = false, $splitOnly = [], $splitInAddition = [], $preventSplit = [])
	{
		if (empty($splitOnly)) {
			$defaultSplit = '"\'\\()[]|,';
			// TODO: Cache or input from outside as ready-to-use string (via preparation function)
			foreach ($preventSplit as $token) {
				$defaultSplit = str_replace($token, '', $defaultSplit);
			}
			$defaultSplit = preg_quote($defaultSplit);
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
			$regex = '(["\'()\\\\,\[\]]' . (count($s) > 0 ? '|' . implode('|', $s) : '') . ')';
		}
		
		$collected = [];
		static::splitGoThrough(
			$str,
			$regex,
			function ($type, $part) use (&$collected, $keepSeparators, $splitOnly) {
				if (!$keepSeparators && $type == static::TYPE_SEPARATOR) {
					return;
				}
				if ($type == static::TYPE_SEPARATOR && !in_array($part, $splitOnly)) {
					return;
				}
				if (strlen($part) > 0) {
					if ($part[0] == '(' && $part[-1] == ')') {
						$part = substr($part, 1, -1);
					}
					$collected[] = $part;
				}
			},
			$splitOnly
		);
		
		return $collected;
	}
	
	private static function splitGoThrough(&$str, $regex, $onMatch, $splitChars)
	{
		preg_match_all('/' . $regex . '/m', $str, $positions, PREG_OFFSET_CAPTURE);
		$positions = $positions[0];
		
		$start = 0;
		$charsToRemove = [];
		
		$functionName = false;
		$quotesOpen = null;
		$parents = 0;
		$array = 0;
		
		$l = count($positions);
		for ($i = 0; $i < $l; $i++) {
			$pos = $positions[$i][1];
			$c = trim($positions[$i][0]);
			$opened = false;
			$closed = false;
			switch ($c) {
				case '\\':
					if ($parents == 0 && $array == 0) {
						$cn = $str[$pos + 1] ?? 'x';
						$cn2 = $str[$pos + 2] ?? 'y';
						if ($cn == "\r" || $cn == "\n") {
							$charsToRemove[] = $pos - $start;
							$charsToRemove[] = $pos - $start + 1;
							if (($cn2 == "\r" || $cn2 == "\n") && $cn != $cn2) {
								$charsToRemove[] = $pos - $start + 2;
							}
						}
						if ($positions[$i + 1][1] == $pos + 1) {
							$i++;
						}
					}
					continue 2;
				case '"':
				case "'":
					if ($c === $quotesOpen) {
						$quotesOpen = null;
					} else {
						$quotesOpen = $c;
					}
					break;
				case '(':
					if ($quotesOpen === null && $array == 0) {
						$parents++;
						if ($parents == 1) {
							$opened = true;
						}
					}
					break;
				case '[':
					if ($quotesOpen === null && $parents == 0) {
						$array++;
						if ($array == 1) {
							$opened = true;
						}
					}
					break;
				case ')':
					if ($quotesOpen === null && $array == 0) {
						$parents--;
						if ($parents == 0) {
							$closed = true;
						}
					}
					break;
				case ']':
					if ($quotesOpen === null && $parents == 0) {
						$array--;
						if ($array == 0) {
							$closed = true;
						}
					}
					break;
			}
			
			if (!empty($splitChars) && !in_array($c, $splitChars)) {
				// Don't split, as it's a non-split character
			} else if ($opened || $closed) {
				if ($opened == true && $c == '(' && ctype_alnum(substr($str, $start, $pos - $start))) {
					// Function name, don't separate this
					$functionName = true;
				} else {
					if ($pos - $start > 0) {
						$offset = $closed ? 1 : 0;
						$onMatch(
							static::TYPE_BETWEEN,
							static::removeChars(
								substr(
									$str,
									$start - ($functionName ? 0 : $offset),
									$pos - $start + $offset * ($functionName ? 1 : 2)
								),
								$charsToRemove
							)
						);
						$functionName = false;
					}
					$start = $pos + strlen($positions[$i][0]);
				}
			} else if ($quotesOpen === null && $parents == 0 && $array == 0) {
				if ($pos - $start > 0) {
					$offset = ($c == '"' || $c == "'") ? 1 : 0;
					$onMatch(
						static::TYPE_BETWEEN,
						static::removeChars(substr($str, $start, $pos - $start + $offset), $charsToRemove)
					);
				}
				if ($offset == 0) {
					$onMatch(static::TYPE_SEPARATOR, $c);
				}
				$charsToRemove = [];
				$start = $pos + strlen($positions[$i][0]);
			}
		}
		
		$onMatch(static::TYPE_BETWEEN, static::removeChars(substr($str, $start), $charsToRemove));
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
