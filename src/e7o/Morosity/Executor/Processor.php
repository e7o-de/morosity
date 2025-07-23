<?php

namespace e7o\Morosity\Executor;

use \e7o\Morosity\Loader\Loader;
use \e7o\Morosity\Parser\ParamParser;
use \e7o\Morosity\Parser\Strings;

class Processor implements VariableContext, Environment
{
	// Extensions
	private $commandHandler = [];
	private $postProcessors = [];
	private $loader;
	private $functions = [];
	
	// Additonal values
	private $stack = [[]];
	
	private const TYPEHINT_VARIABLE = 0;
	private const TYPEHINT_CONSTANT = 1;
	private const TYPEHINT_STRING = 2;
	private const TYPEHINT_ARRAY = 3;
	
	public function __construct()
	{
	}
	
	public function setLoader(Loader $loader)
	{
		$this->loader = $loader;
	}
	
	public function getLoader(): \e7o\Morosity\Loader\Loader
	{
		return $this->loader;
	}
	
	public function setCommandHandler(string $forType, Handler $handler)
	{
		$this->commandHandler[$forType] = $handler;
	}
	
	public function setPostProcessors($processors)
	{
		$this->postProcessors = $processors;
	}
	
	public function addFunction($name, \Closure $function = null)
	{
		$this->functions[$name] = $function;
	}
	
	public function addValue(string $name, $value, $canBubble = false)
	{
		$nameParts = ParamParser::splitOnly($name, false, ['.', '[', ']']);
		if (count($nameParts) > 1) {
			$name = $nameParts[0];
			if ($nameParts[1][0] == '[') {
				$nameParts[1] = substr($nameParts[1], 1, -1);
				$nameParts[1] = $this->evaluateExpression($nameParts[1]);
			}
		} else if (substr($name, -2) == '[]') {
			$nameParts[1] = '';
			$name = substr($name, 0, -2);
		}
		
		if ($canBubble) {
			$stackPos = $this->findContextForIdentifier($name);
		} else {
			$stackPos = count($this->stack) - 1;
		}
		
		if (count($nameParts) > 1) {
			if (empty($this->stack[$stackPos][$name])) {
				if (strlen($nameParts[1]) > 0) {
					$this->stack[$stackPos][$name] = [$nameParts[1] => $value];
				} else {
					$this->stack[$stackPos][$name] = [$value];
				}
			} else if (!is_array($this->stack[$stackPos][$name])) {
				throw new \Exception('Cannot add value to non-array: ' . $name);
			} else if (strlen($nameParts[1]) > 0) {
				$this->stack[$stackPos][$name][$nameParts[1]] = $value;
			} else {
				$this->stack[$stackPos][$name][] = $value;
			}
		} else {
			$this->stack[$stackPos][$name] = $value;
		}
	}
	
	private function findContextForIdentifier($name)
	{
		for ($i = count($this->stack) - 1; $i >= 0; $i--) {
			if (isset($this->stack[$i][$name])) {
				return $i;
			}
		}
		return count($this->stack) - 1;
	}
	
	public function getValue(string $name)
	{
		for ($i = count($this->stack) - 1; $i >= 0; $i--) {
			if (isset($this->stack[$i][$name])) {
				return $this->stack[$i][$name];
			}
		}
		// TODO: Error/warning when unknown
		return null;
	}
	
	public function hasValue(string $name)
	{
		for ($i = count($this->stack) - 1; $i >= 0; $i--) {
			if (isset($this->stack[$i][$name])) {
				return true;
			}
		}
		// TODO: Error/warning when unknown
		return false;
	}
	
	public function addValues(array $values, $canBubble = false)
	{
		foreach ($values as $key => $value) {
			$this->addValue($key, $value);
		}
	}
	
	public function setValues(array $values)
	{
		$this->stack[count($this->stack) - 1] = $values;
	}
	
	public function pushStack(array $data)
	{
		$this->stack[count($this->stack)] = $data;
	}
	
	public function popStack()
	{
		unset($this->stack[count($this->stack) - 1]);
	}
	
	public function render(string $template)
	{
		$executor = new DefaultExecutor($this, $this, $this->commandHandler);
		$executor->setPostProcessors($this->postProcessors);
		return $executor->render($template);
	}
	
