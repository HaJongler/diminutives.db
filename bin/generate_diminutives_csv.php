<?php
/* -*- indent-tabs-mode: t -*- */
/* Copyright 2010 Daniel Trebbien
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

set_time_limit(0);

define("MEDIAWIKI_API_URL", "http://en.wiktionary.org/w/api.php");


//-----------------------------------------------------------------------------
// Parse command line options
//-----------------------------------------------------------------------------
function print_usage() {
	global $argv;
	fputs(STDERR, "Usage: php ${argv[0]} [OPTION]...\n");
	fputs(STDERR, "Write a CSV file of formal given names and common diminutives of each to\n" . "`./gen/\$(SEX)_diminutives.csv`.\n\n");
	fputs(STDERR, "Options:\n");
	fputs(STDERR, "  -s SEX, --sex SEX         either \"male\" or \"female\"\n");
	fputs(STDERR, "  --help                    display this help message and exit\n");
	fputs(STDERR, "\n");
}

$opt = getopt("s:", array(
	"sex:",
	"help"
));

if (isset($opt["help"])) {
	print_usage();
	exit(0);
} else if (empty($opt["s"]) && empty($opt["sex"])) {
	fputs(STDERR, "Sex (\"male\" or \"female\") must be specified.\n");
	print_usage();
	exit(1);
}

$sex = strtolower(empty($opt["sex"]) ? $opt["s"] : $opt["sex"]);
if ($sex == "m")
	$sex = "male";
else if ($sex == "f")
	$sex = "female";
else if ($sex != "male" && $sex != "female") {
	fputs(STDERR, "Invalid sex (must be \"male\" or \"female\")\n");
	print_usage();
	exit(1);
}


//-----------------------------------------------------------------------------
// Initialize the CURL handle
//-----------------------------------------------------------------------------
$ch = curl_init();
$cv = curl_version();
$user_agent = "curl ${cv['version']} (${cv['host']}) libcurl/${cv['version']} ${cv['ssl_version']} zlib/${cv['libz_version']} <git://github.com/dtrebbien/diminutives.db.git>";
curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
curl_setopt($ch, CURLOPT_COOKIEFILE, "cookies.txt");
curl_setopt($ch, CURLOPT_COOKIEJAR, "cookies.txt");
curl_setopt($ch, CURLOPT_ENCODING, "deflate, gzip, identity");
curl_setopt($ch, CURLOPT_HEADER, FALSE);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);


//-----------------------------------------------------------------------------
// Determine the Wiktionary articles that are in Category:English_diminutives_of_SEX_given_names and save the list to a file.
//-----------------------------------------------------------------------------
function get_category_members($cmtitle, $cmnamespace = "0") {
	global $ch;
	
	$urlencoded_cmtitle = urlencode($cmtitle);
	$urlencoded_cmnamespace = urlencode($cmnamespace);
	
	$ret = array();
	
	$urlencoded_cmcontinue = "";
	do {
		curl_setopt($ch, CURLOPT_URL, MEDIAWIKI_API_URL . "?action=query&list=categorymembers&cmtitle=$urlencoded_cmtitle&cmnamespace=$urlencoded_cmnamespace&cmcontinue=$urlencoded_cmcontinue&cmlimit=300&format=xml");
		$urlencoded_cmcontinue = "";
		$xml = curl_exec($ch);
		
		$xml_reader = new XMLReader();
		$xml_reader->xml($xml, "UTF-8");
		while ($xml_reader->read()) {
			if ($xml_reader->nodeType == XMLReader::ELEMENT) {
				if ($xml_reader->name == "cm") {
					$ret[] = trim($xml_reader->getAttribute("title"));
				} else if ($xml_reader->name == "categorymembers") {
					$cmcontinue = $xml_reader->getAttribute("cmcontinue");
					if (!empty($cmcontinue))
						$urlencoded_cmcontinue = urlencode($cmcontinue);
				}
			}
		}
		
		sleep(3);
	} while (! empty($urlencoded_cmcontinue));
	
	return $ret;
}

$cat = "English diminutives of $sex given names";
$cat = str_replace(" ", "_", $cat);
$category_member_titles = get_category_members("Category:$cat");

$fp = @fopen("gen/$cat.txt", "w+");
if ($fp === FALSE) {
	var_dump($category_member_titles);
} else {
	foreach ($category_member_titles as $title) {
		fputs($fp, $title . "\r\n");
	}
}
fclose($fp);


//-----------------------------------------------------------------------------
// Download the latest revision text for each article concerning a diminutive
//-----------------------------------------------------------------------------
function extract_first_rev(XMLReader $xml_reader)
{
	while ($xml_reader->read()) {
		if ($xml_reader->nodeType == XMLReader::ELEMENT) {
			if ($xml_reader->name == "rev") {
				return htmlspecialchars_decode($xml_reader->readInnerXML(), ENT_QUOTES);
			}
		} else if ($xml_reader->nodeType == XMLReader::END_ELEMENT) {
			if ($xml_reader->name == "page") {
				throw new Exception("Unexpectedly found `</page>`");
			}
		}
	}
	
	throw new Exception("Reached the end of the XML document without finding revision content");
}

$category_member_contents = array();

while (! empty($category_member_titles)) {
	$titles = array();
	for ($i = 0; $i < 20 && ! empty($category_member_titles); ++$i) {
		$titles[] = array_shift($category_member_titles);
	}
	$titles = implode("|", $titles);
	$urlencoded_titles = urlencode($titles);
	
	curl_setopt($ch, CURLOPT_URL, MEDIAWIKI_API_URL . "?action=query&prop=revisions&titles=$urlencoded_titles&rvprop=content&format=xml");
	$xml = curl_exec($ch);
	
	$xml_reader = new XMLReader();
	$xml_reader->xml($xml, "UTF-8");
	while ($xml_reader->read()) {
		if ($xml_reader->nodeType == XMLReader::ELEMENT) {
			if ($xml_reader->name == "page") {
				$title = $xml_reader->getAttribute("title");
				$content = extract_first_rev($xml_reader);
				$category_member_contents[$title] = $content;
			}
		}
	}
	
	sleep(3);
}


//-----------------------------------------------------------------------------
// Extract formal given names and a list of common diminutives of each
//-----------------------------------------------------------------------------
function extract_sections($content, $level) {
	$ret = array();
	$delimiter = str_repeat("=", $level);
	preg_match_all("~^$delimiter([^=].+)$delimiter~m", $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
	$c = count($matches);
	for ($i = 0; $i < $c; ++$i) {
		$match = $matches[$i];
		$heading = trim($match[1][0]);
		
		$j = $match[0][1] + strlen($match[0][0]);
		if ($i + 1 >= $c) {
			$section_content = substr($content, $j);
		} else {
			$section_content = substr($content, $j, $matches[$i + 1][0][1] - $j);
		}
		
		$ret[] = array($heading, $section_content);
	}
	
	return $ret;
}

function extract_linked_titles($content) {
	$ret = array();
	preg_match_all("~\[\[([^|\]]+)(?:|([^\]]*))?\]\]~", $content, $matches, PREG_SET_ORDER);
	foreach ($matches as $match) {
		$ret[] = str_replace("_", " ", trim($match[1]));
	}
	return $ret;
}

function extract_single_word_proper_nouns($content) {
	$ret = array();
	preg_match_all("~[[:upper:]]\w*~", $content, $matches, PREG_SET_ORDER);
	foreach ($matches as $match) {
		$ret[] = $match[0];
	}
	return $ret;
}

function remove_special_tags_and_inner_content($content) {
	$content = preg_replace("~<ref[^>]*>(?:[^<]|<[^/]|</[^r]|</r[^e]|</re[^f])*</ref[[:space:]]*>~im", "", $content);
	return $content;
}

$diminutives = array();

foreach ($category_member_contents as $title => $content) {
	$level2_sections = extract_sections($content, 2);
	foreach ($level2_sections as $level2_section) {
		if (strcasecmp($level2_section[0], "English") == 0) {
			$english_section = $level2_section;
		}
	}
	
	$htmlencoded_title = htmlspecialchars($title, ENT_QUOTES, "UTF-8");
	if (! isset($english_section))
		throw new Exception("No \"English\" section was found for the page with title \"$htmlencoded_title\".");
	
	$level3_sections = extract_sections($english_section[1], 3);
	foreach ($level3_sections as $level3_section) {
		if (strcasecmp($level3_section[0], "Proper noun") == 0) {
			$english_proper_noun_section_content = remove_special_tags_and_inner_content($level3_section[1]);
			break;
		}
	}
	
	if (! isset($english_proper_noun_section_content))
		throw new Exception("No \"Proper noun\" subsection of the English section was found for the page with title \"$htmlencoded_title\".");
	
	// go through the ordered list items
	preg_match_all("~^#(.*)$~m", $english_proper_noun_section_content, $matches, PREG_SET_ORDER);
	$b = FALSE;
	foreach ($matches as $match) {
		$li_content = substr(trim($match[1]), 1); // as each "sense" on Wiktionary begins with a capital letter, this is a trick to get rid of many capitalized words that are not proper nouns.
		if (stripos($li_content, " $sex") !== FALSE ||
			stripos($li_content, "|$sex") !== FALSE ||  // {{given name|male|diminutive=...}}
			stripos($li_content, "[$sex") !== FALSE) { // [[male]]
			$single_word_proper_nouns = extract_single_word_proper_nouns($li_content);
			$given_names = array_filter($single_word_proper_nouns, function($word) use($title) {
				if ($word == "An" || $word == "The")
					return FALSE;
				else if (strlen($word) <= 1) // special case for the Jay article and others
					return FALSE;
				else if (strcasecmp($word, "American") == 0 ||
					strcasecmp($word, "Australia") == 0 ||
					strcasecmp($word, "Australian") == 0 ||
					strcasecmp($word, "British") == 0 ||
					strcasecmp($word, "Germanic") == 0 ||
					strcasecmp($word, "English") == 0 ||
					strcasecmp($word, "Ireland") == 0 ||
					strcasecmp($word, "Irish") == 0 ||
					strcasecmp($word, "Italian") == 0 ||
					strcasecmp($word, "Latin") == 0 ||
					strcasecmp($word, "Scotland") == 0 ||
					strcasecmp($word, "Scottish") == 0 ||
					strcasecmp($word, "US") == 0)
					return FALSE;
				else if (strcasecmp($word, $title) == 0)
					return FALSE;
				return TRUE;
			});
			foreach ($given_names as $given_name) {
				$given_name = $given_name;//mb_strtoupper($given_name, "UTF-8");
				$diminutive = $title;//mb_strtoupper($title, "UTF-8");
				if (! isset($diminutives[$given_name]))
					$diminutives[$given_name] = array($diminutive);
				else
					$diminutives[$given_name][] = $diminutive;
			}
			$b = TRUE;
		}
	}
	
	if (! $b)
		echo "$htmlencoded_title\n";
}


//-----------------------------------------------------------------------------
// Print a CSV of the extracted information on diminutives to `gen/SEX_diminutives.csv`.
//-----------------------------------------------------------------------------
ksort($diminutives);

$fp = fopen("gen/${sex}_diminutives.csv", "w+");
foreach ($diminutives as $given_name => $ds) {
	array_unshift($ds, $given_name);
	$str = implode(",", $ds);
	fputs($fp, "$str\n");
}
fclose($fp);