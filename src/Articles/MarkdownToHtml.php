<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Articles;

/**
 * A small Markdown reader for article bodies sent through MCP.
 *
 * AI tools often write Markdown. This turns the parts an article uses into
 * HTML, which BlockConverter then turns into blocks: headings, paragraphs,
 * bold, italic, inline code, links, images, lists (one level of nesting),
 * quotes, fenced code, tables and horizontal rules.
 *
 * Not a full CommonMark parser, on purpose: anything it does not know stays
 * as text, which is the safe failure for a blog post.
 */
final class MarkdownToHtml {

	public function convert( string $markdown ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $markdown );
		$out   = array();
		$para  = array();
		$i     = 0;
		$n     = count( $lines );

		$flush = function () use ( &$para, &$out ): void {
			if ( array() !== $para ) {
				$out[] = '<p>' . $this->inline( implode( ' ', array_map( 'trim', $para ) ) ) . '</p>';
				$para  = array();
			}
		};

		while ( $i < $n ) {
			$line = $lines[ $i ];
			$t    = trim( $line );

			if ( '' === $t ) {
				$flush();
				++$i;
				continue;
			}

			// fenced code
			if ( preg_match( '/^(```|~~~)/', $t, $m ) ) {
				$flush();
				$code = array();
				++$i;
				while ( $i < $n && 0 !== strpos( trim( $lines[ $i ] ), $m[1] ) ) {
					$code[] = $lines[ $i ];
					++$i;
				}
				++$i;
				$out[] = '<pre><code>' . htmlspecialchars( implode( "\n", $code ), ENT_NOQUOTES, 'UTF-8' ) . '</code></pre>';
				continue;
			}

			// heading
			if ( preg_match( '/^(#{1,6})\s+(.*?)\s*#*$/', $t, $m ) ) {
				$flush();
				$level = strlen( $m[1] );
				$out[] = "<h{$level}>" . $this->inline( $m[2] ) . "</h{$level}>";
				++$i;
				continue;
			}

			// horizontal rule
			if ( preg_match( '/^([-*_])(\s*\1){2,}$/', $t ) ) {
				$flush();
				$out[] = '<hr>';
				++$i;
				continue;
			}

			// table: a | row followed by a |---| row
			if ( false !== strpos( $t, '|' ) && $i + 1 < $n && preg_match( '/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$/', trim( $lines[ $i + 1 ] ) ) ) {
				$flush();
				$head = $this->cells( $t );
				$i   += 2;
				$rows = array();
				while ( $i < $n && false !== strpos( $lines[ $i ], '|' ) && '' !== trim( $lines[ $i ] ) ) {
					$rows[] = $this->cells( trim( $lines[ $i ] ) );
					++$i;
				}
				$html = '<table><thead><tr>' . implode( '', array_map( fn( $c ) => '<th>' . $this->inline( $c ) . '</th>', $head ) ) . '</tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$html .= '<tr>' . implode( '', array_map( fn( $c ) => '<td>' . $this->inline( $c ) . '</td>', $r ) ) . '</tr>';
				}
				$out[] = $html . '</tbody></table>';
				continue;
			}

			// quote
			if ( 0 === strpos( $t, '>' ) ) {
				$flush();
				$q = array();
				while ( $i < $n && 0 === strpos( trim( $lines[ $i ] ), '>' ) ) {
					$q[] = ltrim( substr( trim( $lines[ $i ] ), 1 ) );
					++$i;
				}
				$parts = preg_split( '/\n\s*\n/', implode( "\n", $q ) );
				$out[] = '<blockquote>' . implode( '', array_map( fn( $p ) => '<p>' . $this->inline( preg_replace( '/\s+/', ' ', trim( $p ) ) ) . '</p>', array_filter( $parts, 'strlen' ) ) ) . '</blockquote>';
				continue;
			}

			// lists
			if ( preg_match( '/^(\s*)([-*+]|\d+[.)])\s+/', $line ) ) {
				$flush();
				list( $html, $i ) = $this->list_at( $lines, $i );
				$out[]            = $html;
				continue;
			}

