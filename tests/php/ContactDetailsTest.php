<?php
declare( strict_types = 1 );

use AIWP\Designer\Pages\PageInspector;
use PHPUnit\Framework\TestCase;

/**
 * A phone number in the footer and on the contact page is not asking twice.
 *
 * `asked_twice` exists to catch a page that ends with the same button the
 * header already carries. It compared every link, so on the one page whose job
 * is to carry the email address and the telephone number it reported both as
 * duplicates — and told the author to delete them from the contact page.
 */
final class ContactDetailsTest extends TestCase {

	/** @return array<string,string> */
	private function links( string $markup ): array {
		$method = new \ReflectionMethod( PageInspector::class, 'links_in' );
		$method->setAccessible( true );

		$inspector = ( new \ReflectionClass( PageInspector::class ) )->newInstanceWithoutConstructor();

		return $method->invoke( $inspector, $markup );
	}

	public function test_an_email_address_is_not_counted_as_an_ask(): void {
		$this->assertSame( array(), $this->links( '<a href="mailto:hello@example.com">hello@example.com</a>' ) );
	}

	public function test_a_telephone_number_is_not_counted_as_an_ask(): void {
		$this->assertSame( array(), $this->links( '<a href="tel:+442079460412">+44 20 7946 0412</a>' ) );
	}

	public function test_a_real_call_to_action_is_still_counted(): void {
		$this->assertNotSame( array(), $this->links( '<a href="/contact/">Request a free SEO audit</a>' ) );
	}

	public function test_a_bare_anchor_is_still_ignored(): void {
		$this->assertSame( array(), $this->links( '<a href="#">Top</a>' ) );
	}
}
