#!/usr/bin/env php
<?php

require dirname(__FILE__) . '/pubcommon.php';

class PubReader
{
	private $format = array();
	private $fh;

	public function Number($b)
	{
		if (is_string($b))
			$b = array_map('ord', str_split($b));

		if (!is_array($b))
			throw new Exception("Number() was given a non-string/array type.");

		if (count($b) < 1 || $b[0] == 0 || $b[0] == 254) $b[0] = 1;
		if (count($b) < 2 || $b[1] == 0 || $b[1] == 254) $b[1] = 1;
		if (count($b) < 3 || $b[2] == 0 || $b[2] == 254) $b[2] = 1;
		if (count($b) < 4 || $b[3] == 0 || $b[3] == 254) $b[3] = 1;

		--$b[0];
		--$b[1];
		--$b[2];
		--$b[3];

		return ($b[3]*16194277 + $b[2]*64009 + $b[1]*253 + $b[0]);
	}

	public function __construct($format, $filename)
	{
		$this->format = $format;
		$this->fh = fopen($filename, 'rb');

		if (!$this->fh)
			throw new Exception("Failed to open pub file: " . $filename);
	}

	public function readHeader()
	{
		$type = fread($this->fh, 3);
		$rid = fread($this->fh, 4);
		$num = fread($this->fh, 2);
		$zero = fread($this->fh, 1);

		return array(
			'type' => $type,
			'rid' => self::Number($rid),
			'num' => self::Number($num),
			'zero' => self::Number($zero)
		);
	}

	public function readNextEntry()
	{
		$data = array();

		$stringlens = array();

		foreach ($this->format as $fmt)
		{
			if ($fmt[PUB_KEY_TYPE] == PUB_STRING)
			{
				$stringlens[$fmt[PUB_KEY_NAME]] = self::Number(fread($this->fh, 1));
			}
		}

		foreach ($this->format as $fmt)
		{
			switch ($fmt[PUB_KEY_TYPE])
			{
				case PUB_STRING:
					$namelen = $stringlens[$fmt[PUB_KEY_NAME]];

					if ($namelen > 0)
						$name = fread($this->fh, $namelen);
					else
						$name = '';

					$data[$fmt[PUB_KEY_NAME]] = $name;
					break;

				default:
					// What a coincidence, INT1..INT4 are mapped to 1..4!
					$value = self::Number(fread($this->fh, $fmt[PUB_KEY_TYPE]));

					if (isset($fmt[PUB_KEY_ENUM][$value]))
						$value = $fmt[PUB_KEY_ENUM][$value];

					$data[$fmt[PUB_KEY_NAME]] = $value;
			}
		}

		return $data;
	}
};

function dump_pub($filetype, $filename, $formatfile, $prefix)
{
	$format = pub_format_parse_file($formatfile);
	$reader = new PubReader($format, $filename);

	$header = $reader->readHeader();
	
	$names = new NameManager();

	if ($header['type'] != $filetype)
		throw new Exception("$filetype file is not an $filetype file!");

	for ($i = 0; $i < $header['num']; ++$i)
	{
		$entry = $reader->readNextEntry();

		if ($entry['name'] == 'eof')
			break;

		$gender = null;

		if ($filetype == 'EIF')
		{
			if ($entry['type'] == 'Armor')
				$gender = $entry['spec2'];
		}

		$idname = $names->generateName($entry['name'], $gender);

		// Re-order keys
		{
			// Re-order unknowns to the bottom
			foreach (array_keys($entry) as $k)
			{
				if (substr($k, 0, 7) == 'unknown')
				{
					$v = $entry[$k];
					unset($entry[$k]);
					$entry[$k] = $v;
				}

				if (substr($k, 0, 5) == 'Xdrop')
				{
					unset($entry[$k]);
				}
			}

			// Re-order ID and name to the top...
			$name = $entry['name'];
			unset($entry['name']);
			if ($filetype == 'ESF') $shout = $entry['shout'];
			if ($filetype == 'EIF') $size = $entry['size'];
			if ($filetype == 'EIF') $weight = $entry['weight'];
			if ($filetype == 'ESF') unset($entry['shout']);
			if ($filetype == 'EIF') unset($entry['size']);
			if ($filetype == 'EIF') unset($entry['weight']);
			$entry = array_reverse($entry);
			$entry['name'] = $name;
			if ($filetype == 'ESF') $entry['shout'] = $shout;
			if ($filetype == 'EIF') $entry['size'] = $size;
			if ($filetype == 'EIF') $entry['weight'] = $weight;
			$entry['id'] = $i+1;
			$entry = array_reverse($entry);
		}

		// Low quality clean up logic
		{
			$cleanup = array();

			foreach ($entry as $k => $v)
			{
				if ($v === 0)
					$cleanup[$k] = $k;
			}

			if ($filetype == 'EIF')
			{
				$cleanup['unknown3'] = 'unknown3';
			}

			foreach ($cleanup as $k)
			{
				unset($entry[$k]);
			}
		}

		$filename = "$prefix$idname.json";

		echo "Dumping: $filename\n";
		file_put_contents($filename, json_encode($entry, JSON_PRETTY_PRINT));
	}
}

function usage()
{
	echo "usage: autogen.php [EIF|ENF|ESF|ECF] pubfile outdir\n\n";
	echo "Generates a directory of JSON files from a pub file.\n";
	exit(1);
}

$argp = 1;

if ($argc < 3)
	usage();

$filetype = 'Could not by detected from file extension.';

if ($argc > 3)
{
	$filetype = $argv[$argp++];
}

$filename = $argv[$argp++];
$outdir = $argv[$argp++];

     if (substr($filename, -4) == '.eif') $filetype = 'EIF';
else if (substr($filename, -4) == '.enf') $filetype = 'ENF';
else if (substr($filename, -4) == '.esf') $filetype = 'ESF';
else if (substr($filename, -4) == '.ecf') $filetype = 'ECF';

if (substr($outdir, -1) != '/')
	$outdir .= '/';

$formatfile = strtolower($filetype) . '_format.txt';

if (!in_array($filetype, array('EIF', 'ENF', 'ESF', 'ECF')))
{
	echo "Unknown file type: ", $filetype, "\n";
	echo "Valid file types are: EIF, ENF, ESF, ECF\n";
	exit(1);
}

if (!is_file($filename))
{
	echo "Can not find $filename.\n";
	exit(1);
}

if (!is_file($formatfile))
{
	$formatfile2 = dirname(__FILE__) . '/' . $formatfile;

	if (!is_file($formatfile2))
	{
		echo "Can not find $formatfile.\n";
		exit(1);
	}

	$formatfile = $formatfile2;
}

if (!is_dir($outdir))
{
	echo "Output directory did not exist. Creating $outdir...\n";
	
	if (!mkdir($outdir))
	{
		echo "Could not create directory.\n";
		exit(1);
	}
}

dump_pub($filetype, $filename, $formatfile, $outdir);