			$para[] = $line;
			++$i;
		}
		$flush();

		return implode( "\n", $out );
	}

	/**
	 * A list starting at line $i, with one level of nesting by indentation.
	 *
	 * @param string[] $lines
	 * @return array{0:string,1:int}
	 */
	private function list_at( array $lines, int $i ): array {
		$n = count( $lines );
		preg_match( '/^(\s*)([-*+]|\d+[.)])\s+/', $lines[ $i ], $m );
		$indent  = strlen( str_replace( "\t", '    ', $m[1] ) );
		$ordered = ctype_digit( rtrim( $m[2], '.)' ) );
		$items   = array();

		while ( $i < $n ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) ) {
				// a blank line ends the list unless the next line continues it
				if ( $i + 1 < $n && preg_match( '/^(\s*)([-*+]|\d+[.)])\s+/', $lines[ $i + 1 ], $nm ) && strlen( str_replace( "\t", '    ', $nm[1] ) ) >= $indent ) {
					++$i;
					continue;
				}
				break;
			}
			if ( ! preg_match( '/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $im ) ) {
				// a wrapped line belongs to the last item
				if ( array() !== $items && preg_match( '/^\s+\S/', $line ) ) {
					$items[ count( $items ) - 1 ]['text'] .= ' ' . trim( $line );
					++$i;
					continue;
				}
				break;
			}
			$this_indent = strlen( str_replace( "\t", '    ', $im[1] ) );
			if ( $this_indent > $indent && array() !== $items ) {
				list( $sub, $i ) = $this->list_at( $lines, $i );
				$items[ count( $items ) - 1 ]['sub'] .= $sub;
				continue;
			}
			if ( $this_indent < $indent ) {
				break;
			}
			$items[] = array( 'text' => $im[3], 'sub' => '' );
			++$i;
		}

		$tag  = $ordered ? 'ol' : 'ul';
		$html = "<{$tag}>";
		foreach ( $items as $it ) {
			$html .= '<li>' . $this->inline( trim( $it['text'] ) ) . $it['sub'] . '</li>';
		}
		return array( $html . "</{$tag}>", $i );
	}

	/**
	 * Bare http(s) addresses become links, the way readers expect on a website.
	 * Text already inside a link or a tag is left alone.
	 */
	private function autolink( string $html ): string {
		$parts  = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$in_a   = 0;
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part && '<' === $part[0] ) {
				if ( preg_match( '/^<a[\s>]/i', $part ) ) {
					++$in_a;
				} elseif ( preg_match( '/^<\/a>/i', $part ) ) {
					$in_a = max( 0, $in_a - 1 );
				}
				continue;
			}
			if ( $in_a ) {
				continue;
			}
			$parts[ $i ] = preg_replace_callback(
				'~\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]~i',
				static fn( $m ) => '<a href="' . htmlspecialchars( html_entity_decode( $m[0], ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' ) . '">' . $m[0] . '</a>',
				$part
			);
		}
		return implode( '', $parts );
	}

	/** @return string[] */
	private function cells( string $row ): array {
		$row = trim( trim( $row ), '|' );
		return array_map( 'trim', explode( '|', $row ) );
	}

	private function inline( string $text ): string {
		$codes = array();
		// protect inline code first
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			function ( $m ) use ( &$codes ) {
				$codes[] = '<code>' . htmlspecialchars( $m[1], ENT_NOQUOTES, 'UTF-8' ) . '</code>';
				return "\x01" . ( count( $codes ) - 1 ) . "\x01";
			},
			$text
		);

		$text = htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
			fn( $m ) => '<img src="' . htmlspecialchars( $m[2], ENT_QUOTES, 'UTF-8' ) . '" alt="' . htmlspecialchars( $m[1], ENT_QUOTES, 'UTF-8' ) . '">',
			$text
		);
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
			fn( $m ) => '<a href="' . htmlspecialchars( $m[2], ENT_QUOTES, 'UTF-8' ) . '">' . $m[1] . '</a>',
			$text
		);
		$text = $this->autolink( $text );
		$text = preg_replace( '/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/', '<strong>$2</strong>', $text );
		$text = preg_replace( '/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![_\w])_(?=\S)(.+?)(?<=\S)_(?![_\w])/', '<em>$1</em>', $text );
		$text = preg_replace( '/~~(?=\S)(.+?)(?<=\S)~~/', '<s>$1</s>', $text );

		return preg_replace_callback( "/\x01(\d+)\x01/", fn( $m ) => $codes[ (int) $m[1] ], $text );
	}
}
