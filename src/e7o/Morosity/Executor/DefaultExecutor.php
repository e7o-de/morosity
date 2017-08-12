<?php

namespace e7o\Morosity\Executor;

use \e7o\Morosity\Parser\Tokenizer;

class DefaultExecutor implements ExecutionContext
{
	// Data accessor
	protected $context;
	protected $commandHandler;
	
	// Execution
	private $position;
	private $parsed;
	private $currentLoops, $jumpBack;
	private $ifHadMatch, $ifType;
	private $recursiveStack;
	
	public function __construct(VariableContext $context, array $commandHandler)
	{
		$this->context = $context;
		$this->commandHandler = $commandHandler;
	}
	
	/**
	 * This method renders a template. THIS METHOD IS NOT TRHEAD-SAFE OR SIMILAR.
	 * It can be executed just one at a time. Create a new instance of that object
	 * to render while other rendering is in progress.
	 */
	public function render($template)
	{
		// Split into pieces
		$this->parsed = new Tokenizer($template);
		
		// Parse information
		// Collect results
		$result = '';
		// Array for remembering line numbers to which line we have to jump back
		$this->jumpBack = array();
		// A stack for active loops
		$this->currentLoops = array();
		// Set to -1 that the first increment doesn't jump after the 0 :)
		$this->position = -1;
		// Saves if one condition was matched; array for nested.
		$this->ifHadMatch = array();
		// Empty variables
		$this->variables = [];
		// Ignore lines (non matching if-branches)
		$ignoreTillNextIfKeyword = array();
		// Ignore nested structures if necessary
		$ignoreDeeperIfKeywords = 0;
		$ignoreTillNextEndfor = 0;
		// Type of IF (normal, and, or ...)
		$this->ifType = array();
		// Deep of the if
		$ifDeep = 0;
		// Recursion
		$this->recursiveStack = array();
		// Go!
		$commandCounter = 0;
		while ($this->position < count($this->parsed)) {
			// Prevent infinite loops; increase this value if you really have an
			// use case for that.
			if ($commandCounter++ > 250000) {
				throw new \Exception('Too much commands executed, killing to prevent infinite loop');
			}
			// Increment for loop
			$this->position++;
			
			if (!isset($this->parsed[$this->position])) {
				continue;
			}
			// Read line
			$currentLine = $this->parsed[$this->position];
			
			// Something to ignore?
			// If we are in a branch we have to ignore, we can continue with
			// the next step of our loop.
			if (!isset($ignoreTillNextIfKeyword[$ifDeep])) {
				$ignoreIfMode = false;
			} else {
				$ignoreIfMode = count($ignoreTillNextIfKeyword) > 0 && $ignoreTillNextIfKeyword[$ifDeep];
			}
			if (
				$ignoreIfMode
				&& !is_array($currentLine)
				&& substr($currentLine, 0, 5) != '{% if'
				&& substr($currentLine, 0, 7) != '{% else'
				&& substr($currentLine, 0, 8) != '{% endif'
			) {
				continue;
			}
			if ($ignoreTillNextEndfor > 0) {
				if (!isset($currentLine)) {
				} else if (substr($currentLine, 0, 6) == '{% for') {
					$ignoreTillNextEndfor++;
				} else if (substr($currentLine, 0, 9) == '{% endfor') {
					$ignoreTillNextEndfor--;
				}
				continue;
			}
			// Check type
			if (substr($currentLine, 0, 2) == '{%') {
				$commandType = explode(' ', trim(substr($currentLine, 2)), 2);
				$commandParams = isset($commandType[1]) ? $commandType[1] : ''; // Might be a syntax error in all cases if not given?
				$commandType = strtolower(trim($commandType[0]));
				switch ($commandType) {
					case 'for':
						// Find current loop
						$doLoop = $this->initLoop($commandParams);
						if ($doLoop == false) {
							// Fast-forward to stuf after the loop
							$ignoreTillNextEndfor = 1;
						}
						break;
					case 'endfor':
						// Ends a loop
						// Read current loop information from stack
						end($this->currentLoops);
						$loop = &$this->currentLoops[key($this->currentLoops)];
						// Check if counter is smaller than the maximum loop count
						if ($loop[ExecutionContext::LOOP_CURRENT_INDEX] < $loop[ExecutionContext::LOOP_COUNT]) {
							// Yes: Just increase the counter and go back to start of the loop
							$loop[ExecutionContext::LOOP_CURRENT_INDEX]++;
							next($loop[ExecutionContext::LOOP_ARRAY]);
							$this->position = $loop[ExecutionContext::LOOP_JUMPBACK];
							// ... and set variables
							if (!empty($loop[ExecutionContext::LOOP_VARS][0])){
								$key = key($loop[ExecutionContext::LOOP_ARRAY]);
								$this->context->addValue($loop[ExecutionContext::LOOP_VARS][0][0], $key);
							}
							if (!empty($loop[ExecutionContext::LOOP_VARS][1])){
								$value = current($loop[ExecutionContext::LOOP_ARRAY]);
								$this->context->addValue($loop[ExecutionContext::LOOP_VARS][1][0], $value);
							}
						} else {
							// No: Clean up ...
							$this->restoreValues($this->currentLoops[key($this->currentLoops)][ExecutionContext::LOOP_VARS]);
							// ... and remove from stack
							unset($this->currentLoops[key($this->currentLoops)]);
						}
						break;
					case 'if':
						if ($ignoreIfMode) {
							// Ignore this one but count it
							$ignoreDeeperIfKeywords++;
						} else {
							// Add new IF to stack
							$ifDeep++;
							$this->ifHadMatch[$ifDeep] = false;
							$ignoreTillNextIfKeyword[$ifDeep] = false;
							$this->ifType[$ifDeep] = $commandType;
						}
					case 'elseif':
					case 'else':
						if ($ignoreDeeperIfKeywords <= 0) {
							// Read if any condition already matched
							$match = $this->ifHadMatch[$ifDeep];
							// Set ignore to true in advance
							$ignoreTillNextIfKeyword[$ifDeep] = true;
							// When there was never a matching in this case
							if (!$match) {
								// Process conditions
								$match = $this->checkConditions($commandParams);
								// Match :)
								if ($match) {
									// We had a match, remember this
									$this->ifHadMatch[$ifDeep] = true;
									// Don't ignore this block
									$ignoreTillNextIfKeyword[$ifDeep] = false;
								}
							}
						}
						break;
					case 'endif':
						if ($ignoreDeeperIfKeywords > 0) {
							$ignoreDeeperIfKeywords--;
						} else {
							// Remove from stack
							$this->ifHadMatch[$ifDeep] = null;
							$ignoreTillNextIfKeyword[$ifDeep] = null;
							$this->ifType[$ifDeep] = null;
							$ifDeep--;
						}
						break;
					case 'recursive':
						// Starts a recursion
						// First, find the variable
						$a = $this->evaluateExpression($commandParams);
						// Put array and jumpback position on stack
						$this->recursiveStack[] = array($a, $this->position);
						break;
					case 'recursion':
						// Do a recursive call
						// Get array
						$rec = $this->evaluateExpression($commandParams);
						// Is array with elements, especially the one we need for recursion?
						if (is_array($rec) && count($rec) > 0) {
							// Add array + remember jumpback position
							$this->recursiveStack[] = array($rec, $this->position);
							// Jump back (at the moment everytime the same)
							$this->position = $this->recursiveStack[0][1];
						}
						break;
					case 'endrecursive':
						// Ends a recursion, get deep
						end($this->recursiveStack);
						$recDeep = count($this->recursiveStack) - 1;
						// If we aren't ontop, we have to jump back
						if ($recDeep > 0) {
							// Jump back position is the RECURSION call
							$rec = &$this->recursiveStack[key($this->recursiveStack)];
							$this->position = $rec[1];
						}
						// Remove from stack
						unset($this->recursiveStack[key($this->recursiveStack)]);
						break;
					case 'set':
						// Define a variable/array
						$parts = explode('=', $commandParams, 2);
						$this->context->addValue(trim($parts[0]), $this->evaluateExpression(trim($parts[1])));
						break;
					default:
						if (isset($this->commandHandler[$commandType])) {
							$result .= $this->commandHandler[$commandType]
								->handleCommand($this->context, $commandType, $commandParams)
							;
						} else {
							throw new \Exception('Unknown command: ' . $commandType);
						}
				}
			} else if (substr($currentLine, 0, 2) == '{{') {
				$value = $this->evaluateExpression(trim(substr($currentLine, 2)));
				$result .= $value;
			} else if (substr($currentLine, 0, 2) == '{#') {
				// Ignore
			} else {
				// No special code, simply add it
				$result .= $currentLine;
			}
		}

		return $result;
	}
	
