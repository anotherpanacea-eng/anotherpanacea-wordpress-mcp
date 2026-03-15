<?php
/**
 * Bidirectional Markdown / Gutenberg block markup converter.
 *
 * @package AnotherPanacea_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts between Markdown and Gutenberg block markup in both directions.
 */
class APMCP_Markdown_Converter {

	/**
	 * Convert Gutenberg block markup to Markdown.
	 *
	 * @param string $content Block markup content.
	 * @return string Markdown content.
	 */
	public static function blocks_to_markdown( $content ) {
		if ( empty( $content ) ) {
			return '';
		}

		$md = $content;

		// Remove block comments wrapping, processing specific blocks.

		// Headings: <!-- wp:heading {"level":N} --><hN>...</hN><!-- /wp:heading -->.
		$md = preg_replace_callback(
			'/<!-- wp:heading\s*(\{[^}]*\})?\s*-->\s*<h([1-6])[^>]*>(.*?)<\/h\2>\s*<!-- \/wp:heading -->/s',
			function ( $m ) {
				$level = (int) $m[2];
				$text  = strip_tags( $m[3] );
				return str_repeat( '#', $level ) . ' ' . trim( $text );
			},
			$md
		);

		// Images: <!-- wp:image {...} --><figure...><img src="..." alt="...".../>...</figure><!-- /wp:image -->.
		$md = preg_replace_callback(
			'/<!-- wp:image\s*(\{[^}]*\})?\s*-->\s*<figure[^>]*>\s*<img[^>]*src="([^"]*)"[^>]*?\/?>\s*(?:<figcaption[^>]*>(.*?)<\/figcaption>)?\s*<\/figure>\s*<!-- \/wp:image -->/s',
			function ( $m ) {
				$url = $m[2];
				// Try to get alt from image tag.
				$alt = ! empty( $m[3] ) ? strip_tags( $m[3] ) : '';
				return '![' . $alt . '](' . $url . ')';
			},
			$md
		);

		// Also catch alt text from the img tag itself.
		$md = preg_replace_callback(
			'/<!-- wp:image\s*(\{[^}]*\})?\s*-->\s*<figure[^>]*>\s*<img[^>]*?alt="([^"]*)"[^>]*?src="([^"]*)"[^>]*?\/?>\s*(?:<figcaption[^>]*>(.*?)<\/figcaption>)?\s*<\/figure>\s*<!-- \/wp:image -->/s',
			function ( $m ) {
				$alt = $m[2];
				$url = $m[3];
				return '![' . $alt . '](' . $url . ')';
			},
			$md
		);

		// Code blocks: <!-- wp:code --><pre...><code>...</code></pre><!-- /wp:code -->.
		$md = preg_replace_callback(
			'/<!-- wp:code\s*(\{[^}]*\})?\s*-->\s*<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>\s*<!-- \/wp:code -->/s',
			function ( $m ) {
				$code = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5 );
				// Try to extract language from attributes.
				$lang = '';
				if ( ! empty( $m[1] ) ) {
					$attrs = json_decode( $m[1], true );
					if ( isset( $attrs['language'] ) ) {
						$lang = $attrs['language'];
					}
				}
				return "```{$lang}\n{$code}\n```";
			},
			$md
		);

		// Blockquotes: <!-- wp:quote --><blockquote...><p>...</p></blockquote><!-- /wp:quote -->.
		$md = preg_replace_callback(
			'/<!-- wp:quote\s*(\{[^}]*\})?\s*-->\s*<blockquote[^>]*>(.*?)<\/blockquote>\s*<!-- \/wp:quote -->/s',
			function ( $m ) {
				$inner = $m[2];
				// Strip <p> tags and convert to lines.
				$inner = preg_replace( '/<p[^>]*>(.*?)<\/p>/s', '$1', $inner );
				$inner = strip_tags( $inner );
				$lines = explode( "\n", trim( $inner ) );
				return implode(
					"\n",
					array_map(
						function ( $line ) {
							return '> ' . trim( $line );
						},
						$lines
					)
				);
			},
			$md
		);

