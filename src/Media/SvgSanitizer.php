<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Media;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Rewrites an SVG file down to a drawing.
 *
 * An SVG is XML, and XML can carry script, external references and embedded
 * HTML. Rather than hunt for the bad parts, this keeps only an allowlist of
 * drawing elements and attributes and drops everything else, then writes the
 * document out again from the parsed tree. Nothing the author wrote survives
 * unless it was on the list.
 */
final class SvgSanitizer {

	public const ALLOWED_TAGS = array(
		'svg', 'g', 'defs', 'title', 'desc',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'use', 'symbol',
		'clippath', 'mask',
		'lineargradient', 'radialgradient', 'stop', 'pattern',
	);

	public const ALLOWED_ATTRS = array(
		// structure
		'id', 'class', 'viewbox', 'width', 'height', 'version', 'xmlns', 'xmlns:xlink',
		'preserveaspectratio', 'transform', 'role', 'aria-hidden', 'aria-label', 'focusable',
		// geometry
		'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
		'points', 'dx', 'dy', 'offset', 'pathlength',
		// paint
		'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask',
		'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
		'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset',
		'opacity', 'color', 'stop-color', 'stop-opacity',
		'gradientunits', 'gradienttransform', 'spreadmethod',
		'patternunits', 'patterncontentunits', 'patterntransform',
		'clippathunits', 'maskunits', 'maskcontentunits',
		// type
		'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
		'text-anchor', 'dominant-baseline', 'xml:space',
		// the only reference allowed, and only to a fragment in this same file
		'href', 'xlink:href',
	);

	/** @var string[] */
	private array $removed = array();

	/**
	 * @return array{ok:bool,svg:string,removed:string[],error:string}
	 */
	public function sanitize( string $markup ): array {
		$this->removed = array();

		$markup = trim( $markup );
		if ( '' === $markup ) {
			return $this->fail( 'The SVG is empty.' );
		}

		// A document type declaration can pull in an external entity. There is
		// no legitimate reason for one here, so refuse the file outright.
		if ( preg_match( '/<!DOCTYPE/i', $markup ) || preg_match( '/<!ENTITY/i', $markup ) ) {
			return $this->fail( 'An SVG with a DOCTYPE or ENTITY declaration is not accepted.' );
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		// No NOENT: entities stay unexpanded. No NONET means no fetching either.
		$loaded   = $doc->loadXML( $markup, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $doc->documentElement instanceof DOMElement ) {
			return $this->fail( 'The SVG could not be parsed as XML. Every tag must be closed.' );
		}

		if ( 'svg' !== strtolower( $doc->documentElement->nodeName ) ) {
			return $this->fail( 'The root element must be <svg>.' );
		}

		$this->clean( $doc->documentElement );

		if ( ! $doc->documentElement->hasAttribute( 'xmlns' ) ) {
			$doc->documentElement->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}

		if ( ! $doc->documentElement->hasAttribute( 'viewBox' ) ) {
			return $this->fail( 'The <svg> needs a viewBox so it scales.' );
		}

		$out = $doc->saveXML( $doc->documentElement );
		if ( ! is_string( $out ) || '' === $out ) {
			return $this->fail( 'The SVG could not be written back out.' );
		}

		return array(
			'ok'      => true,
			'svg'     => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n",
			'removed' => array_values( array_unique( $this->removed ) ),
			'error'   => '',
		);
	}

	private function clean( DOMElement $element ): void {
		foreach ( iterator_to_array( $element->childNodes ) as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( ! in_array( strtolower( $child->nodeName ), self::ALLOWED_TAGS, true ) ) {
					$this->removed[] = '<' . strtolower( $child->nodeName ) . '>';
					$element->removeChild( $child );
					continue;
				}
				$this->clean( $child );
				continue;
			}

			if ( $child instanceof DOMNode && XML_TEXT_NODE !== $child->nodeType && XML_CDATA_SECTION_NODE !== $child->nodeType ) {
				// Comments and processing instructions carry nothing we need.
				$element->removeChild( $child );
			}
		}

		$this->clean_attributes( $element );
	}

	private function clean_attributes( DOMElement $element ): void {
		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			if ( ! $attribute instanceof DOMAttr ) {
				continue;
			}

			$name  = strtolower( $attribute->nodeName );
			$value = (string) $attribute->nodeValue;

			if ( ! in_array( $name, self::ALLOWED_ATTRS, true ) ) {
				$this->removed[] = $name;
				$element->removeAttributeNode( $attribute );
				continue;
			}

			if ( 'href' === $name || 'xlink:href' === $name ) {
				// Only a reference to another shape inside this same file.
				if ( ! preg_match( '/^#[A-Za-z][\w:.-]*$/', $value ) ) {
					$this->removed[] = $name;
					$element->removeAttributeNode( $attribute );
				}
				continue;
			}

			// url(#id) is how a gradient or clip path is used. Anything else
			// pointing outward is dropped.
			if ( false !== stripos( $value, 'url(' ) && ! preg_match( '/^url\(#[\w:.-]+\)$/i', trim( $value ) ) ) {
				$this->removed[] = $name;
				$element->removeAttributeNode( $attribute );
				continue;
			}

			if ( preg_match( '/(javascript|vbscript|data)\s*:/i', $value ) ) {
				$this->removed[] = $name;
				$element->removeAttributeNode( $attribute );
			}
		}
	}

	/**
	 * @return array{ok:bool,svg:string,removed:string[],error:string}
	 */
	private function fail( string $message ): array {
		return array(
			'ok'      => false,
			'svg'     => '',
			'removed' => $this->removed,
			'error'   => $message,
		);
	}
}
