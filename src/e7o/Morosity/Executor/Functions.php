<?php

namespace e7o\Morosity\Executor;

class Functions
{
	private static $alias = [
		'e' => 'encode',
		'split' => 'explode',
		'join' => 'implode',
		'len' => 'count',
		'length' => 'count',
		'slice' => 'cut',
	];
	
	public static function call(string &$func, &$value, array &$params)
	{
		if (isset(static::$alias[$func])) {
			$func = static::$alias[$func];
		}
		if (static::has($func)) {
			return static::$func($value, $params);
		}
	}
	
	public static function has(string &$func)
	{
		return isset(static::$alias[$func]) || is_callable(__CLASS__ . '::' . $func);
	}
	
	public static function array(&$value, &$param)
	{
		if (!isset($value[$param[0]])) {
			return '';
		} else {
			return $value[$param[0]];
		}
	}
	
	public static function explode(&$value, &$param)
	{
		return @explode($param[0], $value);
	}
	
	public static function implode(&$value, &$param)
	{
		if (is_array($value)) {
			return @implode($param[0], $value);
		} else if (is_array($param)) {
			return @implode('', $param);
		} else {
			return $value;
		}
	}
	
	public static function count(&$value, &$param)
	{
		if (is_array($value)) {
			return count($value);
		} elseif (is_string($value)) {
			return strlen($value);
		} else {
			return $value ? 1 : 0;
		}
	}
	
	public static function reverse(&$value, &$param)
	{
		if (is_array($value)) {
			return array_reverse($value);
		} elseif (is_string($value)) {
			return strrev($value);
		} else {
			return '';
		}
	}
	
	public static function dump(&$value, &$param)
	{
		$value = str_replace('=>', '', print_r($value, true));
		$value = str_replace('[', '<small>[', $value);
		$value = str_replace(']', ']</small>', $value);
		$value = str_replace(' ', '&nbsp;', $value);
		return nl2br($value);
	}
	
	public static function uppercase(&$value, &$param)
	{
		return strtoupper($value);
	}
	
	public static function lowercase(&$value, &$param)
	{
		return strtolower($value);
	}
	
	public static function rot13(&$value, &$param)
	{
		return str_rot13($value);
	}
	
	public static function md5(&$value, &$param)
	{
		return md5($value);
	}
	
	public static function hash(&$value, &$param)
	{
		try {
			return hash($param[0] ?? 'md5', $value);
		} catch (\ValueError $e) {
			// TODO: Real error
			return '(invalid hashing algorithm ' . $param[0] . ')';
		}
	}
	
	public static function shuffle(&$value, &$param)
	{
		if (is_array($value)) {
			shuffle($value);
			return $value;
		} else {
			return str_shuffle($value);
		}
	}
	
	public static function wordcount(&$value, &$param)
	{
		return str_word_count($value);
	}
	
	public static function cut(&$value, &$param)
	{
		if (is_array($value)) {
			if (count($param) > 1) {
				return array_slice($value, (int)$param[0], (int)$param[1]);
			} else {
				return array_slice($value, 0, (int)$param[0]);
			}
		} else {
			if (count($param) > 1) {
				return substr($value, (int)$param[0], (int)$param[1]);
			} else {
				return substr($value, 0, (int)$param[0]);
			}
		}
	}
	
	public static function substr(&$value, &$param)
	{
		if (isset($param[1])) {
			return substr($value, (int)$param[0], (int)$param[1]);
		} else {
			return substr($value, (int)$param[0]);
		}
	}
	
	public static function paragraphcut(&$value, &$param)
	{
		$pos = strpos($value, "\n", (int)$param[0] - 10);
		if ($pos !== false) {
			return substr($value, 0, $pos);
		} else {
			return $value;
		}
	}
	
	public static function wordcut(&$value, &$param)
	{
		preg_match('/[[:space:]]/', $value, $captured, PREG_OFFSET_CAPTURE, (int)$param[0] - 5);
		if (count($captured) > 0 || $captured[0][1] > 10) {
			$pos = $captured[0][1];
		} else {
			$pos = (int)$param[0];
		}
		return substr($value, 0, $pos);
	}
	
	public static function concat(&$value, &$param)
	{
		// ToDo: Untested
		for ($i = 0; $i < count($param); $i++) {
			$value .= $param[$i];
		}
		return $value;
	}
	