		// Ordered lists: <!-- wp:list {"ordered":true} -->.
		$md = preg_replace_callback(
			'/<!-- wp:list\s*\{[^}]*"ordered"\s*:\s*true[^}]*\}\s*-->\s*<ol[^>]*>(.*?)<\/ol>\s*<!-- \/wp:list -->/s',
			function ( $m ) {
				return self::html_list_to_markdown( $m[1], true );
			},
			$md
		);

		// Unordered lists: <!-- wp:list --> or <!-- wp:list {...} --> (without ordered:true).
		$md = preg_replace_callback(
			'/<!-- wp:list\s*(\{[^}]*\})?\s*-->\s*<ul[^>]*>(.*?)<\/ul>\s*<!-- \/wp:list -->/s',
			function ( $m ) {
				return self::html_list_to_markdown( $m[2], false );
			},
			$md
		);

		// Separator: <!-- wp:separator --> ... <!-- /wp:separator -->.
		$md = preg_replace(
			'/<!-- wp:separator\s*(\{[^}]*\})?\s*-->\s*<hr[^>]*\/?>\s*<!-- \/wp:separator -->/s',
			'---',
			$md
		);

		// Paragraphs: <!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->.
		$md = preg_replace_callback(
			'/<!-- wp:paragraph\s*(\{[^}]*\})?\s*-->\s*<p[^>]*>(.*?)<\/p>\s*<!-- \/wp:paragraph -->/s',
			function ( $m ) {
				$text = $m[2];
				// Convert inline HTML: <strong>, <em>, <a>, <code>.
				$text = self::inline_html_to_markdown( $text );
				return trim( $text );
			},
			$md
		);

		// Catch any remaining unrecognized blocks: preserve as HTML with a note.
		$md = preg_replace_callback(
			'/<!-- wp:(\S+)\s*(\{[^}]*\})?\s*-->(.*?)<!-- \/wp:\1 -->/s',
			function ( $m ) {
				$block_type = $m[1];
				$inner      = trim( $m[3] );
				return "<!-- unrecognized block: {$block_type} -->\n{$inner}";
			},
			$md
		);

		// Remove any remaining bare block comments (self-closing or unpaired).
		$md = preg_replace( '/<!-- \/?wp:\S+\s*(?:\{[^}]*\})?\s*\/?-->\s*/s', '', $md );

		// Clean up excessive blank lines.
		$md = preg_replace( '/\n{3,}/', "\n\n", $md );