	public function getLoop($above = 0)
	{
		$this->scrollBackInArray($this->currentLoops, $above);
		return current($this->currentLoops);
	}
	
	public function getRecursion($above = 0)
	{
		$this->scrollBackInArray($this->recursiveStack, $above);
		return current($this->recursiveStack);
	}
	
	private function scrollBackInArray(&$array, $above)
	{
		end($array);
		for ($i = 0; $i < $above; $i++) {
			prev($array);
		}
	}
	
	public function getRecursionDeep()
	{
		return count($this->recursiveStack);
	}
	
	private function checkConditions($conditionString)
	{
		if (strlen($conditionString) == 0) {
			return true;
		}
		
		$mode = end($this->ifType);
		switch ($mode) { // todo: remove
			case 'ifand': $mode = false; break;
			case 'ifor': $mode = true; break;
			default: $mode = false;
		}
		
		$conditions = explode(';', $conditionString);
		foreach ($conditions as $cond) {
			if ($this->checkSingleCondition($cond) == $mode) {
				return $mode;
			}
		}
		return !$mode;
	}
	
	private function checkSingleCondition($condition)
	{
		// Detect not condition
		if ($condition[0] == '!') {
			$condition = substr($condition, 1);
			$not = true;
		} else {
			$not = false;
		}
		// Check operators; we need to ensure the correct order of the operators
		// (> behind >= to prevent false detections)
		$operators = array('==', '!=', '<=', '>=', '<', '>', ' IN ', ' NOTIN ', ' is');
		$found = false;
		foreach ($operators as $op) {
			if (strpos($condition, $op) !== false) {
				$found = true;
				break;
			}
		}
		if ($found) {
			$v1 = trim(substr($condition, 0, strpos($condition, $op)));
			$v2 = trim(substr($condition, strpos($condition, $op) + strlen($op)));
		} else {
			$v1 = trim($condition);
			$op = '__has_value';
			$v2 = null;
		}
		
		if ($v1 !== null) {
			$v1 = $this->evaluateExpression($v1);
		}
		
		if ($op === 'is') {
			switch ($v2) {
				case 'empty':
					return empty($v1);
				case 'not empty':
					return !empty($v1);
				case 'odd':
					return ($v1 % 2 == 1) ^ $not;
				case 'even':
					return ($v1 % 2 == 0) ^ $not;
				case 'numeric':
					return (is_numeric($v1)) ^ $not;
				default:
					// todo, unknown comparision
			}
		} else {
			if ($v2 !== null) {
				$v2 = $this->evaluateExpression($v2);
			}
			
			switch (trim($op)) {
				case '==':
					return (bool)($v1 == $v2) ^ $not;
				case '!=':
					return (bool)($v1 != $v2) ^ $not;
				case '<=':
					return (bool)($v1 <= $v2) ^ $not;
				case '>=':
					return (bool)($v1 >= $v2) ^ $not;
				case '<':
					return (bool)($v1 < $v2) ^ $not;
				case '>':
					return (bool)($v1 > $v2) ^ $not;
				case 'IN':
					if (is_array($v2)) {
						return in_array($v1, $v2) ^ $not;
					} else {
						return (strpos($v2, $v1) !== false) ^ $not;
					}
				case 'NOTIN':
					if (is_array($v2)) {
						return !in_array($v1, $v2) ^ $not;
					} else {
						return (strpos($v2, $v1) === false) ^ $not;
					}
				case '__has_value': // Internal keyword
					$hasValue =
						is_array($v1)
						|| is_string($v1) && strlen($v1) > 0
						|| is_object($v1)
						|| is_numeric($v1)
						|| $v1 === true
					;
					return $hasValue ^ $not;
				default:
					return false ^ $not;
			}
		}
	}
	
