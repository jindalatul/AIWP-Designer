<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Pages;

use AIWP\Designer\Security\AuditLogger;

/**
 * Which page WordPress serves at the root of the site.
 *
 * Building a homepage and leaving the site showing the default blog listing is a
 * mistake nobody means to make, so a page that is plainly the homepage takes the
 * front-page slot on its own. It only takes effect once the page is published:
 * pointing the front page at a draft would show visitors a 404.
 */
final class FrontPage {

	/** Set on a page that should become the front page when it is published. */
	public const META_INTENT = '_aiwp_front_page_intent';

	/** Titles and slugs that mean "this is the homepage". */
	private const HOME_NAMES = array( 'home', 'homepage', 'index', 'front page', 'front-page' );

	public static function current(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}
		return absint( get_option( 'page_on_front' ) );
	}

	public static function is_front( int $page_id ): bool {
		return $page_id > 0 && self::current() === $page_id;
	}

	/**
	 * Has anybody chosen a front page yet?
	 */
	public static function is_unset(): bool {
		$current = self::current();
		return 0 === $current || 'page' !== get_post_type( $current );
	}

	/**
	 * Point the site root at this page.
	 */
	public static function set( int $page_id ): bool {
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) ) {
			return false;
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		AuditLogger::log( 'front_page_set', array( 'page_id' => $page_id ) );

		return true;
	}

	/**
	 * Hand the site root back to the blog listing.
	 */
	public static function clear(): void {
		$previous = self::current();

		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );

		if ( $previous > 0 ) {
			self::release( $previous );
		}

		AuditLogger::log( 'front_page_cleared', array( 'page_id' => $previous ) );
	}

	/**
	 * Decide whether this page should claim the front page.
	 *
	 * Explicit wins. Otherwise a page named like a homepage takes the slot, but
	 * only while the slot is empty — an existing front page is never replaced by
	 * accident.
	 *
	 * @param array<string,mixed> $page_input the `page` object from the tool call
	 */
	public static function wants_front_page( array $page_input ): bool {
		if ( array_key_exists( 'front_page', $page_input ) ) {
			return (bool) $page_input['front_page'];
		}

		if ( ! self::is_unset() ) {
			return false;
		}

		return self::looks_like_home( (string) ( $page_input['slug'] ?? '' ) )
			|| self::looks_like_home( (string) ( $page_input['title'] ?? '' ) );
	}

	private static function looks_like_home( string $value ): bool {
		$value = strtolower( trim( $value ) );
		return '' !== $value && in_array( $value, self::HOME_NAMES, true );
	}

	/**
	 * Record the intent, and act on it now if the page is already public.
	 *
	 * @return bool whether the page is the front page right now
	 */
	public static function claim( int $page_id ): bool {
		update_post_meta( $page_id, self::META_INTENT, '1' );

		if ( 'publish' === get_post_status( $page_id ) ) {
			return self::set( $page_id );
		}

		return false;
	}

	public static function release( int $page_id ): void {
		delete_post_meta( $page_id, self::META_INTENT );
	}

	/**
	 * A published page that asked to be the front page and is not it.
	 *
	 * @return int the page id, or 0
	 */
	public static function waiting(): int {
		$found = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'meta_key'         => self::META_INTENT,
				'meta_value'       => '1',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		foreach ( (array) $found as $id ) {
			if ( ! self::is_front( (int) $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	public static function has_intent( int $page_id ): bool {
		return '1' === (string) get_post_meta( $page_id, self::META_INTENT, true );
	}

	/**
	 * Called when a page is published: honour an intent recorded at build time.
	 */
	public static function apply_on_publish( int $page_id ): bool {
		if ( ! self::has_intent( $page_id ) || self::is_front( $page_id ) ) {
			return false;
		}

		return self::set( $page_id );
	}

	/**
	 * Honour the intent however the page got published.
	 *
	 * page_publish called apply_on_publish and nothing else did, so a page
	 * built as the homepage and then published any other way — from wp-admin,
	 * or by a path in this plugin that sets the status itself — kept the intent
	 * and never got the root. The site root went on serving the blog listing
	 * and nothing said so.
	 */
	public static function watch(): void {
		add_action(
			'transition_post_status',
			static function ( $new_status, $old_status, $post ): void {
				if ( 'publish' !== $new_status || $new_status === $old_status ) {
					return;
				}
				if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
					return;
				}

				self::apply_on_publish( (int) $post->ID );
			},
			10,
			3
		);
	}

	/**
	 * A front page cannot be a draft. If the page WordPress points at is not
	 * public, say so rather than letting the site 404 quietly.
	 */
	public static function problem(): string {
		$page_id = self::current();

		if ( 0 === $page_id ) {
			// A page was built to be the homepage, is published, and the root
			// still serves something else. This used to report nothing at all,
			// so the one page the site is for was unreachable at its own
			// address and the context said everything was fine.
			$waiting = self::waiting();

			if ( 0 !== $waiting ) {
				return sprintf(
					/* translators: %s: page title */
					__( '"%s" was built as the homepage and is published, but the site root still serves something else. Call site_set_front_page.', 'aiwp-designer' ),
					(string) get_the_title( $waiting )
				);
			}

			return '';
		}

		if ( 'publish' !== get_post_status( $page_id ) ) {
			return sprintf(
				/* translators: %s: page title */
				__( '"%s" is set as the front page but is not published, so visitors see a not-found page at the site root.', 'aiwp-designer' ),
				(string) get_the_title( $page_id )
			);
		}

		return '';
	}
}