	public static function repeat(&$value, &$param)
	{
		return str_repeat($value, $param[0]);
	}
	
	public static function replace(&$value, &$param)
	{
		return str_replace($param[0], $param[1], $value);
	}
	
	public static function remove(&$value, &$param)
	{
		$newVal = $value;
		foreach ($param as $p) {
			if (!is_array($p)) {
				$p = [$p];
			}
			foreach ($p as $singlePar) {
				if (is_array($value)) {
					foreach ($value as $k => $v) {
						if ($v === $singlePar) {
							unset($newVal[$k]);
						}
					}
				} else {
					$newVal = str_replace($singlePar, '', $newVal);
				}
			}
		}
		return $newVal;
	}
	
	public static function contains(&$value, &$param)
	{
		if (is_array($value)) {
			foreach ($value as $v) {
				if ($v === $param[0]) {
					return true;
				}
			}
			return false;
		} else {
			return is_array($param) && count($param) > 0 && stripos($value, $param[0]) !== false;
		}
	}
	
	public static function encode(&$value, &$param)
	{
		return @htmlentities($value, \ENT_QUOTES, 'UTF-8');
	}
	
	public static function striphtml(&$value, &$param)
	{
		return strip_tags($value, '<br><p>');
	}
	
	public static function nl2br(&$value, &$param)
	{
		return nl2br($value);
	}
	
	public static function subtract(&$value, &$param)
	{
		if (!isset($param[0])) {
			$param[0] = 0;
		}
		return $value - $param[0];
	}
	
	public static function add(&$value, &$param)
	{
		if (!isset($param[0])) {
			$param[0] = 0;
		}
		return $value + $param[0];
	}
	
	public static function increment(&$value, &$param)
	{
		return $value + 1;
	}
	
	public static function decrement(&$value, &$param)
	{
		return $value - 1;
	}
	
	public static function multiply(&$value, &$param)
	{
		if (!isset($param[0])) {
			$param[0] = 1;
		}
		return $value * (double)$param[0];
	}
	
	public static function divide(&$value, &$param)
	{
		if (isset($param[0]) && is_numeric($param[0]) && $param[0] != 0) {
			return $value / (double)$param[0];
		} else {
			return 'error';
		}
	}
	
	public static function date(&$value, &$param)
	{
		// Use default format when no specification is made
		if (empty($param[0])) {
			$param[0] = \DateTime::W3C;
		}
		if ($value instanceof \DateTime) {
			$value = $value->format($param[0]);
		} else if (is_numeric($value)) {
			$value = date($param[0], (int)$value);
		} else {
			// Try to parse value as date
			try {
				if (empty($value)) {
					$value = 'Now';
				}
				$value = new \DateTime($value);
				$value = $value->format($param[0]);
			} catch (\Exception $e) {
				$value = '(invalid date given)';
			}
		}
		// TODO: Replace it with language-specific month name
		return $value;
	}
	
	public static function now(&$value, &$param)
	{
		if (is_string($value)) {
			$null = null;
			$p = [$value];
			return static::date($null, $p);
		} else {
			return new \DateTime('Now');
		}
	}
	
	public static function int(&$value, &$param)
	{
		return (int)$value;
	}
	
	public static function hex(&$value, &$param)
	{
		return dechex($value);
	}
	
	public static function binary(&$value, &$param)
	{
		return decbin($value);
	}
	
	public static function char(&$value, &$param)
	{
		return chr($value);
	}
	
	public static function ord(&$value, &$param)
	{
		return ord($value);
	}
	
	public static function round(&$value, &$param)
	{
		if (!isset($param[0]) || !is_numeric($param[0])) {
			$param[0] = 0;
		}
		return round($value, (int)$param[0]);
	}
	
	public static function json(&$value, &$param)
	{
		return @json_encode($value);
	}
	
	public static function unjson(&$value, &$param)
	{
		return @json_decode($value, true);
	}
	
	public static function if(&$value, &$param)
	{
		if ($value) {
			return $param[0] ?? 1;
		} else {
			return $param[1] ?? null;
		}
	}
	
	public static function not(&$value, &$param)
	{
		return !((bool)$param[0] ?? $value);
	}
}
