<?php
class FeedParser {
	private $doc;
	private $error;
	private $items;
	private $link;
	private $title;
	private $type;
	private $xpath;

	const FEED_RDF = 0;
	const FEED_RSS = 1;
	const FEED_ATOM = 2;

	function __construct($data) {
		libxml_use_internal_errors(true);
		libxml_clear_errors();
		$this->doc = new DOMDocument();
		$this->doc->loadXML($data);

		mb_substitute_character("none");

		$error = libxml_get_last_error();

		// libxml compiled without iconv?
		if ($error && ($error->code == 32 || $error->code == 9)) {
			if (preg_match('/^(<\?xml[\t\n\r ].*?encoding=["\'])(.+?)(["\'].*?\?>)/s', $data, $matches) === 1) {
				$enc = $matches[2];

				$data = mb_convert_encoding($data, 'UTF-8', $enc);

				$data = preg_replace('/^<\?xml[\t\n\r ].*?\?>/s', $matches[1] . "UTF-8" . $matches[3] , $data);


				// apparently not all UTF-8 characters are valid for XML
				$data = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $data);

				if ($data) {
					libxml_clear_errors();

					$this->doc = new DOMDocument();
					$this->doc->loadXML($data);

					$error = libxml_get_last_error();
				}
		   }
		}

		// some terrible invalid unicode entity?
		if ($error && $error->code == 9) {
			$data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');

			if ($data) {
				libxml_clear_errors();

				$this->doc = new DOMDocument();
				$this->doc->loadXML($data);

				$error = libxml_get_last_error();
			}
		}

		$this->error = $this->format_error($error);
		libxml_clear_errors();

		$this->items = array();
	}

	function init() {
		$root = $this->doc->firstChild;
		$xpath = new DOMXPath($this->doc);
		$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
		$xpath->registerNamespace('atom03', 'http://purl.org/atom/ns#');
		$xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');
		$xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
		$xpath->registerNamespace('slash', 'http://purl.org/rss/1.0/modules/slash/');
		$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
		$xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');

		$this->xpath = $xpath;

		$root = $xpath->query("(//atom03:feed|//atom:feed|//channel|//rdf:rdf|//rdf:RDF)");

		if ($root) {
			$root = $root->item(0);

			if ($root) {
				switch (mb_strtolower($root->tagName)) {
				case "rdf:rdf":
					$this->type = $this::FEED_RDF;
					break;
				case "channel":
					$this->type = $this::FEED_RSS;
					break;
				case "feed":
					$this->type = $this::FEED_ATOM;
					break;
				default:
					if( !isset($this->error) ){
						$this->error = "Unknown/unsupported feed type";
					}
					return;
				}
			}

			switch ($this->type) {
			case $this::FEED_ATOM:

				$title = $xpath->query("//atom:feed/atom:title")->item(0);

				if (!$title)
					$title = $xpath->query("//atom03:feed/atom03:title")->item(0);


				if ($title) {
					$this->title = $title->nodeValue;
				}

				$link = $xpath->query("//atom:feed/atom:link[not(@rel)]")->item(0);

				if (!$link)
					$link = $xpath->query("//atom03:feed/atom03:link[not(@rel)]")->item(0);


				if ($link && $link->hasAttributes()) {
					$this->link = $link->getAttribute("href");
				}

				$articles = $xpath->query("//atom:entry");

				if (!$articles || $articles->length == 0)
					$articles = $xpath->query("//atom03:entry");

				foreach ($articles as $article) {
					array_push($this->items, new FeedItem_Atom($article, $this->doc, $this->xpath));
				}

				break;
			case $this::FEED_RSS:
				$title = $xpath->query("//channel/title")->item(0);

				if ($title) {
					$this->title = $title->nodeValue;
				}

				$link = $xpath->query("//channel/link")->item(0);

				if ($link) {
					if ($link->getAttribute("href"))
						$this->link = $link->getAttribute("href");
					else if ($link->nodeValue)
						$this->link = $link->nodeValue;
				}

				$articles = $xpath->query("//channel/item");

				foreach ($articles as $article) {
					array_push($this->items, new FeedItem_RSS($article, $this->doc, $this->xpath));
				}

				break;
			case $this::FEED_RDF:
				$xpath->registerNamespace('rssfake', 'http://purl.org/rss/1.0/');

				$title = $xpath->query("//rssfake:channel/rssfake:title")->item(0);

				if ($title) {
					$this->title = $title->nodeValue;
				}

				$link = $xpath->query("//rssfake:channel/rssfake:link")->item(0);

				if ($link) {
					$this->link = $link->nodeValue;
				}

				$articles = $xpath->query("//rssfake:item");

				foreach ($articles as $article) {
					array_push($this->items, new FeedItem_RSS($article, $this->doc, $this->xpath));
				}

				break;

			}
		} else {
			if( !isset($this->error) ){
				$this->error = "Unknown/unsupported feed type";
			}
			return;
		}
	}

	function format_error($error) {
		if ($error) {
			return sprintf("LibXML error %s at line %d (column %d): %s",
				$error->code, $error->line, $error->column,
				$error->message);
		} else {
			return "";
		}
	}

	function error() {
		return $this->error;
	}

	function get_link() {
		return $this->link;
	}

	function get_title() {
		return $this->title;
	}

	function get_items() {
		return $this->items;
	}

	function get_links($rel) {
		$rv = array();

		switch ($this->type) {
		case $this::FEED_ATOM:
			$links = $this->xpath->query("//atom:feed/atom:link");

			foreach ($links as $link) {
				if (!$rel || $link->hasAttribute('rel') && $link->getAttribute('rel') == $rel) {
					array_push($rv, $link->getAttribute('href'));
				}
			}
			break;
		case $this::FEED_RSS:
			$links = $this->xpath->query("//atom:link");

			foreach ($links as $link) {
				if (!$rel || $link->hasAttribute('rel') && $link->getAttribute('rel') == $rel) {
					array_push($rv, $link->getAttribute('href'));
				}
			}
			break;
		}

		return $rv;
	}
} ?>