<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Design;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Checks a page against the craft rules a design system implies.
 *
 * The AI cannot see what it built. This is the next best thing: every check
 * here is something a careful art director would notice in a review, and
 * something a machine can actually decide without looking at pixels — colours
 * that are not in the palette, a type scale with fourteen sizes in it, spacing
 * that ignores the spacing scale, text that never meets the contrast rules,
 * buttons with no focus state.
 *
 * What it cannot see is said out loud in `not_checked`, so nobody reads a pass
 * here as "the page looks good".
 */
final class DesignReviewer {

	/** What one rem is in a browser nobody has rescaled. */
	/**
	 * Some checks only make sense on a page.
	 *
	 * A header that has nothing bigger than 35px is a header doing its job, not
	 * a timid one, and telling it to shout would make it worse. The same goes
	 * for line length and heading order: the chrome has no body copy and no
	 * document outline of its own.
	 */
	private bool $is_chrome = false;

	public function reviewing_chrome(): self {
		$clone            = clone $this;
		$clone->is_chrome = true;

		return $clone;
	}

	/**
	 * Two indents this far apart or less were trying to line up.
	 * Further apart than this is a hierarchy somebody chose.
	 */
	private const NEAR_MISS = 24.0;

	/** At and above this, type is display type and wants its own leading. */
	/*
	 * 28 was too low. A 30px standfirst at 1.4 is correctly set, and flagging
	 * it taught people to ignore the finding. Genuinely display type — the
	 * thing that is first on the page — starts higher.
	 */
	private const DISPLAY_SIZE = 44.0;

	/** The loosest leading display type should have. */
	private const DISPLAY_LEADING = 1.3;

	private const ROOT_FONT_SIZE = 16.0;

	/** How far off a palette colour still counts as "meant to be that colour". */
	private const COLOR_TOLERANCE = 12.0;

	/** Sizes a value may be off the type ladder and still count as on it. */
	private const SIZE_TOLERANCE = 0.04;

	/** Spacing values that are always fine whatever the scale says. */
	private const FREE_SPACING = array( 0.0, 1.0, 2.0, 3.0, 4.0 );

	private const WEIGHT = array(
		'high'   => 12,
		'medium' => 6,
		'low'    => 2,
	);

	/** @var array<int,array<string,mixed>> */
	private array $findings = array();

	/** The CSS this page or chrome owns, and is judged on. */
	private CSSReader $css;

	/**
	 * The CSS that also applies but belongs to someone else: the site design
	 * system and the plugin baseline. A page is not at fault for a rule it did
	 * not write, so this is read for "does the site already do this?" and
	 * never for "did this page break a rule?".
	 */
	private CSSReader $inherited;

	private DesignSystem $system;

	private string $html;

	private float $base_size = 16.0;

	/** What this site is meant to be built out of. */
	private ComponentLibrary $library;

