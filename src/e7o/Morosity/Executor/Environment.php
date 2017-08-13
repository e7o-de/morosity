<?php

namespace e7o\Morosity\Executor;

interface Environment
{
	public function getLoader(): \e7o\Morosity\Loader\Loader;
}