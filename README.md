## Welcome to the Morosity Template Engine ##

This is a small template engine with a pretty similar syntax
to other existing ones, like Twig or Smarty or so.

The main idea behind it was to have something where I can reuse
existing .twig files without all that fat Twig implementation with
all that fancy caches etc. And, of course, just the fun of it.

Code quality might improve in future, as well as the feature set,
but for now it's pretty acceptable.

## Quick start ##

```
composer require e7o/morosity
```

For now, you might need an `"minimum-stability": "dev"` in your composer.json.

Simplest usage:

```
$template = new \e7o\Morosity\Morosity(
	new \e7o\Morosity\Loader\FileLoader('templates')
);
echo $template->render(
	'hello.htm',
	['var1' => 'hello world']
);
```

## Status & license ##

The engine is actually working.

License is kept simple, just a LGPLv3 or later.

## Tests? ##

Functionality:

```
php spec/verify.php
```

Speed:

```
php spec/measure.php
```

Yes, this aren't actual unit tests you might have expected.