	private function initLoop($command)
	{
		$parts = explode(' in ', $command, 2);
		
		// Get variable names
		$vars = explode(',', $parts[0]);
		switch (count($vars)) {
			case 1:
				$loopVarNames = [null, [trim($vars[0]), null]];
				break;
			case 2:
				$loopVarNames = [[trim($vars[0]), null], [trim($vars[1]), null]];
				break;
			default:
				throw new Exception('Invalid number of variables in for loop: ' . $parts[0]);
		}
		
		// Get array to loop over
		// TODO: If not set throw syntax error
		$loopArr = $this->evaluateExpression($parts[1]);
		// Converting objects if known
		if ($loopArr instanceof \PDOStatement) {
			$newLoopArr = array();
			while ($loopVal = $loopArr->fetch()) {
				$newLoopArr[] = $loopVal;
			}
			$loopArr = $newLoopArr;
		}
		
		// Not doing anything on an empty array
		if (empty($loopArr)) {
			return false;
		}
		
		// Init loop
		$this->storeVariables($loopVarNames);
		# todo: loopArr nie null weil if(empty)
		if (!is_array($loopArr) && !($loopArr instanceof \Traversable)) {
			// Unknown variable
			throw new \Exception('Bad LOOP initialisator: ' . $command);
		} else {
			// Set info
			reset($loopArr);
			$loopInfo = [
				self::LOOP_CURRENT_INDEX => 0,
				self::LOOP_COUNT => count($loopArr) - 1,
				self::LOOP_ARRAY => &$loopArr,
				self::LOOP_JUMPBACK => $this->position,
				self::LOOP_RECURSIVE => ($command == 'RECURSIVE'),
				self::LOOP_VARS => $loopVarNames,
			];
			if (count($loopArr) > 0) {
				if (!empty($loopVarNames[0])) {
					$this->context->addValue($loopVarNames[0][0], key($loopArr));
				}
				if (!empty($loopVarNames[1])) {
					$this->context->addValue($loopVarNames[1][0], current($loopArr));
				}
			}
		}
		// Set nesting loops information - put loop id on stack
		$this->currentLoops[] = $loopInfo;
		
		return true;
	}
	
	private function storeVariables(&$arr)
	{
		foreach ($arr as &$val) {
			if ($this->context->hasValue($val[0])) {
				$val[1] = $this->context->getValue($val[0]);
			}
		}
	}
	
	private function restoreValues(&$arr)
	{
		foreach ($arr as $val) {
			$this->context->addValue($val[0], $val[1]);
		}
	}
	
	private function evaluateExpression($expression)
	{
		return $this->context->evaluateExpression($expression, $this);
	}
}