	public function evaluateExpression(string $expression, ExecutionContext $context = null)
	{
		return $this->evaluateExpressionInt($expression, $context)[0];
	}
	
	private function evaluateExpressionInt(string $expression, ExecutionContext $context = null)
	{
		$typeHint = static::TYPEHINT_VARIABLE;
		
		$parts = ParamParser::splitOnly($expression, false, ['|']);
		$expression = array_shift($parts);
		$pipeModifier = $parts;
		
		// Check some special syntax
		if (strtolower($expression) == 'false') {
			$val = false;
			$typeHint = static::TYPEHINT_CONSTANT;
		} else if (strtolower($expression) == 'true') {
			$val = true;
			$typeHint = static::TYPEHINT_CONSTANT;
		} else if (strtolower($expression) == 'null') {
			$val = null;
			$typeHint = static::TYPEHINT_CONSTANT;
		} else if (strtolower($expression) == '') {
			$val = '';
			$typeHint = static::TYPEHINT_STRING;
		} else if (is_array($expression)) {
			$val = $expression;
			$typeHint = static::TYPEHINT_ARRAY;
		} else if (is_float($expression)) {
			$val = (float)$expression;
			$typeHint = static::TYPEHINT_CONSTANT;
		} else if (is_numeric($expression)) {
			$val = (int)$expression;
			$typeHint = static::TYPEHINT_CONSTANT;
		} else if (substr($expression, 0, 5) == 'loop.') {
			// Loop variable, first check context
			if ($context === null) {
				throw new \Exception('Cannot access LOOP variables without context');
			}
			// Remove first five characters
			$expression = substr($expression, 5);
			// Remove _additional_ dots from string
			$dots = $this->countAndRemoveDots($expression);
			// Fetch the matching loop
			$loop = $context->getLoop($dots);
			// Check for predefined values
			$val = 0;
			switch ($expression) {
				// Current index
				case 'index':
					$val = 1;
				case 'index0':
					$val += $loop[ExecutionContext::LOOP_CURRENT_INDEX];
					break;
				// Remaining elements
				case 'revindex':
					$val = -1;
				case 'revindex0':
					$val += $loop[ExecutionContext::LOOP_COUNT] - $loop[ExecutionContext::LOOP_CURRENT_INDEX];
					break;
				// Total count of elements
				case 'length':
					$val = $loop[ExecutionContext::LOOP_COUNT] + 1;
					break;
				// Positions
				case 'first':
					$val = (bool)($loop[ExecutionContext::LOOP_CURRENT_INDEX] == 0);
					break;
				case 'last':
					$val = (bool)($loop[ExecutionContext::LOOP_CURRENT_INDEX] == $loop[ExecutionContext::LOOP_COUNT]);
					break;
				// Everything else
				default:
					// Get array
					if (!is_array($loop[ExecutionContext::LOOP_ARRAY])) {
						$val = $loop[ExecutionContext::LOOP_ARRAY];
						break;
					}
					$arr = &$loop[ExecutionContext::LOOP_ARRAY][$loop[ExecutionContext::LOOP_CURRENT_INDEX]];
					if (strlen($expression) == 0) {
						// Direct value ("LOOP."), direct access
						$val = $arr;
					} else {
						// Read out of the array
						$val = isset($arr[$expression]) ? $arr[$expression] : null;
					}
					break;
			}
			$typeHint = static::TYPEHINT_CONSTANT;
		} else if (preg_match('/^([\'"]?)[0-9a-z]+\1\.\.([\'"]?)[0-9a-z]+\2$/i', $expression)) {
			// Range: 2..5 or sth like this
			$range = explode('..', $expression);
			$val = range($this->evaluateExpression($range[0]), $this->evaluateExpression($range[1]));
			$typeHint = static::TYPEHINT_ARRAY;
		} else if ($expression[0] == "'" || $expression[0] == '"') {
			$val = substr($expression, 1, -1);
			$val = Strings::unescape($val);
			$typeHint = static::TYPEHINT_STRING;
		} else if (!empty($context) && (preg_match('/^[a-z0-9_.]+ *\(/i', $expression))) {
			// Function call -- Macro or internal function
			$pos = strpos($expression, '(');
			$name = trim(substr($expression, 0, $pos));
			$params = substr($expression, $pos + 1);
			$params = substr($params, 0, strrpos($params, ')'));
			$args = [];
			$params = ParamParser::splitOnly($params, false, [',']);
			foreach ($params as $param) {
				$args[] = $this->evaluateExpression($param, $context);
			}
			if ($context->hasMacro($name)) {
				$val = $context->callMacro($name, $args);
			} else if (!empty($this->functions[$name])) {
				$f = $this->functions[$name];
				$val = $f(...$args);
			} else if (Functions::has($name)) {
				$val = array_shift($args);
				$val = Functions::call($name, $val, $args);
			} else {
				throw new \Exception('Unknown macro/function ' . $name . ' called');
			}
			$typeHint = static::TYPEHINT_STRING;
		} else if ($expression[0] == '[') {
			$val = $this->evaluateArray($expression, $context);
			$typeHint = static::TYPEHINT_ARRAY;
		} else {
			// Array access handling etc.
			$expressionSplit = ParamParser::splitOnly($expression, false, ['.', '[', ']', '(', ')', ',']);
			// First part must be a variable name, as it didn't match any of the given
			// options like string, number, range ...
			$val = $this->getValue($expressionSplit[0]);
			
			for ($i = 1; $i < count($expressionSplit); $i++) {
				$expressionSub = $expressionSplit[$i];
				if ($expressionSub[0] == '[' && $expressionSub[-1] == ']') {
					$expressionSub = substr($expressionSub, 1, -1);
					// Only on [...] syntax it can be a real expression; otherwise
					// it's a constant index name
					$expressionSub = $this->evaluateExpressionInt($expressionSub, $context)[0];
				}
				if (!is_string($expressionSub) && !is_int($expressionSub)) {
					// TODO: error
					$val = 'Invalid array key type: ' . gettype($expressionSub);
				}
				
				if (isset($val[$expressionSub])) {
					$val = $val[$expressionSub];
				} else {
					// TODO: If there's stuff left ($i < count-1), we should check
					// what to do (show an error?)
					$val = '';
					break;
				}
			}
		}
		
		// Post-process
		if (!empty($pipeModifier)) {
			foreach ($pipeModifier as $param) {
				$param = trim($param);
				if (strlen($param) == 0) {
					continue;
				}
				if (($pPos = strpos($param, '(')) !== false && $param[-1] == ')') {
					$shift = substr($param, 0, $pPos);
					$param = substr($param, $pPos + 1, strlen($param) - $pPos - 2);
					$param = ParamParser::split($param);
					array_unshift($param, $shift);
				} else {
					$param = ParamParser::split($param);
				}
				// Preprocess params
				for ($i = 1; $i < count($param); $i++) {
					$param[$i] = $this->evaluateExpression($param[$i], $context);
				}
				// Call
				$func = strtolower(array_shift($param));
				// TODO: needs the same stuff as a few lines above, so: unify
				if (!empty($this->functions[$func])) {
					$f = $this->functions[$func];
					$val = $f($val);
				} else {
					try {
						$val = Functions::call($func, $val, $param);
					} catch (\TypeError $e) {
						// TODO: Error message
						$val = '(invalid type)';
					}
				}
			}
		}
		// Done
		return [$val, $typeHint];
	}
	
	private function countAndRemoveDots(string &$string)
	{
		for ($i = 0; $i < strlen($string); $i++) {
			if ($string[$i] != '.') {
				$string = substr($string, $i);
				return $i;
			}
		}
		$i = strlen($string);
		$string = '';
		return $i;
	}
	
	private function evaluateArray($string, $context)
	{
		$string = substr($string, 1, -1);
		$sub = ParamParser::splitOnly($string, false, [',', '[', ']']);
		$val = [];
		foreach ($sub as $s) {
			$s = trim($s);
			if (strlen($s) == 0) {
				// trailing comma
				continue;
			}
			$val[] = $this->evaluateExpression($s, $context);
		}
		return $val;
	}
}

