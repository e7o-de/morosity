<?php

namespace e7o\Morosity\Parser;

class ParamParser
{
	/**
	* Splits a text into tokens, separated by "|" and ",". Additional characters
	* can be given in $splitInAddition (they need to be regex-safe, so e.g. a "["
	* should be passed as "\[").
	*/
	public static function split(string $str, $splitInAddition = '')
	{
		$l = strlen($str);
		$start = 0;
		$quotesOpen = null;
		$escaped = 0;
		$charsToRemove = [];
		$collected = [];
		$parents = 0;
		preg_match_all('/["\'\\\\()|,' . $splitInAddition . ']/', $str, $positions, PREG_OFFSET_CAPTURE);
		$positions = $positions[0];
		$l = count($positions);
		for ($i = 0; $i < $l; $i++) {
			$pos = $positions[$i][1];
			$c = $str[$pos];
			switch ($c) {
				case '\\':
					if ($parents == 0) {
						$charsToRemove[] = $pos - $start;
					}
					if ($pos < $l && $str[$pos + 1] == '\\') {
						$i++;
					} else {
						$escaped = 2;
					}
					break;
				case '"':
				case "'":
					if ($escaped > 0) {
						// Skip
					} else if ($c === $quotesOpen) {
						$quotesOpen = null;
					} else {
						$quotesOpen = $c;
					}
					break;
				case '(':
					if ($quotesOpen === null) {
						$parents++;
					}
					break;
				case ')':
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
						$start = $pos + 1;
					}
					break;
			}
			$escaped--;
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
