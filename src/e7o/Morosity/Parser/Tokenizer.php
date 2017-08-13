<?php

namespace e7o\Morosity\Parser;

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
			if ($c == '{') {
				$c = '}';
			}
			$toClose = $c . '}';
			$j = strpos($tmpl, $toClose, $i);
			if ($j > $i) {
				// Closing tag found, push both parts to array
				$parsed[] = substr($tmpl, $oldi, $i - $oldi);
				$parsed[] = substr($tmpl, $i, $j - $i);
				// Remember positions
				$i = $j + 2;
				$oldi = $i;
			} else {
				// Parse error.
				throw new \Exception('Missing end tag around #' . $i);
			}
		}
		// Also add last piece of code to array
		$parsed[] = substr($tmpl, $oldi);
		return $parsed;
	}
}
