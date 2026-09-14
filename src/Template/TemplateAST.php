<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Template;

/**
 * One node of a parsed template.
 *
 * Node kinds:
 *  - text  : raw markup, rendered as-is (already validated as safe HTML)
 *  - interp: {{text:path}} and friends
 *  - if    : {{#if:path}} ... {{/if}}
 *  - each  : {{#each:path}} ... {{/each}}
 */
final class TemplateAST {

	public const TEXT   = 'text';
	public const INTERP = 'interp';
	public const IF_    = 'if';
	public const EACH   = 'each';

	public string $kind;
	public string $value    = '';
	public string $filter   = '';
	public string $path     = '';
	public int    $line     = 0;

	/** @var TemplateAST[] */
	public array $children = array();

	public function __construct( string $kind ) {
		$this->kind = $kind;
	}

	public static function text( string $value ): self {
		$node        = new self( self::TEXT );
		$node->value = $value;
		return $node;
	}

	public static function interp( string $filter, string $path, int $line ): self {
		$node         = new self( self::INTERP );
		$node->filter = $filter;
		$node->path   = $path;
		$node->line   = $line;
		return $node;
	}

	public static function block( string $kind, string $path, int $line ): self {
		$node       = new self( $kind );
		$node->path = $path;
		$node->line = $line;
		return $node;
	}

	public function add( self $child ): void {
		$this->children[] = $child;
	}
}
