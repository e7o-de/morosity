<?php

namespace e7o\Morosity\Parser;

abstract class Strings
{
	private static $simpleEscapers = [
		't' => "\t", 'n' => "\n", 'r' => "\r", 'a' => "\a",
		'b' => "\b", 'f' => "\f", 'v' => "\v",
		'"' => '"', "'" => "'",
	];
	
	public static function unescape($string)
	{
		// TODO: Ensure no double replacements
		// TODO: Add \0xABC etc.
		foreach (static::$simpleEscapers as $search => $replace) {
			$string = str_replace('\\' . $search, $replace, $string);
		}
		return $string;
	}
}
