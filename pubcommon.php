<?php

// Matt Browne
// http://php.net/manual/en/function.json-decode.php#112735
function json_clean_decode()
{
	$args = func_get_args();

	if (count($args) < 1)
		return null;

	$args[0] = preg_replace("#(/\*([^*]|[\r\n]|(\*+([^*/]|[\r\n])))*\*+/)|([\s\t]//.*)|(^//.*)#", '', $args[0]);

	return call_user_func_array('json_decode', $args);
}

class NameManager
{
	private $name_usages = array();

	public function generateName($item_name, $gender = null)
	{
		$name = strtolower($item_name);
		$name = preg_replace('/[\\(\\[].*?[\\)\\]]/', '', $name);
		$name = trim($name);
		$name = preg_replace('/[^a-z0-9_]/', '_', $name);
		$name = str_replace('__', '_', $name);

		if (!is_null($gender))
		{
			if ($gender) $name .= '_m';
			else         $name .= '_f';
		}

		if (!isset($this->name_usages[$name]))
		{
			$this->name_usages[$name] = 1;
		}
		else
		{
			++$this->name_usages[$name];
			$name .= '_' . $this->name_usages[$name];
		}

		return $name;
	}
};

define('PUB_INT1', 1);
define('PUB_INT2', 2);
define('PUB_INT3', 3);
define('PUB_INT4', 4);
define('PUB_STRING', 5);

define('PUB_KEY_TYPE', 0);
define('PUB_KEY_NAME', 1);
define('PUB_KEY_ENUM', 2);

function pub_format_parse_line($line, $line_no = null)
{
	static $type_map = array(
		'int1' => PUB_INT1,
		'int2' => PUB_INT2,
		'int3' => PUB_INT3,
		'int4' => PUB_INT4,
		'string' => PUB_STRING
	);

	$format = array();
	$parts = explode(' ', $line);

	if (!is_null($line_no))
		$line_message = " on line " . $line_no;
	else
		$line_message = '';

	if (count($parts) < 2)
		throw new Exception("Invalid line in pub format file" . $line_message . ": " . $line);

	if (!isset($type_map[$parts[0]]))
		throw new Exception("Unknown type in pub format file" . $line_message . ": " . $parts[0]);

	$format[PUB_KEY_TYPE] = $type_map[$parts[0]];
	$format[PUB_KEY_NAME] = $parts[1];

	if (count($parts) >= 3)
	{
		if ($parts[2] != '{')
			throw new Exception("Bad line in pub format file" . $line_message . ": " . $line);

		$ended = false;

		$enum = array();

		for ($i = 3; $i < count($parts); ++$i)
		{
			if ($parts[$i] == '')
				continue;

			if ($ended)
				throw new Exception("Junk after '}' in pub format file" . $line_message . ": " . $line);

			if ($parts[$i] == '}')
			{
				$ended = true;
				continue;
			}

			$value = $parts[$i];

			if ($value == '?')
				$value = null;

			$enum[] = $value;
		}

		if (!$ended)
			throw new Exception("Missing '}' in pub format file" . $line_message . ": " . $line);

		$format[PUB_KEY_ENUM] = $enum;
	}

	return $format;
}

function pub_format_parse_file($filename)
{
	$format = array();
	$lines = file($filename);

	$unk_count = 0;
	$drop_count = 0;

	foreach ($lines as $line_no => $line)
	{
		$line = trim($line);

		if (strlen($line) == 0)
			continue;

		if (substr($line, 0, 1) == '#')
			continue;

		$entry = pub_format_parse_line($line, $line_no+1);

		if ($entry[PUB_KEY_NAME] == '?')
			$entry[PUB_KEY_NAME] = 'unknown'. (++$unk_count);

		if ($entry[PUB_KEY_NAME] == '-')
			$entry[PUB_KEY_NAME] = 'Xdrop'. (++$drop_count);

		$format[] = $entry;
	}

	return $format;
}
