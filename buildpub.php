#!/usr/bin/env php
<?php

require dirname(__FILE__) . '/pubcommon.php';

class PubWriter
{
	private $format = array();
	private $fh;
	private $filename;

	public function ENumber($value, $n)
	{
		if ($value < 0)
			throw new Exception("Negative number passed to ENumber");

		if ($value > pow(253, $n))
			throw new Exception("Value too large for data type");

		$b = array_fill(0, $n, chr(254));

		for ($i = 0; $i < $n; ++$i)
		{
			$b[$i] = chr($value % 253 + 1);
			$value = floor($value / 253);

			if ($value == 0)
				break;
		}

		return implode('', $b);
	}

	public function __construct($format, $filename)
	{
		$this->format = $format;
		$this->fh = fopen($filename, 'wb');
		$this->filename = $filename;

		if (!$this->fh)
			throw new Exception("Failed to create pub file: " . $filename);
	}

	public function writeHeader($header)
	{
		fwrite($this->fh, $header['type'], 3);
		fwrite($this->fh, "\x0\x0\x0\x0");
		fwrite($this->fh, self::ENumber($header['num'], 2));
		fwrite($this->fh, self::ENumber(0, 1));
	}

	public function writeDummyEntry()
	{
		$this->writeEntry(array());
	}

	public function writeEOFEntry()
	{
		$this->writeEntry(array('name' => 'eof'));
	}

	public function writeEntry($entry)
	{
		foreach ($this->format as $fmt)
		{
			if ($fmt[PUB_KEY_TYPE] == PUB_STRING)
			{
				if (isset($entry[$fmt[PUB_KEY_NAME]]))
					$length = strlen($entry[$fmt[PUB_KEY_NAME]]);
				else
					$length = 0;

				fwrite($this->fh, self::ENumber($length, 1));
			}
		}

		foreach ($this->format as $fmt)
		{
			switch ($fmt[PUB_KEY_TYPE])
			{
				case PUB_STRING:
					if (isset($entry[$fmt[PUB_KEY_NAME]]))
						fwrite($this->fh, $entry[$fmt[PUB_KEY_NAME]]);
					break;

				default:
					// What a coincidence, INT1..INT4 are mapped to 1..4!
					if (isset($entry[$fmt[PUB_KEY_NAME]]))
						$value = $entry[$fmt[PUB_KEY_NAME]];
					else
						$value = 0;

					if (isset($fmt[PUB_KEY_ENUM]))
					{
						$lower_value = strtolower($value);

						foreach ($fmt[PUB_KEY_ENUM] as $k => $v)
						{
							if ($lower_value == strtolower($v))
							{
								$value = $k;
								break;
							}
						}
					}

					fwrite($this->fh, self::ENumber($value, $fmt[PUB_KEY_TYPE]));
			}
		}
	}

	public function finish()
	{
		fflush($this->fh);
		$rid = crc32(file_get_contents($this->filename)) | 0x01010101;
		fseek($this->fh, 3, SEEK_SET);
		fwrite($this->fh, pack('n', ($rid >> 16) & 0xFFFF) . pack('n', $rid & 0xFFFF), 4);
		return $rid;
	}
}

function generate_pub($filetype, $filename, $formatfile, $prefix)
{
	$entries = array();

	$format = pub_format_parse_file($formatfile);
	$pub = new PubWriter($format, $filename);

	$entries = array();
	$max_id = 0;

	foreach (glob($prefix . '*.json') as $jsonfile)
	{
		$entry = json_clean_decode(file_get_contents($jsonfile), true);

		if (!$entry)
			throw new Exception("Bad JSON file: $jsonfile");

		$entries[$entry['id']] = $entry;
		$max_id = max($max_id, $entry['id']);
	}

	$pub->writeHeader(array(
		'type' => $filetype,
		'num' => $max_id + 1
	));

	for ($i = 1; $i <= $max_id; ++$i)
	{
		// testing
		
		/*
		if ($filetype == 'ENF')
		{
			foreach (array('unknown1', 'unknown4') as $k)
			{
				if ($entries[$i][$k] != 0)
				{
					echo "$i (" . $entries[$i]['name'] . ") has $k = ", $entries[$i][$k], "\n";
				}
			}
		}
		*/

		if (isset($entries[$i]))
		{
			$pub->writeEntry($entries[$i]);
		}
		else
		{
			echo "$filetype #$i unused.\n";
			$pub->writeDummyEntry();
		}
	}

	$pub->writeEOFEntry();

	$rid = sprintf('%x', $pub->finish());

	$kbsize = number_format(filesize($filename) / 1024, 1);

	echo "Wrote $max_id+1 entries to $filename ($kbsize kB). RID=$rid\n";

	if ($kbsize > 62.5)
	{
		echo "WARNING! $filename may be too large.\n";
	}
}

function usage()
{
	echo "usage: buildpub.php [EIF|ENF|ESF|ECF] pubfile indir\n\n";
	echo "Builds a pub file from a directory full of JSON files.\n";
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
$indir = $argv[$argp++];

     if (substr($filename, -4) == '.eif') $filetype = 'EIF';
else if (substr($filename, -4) == '.enf') $filetype = 'ENF';
else if (substr($filename, -4) == '.esf') $filetype = 'ESF';
else if (substr($filename, -4) == '.ecf') $filetype = 'ECF';

if (substr($indir, -1) != '/')
	$indir .= '/';

$formatfile = strtolower($filetype) . '_format.txt';

if (!in_array($filetype, array('EIF', 'ENF', 'ESF', 'ECF')))
{
	echo "Unknown file type: ", $filetype, "\n";
	echo "Valid file types are: EIF, ENF, ESF, ECF\n";
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

if (!is_dir($indir))
{
	echo "Input directory does not exist.";
	exit(1);
}

generate_pub($filetype, $filename, $formatfile, $indir);