	public function __construct( string $css, string $html, DesignSystem $system, string $inherited_css = '', ?ComponentLibrary $library = null ) {
		$this->css       = new CSSReader( $css );
		$this->inherited = new CSSReader( $inherited_css );
		$this->html      = $html;
		$this->system    = $system;
		$this->library   = $library ?? ComponentLibrary::empty();

		$tokens = $system->tokens();
		$base   = (string) ( $tokens['typography']['base_size'] ?? '16px' );
		$parsed = $this->to_px( $base );
		if ( null !== $parsed && $parsed > 0 ) {
			$this->base_size = $parsed;
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function review(): array {
		$this->findings = array();

		$this->check_palette();
		$this->check_contrast();
		$this->check_type_scale();
		$this->check_type_contrast();
		$this->check_spacing_scale();
		$this->check_radius();
		$this->check_fonts();
		$this->check_states();
		$this->check_motion();
		$this->check_motion_consistency();
		$this->check_leading();
		$this->check_lopsided_padding();
		$this->check_alignment();
		$this->check_responsive();
		$this->check_measure();
		$this->check_heading_order();
		$this->check_component_reuse();
		$this->check_style_decisions();

		usort(
			$this->findings,
			static function ( array $a, array $b ): int {
				$order = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				return ( $order[ $a['severity'] ] ?? 3 ) <=> ( $order[ $b['severity'] ] ?? 3 );
			}
		);

		$score = 100;
		foreach ( $this->findings as $finding ) {
			$score -= self::WEIGHT[ $finding['severity'] ] ?? 0;
		}

		return array(
			'score'       => max( 0, $score ),
			'findings'    => $this->findings,
			'counts'      => array(
				'high'   => $this->count( 'high' ),
				'medium' => $this->count( 'medium' ),
				'low'    => $this->count( 'low' ),
			),
			'not_checked' => array(
				'Whether the page actually looks good. Open the preview URL and look.',
				'Anything that needs layout: overlap, overflow, balance, where the fold lands.',
				'Whether the words are true, or worth reading.',
				'Contrast where the text colour and its background are set in different rules.',
			),
		);
	}

	// ------------------------------------------------------------ the checks

	/**
	 * Colours that are not in the design system.
	 */
	private function check_palette(): void {
		$palette = array();
		foreach ( (array) ( $this->system->tokens()['colors'] ?? array() ) as $name => $value ) {
			if ( is_string( $value ) && null !== Color::to_rgb( $value ) ) {
				$palette[ (string) $name ] = strtolower( $value );
			}
		}

		if ( array() === $palette ) {
			return;
		}

		$strays = array();

		foreach ( $this->css->declarations() as $declaration ) {
			if ( false === strpos( $declaration['property'], 'color' )
				&& 'background' !== $declaration['property']
				&& 'border' !== substr( $declaration['property'], 0, 6 )
				&& 'fill' !== $declaration['property']
				&& 'stroke' !== $declaration['property']
				&& 'box-shadow' !== $declaration['property'] ) {
				continue;
			}

			foreach ( Color::find_all( $declaration['value'] ) as $color ) {
				if ( self::is_neutral( $color ) ) {
					continue;
				}

				$nearest  = '';
				$shortest = PHP_FLOAT_MAX;

				foreach ( $palette as $name => $value ) {
					$distance = Color::distance( $color, $value );
					if ( null !== $distance && $distance < $shortest ) {
						$shortest = $distance;
						$nearest  = (string) $name;
					}
				}

				if ( $shortest <= self::COLOR_TOLERANCE ) {
					continue;
				}

				$key = $color;
				if ( ! isset( $strays[ $key ] ) ) {
					$strays[ $key ] = array(
						'count'    => 0,
						'nearest'  => $nearest,
						'distance' => $shortest,
						'first'    => $declaration['selector'],
						'line'     => $declaration['line'],
					);
				}
				++$strays[ $key ]['count'];
			}
		}

		if ( array() === $strays ) {
			return;
		}

		$list = array();
		foreach ( $strays as $color => $info ) {
			$list[] = sprintf(
				'%s (%d use%s, first in "%s" on line %d, closest token: %s)',
				$color,
				$info['count'],
				1 === $info['count'] ? '' : 's',
				$info['first'],
				$info['line'],
				$info['nearest']
			);
		}

		$this->add(
			'off_palette_colors',
			count( $strays ) > 3 ? 'high' : 'medium',
			sprintf( '%d colour(s) are not in the design system.', count( $strays ) ),
			implode( '; ', array_slice( $list, 0, 12 ) ),
			'Use var(--aiwp-color-*) instead. A page that invents its own colours stops matching the rest of the site. '
				. 'If a colour is genuinely needed, add it to the design system so every page can use it.'
		);
	}

	/**
	 * Text that cannot be read on the background it was given.
	 *
	 * Only pairs written in the same rule are judged, because that is the only
	 * place the answer is certain without laying the page out.
	 */
	private function check_contrast(): void {
		$fails = array();

		foreach ( $this->css->rules() as $rule ) {
			$text = $rule['declarations']['color'] ?? '';
			$back = $rule['declarations']['background-color'] ?? ( $rule['declarations']['background'] ?? '' );

			if ( '' === $text || '' === $back ) {
				continue;
			}

			$text_color = $this->resolve( $text );
			$back_color = $this->resolve( $back );

			if ( '' === $text_color || '' === $back_color ) {
				continue;
			}

			// A see-through background sits on something unknown.
			if ( Color::alpha( $back_color ) < 0.95 ) {
				continue;
			}

			$ratio = Color::contrast( $text_color, $back_color );
			if ( null === $ratio ) {
				continue;
			}

			$large    = $this->is_large_text( $rule['declarations'] );
			$required = $large ? 3.0 : 4.5;

			if ( $ratio + 0.005 < $required ) {
				$fails[] = sprintf(
					'"%s" puts %s on %s at %.2f:1, needs %.1f:1',
					$rule['selector'],
					$text_color,
					$back_color,
					$ratio,
					$required
				);
			}
		}

		if ( array() === $fails ) {
			return;
		}

		$this->add(
			'contrast',
			'high',
			sprintf( '%d rule(s) set text that is too faint to read on its own background.', count( $fails ) ),
			implode( '; ', array_slice( $fails, 0, 10 ) ),
			'WCAG asks for 4.5:1 on body text and 3:1 on large text (24px, or 18.66px bold). '
				. 'Darken the text or lighten the background until it passes.'
		);
	}

	/**
	 * Font sizes that are not on the ladder the design system set.
	 */
	private function check_type_scale(): void {
		$tokens = $this->system->tokens();
		$ratio  = (float) ( $tokens['typography']['scale'] ?? 0 );

		$sizes = $this->font_sizes();
		if ( array() === $sizes ) {
			return;
		}

		$distinct = array_values( array_unique( array_map( static fn( float $s ): float => round( $s, 1 ), $sizes ) ) );
		sort( $distinct );

		if ( count( $distinct ) > 9 ) {
			$this->add(
				'too_many_type_sizes',
				'medium',
				sprintf( 'The page uses %d different font sizes.', count( $distinct ) ),
				implode( 'px, ', array_map( static fn( float $s ): string => (string) $s, $distinct ) ) . 'px',
				'A page reads as designed when it uses five or six sizes, used consistently. '
					. 'Pick the sizes off the scale and reuse them.'
			);
		}

		if ( $ratio <= 1.0 ) {
			return;
		}

		$ladder = array();
		for ( $step = -3; $step <= 7; $step++ ) {
			$ladder[] = $this->base_size * ( $ratio ** $step );
		}

		$off = array();
		foreach ( $distinct as $size ) {
			$closest = PHP_FLOAT_MAX;
			foreach ( $ladder as $rung ) {
				$closest = min( $closest, abs( $size - $rung ) / $rung );
			}
			if ( $closest > self::SIZE_TOLERANCE ) {
				$off[] = $size . 'px';
			}
		}

		if ( array() === $off ) {
			return;
		}

		$this->add(
			'off_scale_type',
			'medium',
			sprintf( '%d font size(s) are not on the %s type scale.', count( $off ), (string) $ratio ),
			implode( ', ', array_slice( $off, 0, 14 ) ),
			sprintf(
				'The scale from %.0fpx at a ratio of %s gives: %s. Round to the nearest rung.',
				$this->base_size,
				(string) $ratio,
				implode( ', ', array_map( static fn( float $r ): string => round( $r ) . 'px', array_slice( $ladder, 2, 7 ) ) )
			)
		);
	}

	/**
	 * A hero that is barely bigger than body text never feels designed.
	 */
	private function check_type_contrast(): void {
		if ( $this->is_chrome ) {
			return;
		}

		/*
		 * A site that said in words it is quiet gets to be quiet.
		 *
		 * check_motion already stands down when the design system chose
		 * stillness on purpose, and this is the same thing one property over:
		 * a survey-report site whose personality says "dense, technical,
		 * nothing shouts" does not want a 90px headline, and telling it to
		 * find one is my taste overruling its decision. The written decisions
		 * are the site's; this check is only for the sites that never made
		 * one.
		 */
		/*
		 * A site that chose to be quiet gets to be quiet — but only if there
		 * is something on the page to look at. Quiet type and no image at all
		 * is not restraint, it is a page nobody designed, and letting the
		 * written decision excuse both is how I silenced the one check that
		 * would have said so.
		 */
		if ( $this->chose_to_be_quiet() && $this->has_anything_to_look_at() ) {
			return;
		}

		$sizes = array_merge( $this->font_sizes(), $this->font_sizes( $this->inherited ) );
		if ( count( $sizes ) < 3 ) {
			return;
		}

		$largest = max( $sizes );
		$ratio   = $largest / $this->base_size;

		if ( $ratio >= 2.4 ) {
			return;
		}

		$this->add(
			'timid_type',
			'medium',
			sprintf( 'The largest text on the page is %.0fpx, only %.1f times the body size.', $largest, $ratio ),
			'Nothing on the page is loud enough to be the first thing you read.',
			'A confident page usually has a headline three to five times body size. '
				. 'Use clamp() so it stays sensible on a phone, for example clamp(2.5rem, 6vw, 4.5rem).'
		);
	}

	/**
	 * Padding, margins and gaps that ignore the spacing scale.
	 */
	/** Whether the imagery decision is that there is none. */
	private function imagery_says_none( string $said ): bool {
		foreach ( array( 'none', 'no image', 'no photograph', 'no photography', 'text only', 'nothing' ) as $phrase ) {
			if ( false !== strpos( $said, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/** Anything on the page that is looked at rather than read. */
	private function has_anything_to_look_at(): bool {
		if ( '' === trim( $this->html ) ) {
			// No markup to judge, so nothing can be said either way.
			return true;
		}

		return 1 === preg_match( '/<(img|svg|figure|picture|video|canvas)\b/i', $this->html );
	}

	/**
	 * Whether the design system said, in words, that this site is restrained.
	 *
	 * Read from personality and layout, which are prose the site wrote about
	 * itself. Nothing is inferred from the values: a site can be quiet and
	 * still have a large headline, and that is its business.
	 */
	private function chose_to_be_quiet(): bool {
		$style = (array) ( $this->system->tokens()['style'] ?? array() );
		$said  = strtolower( (string) ( $style['personality'] ?? '' ) . ' ' . (string) ( $style['layout'] ?? '' ) );

		if ( '' === trim( $said ) ) {
			return false;
		}

		foreach ( array( 'quiet', 'nothing shouts', 'nothing on this site shouts', 'restrained', 'understated',
			'dense', 'technical', 'not an advertisement', 'rather than an advertisement', 'sober', 'unhurried' ) as $phrase ) {
			if ( false !== strpos( $said, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	private function check_spacing_scale(): void {
		$scale = array();
		foreach ( (array) ( $this->system->tokens()['spacing'] ?? array() ) as $value ) {
			$px = $this->to_px( (string) $value );
			if ( null !== $px ) {
				$scale[] = $px;
			}
		}

		if ( array() === $scale ) {
			return;
		}

		$allowed = array_merge( $scale, self::FREE_SPACING );
		$off     = array();

		foreach ( $this->css->declarations() as $declaration ) {
			if ( ! preg_match( '/^(padding|margin|gap|row-gap|column-gap)(-(top|right|bottom|left|inline|block|inline-start|inline-end|block-start|block-end))?$/', $declaration['property'] ) ) {
				continue;
			}

			// Anything responsive is a deliberate choice, not a stray number.
			if ( preg_match( '/(clamp|calc|min|max)\(/i', $declaration['value'] ) ) {
				continue;
			}

			foreach ( preg_split( '/\s+/', trim( $declaration['value'] ) ) ?: array() as $piece ) {
				if ( 'auto' === $piece || '' === $piece ) {
					continue;
				}

				$px = $this->to_px( $piece );
				if ( null === $px ) {
					continue;
				}

				$near = false;
				foreach ( $allowed as $step ) {
					if ( abs( $px - $step ) < 0.51 ) {
						$near = true;
						break;
					}
				}

				if ( ! $near ) {
					$key         = (string) round( $px ) . 'px';
					$off[ $key ] = ( $off[ $key ] ?? 0 ) + 1;
				}
			}
		}

		if ( array() === $off ) {
			return;
		}

		arsort( $off );
		$shown = array();
		foreach ( array_slice( $off, 0, 12, true ) as $value => $count ) {
			$shown[] = sprintf( '%s (%d×)', $value, $count );
		}

		// When nearly every stray value is a multiple of four, the page is not
		// being careless: the scale is too sparse to build with. Say that,
		// rather than telling the AI to round 20px up to 32px.
		$tidy = 0;
		foreach ( array_keys( $off ) as $value ) {
			if ( 0 === ( (int) $value ) % 4 ) {
				++$tidy;
			}
		}

		$sparse = count( $off ) >= 4 && $tidy / count( $off ) >= 0.75;

		$fix = sprintf(
			'The scale is %s. Use var(--aiwp-space-*).',
			implode( ', ', array_map( static fn( float $s ): string => round( $s ) . 'px', $scale ) )
		);

		if ( $sparse ) {
			$fix .= ' Every stray value here is a multiple of four, so the problem is probably the scale itself: '
				. 'five steps is not enough to build a real page with. Widen the spacing scale in the design system '
				. 'so every page can use the same steps, instead of each page inventing its own.';
		} else {
			$fix .= ' Spacing picked one value at a time is the single biggest reason a page looks slightly wrong '
				. 'without anyone being able to say why.';
		}

		$this->add(
			'off_scale_spacing',
			$sparse ? 'low' : ( count( $off ) > 6 ? 'medium' : 'low' ),
			sprintf(
				'%d spacing value(s) are not on the spacing scale.%s',
				count( $off ),
				$sparse ? ' They are all multiples of four, so the scale may be too sparse.' : ''
			),
			implode( ', ', $shown ),
			$fix
		);
	}

	private function check_radius(): void {
		$radii = array();
		foreach ( (array) ( $this->system->tokens()['radius'] ?? array() ) as $value ) {
			$px = $this->to_px( (string) $value );
			if ( null !== $px ) {
				$radii[] = $px;
			}
		}

		if ( array() === $radii ) {
			return;
		}

		$radii[] = 0.0;
		$off     = array();

		foreach ( $this->css->values_of( 'border-radius' ) as $value ) {
			if ( false !== strpos( $value, '%' ) || preg_match( '/(clamp|calc|var)\(/i', $value ) ) {
				continue;
			}

			foreach ( preg_split( '/\s+/', trim( $value ) ) ?: array() as $piece ) {
				$px = $this->to_px( $piece );
				if ( null === $px ) {
					continue;
				}
				// A pill shape is a deliberate look, not a stray value.
				if ( $px >= 500 ) {
					continue;
				}

				$near = false;
				foreach ( $radii as $step ) {
					if ( abs( $px - $step ) < 0.51 ) {
						$near = true;
						break;
					}
				}

				if ( ! $near ) {
					$off[ round( $px ) . 'px' ] = true;
				}
			}
		}

		if ( array() === $off ) {
			return;
		}

		$this->add(
			'off_scale_radius',
			'low',
			sprintf( '%d corner radius value(s) are not in the design system.', count( $off ) ),
			implode( ', ', array_keys( $off ) ),
			'Use var(--aiwp-radius-*). Mixed radii on the same page read as sloppiness.'
		);
	}

	private function check_fonts(): void {
		$families = array();

		foreach ( $this->css->values_of( 'font-family' ) as $value ) {
			if ( preg_match( '/var\(/i', $value ) ) {
				continue;
			}
			$first = strtolower( trim( (string) strtok( $value, ',' ), " \t\"'" ) );
			if ( '' !== $first && ! in_array( $first, array( 'inherit', 'initial', 'unset' ), true ) ) {
				$families[ $first ] = true;
			}
		}

		if ( count( $families ) <= 3 ) {
			return;
		}

		$this->add(
			'too_many_fonts',
			'medium',
			sprintf( 'The page names %d different font families directly.', count( $families ) ),
			implode( ', ', array_keys( $families ) ),
			'Two families, three at the very most. Use var(--aiwp-font-heading) and var(--aiwp-font-body) '
				. 'so the page follows the site rather than setting its own rules.'
		);
	}

	private function check_states(): void {
		$missing = array();

		if ( ! $this->anywhere_has( ':hover' ) ) {
			$missing[] = 'hover';
		}
		if ( ! $this->anywhere_has( ':focus-visible' ) && ! $this->anywhere_has( ':focus' ) ) {
			$missing[] = 'keyboard focus';
		}

		if ( array() === $missing ) {
			return;
		}

		$clickable = substr_count( $this->html, '<a ' ) + substr_count( $this->html, '<button' );
		if ( $clickable < 2 ) {
			return;
		}

		$this->add(
			'missing_states',
			in_array( 'keyboard focus', $missing, true ) ? 'high' : 'medium',
			sprintf( 'The page has %d links and buttons but no %s state, here or anywhere the site sets one.', $clickable, implode( ' or ', $missing ) ),
			'Nothing changes when a visitor points at a control, or tabs onto it.',
			'Add :hover for the mouse and :focus-visible for the keyboard. A missing focus ring makes the page '
				. 'unusable without a mouse, and a missing hover makes it feel dead.'
		);
	}

	private function check_motion(): void {
		$motion = strtolower( (string) ( ( (array) ( $this->system->tokens()['style'] ?? array() ) )['motion'] ?? '' ) );
		if ( false !== strpos( $motion, 'none' ) || false !== strpos( $motion, 'no motion' ) || false !== strpos( $motion, 'still' ) ) {
			return; // The site chose stillness on purpose.
		}

		foreach ( array( $this->css, $this->inherited ) as $sheet ) {
			$moves = array() !== $sheet->values_of( 'transition' )
				|| array() !== $sheet->values_of( 'transition-property' )
				|| array() !== $sheet->values_of( 'animation' )
				|| $sheet->keyframes() > 0;

			if ( $moves ) {
				return;
			}
		}

		$this->add(
			'no_motion',
			'low',
			'Nothing on the page moves or eases.',
			'No transition, no animation.',
			'One or two small transitions (a button colour, a card lift on hover, 150-200ms) are the cheapest '
				. 'way to make a page feel finished. Wrap anything larger in @media (prefers-reduced-motion: no-preference).'
		);
	}

	/**
	 * Motion that was decided once, or motion that was invented per section.
	 *
	 * A site whose design system says "a little on hover" and whose pages then
	 * write .16s here, .18s there and .22s further down does not read as a
	 * choice. Nobody can name the difference, but the site feels assembled
	 * instead of designed. The design system carries --aiwp-motion-* for
	 * exactly this, the way it carries the type scale.
	 */
	private function check_motion_consistency(): void {
		$typed = array();
		$token = 0;
		$slow  = 0.0;

		foreach ( array( 'transition', 'transition-duration', 'animation', 'animation-duration' ) as $property ) {
			foreach ( $this->css->values_of( $property ) as $value ) {
				if ( false !== strpos( $value, 'var(--aiwp-motion-' ) ) {
					++$token;
					continue;
				}

				foreach ( $this->durations( $value ) as $ms ) {
					// A zero is "off", not a speed, and disagrees with nothing.
					if ( $ms > 0.0 ) {
						$typed[ (string) $ms ] = $ms;
						$slow                  = max( $slow, $ms );
					}
				}
			}
		}

		if ( $slow > 1000.0 ) {
			$this->add(
				'motion_too_slow',
				'medium',
				sprintf( 'Something on this page takes %dms.', (int) round( $slow ) ),
				'Over a second. On a hover or a state change that reads as the page having stopped responding.',
				'Keep anything a person triggers under about 400ms. Long timings belong to things that happen on their own, '
					. 'like an element arriving as the page scrolls.'
			);
		}

		if ( array() === $typed ) {
			return;
		}

		sort( $typed );

		$list = implode( 'ms, ', array_map( static fn ( float $ms ): string => (string) (int) round( $ms ), $typed ) ) . 'ms';

		$this->add(
			'motion_not_shared',
			'low',
			'This page types its own timings.',
			sprintf(
				'%s written into the page CSS. %s',
				$list,
				$token > 0
					? 'Some of this page already uses the site\'s own timing, so the page disagrees with itself.'
					: 'The next page will pick different numbers, and nobody will be able to say why the site feels uneven.'
			),
			'Use var(--aiwp-motion-fast) for something under a cursor, var(--aiwp-motion-base) for most things and '
				. 'var(--aiwp-motion-slow) for something arriving on its own, each with var(--aiwp-motion-ease). '
				. 'They come from the design system, so every page moves the same way and one edit changes all of them.'
		);
	}

	/**
	 * Every duration in a value, in milliseconds.
	 *
	 * @return float[]
	 */
	private function durations( string $value ): array {
		if ( ! preg_match_all( '/(-?[0-9]*\.?[0-9]+)\s*(ms|s)\b/i', $value, $found, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();

		foreach ( $found as $match ) {
			$number = (float) $match[1];
			$out[]  = 's' === strtolower( $match[2] ) ? $number * 1000.0 : $number;
		}

		return $out;
	}

	/**
	 * Big text left with body leading.
	 *
	 * Type gets tighter as it gets bigger — that is the oldest rule in setting
	 * type and the one most often missed, because nothing looks broken. A
	 * 35px line at the body's 1.6 falls into three loose lines and reads as
	 * three separate thoughts instead of one sentence. It is the single
	 * commonest reason a competent page still looks amateur.
	 *
	 * A rule that sets a large size and no line-height inherits the body's,
	 * whatever that is. So the check is not "is the leading wrong" — it is
	 * "did anyone decide".
	 */
	private function check_leading(): void {
		$loose = array();

		foreach ( $this->css->rules() as $rule ) {
			$declarations = (array) $rule['declarations'];

			if ( ! isset( $declarations['font-size'] ) ) {
				continue;
			}

			$size = $this->largest_size( (string) $declarations['font-size'] );

			if ( $size < self::DISPLAY_SIZE ) {
				continue;
			}

			$leading = (string) ( $declarations['line-height'] ?? '' );

			if ( '' === $leading ) {
				/*
				 * The site stylesheet usually sets leading for h1, h2 and h3
				 * once, which is exactly the right place for it. Reading only
				 * the page's own rules reports that a heading decided nothing
				 * when the site decided it for every heading it has.
				 */
				if ( $this->leading_inherited( (string) $rule['selector'] ) ) {
					continue;
				}

				$loose[] = sprintf( '"%s" at %dpx sets no line-height', $this->short_selector( (string) $rule['selector'] ), (int) round( $size ) );
				continue;
			}

			$number = (float) $leading;

			// A unitless number, or a length we can compare against the size.
			if ( str_ends_with( trim( $leading ), 'px' ) ) {
				$number = $size > 0 ? $number / $size : 0.0;
			}

			if ( $number > 0 && $number > self::DISPLAY_LEADING ) {
				$loose[] = sprintf(
					'"%s" at %dpx has line-height %s',
					$this->short_selector( (string) $rule['selector'] ),
					(int) round( $size ),
					$leading
				);
			}
		}

		if ( array() === $loose ) {
			return;
		}

		$this->add(
			'leading_not_decided',
			'medium',
			sprintf( '%d large text rule(s) use body leading.', count( $loose ) ),
			implode( '; ', array_slice( $loose, 0, 4 ) ) . '.',
			sprintf(
				'Text at %dpx or more wants line-height between 1.0 and %s. Left alone it inherits the body\'s, '
					. 'and a headline falls into loose separate lines instead of reading as one thing.',
				(int) self::DISPLAY_SIZE,
				(string) self::DISPLAY_LEADING
			)
		);
	}

	/**
	 * A block with far more space below its content than above it, or the
	 * other way round.
	 *
	 * This is what makes a footer look like it is falling out of the page: 22px
	 * above the last line and 88px below it. Nobody chose that; it is what you
	 * get when a padding shorthand is written once and the content inside
	 * changes. Deliberate asymmetry is normal — four times is not.
	 */
	private function check_lopsided_padding(): void {
		$found = array();

		foreach ( $this->css->rules() as $rule ) {
			$declarations = (array) $rule['declarations'];

			$top    = $this->edge( $declarations, 'top' );
			$bottom = $this->edge( $declarations, 'bottom' );

			if ( null === $top || null === $bottom || ( $top < 1.0 && $bottom < 1.0 ) ) {
				continue;
			}

			$big   = max( $top, $bottom );
			$small = min( $top, $bottom );

			// Both small enough that the difference cannot be seen.
			if ( $big < 24.0 ) {
				continue;
			}

			if ( $small > 0.0 && $big / max( $small, 1.0 ) < 3.0 ) {
				continue;
			}

			$found[] = sprintf(
				'"%s" has %dpx above and %dpx below',
				$this->short_selector( (string) $rule['selector'] ),
				(int) round( $top ),
				(int) round( $bottom )
			);
		}

		if ( array() === $found ) {
			return;
		}

		$this->add(
			'padding_lopsided',
			'low',
			sprintf( '%d block(s) have much more space on one side than the other.', count( $found ) ),
			implode( '; ', array_slice( $found, 0, 4 ) ) . '.',
			'Uneven space reads as a mistake rather than as rhythm, and it is what makes a footer look like it is '
				. 'falling out of the page. Use the same step of the spacing scale on both sides unless the '
				. 'difference is doing something.'
		);
	}

	/**
	 * The largest length in a value, so clamp() is read at its top end and a
	 * token is read as the number it stands for.
	 */
	private function largest_size( string $value ): float {
		$direct = $this->to_px( $value );

		if ( null !== $direct ) {
			return $direct;
		}

		$largest = 0.0;

		// A token inside a clamp, or several tokens in a shorthand.
		if ( preg_match_all( '/var\(\s*--aiwp-(?:text|space)-[\w-]+\s*\)/', $value, $tokens ) ) {
			foreach ( $tokens[0] as $token ) {
				$largest = max( $largest, (float) ( $this->to_px( $token ) ?? 0.0 ) );
			}
		}

		if ( preg_match_all( '/(-?[0-9]*\.?[0-9]+)\s*(px|rem|em)\b/i', $value, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$largest = max( $largest, (float) ( $this->to_px( $match[1] . strtolower( $match[2] ) ) ?? 0.0 ) );
			}
		}

		return $largest;
	}

	/**
	 * Padding on one edge, from the shorthand or the long form.
	 *
	 * @param array<string,string> $declarations
	 */
	private function edge( array $declarations, string $side ): ?float {
		if ( isset( $declarations[ 'padding-' . $side ] ) ) {
			return $this->largest_size( (string) $declarations[ 'padding-' . $side ] );
		}

		if ( ! isset( $declarations['padding'] ) ) {
			return null;
		}

		$parts = preg_split( '/\s+/', trim( (string) $declarations['padding'] ) ) ?: array();

		// A var() we cannot resolve tells us nothing either way.
		if ( array() === $parts || count( $parts ) > 4 ) {
			return null;
		}

		$top    = $parts[0];
		$bottom = 4 === count( $parts ) ? $parts[2] : ( 3 === count( $parts ) ? $parts[2] : $parts[0] );
		$raw    = 'top' === $side ? $top : $bottom;

		$size = $this->largest_size( (string) $raw );

		return $size > 0.0 ? $size : null;
	}

	private function short_selector( string $selector ): string {
		$selector = (string) preg_replace( '/\[data-aiwp-[a-z-]+="[^"]*"\]\s*/', '', $selector );

		return '' === trim( $selector ) ? '(root)' : trim( $selector );
	}

	/**
	 * Whether the site stylesheet already sets leading for this element.
	 *
	 * @param string $selector the page rule's selector
	 */
	private function leading_inherited( string $selector ): bool {
		if ( ! preg_match( '/\b(h[1-6])\s*$/i', trim( $selector ), $found ) ) {
			return false;
		}

		$element = strtolower( $found[1] );

		foreach ( $this->inherited->rules() as $rule ) {
			if ( ! isset( $rule['declarations']['line-height'] ) ) {
				continue;
			}

			if ( preg_match( '/\b' . $element . '\b/i', (string) $rule['selector'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Blocks that were meant to line up and do not.
	 *
	 * The commonest version has one shape. A section head is a grid with a
	 * fixed first column — say 56px for a number — and a gap. Its heading
	 * therefore starts at 56 + gap. The body underneath is then indented with
	 * margin-left: 56px to "line up with the heading", and lands one gap short.
	 * Fourteen pixels out: too small to look deliberate, too large to look
	 * right, and invisible unless somebody measures the boxes.
	 *
	 * Only near misses are reported. Two indents far apart are a deliberate
	 * hierarchy; two within a couple of dozen pixels are an accident.
	 */
	private function check_alignment(): void {
		$indents = array();

		foreach ( $this->css->rules() as $rule ) {
			$selector     = $this->short_selector( (string) $rule['selector'] );
			$declarations = (array) $rule['declarations'];

			foreach ( $this->indents_of( $declarations ) as $label => $value ) {
				if ( $value >= 16.0 ) {
					$indents[] = array(
						'where' => $selector . ' (' . $label . ')',
						'at'    => $value,
						'kind'  => $label,
					);
				}
			}
		}

		if ( count( $indents ) < 2 ) {
			return;
		}

		$near = array();

		foreach ( $indents as $i => $one ) {
			foreach ( array_slice( $indents, $i + 1 ) as $other ) {
				/*
				 * One side has to be a grid's second column.
				 *
				 * Comparing any left offset against any other says a card with
				 * 16px of padding disagrees with a block that has a 32px
				 * margin, which is not a disagreement — they are different
				 * boxes in different coordinate spaces and were never trying
				 * to line up. The mistake worth reporting is specific: a
				 * heading sitting in a grid column, and a sibling indented to
				 * meet it that lands a gap short.
				 */
				if ( 'second column' !== $one['kind'] && 'second column' !== $other['kind'] ) {
					continue;
				}

				if ( $one['kind'] === $other['kind'] ) {
					continue;
				}

				$apart = abs( $one['at'] - $other['at'] );

				if ( $apart < 0.5 || $apart > self::NEAR_MISS ) {
					continue;
				}

				$key          = sprintf( '%s|%s', $one['where'], $other['where'] );
				$near[ $key ] = sprintf(
					'%s starts at %dpx and %s at %dpx, %dpx apart',
					$one['where'],
					(int) round( $one['at'] ),
					$other['where'],
					(int) round( $other['at'] ),
					(int) round( $apart )
				);
			}
		}

		if ( array() === $near ) {
			return;
		}

		$this->add(
			'almost_aligned',
			'medium',
			sprintf( '%d pair(s) of blocks almost line up.', count( $near ) ),
			implode( '; ', array_slice( array_values( $near ), 0, 3 ) ) . '.',
			'A grid with a fixed first column starts its second column at that width plus the gap, so a sibling '
				. 'indented by the column width alone lands one gap short. Either use the same number on both, or '
				. 'make the difference big enough to read as a choice.'
		);
	}

	/**
	 * Where a rule's content starts, from the left.
	 *
	 * @param array<string,string> $declarations
	 * @return array<string,float>
	 */
	private function indents_of( array $declarations ): array {
		$out = array();

		foreach ( array( 'margin-left' => 'margin-left', 'padding-left' => 'padding-left' ) as $property => $label ) {
			if ( isset( $declarations[ $property ] ) ) {
				$out[ $label ] = $this->largest_size( (string) $declarations[ $property ] );
			}
		}

		/*
		 * Only a deliberate left indent counts. A shorthand padding on a
		 * container is how wide the page gutter is, not an attempt to meet
		 * anything, and comparing it against a grid column reports that 34 and
		 * 36 disagree — which is true and means nothing.
		 */

		// A fixed first column: the next column starts at its width plus the gap.
		if ( isset( $declarations['grid-template-columns'] ) ) {
			$first = $this->first_track( (string) $declarations['grid-template-columns'] );
			$gap   = isset( $declarations['gap'] ) ? $this->largest_size( (string) $declarations['gap'] )
				: ( isset( $declarations['column-gap'] ) ? $this->largest_size( (string) $declarations['column-gap'] ) : 0.0 );

			if ( null !== $first ) {
				$out['second column'] = $first + $gap;
			}
		}

		return $out;
	}

	/** A first grid track, when it is a plain fixed width. */
	private function first_track( string $value ): ?float {
		$parts = preg_split( '/\s+(?![^(]*\))/', trim( $value ) ) ?: array();

		if ( array() === $parts ) {
			return null;
		}

		$first = (string) $parts[0];

		// minmax() and fr are not a fixed edge, so nothing can be said.
		if ( false !== stripos( $first, 'minmax' ) || false !== stripos( $first, 'fr' ) || false !== stripos( $first, 'repeat' ) ) {
			return null;
		}

		$size = $this->largest_size( $first );

		return $size > 0.0 ? $size : null;
	}

	private function check_responsive(): void {
		$fluid = 0;
		foreach ( array_merge( $this->css->declarations(), $this->inherited->declarations() ) as $declaration ) {
			if ( preg_match( '/clamp\(|minmax\(|\d+vw|\bauto-fit\b|\bauto-fill\b/i', $declaration['value'] ) ) {
				++$fluid;
			}
		}

		if ( $this->css->media_queries() + $this->inherited->media_queries() > 0 || $fluid > 2 ) {
			return;
		}

		$this->add(
			'not_responsive',
			'high',
			'The page has no media queries and almost nothing fluid.',
			sprintf( '%d media queries, %d fluid values.', $this->css->media_queries(), $fluid ),
			'It will break on a phone. Use clamp() for type and spacing, minmax() or auto-fit for grids, '
				. 'and a media query where the layout genuinely has to change.'
		);
	}

	private function check_measure(): void {
		if ( $this->is_chrome ) {
			return;
		}

		$limited = false;

		foreach ( array_merge( $this->css->declarations(), $this->inherited->declarations() ) as $declaration ) {
			if ( ! in_array( $declaration['property'], array( 'max-width', 'width', 'max-inline-size' ), true ) ) {
				continue;
			}

			if ( false !== strpos( $declaration['value'], 'ch' ) ) {
				$limited = true;
				break;
			}

			$px = $this->to_px( trim( (string) strtok( $declaration['value'], ' ' ) ) );
			if ( null !== $px && $px > 0 && $px <= 820 ) {
				$limited = true;
				break;
			}
		}

		if ( $limited ) {
			return;
		}

		$words = str_word_count( wp_strip_all_tags( $this->html ) );
		if ( $words < 120 ) {
			return;
		}

		$this->add(
			'no_measure',
			'medium',
			'Nothing limits how wide a line of text can get.',
			sprintf( 'The page has about %d words and no max-width under 820px anywhere.', $words ),
			'On a wide screen the eye loses its place. Give text containers max-width: 68ch, or about 720px.'
		);
	}

	private function check_heading_order(): void {
		if ( $this->is_chrome ) {
			return;
		}

		$doc = $this->document();
		if ( null === $doc ) {
			return;
		}

		$xpath    = new DOMXPath( $doc );
		$headings = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );

		if ( ! $headings ) {
			return;
		}

		$last     = 0;
		$problems = array();

		foreach ( $headings as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}

			$level = (int) substr( $heading->nodeName, 1 );
			$text  = trim( (string) $heading->textContent );

			if ( 0 !== $last && $level > $last + 1 ) {
				$problems[] = sprintf( 'h%d "%s" follows an h%d', $level, $this->shorten( $text ), $last );
			}

			$last = $level;
		}

		if ( array() === $problems ) {
			return;
		}

		$this->add(
			'heading_levels_skip',
			'medium',
			sprintf( '%d heading(s) skip a level.', count( $problems ) ),
			implode( '; ', array_slice( $problems, 0, 8 ) ),
			'Headings are the page outline a screen reader reads out. Go down one level at a time. '
				. 'If a heading is only meant to look smaller, keep the level and change the CSS.'
		);
	}

	/**
	 * A page that builds its own components instead of the site's.
	 *
	 * Page CSS is for laying this page out. The moment a page defines its own
	 * card, its own button, its own panel, the site has two of each and page
	 * twelve stops matching page one. This is the check that notices.
	 */
	private function check_component_reuse(): void {
		// The header and footer are the shared thing. A logo mark, a menu
		// button and a close link belong to them and to nothing else, so
		// telling their author to move them into the page component library is
		// advice nobody should take — and a check people learn to ignore is
		// worse than no check. The chrome still uses the library's buttons.
		if ( $this->is_chrome ) {
			return;
		}

		if ( $this->library->is_empty() ) {
			$this->add(
				'no_component_library',
				'medium',
				'This site has no component library.',
				'Every page is writing its own pieces from scratch, so nothing is shared between them.',
				'Design a set of components for this business with design_set_components, then build pages out of them. '
					. 'Invent them for this site — the point is that no two sites end up alike.'
			);
			return;
		}

		$known = array_flip( $this->library->class_names() );
		$own   = array();

		foreach ( $this->css->rules() as $rule ) {
			// One class on its own, with a surface of its own, is a component.
			// A descendant selector is this page arranging things, which is fine.
			if ( ! preg_match( '/^\.(-?[_a-zA-Z][\w-]*)$/', $rule['selector'], $m ) ) {
				continue;
			}

			if ( isset( $known[ $m[1] ] ) ) {
				continue;
			}

			$surface = 0;
			foreach ( array( 'background', 'background-color', 'border', 'border-width', 'box-shadow', 'border-radius', 'padding' ) as $property ) {
				if ( isset( $rule['declarations'][ $property ] ) ) {
					++$surface;
				}
			}

			if ( $surface >= 2 ) {
				$own[] = '.' . $m[1];
			}
		}

		if ( count( $own ) < 3 ) {
			return;
		}

		$this->add(
			'page_invents_components',
			count( $own ) > 6 ? 'medium' : 'low',
			sprintf(
				'This page defines %d component(s) of its own while the site library has %d.',
				count( $own ),
				$this->library->count()
			),
			implode( ', ', array_slice( $own, 0, 14 ) )
				. '. Counted as a component: one class, on its own, that sets at least two of background, border, shadow, radius or padding.',
			'Use the site\'s components. If the page genuinely needs something new, add it to the library with '
				. 'design_set_components so the next page can use it too, instead of leaving it here where nothing else can reach it.'
		);
	}

	/**
	 * A page that contradicts a decision the site already made.
	 *
	 * The design system records how this site separates things and how much it
	 * moves. Those are choices, not rules — but once made, a page that ignores
	 * them is the reason a site looks like several sites.
	 */
	private function check_style_decisions(): void {
		$style = (array) ( $this->system->tokens()['style'] ?? array() );

		$surface = strtolower( (string) ( $style['surface'] ?? '' ) );
		if ( '' !== $surface && false === strpos( $surface, 'shadow' ) ) {
			$shadows = array_filter(
				$this->css->values_of( 'box-shadow' ),
				static fn( string $v ): bool => 'none' !== strtolower( trim( $v ) )
			);

			if ( count( $shadows ) > 1 ) {
				$this->add(
					'contradicts_style',
					'low',
					sprintf( 'The page uses %d drop shadows, but this site separates things another way.', count( $shadows ) ),
					sprintf( 'The design system says: "%s"', $style['surface'] ),
					'Either separate things the way the rest of the site does, or change the decision in the design '
						. 'system so every page follows the new one. One page going its own way is what makes a site look assembled.'
				);
			}
		}

		/*
		 * Imagery was the one written decision nothing ever checked, and it is
		 * the one that decides whether a page is designed or merely typeset.
		 * A site that says its argument is photographs of pipework, and then
		 * ships pages of unbroken text, reads as a document rather than a
		 * website — however good the typography is.
		 */
		$imagery = strtolower( (string) ( $style['imagery'] ?? '' ) );

		// A header and footer carry navigation, not the page's argument. The
		// imagery decision is about pages.
		if ( ! $this->is_chrome && '' !== $imagery && ! $this->imagery_says_none( $imagery ) && ! $this->has_anything_to_look_at() ) {
			$this->add(
				'nothing_to_look_at',
				'medium',
				'There is no image, diagram or figure anywhere on this page.',
				sprintf( 'The design system says imagery is: "%s"', $style['imagery'] ),
				'A page of unbroken text reads as a document, not as a site, whatever the type is doing. '
					. 'Put in what the decision says belongs here, or change the decision to say this site is '
					. 'text only and mean it.'
			);
		}

		$motion = strtolower( (string) ( $style['motion'] ?? '' ) );
		if ( '' !== $motion && ( false !== strpos( $motion, 'none' ) || false !== strpos( $motion, 'no motion' ) ) ) {
			$moves = count( $this->css->values_of( 'transition' ) ) + count( $this->css->values_of( 'animation' ) );

			if ( $moves > 0 ) {
				$this->add(
					'contradicts_style',
					'low',
					'The page animates things on a site that decided not to move.',
					sprintf( 'The design system says: "%s"', $style['motion'] ),
					'Drop the motion here, or change the decision in the design system.'
				);
			}
		}
	}

	// ------------------------------------------------------------- utilities

	private const NEUTRALS = array(
		'#fff', '#ffffff', '#000', '#000000', 'transparent', 'currentcolor',
	);

	/**
	 * Whether a colour is white, black or nothing, in any notation.
	 *
	 * The literal list above misses the ones a real stylesheet writes:
	 * rgba(255,255,255,.94) under a sticky header, rgba(0,0,0,.06) in a
	 * shadow, #ffffffee. Those are not a palette decision, and reporting them
	 * as one told the author to swap white for a brand colour.
	 */
	private static function is_neutral( string $color ): bool {
		if ( in_array( $color, self::NEUTRALS, true ) ) {
			return true;
		}

		$rgb = Color::to_rgb( $color );
		if ( null === $rgb ) {
			return false;
		}

		return array( 255, 255, 255 ) === $rgb || array( 0, 0, 0 ) === $rgb;
	}

	/**
	 * Font sizes in pixels, ignoring anything responsive.
	 *
	 * @return float[]
	 */
	private function font_sizes( ?CSSReader $sheet = null ): array {
		$sizes = array();

		foreach ( ( $sheet ?? $this->css )->values_of( 'font-size' ) as $value ) {
			// In clamp(min, fluid, max) the largest the text ever gets is the max.
			$clamped = self::clamp_max( $value );
			if ( null !== $clamped ) {
				$value = $clamped;
			}

			// A bare var() that names the scale is resolvable; anything else is not.
			if ( preg_match( '/(calc|min|max)\(/i', $value ) ) {
				continue;
			}
			if ( preg_match( '/var\(/i', $value ) && ! preg_match( '/^var\(\s*--aiwp-text-/', trim( $value ) ) ) {
				continue;
			}

			$px = $this->to_px( trim( $value ) );
			if ( null !== $px && $px > 0 ) {
				$sizes[] = $px;
			}
		}

		return $sizes;
	}

	/**
	 * The last argument of a clamp(), or null when the value is not one.
	 *
	 * Written by hand rather than with a pattern because the arguments contain
	 * their own brackets — clamp(var(--a), 5vw, var(--b)). A pattern that stops
	 * at the first closing bracket reads the minimum as the maximum, which made
	 * every fluid headline on a real site look half its size.
	 */
	private static function clamp_max( string $value ): ?string {
		$at = stripos( $value, 'clamp(' );
		if ( false === $at ) {
			return null;
		}

		$i     = $at + 6;
		$depth = 1;
		$parts = array( '' );

		for ( $n = strlen( $value ); $i < $n; $i++ ) {
			$char = $value[ $i ];

			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
			}

			if ( ',' === $char && 1 === $depth ) {
				$parts[] = '';
				continue;
			}

			$parts[ count( $parts ) - 1 ] .= $char;
		}

		$last = trim( (string) end( $parts ) );

		return '' !== $last ? $last : null;
	}

	/**
	 * @param array<string,string> $declarations
	 */
	private function is_large_text( array $declarations ): bool {
		$size = $this->to_px( $declarations['font-size'] ?? '' );
		if ( null === $size ) {
			return false;
		}

		$weight = strtolower( trim( $declarations['font-weight'] ?? '' ) );
		$bold   = 'bold' === $weight || ( is_numeric( $weight ) && (int) $weight >= 700 );

		return $size >= 24.0 || ( $bold && $size >= 18.66 );
	}

	/**
	 * A colour value, following one level of var() back to the design system.
	 */
	private function resolve( string $value ): string {
		$value = trim( $value );

		if ( preg_match( '/var\(\s*(--[\w-]+)/', $value, $m ) ) {
			$name   = $m[1];
			$tokens = (array) ( $this->system->tokens()['colors'] ?? array() );

			foreach ( $tokens as $key => $token ) {
				if ( '--aiwp-color-' . $key === $name && is_string( $token ) ) {
					return strtolower( $token );
				}
			}

			return '';
		}

		$found = Color::find_all( $value );
		if ( array() !== $found ) {
			return $found[0];
		}

		$word = strtolower( (string) strtok( $value, ' ' ) );
		return in_array( $word, array( 'white', 'black' ), true ) ? $word : '';
	}

	private function to_px( string $value ): ?float {
		$value = trim( strtolower( $value ) );

		// The design system publishes its scale as variables and the prompts
		// tell pages to use them, so a reviewer that cannot read them reports
		// that every page has no large text on it. Which is what it did.
		if ( preg_match( '/^var\(\s*--aiwp-text-([\w-]+)/', $value, $m ) ) {
			$scale = $this->text_scale();
			return $scale[ $m[1] ] ?? null;
		}

		// And the spacing scale, for the same reason: padding is written with
		// these tokens everywhere, and a check that cannot read them reports
		// that no page has any padding at all.
		if ( preg_match( '/^var\(\s*--aiwp-space-([\w-]+)/', $value, $m ) ) {
			$spacing = (array) ( $this->system->tokens()['spacing'] ?? array() );
			$named   = (string) ( $spacing[ $m[1] ] ?? '' );

			return '' === $named ? null : $this->to_px( $named );
		}

		if ( ! preg_match( '/^(-?[\d.]+)(px|rem|em|pt)?$/', $value, $m ) ) {
			return null;
		}

		$number = (float) $m[1];
		$unit   = $m[2] ?? 'px';

		return match ( $unit ) {
			// rem is relative to the browser root, which is 16px. It is NOT the
			// design system's base size, and treating it as one made every
			// reported font size wrong by the ratio between them.
			'rem'   => $number * self::ROOT_FONT_SIZE,
			'em'    => $number * $this->base_size,
			'pt'    => $number * 1.3333,
			default => $number,
		};
	}

	/**
	 * The type scale in pixels, keyed the way the CSS variables are.
	 *
	 * @return array<string,float>
	 */
	private function text_scale(): array {
		static $steps = array( 'xs' => -2, 'sm' => -1, 'base' => 0, 'lg' => 1, 'xl' => 2, '2xl' => 3, '3xl' => 4, '4xl' => 5, '5xl' => 6 );

		$typography = (array) ( $this->system->tokens()['typography'] ?? array() );
		$ratio      = (float) ( $typography['scale'] ?? 1.25 );

		if ( $ratio <= 1.0 ) {
			$ratio = 1.25;
		}

		$out = array();
		foreach ( $steps as $name => $step ) {
			$out[ $name ] = round( $this->base_size * ( $ratio ** $step ), 2 );
		}

		return $out;
	}

	private function document(): ?DOMDocument {
		if ( '' === trim( $this->html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$ok       = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $this->html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $doc : null;
	}

	private function anywhere_has( string $needle ): bool {
		return $this->css->has_selector_containing( $needle )
			|| $this->inherited->has_selector_containing( $needle );
	}

	private function shorten( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? $text );
		return strlen( $text ) > 44 ? substr( $text, 0, 44 ) . '…' : $text;
	}

	private function count( string $severity ): int {
		return count( array_filter( $this->findings, static fn( array $f ): bool => $f['severity'] === $severity ) );
	}

	private function add( string $id, string $severity, string $title, string $detail, string $fix ): void {
		$this->findings[] = array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'detail'   => $detail,
			'fix'      => $fix,
		);
	}
}
