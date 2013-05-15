<?php
/**
 * Part of the Joomla Framework Logger Package
 *
 * @copyright  Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Log\Logger;

use Joomla\Filesystem\Folder;
use Joomla\Log\AbstractLogger;
use Joomla\Log\LogEntry;
use Joomla\Factory;

/**
 * Joomla! Formatted Text File Log class
 *
 * This class is designed to use as a base for building formatted text files for output. By
 * default it emulates the Syslog style format output. This is a disk based output format.
 *
 * @since  1.0
 */
class Formattedtext extends AbstractLogger
{
	/**
	 * @var    resource  The file pointer for the log file.
	 * @since  1.0
	 */
	protected $file;

	/**
	 * @var    string  The format for which each entry follows in the log file.  All fields must be named
	 *                 in all caps and be within curly brackets eg. {FOOBAR}.
	 * @since  1.0
	 */
	protected $format = '{DATETIME}	{PRIORITY}	{CATEGORY}	{MESSAGE}';

	/**
	 * @var    array  The parsed fields from the format string.
	 * @since  1.0
	 */
	protected $fields = array();

	/**
	 * @var    string  The full filesystem path for the log file.
	 * @since  1.0
	 */
	protected $path;

	/**
	 * Constructor.
	 *
	 * @param   array  &$options  Log object options.
	 *
	 * @since   1.0
	 */
	public function __construct(array &$options)
	{
		// Call the parent constructor.
		parent::__construct($options);

		// The name of the text file defaults to 'error.php' if not explicitly given.
		if (empty($this->options['text_file']))
		{
			$this->options['text_file'] = 'error.php';
		}

		// The name of the text file path defaults to that which is set in configuration if not explicitly given.
		if (empty($this->options['text_file_path']))
		{
			$this->options['text_file_path'] = Factory::getConfig()->log_path;
		}

		// False to treat the log file as a php file.
		if (empty($this->options['text_file_no_php']))
		{
			$this->options['text_file_no_php'] = false;
		}

		// Build the full path to the log file.
		$this->path = $this->options['text_file_path'] . '/' . $this->options['text_file'];

		// Use the default entry format unless explicitly set otherwise.
		if (!empty($this->options['text_entry_format']))
		{
			$this->format = (string) $this->options['text_entry_format'];
		}

		// Build the fields array based on the format string.
		$this->parseFields();
	}

	/**
	 * Destructor.
	 *
	 * @since   1.0
	 */
	public function __destruct()
	{
		if (is_resource($this->file))
		{
			fclose($this->file);
		}
	}

	/**
	 * Method to add an entry to the log.
	 *
	 * @param   LogEntry  $entry  The log entry object to add to the log.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function addEntry(LogEntry $entry)
	{
		// Initialise the file if not already done.
		if (!is_resource($this->file))
		{
			$this->initFile();
		}

		// Set some default field values if not already set.
		if (!isset($entry->clientIP))
		{
			// Check for proxies as well.
			if (isset($_SERVER['REMOTE_ADDR']))
			{
				$entry->clientIP = $_SERVER['REMOTE_ADDR'];
			}
			elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
			{
				$entry->clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'];
			}
			elseif (isset($_SERVER['HTTP_CLIENT_IP']))
			{
				$entry->clientIP = $_SERVER['HTTP_CLIENT_IP'];
			}
		}

		// If the time field is missing or the date field isn't only the date we need to rework it.
		if ((strlen($entry->date) != 10) || !isset($entry->time))
		{
			// Get the date and time strings in GMT.
			$entry->datetime = $entry->date->toISO8601();
			$entry->time = $entry->date->format('H:i:s', false);
			$entry->date = $entry->date->format('Y-m-d', false);
		}

		// Get a list of all the entry keys and make sure they are upper case.
		$tmp = array_change_key_case(get_object_vars($entry), CASE_UPPER);

		// Decode the entry priority into an English string.
		$tmp['PRIORITY'] = $this->priorities[$entry->priority];

		// Fill in field data for the line.
		$line = $this->format;

		foreach ($this->fields as $field)
		{
			$line = str_replace('{' . $field . '}', (isset($tmp[$field])) ? $tmp[$field] : '-', $line);
		}

		// Write the new entry to the file.
		if (!fwrite($this->file, $line . "\n"))
		{
			throw new \RuntimeException('Cannot write to log file.');
		}
	}

	/**
	 * Method to generate the log file header.
	 *
	 * @return  string  The log file header
	 *
	 * @since   1.0
	 */
	protected function generateFileHeader()
	{
		$head = array();

		// Build the log file header.

		// If the no php flag is not set add the php die statement.
		if (empty($this->options['text_file_no_php']))
		{
			// Blank line to prevent information disclose: https://bugs.php.net/bug.php?id=60677
			$head[] = '#';
			$head[] = '#<?php die(\'Forbidden.\'); ?>';
		}

		$head[] = '#Date: ' . gmdate('Y-m-d H:i:s') . ' UTC';
		$head[] = '';

		// Prepare the fields string
		$head[] = '#Fields: ' . strtolower(str_replace('}', '', str_replace('{', '', $this->format)));
		$head[] = '';

		return implode("\n", $head);
	}

	/**
	 * Method to initialise the log file.  This will create the folder path to the file if it doesn't already
	 * exist and also get a new file header if the file doesn't already exist.  If the file already exists it
	 * will simply open it for writing.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	protected function initFile()
	{
		// If the file doesn't already exist we need to create it and generate the file header.
		if (!is_file($this->path))
		{
			// Make sure the folder exists in which to create the log file.
			Folder::create(dirname($this->path));

			// Build the log file header.
			$head = $this->generateFileHeader();
		}
		else
		{
			$head = false;
		}

		// Open the file for writing (append mode).
		if (!$this->file = fopen($this->path, 'a'))
		{
			throw new \RuntimeException('Cannot open file for writing log');
		}

		if ($head)
		{
			if (!fwrite($this->file, $head))
			{
				throw new \RuntimeException('Cannot fput file for log');
			}
		}
	}

	/**
	 * Method to parse the format string into an array of fields.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function parseFields()
	{
		$this->fields = array();
		$matches = array();

		// Get all of the available fields in the format string.
		preg_match_all("/{(.*?)}/i", $this->format, $matches);

		// Build the parsed fields list based on the found fields.
		foreach ($matches[1] as $match)
		{
			$this->fields[] = strtoupper($match);
		}
	}
}