		return trim( $md );
	}

	/**
	 * Convert Markdown to Gutenberg block markup.
	 *
	 * @param string $markdown Markdown content.
	 * @return string Block markup.
	 */
	public static function markdown_to_blocks( $markdown ) {
		if ( empty( $markdown ) ) {
			return '';
		}

		// Pre-process HTML <img> tags into Markdown image syntax before
		// wp_kses strips them. Handles linked images (<a><img></a>) and
		// standalone <img> tags from legacy (pre-Gutenberg) content.
		$markdown = preg_replace_callback(
			'/<a\s[^>]*href="([^"]*)"[^>]*>\s*<img\s[^>]*?\/?>\s*<\/a>/i',
			function ( $m ) {
				$link_url = $m[1];
				// Extract src and alt from the img tag.
				$src = '';
				$alt = '';
				if ( preg_match( '/src="([^"]*)"/', $m[0], $sm ) ) {
					$src = $sm[1];
				}
				if ( preg_match( '/alt="([^"]*)"/', $m[0], $am ) ) {
					$alt = $am[1];
				}
				return '![' . $alt . '](' . $src . ')';
			},
			$markdown
		);
		$markdown = preg_replace_callback(
			'/<img\s[^>]*?\/?>/i',
			function ( $m ) {
				$src = '';
				$alt = '';
				if ( preg_match( '/src="([^"]*)"/', $m[0], $sm ) ) {
					$src = $sm[1];
				}
				if ( preg_match( '/alt="([^"]*)"/', $m[0], $am ) ) {
					$alt = $am[1];
				}
				return '![' . $alt . '](' . $src . ')';
			},
			$markdown
		);

		// Strip raw HTML tags that aren't part of Markdown syntax.
		// Allow only the inline elements that inline_markdown_to_html produces,
		// preventing callers from smuggling arbitrary HTML/block markup
		// through the "markdown" format.
		$markdown = wp_kses(
			$markdown,
			array(
				'strong' => array(),
				'em'     => array(),
				'code'   => array(),
				'a'      => array( 'href' => array() ),
			)
		);

		$lines  = explode( "\n", $markdown );
		$blocks = array();
		$i      = 0;
		$count  = count( $lines );

		while ( $i < $count ) {
			$line = $lines[ $i ];

			// Blank line — skip.
			if ( '' === trim( $line ) ) {
				$i++;
				continue;
			}

			// Fenced code block.
			if ( preg_match( '/^```(\w*)/', $line, $m ) ) {
				$lang      = $m[1];
				$code_lines = array();
				$i++;
				while ( $i < $count && ! preg_match( '/^```\s*$/', $lines[ $i ] ) ) {
					$code_lines[] = $lines[ $i ];
					$i++;
				}
				$i++; // skip closing ```
				$code    = esc_html( implode( "\n", $code_lines ) );
				$attrs   = $lang ? ' ' . wp_json_encode( array( 'language' => $lang ) ) : '';
				$blocks[] = "<!-- wp:code{$attrs} -->\n<pre class=\"wp-block-code\"><code>{$code}</code></pre>\n<!-- /wp:code -->";
				continue;
			}

			// Heading.
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $m ) ) {
				$level = strlen( $m[1] );
				$text  = self::inline_markdown_to_html( trim( $m[2] ) );
				$attrs = 2 !== $level ? ' ' . wp_json_encode( array( 'level' => $level ) ) : '';
				$blocks[] = "<!-- wp:heading{$attrs} -->\n<h{$level}>{$text}</h{$level}>\n<!-- /wp:heading -->";
				$i++;
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^(---|\*\*\*|___)\s*$/', $line ) ) {
				$blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
				$i++;
				continue;
			}

			// Image (standalone on its own line).
			if ( preg_match( '/^!\[([^\]]*)\]\(([^)]+)\)$/', trim( $line ), $m ) ) {
				$alt = esc_attr( $m[1] );
				$url = esc_url( $m[2] );
				$blocks[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>\n<!-- /wp:image -->";
				$i++;
				continue;
			}

			// Image at start of line followed by text (legacy inline image).
			// Split into an image block + the remaining text goes back on the line stack.
			if ( preg_match( '/^!\[([^\]]*)\]\(([^)]+)\)\s*(.+)$/', trim( $line ), $m ) ) {
				$alt = esc_attr( $m[1] );
				$url = esc_url( $m[2] );
				$blocks[] = "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>\n<!-- /wp:image -->";
				// Replace current line with the remaining text so it gets processed next.
				$lines[ $i ] = $m[3];
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^>\s?/', $line ) ) {
				$quote_lines = array();
				while ( $i < $count && preg_match( '/^>\s?(.*)$/', $lines[ $i ], $m ) ) {
					$quote_lines[] = $m[1];
					$i++;
				}
				$paragraphs = self::group_into_paragraphs( $quote_lines );
				$inner = implode(
					"\n",
					array_map(
						function ( $p ) {
							return '<p>' . APMCP_Markdown_Converter::inline_markdown_to_html( $p ) . '</p>';
						},
						$paragraphs
					)
				);
				$blocks[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$inner}</blockquote>\n<!-- /wp:quote -->";
				continue;
			}

			// Unordered list.
			if ( preg_match( '/^[\*\-\+]\s+/', $line ) ) {
				$list_lines = array();
				while ( $i < $count && preg_match( '/^[\*\-\+]\s+(.+)$/', $lines[ $i ], $m ) ) {
					$list_lines[] = self::inline_markdown_to_html( $m[1] );
					$i++;
				}
				$items = implode(
					"\n",
					array_map(
						function ( $item ) {
							return "<li>{$item}</li>";
						},
						$list_lines
					)
				);
				$blocks[] = "<!-- wp:list -->\n<ul>{$items}</ul>\n<!-- /wp:list -->";
				continue;
			}

			// Ordered list.
			if ( preg_match( '/^\d+\.\s+/', $line ) ) {
				$list_lines = array();
				while ( $i < $count && preg_match( '/^\d+\.\s+(.+)$/', $lines[ $i ], $m ) ) {
					$list_lines[] = self::inline_markdown_to_html( $m[1] );
					$i++;
				}
				$items = implode(
					"\n",
					array_map(
						function ( $item ) {
							return "<li>{$item}</li>";
						},
						$list_lines
					)
				);
				$blocks[] = "<!-- wp:list {\"ordered\":true} -->\n<ol>{$items}</ol>\n<!-- /wp:list -->";
				continue;
			}

			// Default: paragraph. Collect contiguous non-blank, non-special lines.
			$para_lines = array();
			while ( $i < $count && '' !== trim( $lines[ $i ] )
				&& ! preg_match( '/^(#{1,6}\s|```|>\s?|[\*\-\+]\s|\d+\.\s|---\s*$|\*\*\*\s*$|___\s*$|!\[)/', $lines[ $i ] )
			) {
				$para_lines[] = $lines[ $i ];
				$i++;
			}
			$text     = self::inline_markdown_to_html( implode( "\n", $para_lines ) );
			$blocks[] = "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Convert legacy (pre-Gutenberg) HTML content to Gutenberg block markup.
	 *
	 * Handles the common HTML structures found in classic WordPress posts:
	 * <p>, <blockquote>, <h1>-<h6>, <ul>, <ol>, <img>, <hr>, bare text.
	 * Strips deprecated tags (<font>, <center>, etc.) preserving their content.
	 *
	 * @param string $html Classic HTML content.
	 * @return string Gutenberg block markup.
	 */
	public static function html_to_blocks( $html ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		// If content already has block markup, return as-is.
		if ( has_blocks( $html ) ) {
			return $html;
		}

		// Strip deprecated tags, keeping inner content.
		$deprecated = array( 'font', 'center', 'marquee', 'blink', 'strike', 'big', 'small', 'tt', 'u' );
		foreach ( $deprecated as $tag ) {
			$html = preg_replace( '/<' . $tag . '[^>]*>/i', '', $html );
			$html = preg_replace( '/<\/' . $tag . '>/i', '', $html );
		}

		// Normalize line endings.
		$html = str_replace( array( "\r\n", "\r" ), "\n", $html );

		// Use DOMDocument for robust HTML parsing.
		$blocks = array();

		// Wrap in a root element for parsing.
		$wrapped = '<div id="apmcp-root">' . $html . '</div>';

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		// Suppress warnings from malformed HTML.
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$root = $doc->getElementById( 'apmcp-root' );
		if ( ! $root ) {
			// Fallback: treat entire content as a single paragraph.
			$text = wp_strip_all_tags( $html );
			if ( ! empty( trim( $text ) ) ) {
				return "<!-- wp:paragraph -->\n<p>" . trim( $text ) . "</p>\n<!-- /wp:paragraph -->";
			}
			return '';
		}

		foreach ( $root->childNodes as $node ) {
			$block = self::dom_node_to_block( $node, $doc );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Convert a single DOM node to a Gutenberg block.
	 *
	 * @param DOMNode     $node The DOM node.
	 * @param DOMDocument $doc  The parent document.
	 * @return string|null Block markup, or null to skip.
	 */
	private static function dom_node_to_block( $node, $doc ) {
		// Text nodes: wrap non-empty text in a paragraph.
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text = trim( $node->textContent );
			if ( empty( $text ) ) {
				return null;
			}
			// If the text contains double newlines, split into multiple paragraphs.
			$paragraphs = preg_split( '/\n{2,}/', $text );
			$para_blocks = array();
			foreach ( $paragraphs as $para ) {
				$para = trim( $para );
				if ( ! empty( $para ) ) {
					$para_blocks[] = "<!-- wp:paragraph -->\n<p>{$para}</p>\n<!-- /wp:paragraph -->";
				}
			}
			return ! empty( $para_blocks ) ? implode( "\n\n", $para_blocks ) : null;
		}

		// Only process element nodes.
		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return null;
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'p':
				$inner = self::get_inner_html( $node, $doc );
				$inner = trim( $inner );
				if ( empty( $inner ) ) {
					return null;
				}
				// Check if this paragraph contains only an image.
				if ( preg_match( '/^<img\s[^>]*?\/?>\s*$/i', $inner ) ) {
					return self::img_html_to_block( $inner );
				}
				// Check if paragraph contains a linked image.
				if ( preg_match( '/^<a\s[^>]*>\s*<img\s[^>]*?\/?>\s*<\/a>\s*$/i', $inner ) ) {
					return self::img_html_to_block( $inner );
				}
				return "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->";

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				$inner = self::get_inner_html( $node, $doc );
				$attrs = 2 !== $level ? ' ' . wp_json_encode( array( 'level' => $level ) ) : '';
				return "<!-- wp:heading{$attrs} -->\n<{$tag}>{$inner}</{$tag}>\n<!-- /wp:heading -->";

			case 'blockquote':
				$inner = self::get_inner_html( $node, $doc );
				// Ensure content is wrapped in <p> tags if not already.
				if ( strpos( $inner, '<p' ) === false ) {
					$inner = '<p>' . trim( $inner ) . '</p>';
				}
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$inner}</blockquote>\n<!-- /wp:quote -->";

			case 'ul':
				$inner = self::get_inner_html( $node, $doc );
				return "<!-- wp:list -->\n<ul>{$inner}</ul>\n<!-- /wp:list -->";

			case 'ol':
				$inner = self::get_inner_html( $node, $doc );
				return "<!-- wp:list {\"ordered\":true} -->\n<ol>{$inner}</ol>\n<!-- /wp:list -->";

			case 'hr':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'img':
				$outer = self::get_outer_html( $node, $doc );
				return self::img_html_to_block( $outer );

			case 'figure':
				// Already a figure — likely from newer content. Wrap as image block.
				$outer = self::get_outer_html( $node, $doc );
				if ( strpos( $outer, '<img' ) !== false ) {
					return "<!-- wp:image -->\n{$outer}\n<!-- /wp:image -->";
				}
				return "<!-- wp:paragraph -->\n<p>{$outer}</p>\n<!-- /wp:paragraph -->";

			case 'pre':
				$code = $node->textContent;
				$code = esc_html( $code );
				return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>{$code}</code></pre>\n<!-- /wp:code -->";

			case 'div':
				// Recurse into divs — they're layout wrappers in classic content.
				$child_blocks = array();
				foreach ( $node->childNodes as $child ) {
					$block = self::dom_node_to_block( $child, $doc );
					if ( null !== $block ) {
						$child_blocks[] = $block;
					}
				}
				return ! empty( $child_blocks ) ? implode( "\n\n", $child_blocks ) : null;

			case 'br':
				return null; // Skip bare <br> tags.

			case 'a':
				// A standalone link (not inside a paragraph) — check if it wraps an image.
				$inner = self::get_inner_html( $node, $doc );
				if ( preg_match( '/<img\s/i', $inner ) ) {
					$outer = self::get_outer_html( $node, $doc );
					return self::img_html_to_block( $outer );
				}
				// Treat as a paragraph.
				$outer = self::get_outer_html( $node, $doc );
				return "<!-- wp:paragraph -->\n<p>{$outer}</p>\n<!-- /wp:paragraph -->";

			case 'table':
				$outer = self::get_outer_html( $node, $doc );
				return "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$outer}</figure>\n<!-- /wp:table -->";

			default:
				// Unknown element: wrap its content in a paragraph.
				$inner = self::get_inner_html( $node, $doc );
				if ( ! empty( trim( $inner ) ) ) {
					return "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->";
				}
				return null;
		}
	}

	/**
	 * Convert an HTML <img> tag (possibly wrapped in <a>) to a wp:image block.
	 *
	 * @param string $html HTML containing an img tag.
	 * @return string Block markup.
	 */
	private static function img_html_to_block( $html ) {
		$src = '';
		$alt = '';
		if ( preg_match( '/src="([^"]*)"/', $html, $m ) ) {
			$src = esc_url( $m[1] );
		}
		if ( preg_match( '/alt="([^"]*)"/', $html, $m ) ) {
			$alt = esc_attr( $m[1] );
		}

		return "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$src}\" alt=\"{$alt}\"/></figure>\n<!-- /wp:image -->";
	}

	/**
	 * Get inner HTML of a DOMNode.
	 *
	 * @param DOMNode     $node The node.
	 * @param DOMDocument $doc  The document.
	 * @return string Inner HTML.
	 */
	private static function get_inner_html( $node, $doc ) {
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $doc->saveHTML( $child );
		}
		return $inner;
	}

	/**
	 * Get outer HTML of a DOMNode.
	 *
	 * @param DOMNode     $node The node.
	 * @param DOMDocument $doc  The document.
	 * @return string Outer HTML.
	 */
	private static function get_outer_html( $node, $doc ) {
		return $doc->saveHTML( $node );
	}

	/**
	 * Convert inline HTML (strong, em, a, code) to Markdown equivalents.
	 *
	 * @param string $html HTML string with inline elements.
	 * @return string Markdown string.
	 */
	public static function inline_html_to_markdown( $html ) {
		// Bold.
		$html = preg_replace( '/<strong>(.*?)<\/strong>/s', '**$1**', $html );
		// Italic.
		$html = preg_replace( '/<em>(.*?)<\/em>/s', '*$1*', $html );
		// Inline code.
		$html = preg_replace( '/<code>(.*?)<\/code>/s', '`$1`', $html );
		// Links.
		$html = preg_replace_callback(
			'/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s',
			function ( $m ) {
				return '[' . $m[2] . '](' . $m[1] . ')';
			},
			$html
		);
		// Strip remaining tags.
		$html = strip_tags( $html );
		return $html;
	}

	/**
	 * Convert inline Markdown (bold, italic, code, links) to HTML.
	 *
	 * @param string $text Markdown text with inline formatting.
	 * @return string HTML string.
	 */
	public static function inline_markdown_to_html( $text ) {
		// Inline code (must be before bold/italic to avoid conflicts).
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		// Bold.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		// Italic.
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
		// Links.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			function ( $m ) {
				$link_text = $m[1];
				$url       = esc_url( $m[2] );
				return "<a href=\"{$url}\">{$link_text}</a>";
			},
			$text
		);
		return $text;
	}

	/**
	 * Convert an HTML list (<li> items) to Markdown.
	 *
	 * @param string $html    HTML containing <li> items.
	 * @param bool   $ordered Whether the list is ordered.
	 * @return string Markdown list.
	 */
	private static function html_list_to_markdown( $html, $ordered = false ) {
		$items = array();
		preg_match_all( '/<li[^>]*>(.*?)<\/li>/s', $html, $matches );
		$counter = 1;
		foreach ( $matches[1] as $item ) {
			$text = self::inline_html_to_markdown( strip_tags( $item, '<strong><em><code><a>' ) );
			$text = trim( $text );
			if ( $ordered ) {
				$items[] = "{$counter}. {$text}";
				$counter++;
			} else {
				$items[] = "- {$text}";
			}
		}
		return implode( "\n", $items );
	}

	/**
	 * Group lines into paragraphs (split on blank lines).
	 *
	 * @param array $lines Array of text lines.
	 * @return array Array of paragraph strings.
	 */
	private static function group_into_paragraphs( $lines ) {
		$paragraphs = array();
		$current    = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				if ( ! empty( $current ) ) {
					$paragraphs[] = implode( ' ', $current );
					$current      = array();
				}
			} else {
				$current[] = trim( $line );
			}
		}
		if ( ! empty( $current ) ) {
			$paragraphs[] = implode( ' ', $current );
		}
		return $paragraphs;
	}
}
